<?php
namespace App\Module\Sale\Logic\Backend\Goods;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Lib\Qiniu\Qiniu;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Mqc\MqcBatchModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdBidPriceModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Prd\PrdWaterModel;
use App\Model\Pur\PurCategoryModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurWaterModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Model\Stc\StcStorageModel;
use App\Model\Xye\XyeProductOperateModel;
use App\Module\Mqc\Data\MqcDictData;
use App\Module\Pub\Data\PrdDictData;
use App\Module\Qto\Data\QtoOptionsData;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Configer;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;
use Throwable;

/**
 * Class GoodsLogic
 */
class GoodsLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var QtoOptionsData
     */
    private $qtoOptionsData;

    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 商品列表
     * @param array $query 搜索
     * @param int $idx 页码
     * @param int $size 页量
     * @return array
     */
    public function getPager(array $query, int $idx = 1, $size = 25)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //是否待上架列表
        if (isset($where['src']))
        {
            unset($where['src']);
        }

        //获取翻页数据
        $cols = 'pid,plat,offer,bcode,bid,level,pname,prdstat,stcstat,rectime4,rectime7,rectime61,imgtime,nobids';
        $list = PrdProductModel::M()->getList($where, $cols, ['imgtime' => -1], $size, $idx);
        if ($list == false)
        {
            return [];
        }

        //获取品牌字典
        $bids = ArrayHelper::map($list, 'bid');
        $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bid,bname');

        //获取级别字典
        $levels = ArrayHelper::map($list, 'level');
        $levelDict = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lkey,lname');

        //获取供应商字典
        $offers = ArrayHelper::map($list, 'offer');
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offers]], 'oid,oname');

        //获取销售属性
        $pids = ArrayHelper::map($list, 'pid');
        $mqcDict = $this->getMqcDict($pids);

        //补充数据
        foreach ($list as $key => $value)
        {
            $list[$key]['bname'] = $bidDict[$value['bid']]['bname'] ?? '-';
            $list[$key]['lname'] = $levelDict[$value['level']]['lname'] ?? '-';
            $list[$key]['platname'] = $offerDict[$value['offer']]['oname'] ?? '-';
            $list[$key]['rectime4'] = DateHelper::toString($value['rectime4']);
            $list[$key]['rectime7'] = DateHelper::toString($value['rectime7']);
            $list[$key]['rectime61'] = DateHelper::toString($value['rectime61']);
            $list[$key]['imgtime'] = DateHelper::toString($value['imgtime']);
            $list[$key]['prdstat'] = SaleDictData::PRD_STCSTAT[$value['stcstat']] ?? '-';

            //在库时长
            $list[$key]['intime'] = ceil((time() - $value['rectime4']) / 86400);

            //内存 颜色 销售渠道 网络制式
            $mqcData = $mqcDict[$value['pid']] ?? [];
            $list[$key]['memory'] = $mqcData['memory'] ?? '-';
            $list[$key]['color'] = $mqcData['color'] ?? '-';
            $list[$key]['sale'] = $mqcData['sale'] ?? '-';
            $list[$key]['netway'] = $mqcData['netway'] ?? '-';
        }

        //补充默认数据
        ArrayHelper::fillDefaultValue($list, [0, '', '0']);

        //返回
        return $list;
    }

    /**
     * 商品总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);
        if (isset($where['src']))
        {
            unset($where['src']);
        }

        //获取数据
        $count = PrdProductModel::M()->getCount($where);

        //容错处理，优品非新新二手机销售的订单(比如线下门店成交) 拍照状态不对，导致搜索不出来数据
        $repWhere = [
            'rectime4' => ['between' => [time() - 30 * 86400, time()]],
            'prdstat' => 2,
            'stcstat' => 23,
            'imgstat' => ['!=' => 2]
        ];
        PrdProductModel::M()->update($repWhere, ['imgstat' => 2]);

        //返回
        return $count;
    }

    /**
     * 商品详情
     * @param string $type 不同地方点的弹窗
     * @param string $pid
     * @param string $sid
     * @return array
     * @throws
     */
    public function getInfo(string $type, string $pid, string $sid)
    {
        //商品基础数据
        $cols = 'plat,pid,pname,bcost,bcode,offer,stcstat,recstat,bid,mid,level,olevel,rectime4,saletime';
        $info = PrdProductModel::M()->getRowById($pid, $cols);
        if ($info == false)
        {
            throw new AppException(AppException::NO_DATA);
        }

        $prdSaleInfo = [];

        //运营管理-竞拍场次&一口价
        if ($sid)
        {
            //竞拍商品数据
            if ($type == 'bidSale')
            {
                $prdSaleInfo = PrdBidSalesModel::M()->getRow(['sid' => $sid], 'sid,stat,sprc,kprc,aprc,bprc,luckodr', ['atime' => -1]);
            }

            //一口价商品数据
            if ($type == 'shopSale')
            {
                $prdSaleInfo = PrdShopSalesModel::M()->getRow(['sid' => $sid], 'sid,stat,bprc,luckodr', ['atime' => -1]);
            }
        }

        //商品管理
        if ($type == 'goods')
        {
            $bidSale = PrdBidSalesModel::M()->getRow(['pid' => $pid], 'sid,stat,sprc,kprc,aprc,bprc,atime,luckodr', ['atime' => -1]);
            $shopSale = PrdShopSalesModel::M()->getRow(['pid' => $pid], 'sid,stat,bprc,atime,luckodr', ['atime' => -1]);
            if ($bidSale['atime'] > $shopSale['atime'])
            {
                $prdSaleInfo = $bidSale;
            }
            else
            {
                $shopSale['kprc'] = $shopSale['bprc'];
                $prdSaleInfo = $shopSale;
            }
        }

        //数据处理
        $lkeys = [];
        $levelDict = [];
        if ($info['level'])
        {
            $lkeys[] = $info['level'];
        }
        if ($info['olevel'])
        {
            $lkeys[] = $info['olevel'];
        }
        if ($lkeys)
        {
            $levelDict = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $lkeys]], 'lkey,lname');
        }

        //成交价
        $bprc = '-';
        if (isset($prdSaleInfo['stat']) && in_array($prdSaleInfo['stat'], [21, 33]))
        {
            $paystat = OdrOrderModel::M()->getOneById($prdSaleInfo['luckodr'], 'paystat');
            if ($paystat == 3)
            {
                $bprc = number_format($prdSaleInfo['bprc']);
            }
        }

        // 商品分类
        $cid = PurOdrGoodsModel::M()->getOne(['bcode' => $info['bcode']], 'cid');
        if ($cid)
        {
            $cname = PurCategoryModel::M()->getOne(['cid' => $cid], 'cname');
        }
        $info['cname'] = $cname ?? '-';

        $info['levelname'] = ($levelDict[$info['level']]['lname'] ?? $levelDict[$info['olevel']]['lname'] ?? '') ?: '-';
        $info['brandname'] = QtoBrandModel::M()->getOneById($info['bid'], 'bname', [], '-');
        $info['saletime'] = $info['saletime'] ? DateHelper::toString($info['saletime']) : '-';
        $info['plat'] = CrmOfferModel::M()->getOneById($info['offer'], 'oname', [], '-');
        //$info['prdstat'] = SaleDictData::BID_SALES_STAT[$prdSaleInfo['stat']] ?? '-';
        $info['prdstat'] = SaleDictData::PRD_STCSTAT[$info['stcstat']] ?? '-';
        $info['intime'] = $info['rectime4'] ? ceil((time() - $info['rectime4']) / 86400) : '-';
        $info['sprc'] = !empty($prdSaleInfo['sprc']) ? number_format($prdSaleInfo['sprc']) : '-';
        $info['kprc'] = !empty($prdSaleInfo['kprc']) ? number_format($prdSaleInfo['kprc']) : '-';
        $info['bprc'] = $bprc;
        $info['aprc'] = !empty($prdSaleInfo['aprc']) ? number_format($prdSaleInfo['aprc']) : '-';

        //返回
        return $info;
    }

    /**
     * 质检报告内容
     * @param string $pid 商品主键ID
     * @return mixed
     * @throws
     */
    public function getReport(string $pid)
    {
        //获取质检信息
        $cols = 'bid,beval,brmk,tflow,pid';
        $batch = MqcBatchModel::M()->getRow(['pid' => $pid, 'tflow' => [1, 2], 'chkstat' => 3], $cols, ['etime' => -1]);
        if ($batch == false)
        {
            return [];
        }

        //补充机况文本
        $cateOptionsDesc = $this->qtoOptionsData->getQcDesc(0, $batch['beval']);
        $cateOptionsDesc = array_values($cateOptionsDesc);

        //补充质检备注
        $where = ['bid' => $batch['bid'], 'bconc' => ['!=' => '']];
        $mqcReport = MqcReportModel::M()->getList($where, 'plat,bconc');
        $qcRmk = [];
        foreach ($mqcReport as $value)
        {
            $qcRmk[] = MqcDictData::SYSPLAT[$value['plat']] . ':' . $value['bconc'];
        }
        $cateOptionsDesc[] = [
            'cid' => '0001',
            'cname' => '质检备注',
            'desc' => $qcRmk
        ];

        // 电商备注
        $odrData = PrdProductModel::M()->leftJoin(PurOdrGoodsModel::M(), ['bcode' => 'bcode'])
            ->getRow(['A.pid' => $pid], 'A.bcode');
        $waterList = PurWaterModel::M()->getList(['bcode' => $odrData['bcode']], '*', ['wstat' => -1, 'wtime' => -1, 'atime' => -1]);
        $waccs = ArrayHelper::map($waterList, 'wacc');
        if ($waccs)
        {
            $waccDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $waccs]], 'rname');
        }
        $aaccs = ArrayHelper::map($waterList, 'aacc');
        if ($aaccs)
        {
            $aaccDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $aaccs]], 'rname');
        }
        foreach ($waterList as $key => $val)
        {
            $waterList[$key]['wtime'] = DateHelper::toString($val['wtime']) ?? '-';
            $waterList[$key]['atime'] = DateHelper::toString($val['atime']) ?? '-';
            $waterList[$key]['wacc'] = $waccDict[$val['wacc']]['rname'] ?? '-';
            $waterList[$key]['aacc'] = $aaccDict[$val['aacc']]['rname'] ?? '-';
        }
        $waterList = $waterList ?: '-';
        $cateOptionsDesc[] = [
            'cid' => '0002',
            'cname' => '电商备注',
            'desc' => $waterList
        ];

        //返回
        return $cateOptionsDesc;
    }

    /**
     * 获取商品出价记录
     * @param string $pid
     * @return array
     */
    public function getPriceList(string $pid)
    {
        //查询列
        $cols = 'pid,buyer,btime,bprc,rid';

        //出价记录
        $list = PrdBidPriceModel::M()->getList(['pid' => $pid], $cols, ['btime' => -1]);
        if ($list == false)
        {
            return [];
        }

        //场次名称字典
        $rids = ArrayHelper::map($list, 'rid');
        $roundDict = PrdBidRoundModel::M()->getDict('rid', ['rid' => ['in' => $rids]], 'rid,rname');

        //出价者字典
        $uids = ArrayHelper::map($list, 'buyer');
        $buyerDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $uids]], 'aid,uname');

        //一口价商品字典
        $pids = ArrayHelper::map($list, 'pid');
        $shopPrdDict = PrdShopSalesModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pid');

        //数据处理
        foreach ($list as $key => $value)
        {
            $list[$key]['buyer'] = $buyerDict[$value['buyer']]['uname'] ?? '-';
            $list[$key]['rname'] = $roundDict[$value['rid']]['rname'] ?? '-';
            $list[$key]['rname'] = $roundDict[$value['rid']]['rname'] ?? '一口价';
            $list[$key]['btime'] = DateHelper::toString($value['btime'], 'Y-m-d H:i:s');
            $list[$key]['bprc'] = Utility::formatNumber($value['bprc']);
        }

        //返回
        return $list;
    }

    /**
     * 获取操作流水列表
     * @param string $pid
     * @return array
     * @throws
     */
    public function getWaterList(string $pid)
    {
        //获取商品确认销售时间
        $info = PrdProductModel::M()->getRowById($pid, 'plat,rectime7,rectime61');
        if ($info == false)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }

        if (in_array($info['plat'], [19, 161]))
        {
            //闲鱼拍卖商品显示用户确认拍卖后的流水记录
            $where = [
                'pid' => $pid,
                'atime' => ['>' => $info['rectime61']]
            ];
        }
        else
        {
            //回收商品显示付完款后流水记录
            $where['pid'] = $pid;
            $where['atime'] = ['>' => $info['rectime7']];
        }

        //获取流水列表
        $list = PrdWaterModel::M()->getList($where, 'tid,rmk,acc,atime', ['atime' => -1], 25);
        if ($list == false)
        {
            return [];
        }

        //获取操作人字典
        $accs = ArrayHelper::map($list, 'acc');
        $accDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $accs]], 'aid,uname');

        //组装数据
        foreach ($list as $key => $value)
        {
            $list[$key]['aname'] = $accDict[$value['acc']]['uname'] ?? '-';
            $list[$key]['atime'] = DateHelper::toString($value['atime']);
            $list[$key]['type'] = PrdDictData::WATERTYPE[$value['tid']] ?? '-';
            if ($value['tid'] == 910)
            {
                $imgsrc = json_decode($value['rmk'] ?: '{}', true);
                $list[$key]['rmk'] = Utility::supplementProductImgsrc($imgsrc, 100);
            }
        }

        //返回
        return $list;
    }

    /**
     * 获取商品图片
     * @param string $pid
     * @return array
     * @throws
     */
    public function getPhotos(string $pid)
    {
        //获取商品信息
        $info = PrdSupplyModel::M()->getRow(['pid' => $pid, 'imgpack' => ['!=' => '']], 'imgpack', ['atime' => -1]);
        if ($info == false)
        {
            return [];
        }

        //处理图片
        $imgpack = ArrayHelper::toArray($info['imgpack']);
        $imgpack = Utility::supplementProductImgsrc($imgpack);

        //返回
        return $imgpack;
    }

    /**
     * 处理商品图片
     * @param string $pid
     * @param string $photo
     * @return string
     * @throws
     */
    public function matting(string $pid, string $photo)
    {
        //截取图片地址
        $domain = Configer::get('qiniu:domain:product', '');
        $photoUrl = str_replace($domain . '/', '', $photo);

        //获取图片信息
        $productInfo = PrdSupplyModel::M()->getRow(['pid' => $pid, 'salestat' => 1], 'imgpack', ['atime' => -1]);
        $imgpacks = ArrayHelper::toArray($productInfo['imgpack']);

        //组装图片
        $images = [];
        foreach ($imgpacks as $key => $value)
        {
            $images[] = $value['src'];
        }
        if (!in_array($photoUrl, $images))
        {
            throw new AppException('商品中找不到对应的图片', AppException::DATA_MISS);
        }

        $target = '';
        //将传过来的图片设置为默认
        foreach ($imgpacks as $key => $value)
        {
            if (isset($value['isXyeMain']))
            {
                if ($value['isXyeMain'] == 0)
                {
                    unset($imgpacks[$key]['isXyeMain']);
                }
                if ($value['isXyeMain'] == 1)
                {
                    $target = $value['src'];
                    unset($imgpacks[$key]);
                }
            }
            if ($value['src'] == $photoUrl)
            {
                $imgpacks[$key]['isXyeMain'] = 0;
            }
        }

        //将图片信息转成json
        $image = json_encode($imgpacks);

        //商品待处理事项
        $time = time();
        $oid = IdHelper::generate();
        $data = [
            'oid' => $oid,
            'pid' => $pid,
            'otype' => 41,
            'otime' => $time,
            'atime' => $time
        ];

        try
        {
            //开启事务
            Db::beginTransaction();

            //更新供应库商品图片信息
            PrdSupplyModel::M()->update(['pid' => $pid, 'salestat' => 1], ['imgpack' => $image]);

            //新增一条商品待处理事项
            XyeProductOperateModel::M()->insert($data);

            //上传成功则删除原图片
            if (!empty($target))
            {
                Qiniu::batchDelete($target);
            }

            //投递任务
            AmqpQueue::deliver($this->amqp_common, 'xye_product_image_matting', ['oid' => $oid]);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //事务回滚
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 自建订单-删除商品
     * @param string $gid 订单商品ID
     * @throws
     */
    public function delete(string $gid)
    {
        //获取订单商品信息
        $goodsInfo = OdrGoodsModel::M()->getRowById($gid, 'tid,okey,ostat,pid');
        if ($goodsInfo == false)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }
        if (!in_array($goodsInfo['tid'], [31, 33, 34, 35]) || $goodsInfo['ostat'] != 11)
        {
            throw new AppException('订单商品数据不允许删除', AppException::NO_RIGHT);
        }

        $okey = $goodsInfo['okey'];
        $pid = $goodsInfo['pid'];
        $time = time();

        //删除订单商品数据
        OdrGoodsModel::M()->deleteById($gid);

        //重新获取订单商品列表
        $goodsList = OdrGoodsModel::M()->getList(['okey' => $okey]);
        if ($goodsList == false)
        {
            //无订单商品，则删除订单
            OdrOrderModel::M()->delete(['okey' => $okey]);
        }
        else
        {
            //计算订单相关金额
            $oamt1 = 0; //自营商品金额
            $oamt2 = 0; //供应商商品金额
            $scost11 = 0; //自营商品成本
            $scost21 = 0; //供应商商品成本
            $profit11 = 0; //自有商品毛利
            $profit21 = 0; //供应商商品毛利
            $supprof = 0; //供应商商品佣金

            //重新计算订单相关金额
            foreach ($goodsList as $value)
            {
                $bprc = $value['bprc'];
                $scost1 = $value['scost1'];
                $profit1 = $value['profit1'];
                $supprof += $value['supprof'];
                if ($value['issup'] == 0)
                {
                    $oamt1 += $bprc;
                    $scost11 += $scost1;
                    $profit11 += $profit1;
                }
                else
                {
                    $oamt2 += $bprc;
                    $scost21 += $scost1;
                    $profit21 += $profit1;
                }
            }

            //合计订单总金额和订单总成本
            $oamt = $oamt1 + $oamt2;
            $ocost = $scost11 + $scost21;

            //更新订单数据
            OdrOrderModel::M()->update(['okey' => $okey], [
                'qty' => count($goodsList),
                'oamt' => $oamt,
                'oamt1' => $oamt1,
                'oamt2' => $oamt2,
                'payamt' => $oamt,
                'ocost1' => $ocost,
                'ocost2' => $ocost,
                'scost11' => $scost11,
                'scost12' => $scost11,
                'scost21' => $scost21,
                'scost22' => $scost21,
                'supprof' => $supprof,
                'profit11' => $profit11,
                'profit12' => $profit11,
                'profit21' => $profit21,
                'profit22' => $profit21,
                'mtime' => $time,
            ]);
        }

        //更新商品库存状态
        PrdProductModel::M()->updateById($pid, ['stcstat' => 11, 'stctime' => $time]);
        StcStorageModel::M()->update(['pid' => $pid, 'stat' => 1], ['prdstat' => 11]);
    }

    /**
     * 获取销售质检属性
     * @param array $pids
     * @return array
     */
    private function getMqcDict(array $pids)
    {
        if (empty($pids))
        {
            return [];
        }

        //获取质检字典 提取内存参数
        $mqcDict = MqcBatchModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'beval', ['stime' => -1]);
        $memoryCid = [17000];//内存大类
        $colorCid = [16000, 3300000, 4300000, 6330000, 220700000];//颜色大类
        $saleCid = [14000, 6320000, 7310000, 220500000];//购买渠道大类
        $netCid = [15000, 100000, 11110000];//网络制式

        //提取所有的选项ID,生成选项字典
        $qtoOids = [];
        foreach ($mqcDict as $key => $value)
        {
            $qtoOids = array_merge($qtoOids, explode('#', $value['beval']));
        }
        $qtoOids = array_unique($qtoOids);
        $qtoOidDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $qtoOids], 'plat' => 0], 'cid,oid,oname');

        //组装销售属性数据
        foreach ($mqcDict as $key => $value)
        {
            $beval = explode('#', $value['beval']);
            foreach ($beval as $oid)
            {
                $optData = $qtoOidDict[$oid] ?? [];
                if (empty($optData))
                {
                    continue;
                }
                $cid = $optData['cid'];
                if (in_array($cid, $memoryCid))
                {
                    $mqcDict[$key]['memory'] = $optData['oname'];
                }
                if (in_array($cid, $colorCid))
                {
                    $mqcDict[$key]['color'] = $optData['oname'];
                }
                if (in_array($cid, $saleCid))
                {
                    $mqcDict[$key]['sale'] = $optData['oname'];
                }
                if (in_array($cid, $netCid))
                {
                    $mqcDict[$key]['netway'] = $optData['oname'];
                }
            }
            unset($mqcDict[$key]['pid'], $mqcDict[$key]['beval']);
        }

        //返回
        return $mqcDict;
    }

    /**
     * 商品列表翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //数据条件
        $where = [
//            'stcwhs' => $query['whs'],
            'stcwhs' => [101, 121, 122], //临时写死指定仓库商品
            'imgstat' => 2
        ];

        //待上架
        $src = $query['src'];
        if ($src == 'wait')
        {
            $where['src'] = 'wait';
            $where['stcstat'] = ['in' => [11, 33, 34, 35]];
        }
        $where['recstat'] = ['in' => [61, 62, 63, 64, 7]];

        //库存编号
        $bcode = $query['bcode'];
        if (!empty($bcode))
        {
            $where['bcode'] = $bcode;
        }

        //来源渠道
        $plat = $query['plat'];
        if ($plat > 0)
        {
            if ($plat == 18)
            {
                $where['inway'] = ['in' => [2, 21]];
            }
            else
            {
                $where['plat'] = $plat;
            }
        }

        //供应商名称
        $oname = $query['oname'];
        if (!empty($oname))
        {
            $oWhere['oname'] = ['like' => '%' . $oname . '%'];
            $offer = CrmOfferModel::M()->getDistinct('oid', $oWhere);
            if (count($offer) > 0)
            {
                $where['offer'] = ['in' => $offer];
            }
            else
            {
                $where['offer'] = -1;
            }
        }

        //品牌
        $bid = $query['bid'];
        if (!empty($bid))
        {
            $where['bid'] = $bid;
        }

        //机型
        $mid = $query['mid'];
        if (!empty($mid))
        {
            $where['mid'] = $mid;
        }

        //级别
        $level = $query['level'];
        $where['level'] = ['<' => 40];
        if (!empty($level) && $level < 40)
        {
            $where['level'] = $level;
        }

        //商品库存状态(单选，兼容其他地方处理)
        $stcstat = $query['stcstat'];
        if (!empty($stcstat))
        {
            $where['stcstat'] = $stcstat;
        }

        //商品库存状态(多选)
        if ($query['stcstats'] && count($query['stcstats']) > 0)
        {
            $where['stcstat'] = ['in' => $query['stcstats']];
        }

        //是否流标
        $nobids = $query['nobids'];
        if ($nobids)
        {
            $where['nobids'] = $nobids == 1 ? ['>' => 0] : 0;
        }

        //是否上架
        $onshelf = $query['onshelf'];
        if ($onshelf)
        {
            $where['upshelfs'] = $onshelf == 1 ? ['>' => 0] : 0;
        }

        //时间类型和时间范围
        $type = $query['timetype'];
        $queryTime = $query['date'] ?? [];
        if (count($queryTime) == 2)
        {
            $stime = strtotime($queryTime[0]);
            $etime = strtotime($queryTime[1]) + 86399;
            if ($type == 'saletime')
            {
                $where['$or'] = [
                    ['rectime61' => ['between' => [$stime, $etime]]],
                    ['rectime7' => ['between' => [$stime, $etime]]]
                ];
            }
            else
            {
                $where[$type] = ['between' => [$stime, $etime]];
            }
        }

        //内存筛选 - 在原有条件下筛选数据
        $memory = $query['memory'] ?? 0;
        if ($memory > 0)
        {
            //提取内存对应的oid
            $oids = QtoOptionsModel::M()->getList([
                'plat' => 0,
                'cid' => 17000,
                '$or' => [
                    ['oname' => $memory . 'G'],
                    ['oname' => ['like' => '%+' . $memory . 'G']]
                ]
            ]);

            $orWhere = [];
            foreach ($oids as $value)
            {
                $orWhere[] = ['B.beval' => ['like' => '%#' . $value['oid'] . '#%']];
            }
            if (empty($orWhere))
            {
                $where['bcode'] = '-1';
            }
            else
            {
                //提取现有商品  包含指定内存选项的商品
                $mqcWhere = [];
                foreach ($where as $key => $value)
                {
                    $mqcWhere['A.' . $key] = $value;
                }
                $mqcWhere['$or'] = $orWhere;
                $mqcPrds = PrdProductModel::M()->Join(MqcBatchModel::M(), ['pid' => 'pid'])->getList($mqcWhere, 'A.pid');
                if (empty($mqcPrds))
                {
                    $where['bcode'] = '-1';
                }
                else
                {
                    $where['pid'] = ['in' => array_column($mqcPrds, 'pid')];
                }
            }
        }

        //2020-08-25 15:49临时使用  过滤闲鱼寄卖已上架的商品
//        $swhere = [
//            'inway' => 1611,
//            'stat' => ['in' => [11, 12]],
//        ];
//        $sales = PrdBidSalesModel::M()->getList($swhere, 'pid');
//        if (count($sales) > 0)
//        {
//            $pids = array_column($sales, 'pid');
//            $where['pid'] = ['not in' => $pids];
//        }

        //电商采购商品不能出现在待上架
        if (empty($where['inway']))
        {
            $where['inway'] = ['!=' => 51];
        }

        //返回
        return $where;
    }

    /**
     * 新增备注
     * @param string $rmk
     * @param string $bcode
     * @param string $acc
     * @return array
     * @throws
     */
    public function addWater(string $rmk, string $bcode, string $acc)
    {
        // 组装数据
        $data = [
            'wid'   => IdHelper::generate(),
            'wstat' => 1,
            'rmk' => $rmk,
            'bcode' => $bcode,
            'wacc'  => $acc,
            'wtime' => time()
        ];

        // 数据操作
        PurWaterModel::M()->insert($data);
    }

    /**
     * 删除备注
     * @param string $wid
     * @param string $acc
     * @return array
     * @throws
     */
    public function delWater(string $wid, string $acc)
    {
        // 检查数据是否存在
        $list = PurWaterModel::M()->getList(['wid' => $wid]);
        if (!$list)
        {
            throw new AppException('备注数据不存在');
        }
        // 检查权限
        // 组装数据
        $data = [
            'wstat' => 0,
            'aacc'  => $acc,
            'atime' => time()
        ];

        // 操作数据
        PurWaterModel::M()->update(['wid' => $wid], $data);
    }
}