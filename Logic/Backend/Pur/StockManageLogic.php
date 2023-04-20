<?php
namespace App\Module\Sale\Logic\Backend\Pur;

use App\Exception\AppException;
use App\Model\Crm\CrmOfferModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Prd\PrdWaterModel;
use App\Model\Pur\PurCategoryModel;
use App\Model\Pur\PurLevelModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurUserModel;
use App\Model\Pur\PurWaterModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Model\Stc\StcBorrowGoodsModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Pub\Data\SysConfData;
use App\Module\Pub\Data\SysDictData;
use App\Module\Sale\Data\PurDictData;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

/**
 * 电商管理 - 库存管理
 * Class StockManageLogic
 * @package App\Module\Sale\Logic\Backend\Pur
 */
class StockManageLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 获取分页数据
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //获取查询条件
        $where = $this->getPagerWhere($query);

        //排序处理
        $order = $this->pagerOrd($query['ord']);

        //获取列表数据
        $cols = 'A.inway,A.pid,A.offer,A.bcode,A.imei,A.plat,A.optype,A.ptype,A.bid,A.mid,A.level,A.mdram,A.mdcolor,
        A.mdofsale,A.mdnet,A.mdwarr,A.prdstat,A.stcstat,A.stcwhs,A.supcost,A.pcost,A.prdcost,A.rectime4,A.rectime7,
        A.saletime,A.imgtime,B.gtime1,B.merchant,B.aacc,B.trantime,B.transtat,B.okey,B.dkey,B.cid,B.plevel,B.rmk1';
        $list = PrdProductModel::M()->leftJoin(PurOdrGoodsModel::M(), ['bcode' => 'B.bcode'])->getList($where, $cols, $order, $size, $idx);
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

        //获取机型字典
        $mids = ArrayHelper::map($list, 'mid');
        $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mid,mname');

        //获取机型类目选项字典
        $optCols = ['mdofsale', 'mdnet', 'mdcolor', 'mdram'];
        $optOids = ArrayHelper::maps([$list, $list, $list, $list], $optCols);
        $optionsDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $optOids]], 'oname');

        //获取采购人信息
        $purUsers = ArrayHelper::map($list, 'aacc');
        $purUserDict = PurUserModel::M()->getDict('acc', ['acc' => $purUsers], 'rname');

        //获取销售金额,销售渠道,销售方式以及场次
        $goodsPid = ArrayHelper::map($list, 'pid');
        $saleInfoDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $goodsPid], 'salestat' => 2], 'saleamt,salechn,saleway,saletime');
        $prdBidSaleDict = PrdBidSalesModel::M()->getDict('pid', ['pid' => ['in' => $goodsPid]], 'rid', ['etime' => -1]);

        //是否外借
        $borrowsDict = StcBorrowGoodsModel::M()->getDict('pid', ['pid' => ['in' => $goodsPid], 'rstat' => 1], 'pid');

        //获取场次名称
        $rids = ArrayHelper::map($prdBidSaleDict, 'rid', -1);
        $prdBidRound = PrdBidRoundModel::M()->getDict('rid', ['rid' => ['in' => $rids]], 'rname');

        //组装数据
        foreach ($prdBidSaleDict as $key => $value)
        {
            $prdBidSaleDict[$key]['rname'] = $prdBidRound[$value['rid']]['rname'] ?? '-';
        }

        //获取供应商字典
        $offers = ArrayHelper::map($list, 'offer');
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offers]], 'oname');

        // 获取分类字典
        $cids = ArrayHelper::map($list, 'cid');
        $cidDict = PurCategoryModel::M()->getDict('cid', ['cid' => ['in' => $cids]], 'cname');

        // 获取备注
        $wbcodes = ArrayHelper::map($list, 'bcode');
        $rmkDict = PurWaterModel::M()->getDicts('bcode', ['bcode' => ['in' => $wbcodes], 'wstat' => 1], 'rmk', ['wtime' => -1]);

        // 获取电商等级字典
        $plevels = ArrayHelper::map($list, 'plevel');
        $plevelDict = PurLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $plevels]], 'lname');

        //商品品类
        $ptypeData = [
            1 => '手机',
            2 => '平板',
            3 => '电脑',
        ];

        //转库类型
        $tranType = [
            1 => '转出',
            2 => '转入',
        ];
        foreach ($list as $key => $value)
        {
            $tranStat = 1;//是否可转库
            $reason = ''; //不可转库原因
            if ($value['prdstat'] != 1 || !in_array($value['stcstat'], [11, 12, 13, 21, 33, 34, 35]))
            {
                $tranStat = 0;
                $reason = '商品不在库';
            }
            if (in_array($value['stcstat'], [14, 15]))
            {
                $tranStat = 0;
                $reason = '商品处于上架或报价中';
            }
            if (isset($borrowsDict[$value['pid']]))
            {
                $tranStat = 0;
                $reason = '商品处于外借中';
            }

            //非自有供应商不可转库
            $offers = SysConfData::D()->get('self_offer');
            if (in_array($value['inway'], [2, 21]) && !in_array($value['offer'], $offers))
            {
                $tranStat = 0;
                $reason = '商品来源为非自有供应商';
            }

            $list[$key]['plat'] = SaleDictData::SOURCE_PLAT[$value['plat']] ?? '-';
            if ($value['plat'] == 18)
            {
                $offerName = $offerDict[$value['offer']]['oname'] ?? '';
                $list[$key]['plat'] .= '-' . $offerName;
            }

            if ($value['ptype'] == 0)
            {
                $value['ptype'] = $value['optype'];
            }
            $list[$key]['typeName'] = $ptypeData[$value['ptype']] ?? '-';
            $list[$key]['bname'] = $bidDict[$value['bid']]['bname'] ?? '-';
            $list[$key]['mname'] = $midDict[$value['mid']]['mname'] ?? '-';
            $list[$key]['mdram'] = $optionsDict[$value['mdram']]['oname'] ?? '-';
            $list[$key]['lname'] = $levelDict[$value['level']]['lname'] ?? '-';
            $list[$key]['rname'] = $prdBidSaleDict[$value['pid']]['rname'] ?? '-';
            $list[$key]['mdcolor'] = $optionsDict[$value['mdcolor']]['oname'] ?? '-';
            $list[$key]['mdofsale'] = $optionsDict[$value['mdofsale']]['oname'] ?? '-';
            $list[$key]['mdnet'] = $optionsDict[$value['mdnet']]['oname'] ?? '-';
            $list[$key]['stcstat'] = SaleDictData::PRD_STORAGE_STAT[$value['stcstat']] ?? '-';
            $list[$key]['offer'] = $offerDict[$value['offer']]['oname'] ?? '-';
            //在库天数 (最小单位 0.5)
            $intime = '-';
            if ($value['prdstat'] == 1 && $value['rectime4'] > 0)
            {
                $intime = (time() - $value['rectime4']) / 86400;
                $intDays = intval($intime);
                if ($intime > ($intDays + 0.5))
                {
                    $intime = $intDays + 0.5;
                }
                else
                {
                    $intime = $intDays;
                }
            }
            $list[$key]['intime'] = $intime;
            $list[$key]['stcwhs'] = SysDictData::WHOUSE[$value['stcwhs']] ?? '-';

            //供应商回收成本展示，部分prdcost=0
            if ($value['plat'] == 18 && $value['prdcost'] == 0)
            {
                $value['prdcost'] = $value['supcost'];
            }

            $list[$key]['recoveryPrice'] = $value['prdcost'];
            $list[$key]['purPrice'] = $value['supcost'];
            $list[$key]['purName'] = $purUserDict[$value['aacc']]['rname'] ?? '-';

            $list[$key]['avgPrice3'] = '-';
            $list[$key]['avgPrice7'] = '-';
            $list[$key]['avgPrice14'] = '-';
            $list[$key]['avgPrice30'] = '-';

            $list[$key]['rectime4'] = DateHelper::toString($value['rectime4']);
            $list[$key]['rectime7'] = DateHelper::toString($value['rectime7']);
            $list[$key]['tranType'] = $tranType[$value['transtat']] ?? '-';
            $list[$key]['trantime'] = DateHelper::toString($value['trantime'] ?? 0);
            $list[$key]['imgtime'] = DateHelper::toString($value['imgtime'] ?? 0);
            $list[$key]['saletime'] = DateHelper::toString($value['saletime']);
            $list[$key]['saleamt'] = $saleInfoDict[$value['pid']]['saleamt'] ?? '-';
            $salechn = $saleInfoDict[$value['pid']]['salechn'] ?? '-';
            $saleway = $saleInfoDict[$value['pid']]['saleway'] ?? '-';
            $list[$key]['salechn'] = SaleDictData::SOLD_PLAT[$salechn] ?? '-';
            $list[$key]['saleway'] = SaleDictData::SOLD_METHOD[$saleway] ?? '-';
            $list[$key]['lname'] = $levelDict[$value['level']]['lname'] ?? '-';

            $list[$key]['cname'] = $cidDict[$value['cid']]['cname'] ?? '-';
            $list[$key]['rmk'] = empty($rmkDict[$value['bcode']][0]['rmk']) ? '-' : $rmkDict[$value['bcode']][0]['rmk'];
            $list[$key]['plevel'] = $value['plevel'] ?: '';
            $list[$key]['pname'] = $plevelDict[$value['plevel']]['lname'] ?? '-';
            $list[$key]['rmk1'] = empty($value['rmk1']) ? '-' : $value['rmk1'];

            if ($value['gtime1'] > 0)
            {
                //采购商品
                $list[$key]['recoveryPrice'] = '-';
            }
            else
            {
                //回收商品
                $list[$key]['purPrice'] = '-';
                $list[$key]['rectime7'] = '-';
            }

            //b2c电商留机专场商品默认全部可以勾选
            if ($query['mtype'] == 2 && $list[$key]['rname'] == 'b2c电商留机专场')
            {
                $tranStat = 1;
            }

            //是否显示转库操作按钮
            $list[$key]['tranStat'] = $tranStat;
            $list[$key]['reason'] = $reason;
        }

        //返回
        return $list;
    }

    /**
     * 获取数据总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //获取查询条件
        $where = $this->getPagerWhere($query);

        //获取总数
        $count = PrdProductModel::M()->leftJoin(PurOdrGoodsModel::M(), ['bcode' => 'bcode'])->getCount($where);

        //返回
        return $count;
    }

    /**
     * 列表电商分类
     * @return array
     *
     */
    public function category()
    {
        // 获取数据
        $list = PurCategoryModel::M()->getList(['cstat' => 1], 'cid,cname', ['ctime' => -1]);
        array_unshift($list, ['cid' => '']);

        //获取各分类总数
        $where = [
            'A.inway' => 51,
            'B.gstat' => 4,
            'A.stcstat' => ['in' => [11, 13, 14]],
            'A.zdstat' => 0,
            '$group' => 'B.cid'
        ];
        $countDict = PrdProductModel::M()->leftJoin(PurOdrGoodsModel::M(), ['bcode' => 'B.bcode'])->getDict('cid', $where, 'B.cid,count(1) as count');

        // 组装数据
        foreach ($list as $key => $val)
        {
            $list[$key]['counts'] = $countDict[$val['cid']]['count'] ?? 0;
        }

        // 返回
        return $list;
    }

    /**
     * 导出数据
     * @param array $query
     * @return array
     * @throws
     */
    public function export(array $query, int $size, int $idx)
    {
        //获取列表数据
        $list = $this->getPager($query, $size, $idx);

        //根据查询条件缓存总数据条数
        $countKey = 'sale_pur_' . md5(json_encode($query));
        $count = $this->redis->get($countKey);
        if (!$count)
        {
            $count = $this->getCount($query);
            $this->redis->set($countKey, $count, 60);
        }
        if ($count > 30000)
        {
            throw new AppException('最大支持30000条，请选择筛选条件缩小范围');
        }

        if ($query['cid'])
        {
            //标题头
            $head = [
                'bcode' => '库存编号',
                'bname' => '品牌',
                'rmk' => '备注',
                'mname' => '机型',
                'mdram' => '内存',
                'lname' => '级别',
                'pname' => '电商等级',
                'rmk1' => '电池备注',
                'mdcolor' => '颜色',
                'mdofsale' => '销售地',
                'mdnet' => '网络制式',
                'stcstat' => '商品状态',
                'cname' => '电商分类',
                'intime' => '在库天数',
                'stcwhs' => '仓库',
                'purPrice' => '采购价',
                'purName' => '采购人',
                'recoveryPrice' => '回收价',
                'plat' => '来源',
                'typeName' => '品类',
                'rectime7' => '采购时间',
                'rectime4' => '入库时间',
                'saletime' => '销售时间',
                'saleamt' => '销售金额',
                'salechn' => '销售渠道',
                'saleway' => '销售方式',
            ];
        }
        else
        {
            //标题头
            $head = [
                'bcode' => '库存编号',
                'bname' => '品牌',
                'rmk' => '备注',
                'mname' => '机型',
                'mdram' => '内存',
                'lname' => '级别',
                'pname' => '电商等级',
                'rmk1' => '电池备注',
                'mdcolor' => '颜色',
                'mdofsale' => '销售地',
                'mdnet' => '网络制式',
                'stcstat' => '商品状态',
                'intime' => '在库天数',
                'stcwhs' => '仓库',
                'purPrice' => '采购价',
                'purName' => '采购人',
                'recoveryPrice' => '回收价',
                'plat' => '来源',
                'typeName' => '品类',
                'rectime7' => '采购时间',
                'rectime4' => '入库时间',
                'saletime' => '销售时间',
                'saleamt' => '销售金额',
                'salechn' => '销售渠道',
                'saleway' => '销售方式',
            ];
        }

        //返回
        return [
            'head' => $head,
            'list' => $list,
            'pages' => ceil($count / $size),
        ];
    }

    /**
     * 转库商品
     * @param string $bcode
     * @param string $acc
     * @param int $tranType 转库类型 1 purToB 2 BtoPur
     * @throws
     */
    public function tranPrd(string $bcode, string $acc, int $tranType)
    {
        $prd = PrdProductModel::M()->getRow(['bcode' => $bcode], 'pid,inway,recstat,prdstat,stcstat');
        if (empty($prd))
        {
            throw new AppException('找不到商品数据');
        }
        $pid = $prd['pid'];
        $time = time();

        if ($prd['prdstat'] != 1 || !in_array($prd['stcstat'], [11, 12, 13, 21, 33, 34, 35]))
        {
            throw new AppException('只有在库商品才能转库');
        }
        if (in_array($prd['stcstat'], [14, 15]))
        {
            throw new AppException('商品上架中，不能转库');
        }
        $supply = PrdSupplyModel::M()->getRow(['pid' => $pid], '*', ['atime' => -1]);
        if (empty($supply))
        {
            throw new AppException('缺少待销售数据');
        }
        $borrow = StcBorrowGoodsModel::M()->exist(['pid' => $pid, 'rstat' => 1]);
        if ($borrow)
        {
            throw new AppException('外借中，不可转库');
        }
        if ($tranType == 1)
        {
            //电商库存转B端销售
            if (!PurOdrGoodsModel::M()->exist(['bcode' => $bcode]))
            {
                throw new AppException('电商库存不存在此商品，无法转库-01');
            }
            if ($prd['inway'] != PurDictData::PUR_INWAY1)
            {
                throw new AppException('电商库存不存在此商品，无法转库-02');
            }
            PrdSupplyModel::M()->updateById($supply['sid'], ['salestat' => 4]);

            //新增prd_supply
            $newSupply = $supply;
            $newSupply['sid'] = IdHelper::generate();
            $newSupply['inway'] = PurDictData::PUR_INWAY2;
            if (PrdSupplyModel::M()->exist(['pid' => $pid, 'inway' => PurDictData::PUR_INWAY2]) == false)
            {
                PrdSupplyModel::M()->insert($newSupply);
            }
            else
            {
                PrdSupplyModel::M()->update(['pid' => $pid, 'inway' => PurDictData::PUR_INWAY2], ['salestat' => 1]);
            }

            //更新为转自有商品
            PrdProductModel::M()->updateById($pid, ['inway' => PurDictData::PUR_INWAY2]);

            //标记转库
            PurOdrGoodsModel::M()->update(['bcode' => $bcode], ['transtat' => 1, 'trantime' => $time]);
        }
        else
        {
            //其他回收渠道商品转电商库存
            PrdSupplyModel::M()->updateById($supply['sid'], ['salestat' => 4]);

            //新增prd_supply
            $newSupply = $supply;
            $newSupply['sid'] = IdHelper::generate();
            $newSupply['inway'] = PurDictData::PUR_INWAY1;
            PrdSupplyModel::M()->update(['pid' => $pid], ['salestat' => 4]);
            if (PrdSupplyModel::M()->exist(['pid' => $pid, 'inway' => PurDictData::PUR_INWAY1]) == false)
            {
                PrdSupplyModel::M()->insert($newSupply);
            }
            else
            {
                PrdSupplyModel::M()->update(['pid' => $pid, 'inway' => PurDictData::PUR_INWAY1], ['salestat' => 1]);
            }

            //更新为电商商品
            PrdProductModel::M()->updateById($pid, ['inway' => PurDictData::PUR_INWAY1]);

            //标记转库
            if (PurOdrGoodsModel::M()->exist(['bcode' => $bcode]))
            {
                PurOdrGoodsModel::M()->update(['bcode' => $bcode], ['transtat' => 2, 'trantime' => $time]);
            }
            else
            {
                PurOdrGoodsModel::M()->insert([
                    'gid' => IdHelper::generate(),
                    'bcode' => $bcode,
                    'prdstat' => $prd['prdstat'],
                    'stcstat' => $prd['stcstat'],
                    'gstat' => 4,
                    'transtat' => 2,
                    'trantime' => $time,
                ]);
            }
        }

        //添加流水
        $rmk = '电商库存转B端销售';
        if ($tranType == 2)
        {
            $rmk = '转电商库存';
        }
        $data = [
            'wid' => IdHelper::generate(),
            'tid' => 111,//转库
            'oid' => '',
            'pid' => $pid,
            'rmk' => $rmk,
            'acc' => $acc,
            'atime' => $time
        ];
        PrdWaterModel::M()->insert($data);
    }

    /**
     * 批量将B端销售转电商库存
     * @param string $bcodes
     * @param string $acc
     * @param string $rname
     * @return string
     * @throws
     */
    public function tranAllToPur(string $bcodes, string $acc, string $rname)
    {
        $errors = [];
        $bcodes = explode(',', $bcodes);
        $prd = PrdProductModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]], 'pid,bcode,inway,recstat,prdstat,stcstat');
        foreach ($bcodes as $key => $value)
        {
            //检查数据
            if (empty($prd[$value]))
            {
                $errors[] = $value . '商品不存在';

                //删除有误商品的bcode
                unset($bcodes[$key]);
            }
        }

        $prdPids = ArrayHelper::map($prd, 'pid', -1);

        //判断是否是专场数据
        if ($rname != 'b2c电商留机专场')
        {
            $borrowsDict = StcBorrowGoodsModel::M()->getDict('pid', ['pid' => ['in' => $prdPids], 'rstat' => 1], 'pid');
            foreach ($prd as $key => $value)
            {
                $checkStat = true;//是否校验通过
                if ($value['prdstat'] != 1 || !in_array($value['stcstat'], [11, 12, 13, 21, 33, 34, 35]))
                {
                    $errors[] = $value['bcode'] . '商品不在库';
                    $checkStat = false;
                }
                if (isset($borrowsDict[$value['pid']]))
                {
                    $errors[] = $value['bcode'] . '外借中';
                    $checkStat = false;
                }
                if ($checkStat == false)
                {
                    //删除有误商品的bcode
                    $key = array_search($value['bcode'], $bcodes);
                    array_splice($bcodes, $key, 1);
                }
            }
        }
        else
        {
            //获取场次专场商品数据
            $sales = PrdBidSalesModel::M()->getList(['pid' => ['in' => $prdPids]], 'sid,tid,rid,pid,stat,plat');
            if ($sales == false)
            {
                throw new AppException('竞拍商品数据不存在', AppException::NO_DATA);
            }

            //提取数据
            $salePids = ArrayHelper::map($sales, 'pid');
            $sid = ArrayHelper::map($sales, 'sid');

            //获取商品数据
            $waterData = [];
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $salePids]], 'oid');
            foreach ($sales as $key => $item)
            {
                if ($item['stat'] > 12)
                {
                    throw new AppException('商品在竞拍中，不能操作下架', AppException::OUT_OF_OPERATE);
                }

                //操作流水
                $waterData[] = [
                    'wid' => IdHelper::generate(),
                    'tid' => 917,
                    'oid' => $prdDict[$item['pid']]['oid'] ?? '',
                    'pid' => $item['pid'],
                    'rmk' => '转电商库存，竞拍下架',
                    'acc' => $acc,
                    'atime' => time()
                ];
            }
        }

        $time = time();
        $supplyDict = PrdSupplyModel::M()->getDict('bcode', ['pid' => ['in' => $prdPids], 'inway' => 51], '*', ['atime' => -1]);
        $pids = ArrayHelper::map($supplyDict, 'pid', -1);
        $arr3 = array_diff($prdPids, $pids);
        $pid = array_unique($arr3);
        $newSupply = [];
        if (count($pid) > 0)
        {
            $supply = PrdSupplyModel::M()->getList(['pid' => ['in' => $pid], 'salestat' => 1], '*', ['atime' => -1]);
            $prdSupply = ArrayHelper::dict($supply, 'pid');
            foreach ($pid as $value)
            {
                //检查数据
                if (empty($prdSupply[$value]))
                {
                    $bcode = PrdProductModel::M()->getOne(['pid' => $value], 'bcode');
                    $errors[] = $bcode . '缺少待销售数据';

                    //删除有误商品的bcode
                    $key = array_search($bcode, $bcodes);
                    array_splice($bcodes, $key, 1);
                }
            }
            $newSupply = $supply;
            foreach ($newSupply as $key => $value)
            {
                $newSupply[$key]['sid'] = IdHelper::generate();
                $newSupply[$key]['inway'] = PurDictData::PUR_INWAY1;
                $newSupply[$key]['atime'] = time();
            }
        }

        //判断商品还存在不
        if (count($bcodes) == 0)
        {
            return implode(',', $errors);
        }

        //再次使用正确的bcode去查询商品
        $product = PrdProductModel::M()->getList(['bcode' => ['in' => $bcodes]], 'pid');
        $prdPids = ArrayHelper::map($product, 'pid');

        //获取所有的supply表的sid
        $prdSupply = PrdSupplyModel::M()->getList(['pid' => ['in' => $prdPids]], 'sid');
        $sids = ArrayHelper::map($prdSupply, 'sid');

        //添加流水
        $rmk = '批量转电商库存';
        foreach ($product as $value)
        {
            $data[] = [
                'wid' => IdHelper::generate(),
                'tid' => 111,//转库
                'oid' => '',
                'pid' => $value['pid'],
                'rmk' => $rmk,
                'acc' => $acc,
                'atime' => $time
            ];
        }

        //标记转库
        $purBcodes = $bcodes;
        $purOdrGoods = PurOdrGoodsModel::M()->getList(['bcode' => ['in' => $purBcodes]], '*');
        $purBcode = ArrayHelper::map($purOdrGoods, 'bcode');

        //剔除存在电商商品数据的bcode
        foreach ($purBcode as $value)
        {
            $key = array_search($value, $purBcodes);
            array_splice($purBcodes, $key, 1);
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            if ($rname == 'b2c电商留机专场')
            {
                //删除数据
                PrdBidSalesModel::M()->delete(['sid' => ['in' => $sid]]);
                PrdBidFavoriteModel::M()->delete(['sid' => ['in' => $sid]]);

                //更新场次上架商品数
                $rid = $sales[0]['rid'];
                $count = PrdBidSalesModel::M()->getCount(['rid' => $rid]);
                PrdBidRoundModel::M()->updateById($rid, ['upshelfs' => $count]);

                //更新商品、库存数据
                PrdProductModel::M()->update(['pid' => ['in' => $salePids]], ['stcstat' => 11, 'stctime' => time()]);
                StcStorageModel::M()->update(['pid' => ['in' => $salePids], 'stat' => 1], ['prdstat' => 11]);

                //添加流水
                PrdWaterModel::M()->inserts($waterData);
            }

            //判断存不存在转过电商的数据，不存在则插入
            $purData = [];
            if ($purOdrGoods)
            {
                PurOdrGoodsModel::M()->update(['bcode' => ['in' => $purBcode]], ['transtat' => 2, 'trantime' => $time]);
            }
            if (count($purBcodes) > 0)
            {
                foreach ($purBcodes as $key => $value)
                {
                    $purData[] = [
                        'gid' => IdHelper::generate(),
                        'bcode' => $value,
                        'prdstat' => $prd[$value]['prdstat'],
                        'stcstat' => $prd[$value]['stcstat'],
                        'gstat' => 4,
                        'transtat' => 2,
                        'trantime' => $time,
                    ];
                }
            }

            //插入电商商品数据
            if ($purData)
            {
                PurOdrGoodsModel::M()->inserts($purData);
            }

            //销售状态转自有
            PrdSupplyModel::M()->update(['sid' => ['in' => $sids]], ['salestat' => 4]);

            //将存在的电商数据变为待销售
            PrdSupplyModel::M()->update(['sid' => ['in' => $sids], 'inway' => 51], ['salestat' => 1]);

            //插入电商商品数据
            if ($newSupply)
            {
                PrdSupplyModel::M()->inserts($newSupply, true);
            }

            // 更新为转自有商品
            PrdProductModel::M()->update(['bcode' => ['in' => $bcodes]], ['inway' => PurDictData::PUR_INWAY1]);

            //插入流水
            PrdWaterModel::M()->inserts($data);

            //提交事务
            Db::commit();
        }
        catch (\Exception $exception)
        {
            //回滚事务
            Db::rollback();

            throw new AppException($exception->getMessage());
        }

        if (count($errors) > 0)
        {
            //返回
            return implode(',', $errors);
        }

        //返回
        return 'ok';
    }

    /**
     * 批量将电商商品转B端销售
     * @param string $bcodes
     * @param string $acc
     * @return string
     * @throws
     */
    public function tranAllPrd(string $bcodes, string $acc)
    {
        $errors = [];
        $bcodes = explode(',', $bcodes);
        $prd = PrdProductModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]], 'pid,bcode,inway,recstat,prdstat,stcstat');
        foreach ($bcodes as $key => $value)
        {
            //检查数据
            if (empty($prd[$value]))
            {
                $errors[] = $value . '商品不存在';

                //删除有误商品的bcode
                unset($bcodes[$key]);
            }
        }

        //是否外借
        $prdPids = ArrayHelper::map($prd, 'pid');
        $borrowsDict = StcBorrowGoodsModel::M()->getDict('pid', ['pid' => ['in' => $prdPids], 'rstat' => 1], 'pid');
        foreach ($prd as $key => $value)
        {
            $checkStat = true;//是否校验通过
            if ($value['prdstat'] != 1 || !in_array($value['stcstat'], [11, 12, 13, 21, 33, 34, 35]))
            {
                $errors[] = $value['bcode'] . '商品不在库';
                $checkStat = false;
            }
            if (in_array($value['stcstat'], [14, 15]))
            {
                $errors[] = $value['bcode'] . '商品上架中';
                $checkStat = false;
            }
            if ($value['inway'] != PurDictData::PUR_INWAY1)
            {
                $errors[] = $value['bcode'] . '不是电商库存';
                $checkStat = false;
            }
            if (isset($borrowsDict[$value['pid']]))
            {
                $errors[] = $value['bcode'] . '外借中';
                $checkStat = false;
            }
            if ($checkStat == false)
            {
                //删除有误商品的bcode
                $key = array_search($value['bcode'], $bcodes);
                array_splice($bcodes, $key, 1);
            }
        }
        $time = time();
        $supplyDict = PrdSupplyModel::M()->getDict('bcode', ['pid' => ['in' => $prdPids], 'inway' => 52], '*', ['atime' => -1]);
        $pids = ArrayHelper::map($supplyDict, 'pid', -1);
        $arr3 = array_diff($prdPids, $pids);
        $pid = array_unique($arr3);
        $newSupply = [];
        if (count($pid) > 0)
        {
            $supply = PrdSupplyModel::M()->getList(['pid' => ['in' => $pid], 'inway' => 51], '*', ['atime' => -1]);
            $prdSupply = ArrayHelper::dict($supply, 'pid');
            foreach ($pid as $value)
            {
                //检查数据
                if (empty($prdSupply[$value]))
                {
                    $bcode = PrdProductModel::M()->getOne(['pid' => $value], 'bcode');
                    $errors[] = $bcode . '缺少待销售数据';

                    //删除有误商品的bcode
                    $key = array_search($bcode, $bcodes);
                    array_splice($bcodes, $key, 1);
                }
            }
            $newSupply = $supply;
            foreach ($newSupply as $key => $value)
            {
                $newSupply[$key]['sid'] = IdHelper::generate();
                $newSupply[$key]['inway'] = PurDictData::PUR_INWAY2;
                $newSupply[$key]['atime'] = time();
            }
        }

        //查找电商库存数据
        $goods = PurOdrGoodsModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]], 'bcode');
        foreach ($bcodes as $key => $value)
        {
            //检查数据
            if (empty($goods[$value]))
            {
                $errors[] = $value . '电商库存不存在此商品，无法转库-01';
                unset($bcodes[$key]);
            }
        }

        $product = PrdProductModel::M()->getList(['bcode' => ['in' => $bcodes]], 'pid');
        $prdPid = ArrayHelper::map($product, 'pid');

        //获取所有的supply表的sid
        $prdSupply = PrdSupplyModel::M()->getList(['pid' => ['in' => $prdPid]], 'sid');
        $sids = ArrayHelper::map($prdSupply, 'sid');

        //添加流水
        $rmk = '电商库存转B端销售';
        foreach ($product as $value)
        {
            $data[] = [
                'wid' => IdHelper::generate(),
                'tid' => 111,//转库
                'oid' => '',
                'pid' => $value['pid'],
                'rmk' => $rmk,
                'acc' => $acc,
                'atime' => $time
            ];
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //更新供应商商品状态
            PrdSupplyModel::M()->update(['sid' => ['in' => $sids]], ['salestat' => 4]);

            //有电商数据则转为1
            PrdSupplyModel::M()->update(['sid' => ['in' => $sids], 'inway' => 52], ['salestat' => 1]);

            //插入供应商商品
            PrdSupplyModel::M()->inserts($newSupply);

            // 更新为转自有商品
            PrdProductModel::M()->update(['bcode' => ['in' => $bcodes]], ['inway' => PurDictData::PUR_INWAY2]);

            //标记转库
            PurOdrGoodsModel::M()->update(['bcode' => ['in' => $bcodes]], ['transtat' => 1, 'trantime' => $time]);

            //插入流水
            PrdWaterModel::M()->inserts($data);

            //提交事务
            Db::commit();

        }
        catch (\Exception $exception)
        {
            //回滚事务
            Db::rollback();

            throw new AppException($exception->getMessage());
        }

        if (count($errors) > 0)
        {
            //返回
            return implode(',', $errors);
        }

        //返回
        return 'ok';
    }

    /**
     * 批量或者单个商品设置分类
     * @param string $bcodes
     * @param string $cid
     * @throws
     */
    public function updateClassify(string $bcodes, string $cid)
    {
        $bcodes = explode(',', $bcodes);
        $time = time();

        // 删除已选分类
        if ($bcodes && $cid == '')
        {
            PurOdrGoodsModel::M()->update(['bcode' => ['in' => $bcodes]], ['cid' => $cid, 'ctime' => $time]);
        }
        else
        {
            //判断分类是否停用
            $cstat = PurCategoryModel::M()->getOneById($cid, 'cstat');
            if ($cstat == 0)
            {
                throw new AppException('该分类已删除');
            }

            PurOdrGoodsModel::M()->update(['bcode' => ['in' => $bcodes]], ['cid' => $cid, 'ctime' => $time]);
        }
    }

    /**
     * 获取查询where条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //初始化
        $where = [];

        //指定获取电商库存数据
        if ($query['mtype'] == 1)
        {
            //获取电商在库数据（可转出数据）
            $where = ['A.inway' => PurDictData::PUR_INWAY1];
            $where['B.gstat'] = 4;
        }
        if ($query['mtype'] == 2)
        {
            //获取不是电商的数据（可转入数据）
            $where = ['A.inway' => ['!=' => PurDictData::PUR_INWAY1]];
            $where['A.recstat'] = 7;
        }
        if ($query['mtype'] == 3)
        {
            //获取转库数据
            $where = ['B.transtat' => ['in' => [1, 2]]];
        }

        $queryCols = ['plat', 'ptype', 'bid'];
        foreach ($queryCols as $col)
        {
            if ($query[$col] > 0)
            {
                $where['A.' . $col] = $query[$col];
            }
        }
        if (count($query['mid']) > 0)
        {
            $where['A.mid'] = ['in' => $query['mid']];
        }
        if (count($query['level']) > 0)
        {
            $where['A.level'] = ['in' => $query['level']];
        }

        //库存编码搜索
        if ($query['bcode'] != '')
        {
            $where['A.bcode'] = $query['bcode'];
        }

        if (!empty($query['imei']))
        {
            $where['A.imei'] = $query['imei'];
        }

        if ($query['pacc'] != '')
        {
            $where['B.aacc'] = $query['pacc'];
        }
        if ($query['merchant'] != '')
        {
            $where['B.merchant'] = $query['merchant'];
        }

        if (count($query['time']) == 2)
        {
            $stime = strtotime($query['time'][0]);
            $etime = strtotime($query['time'][1]) + 86399;
            $between = ['between' => [$stime, $etime]];
            if ($query['ttype'] == 1)
            {
                //入库时间
                $where['A.rectime4'] = $between;
            }
            if ($query['ttype'] == 2)
            {
                //采购时间
                $where['A.rectime7'] = $between;
            }
            if ($query['ttype'] == 3)
            {
                //销售时间
                $where['A.saletime'] = $between;
            }
        }

        //供应商搜索
        if ($query['offer'] != '')
        {
            $where['A.offer'] = $query['offer'];
        }

        //场次搜索
        if ($query['round'] != '')
        {
            $products = PrdBidSalesModel::M()->getList(['rid' => $query['round']], 'pid');
            $pids = ArrayHelper::map($products, 'pid');
            if (empty($products))
            {
                $where['A.pid'] = -1;
            }
            else
            {
                $where['A.pid'] = ['in' => $pids];
            }
        }

        //所在仓库
        if ($query['whs'] > 0)
        {
            $where['A.stcwhs'] = $query['whs'];
        }

        //商品状态
        if (count($query['stcstat']) > 0)
        {
            $where['A.stcstat'] = ['in' => $query['stcstat']];
        }

        //内存筛选 - (内存全部选择时，不处理)
        $queryMemory = $query['mdram'] ?? [];
        if (count($queryMemory) > 0 && count($queryMemory) != 9)
        {
            $memWhere = [
                'plat' => 0,
                'cid' => ['in' => [17000, 221200000, 221800000]],
            ];
            $memList = QtoOptionsModel::M()->getList($memWhere, 'oid,oname');
            $memOids = [];
            foreach ($memList as $value)
            {
                foreach ($queryMemory as $value2)
                {
                    $memStr = $value2 . 'G';//搜索32G时，32G / 8G+32G都要搜索出来
                    if ($value2 == 1024 || $value2 == 2048)
                    {
                        $memStr = ceil($value2 / 1024) . 'T';
                    }
                    if ($value['oname'] == $memStr || strstr($value['oname'], '+' . $memStr))
                    {
                        $memOids[] = $value['oid'];
                    }
                }
            }
            if (empty($memOids))
            {
                $where['A.mdram'] = -1;
            }
            else
            {
                $where['A.mdram'] = ['in' => $memOids];
            }
        }

        //排除增单数据
        $where['A.zdstat'] = 0;

        if ($query['atype'] == '')
        {
            if ($query['mtype'] == 1)
            {
                if ($query['cid'])
                {
                    $where['B.cid'] = $query['cid'];
                }
                else
                {
                    $where['B.cid'] = '';
                }
            }
        }

        if ($query['mtype'] == 1)
        {
            if (!empty($query['plevel']))
            {
                $where['B.plevel'] = $query['plevel'];
            }
        }

        //返回
        return $where;
    }

    /**
     * 库存均价计算
     * @param int $mtype 1电商库存 2非电商库存 3转库库存
     * @param string $bcode
     * @return mixed
     */
    public function stcAvgPrc(int $mtype, string $bcode)
    {
        //时间定义
        $stcDate3 = strtotime(date('Y-m-d')) - 3 * 86400;
        $stcDate7 = strtotime(date('Y-m-d')) - 7 * 86400;
        $stcDate14 = strtotime(date('Y-m-d')) - 14 * 86400;
        $stcDate30 = strtotime(date('Y-m-d')) - 30 * 86400;
        $etime = strtotime(date('Y-m-d 23:59:59'));

        //获取商品数据
        $cols = 'mid,level,mdnet,mdram,mdcolor,mdofsale,mdwarr';
        $prdInfo = PrdProductModel::M()->getRow(['bcode' => $bcode], $cols);

        //组装查询条件
        $stcwhere = [
            'A.mid' => $prdInfo['mid'],
            'A.level' => $prdInfo['level'],
            'A.mdnet' => $prdInfo['mdnet'],
            'A.mdram' => $prdInfo['mdram'],
            'A.mdcolor' => $prdInfo['mdcolor'],
            'A.mdofsale' => $prdInfo['mdofsale'],
            'A.mdwarr' => $prdInfo['mdwarr'],
            'A.rectime4' => ['between' => [$stcDate3, $etime]]
        ];
        if ($mtype == 1)
        {
            $stcwhere['A.inway'] = 51;
        }
        elseif ($mtype == 2)
        {
            $stcwhere['A.inway'] = ['!=' => 51];
        }
        else
        {
            $stcwhere['B.transtat'] = ['in' => [1, 2]];
        }

        //初始默认值
        $avgPrice3 = $avgPrice7 = $avgPrice14 = $avgPrice30 = '-';

        //获取指定条件的数据
        $getPrdCount = function ($stcwhere) {
            $rKey = 'pur_stcavg_' . md5(json_encode($stcwhere));
            $cache = $this->redis->get($rKey);
            if ($cache)
            {
                $prdCount = json_decode($cache, true);
            }
            else
            {
                $stcCols = 'sum(prdcost) as prdcost, count(1) as num';
                $prdCount = PrdProductModel::M()->leftJoin(PurOdrGoodsModel::M(), ['bcode' => 'bcode'])->getRow($stcwhere, $stcCols);
                $this->redis->set($rKey, json_encode($prdCount), 300);
            }

            //返回统计结果
            return $prdCount;
        };

        //3天库存均价
        $prdCount = $getPrdCount($stcwhere);
        if ($prdCount['num'] > 0)
        {
            $avgPrice3 = round($prdCount['prdcost'] / $prdCount['num'], 2);
        }

        //7天库存均价
        $stcwhere['A.rectime4']['between'][0] = $stcDate7;
        $prdCount = $getPrdCount($stcwhere);
        if ($prdCount['num'] > 0)
        {
            $avgPrice7 = round($prdCount['prdcost'] / $prdCount['num'], 2);
        }

        //14天库存均价
        $stcwhere['A.rectime4']['between'][0] = $stcDate14;
        $prdCount = $getPrdCount($stcwhere);
        if ($prdCount['num'] > 0)
        {
            $avgPrice14 = round($prdCount['prdcost'] / $prdCount['num'], 2);
        }

        //30天库存均价
        $stcwhere['A.rectime4']['between'][0] = $stcDate30;
        $prdCount = $getPrdCount($stcwhere);
        if ($prdCount['num'] > 0)
        {
            $avgPrice30 = round($prdCount['prdcost'] / $prdCount['num'], 2);
        }

        //返回
        return [
            'avgPrice3' => $avgPrice3,
            'avgPrice7' => $avgPrice7,
            'avgPrice14' => $avgPrice14,
            'avgPrice30' => $avgPrice30,
        ];
    }

    /**
     * 排序处理
     * @param string $ord
     * @return array
     */
    private function pagerOrd(string $ord)
    {
        //排序分类
        switch ($ord)
        {
            //机型排序
            case 'mname-ascending':
                $order = ['A.mid' => 1];
                break;
            case 'mname-descending':
                $order = ['A.mid' => -1];
                break;
            //级别排序
            case 'lname-ascending':
                $order = ['A.level' => 1];
                break;
            case 'lname-descending':
                $order = ['A.level' => -1];
                break;
            //在库时长排序
            case 'intime-ascending':
                $order = ['A.prdstat' => 1, 'A.rectime4' => -1];
                break;
            case 'intime-descending':
                $order = ['A.prdstat' => 1, 'A.rectime4' => 1];
                break;
            //回收价排序
            case 'recoveryPrice-ascending':
                $order = ['A.pcost' => 1];
                break;
            case 'recoveryPrice-descending':
                $order = ['A.pcost' => -1];
                break;
            //采购价排序
            case 'purPrice-ascending':
                $order = ['A.supcost' => 1];
                break;
            case 'purPrice-descending':
                $order = ['A.supcost' => -1];
                break;
            default:
                //默认排序
                $order = ['B.ctime' => -1, 'A.prdstat' => 1, 'A.rectime4' => 1];
                break;
        }

        //返回
        return $order;
    }

    /**
     * 保存电商等级
     * @param string $bcode
     * @param int $plevel
     * @return mixed
     * @throws
     */
    public function saveLevel(string $bcode, int $plevel)
    {
        $goodsData = PurOdrGoodsModel::M()->getRow(['bcode' => $bcode, 'transtat' => ['in' => [0, 2]]]);
        if (!$goodsData)
        {
            throw new AppException('该条数据不存在');
        }

        // 更新数据
        PurOdrGoodsModel::M()->update(['bcode' => $bcode], ['plevel' => $plevel]);
    }

    /**
     * 保存电池备注
     * @param string $bcode
     * @param string $rmk
     * @return mixed
     * @throws
     */
    public function saveRmk(string $bcode, string $rmk)
    {
        $goodsData = PurOdrGoodsModel::M()->getRow(['bcode' => $bcode, 'transtat' => ['in' => [0, 2]]]);
        if (!$goodsData)
        {
            throw new AppException('该条数据不存在');
        }

        $data = [
            'rmk1' => $rmk,
            'ltime' => time()
        ];

        // 更新数据
        PurOdrGoodsModel::M()->update(['bcode' => $bcode], $data);
    }
}