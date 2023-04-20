<?php
namespace App\Module\Sale\Logic\Backend\Bid;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Model\Crm\CrmOfferModel;
use App\Model\Mqc\MqcBatchModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Prd\PrdWaterModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Sale\Data\XinxinDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;

/**
 * 上架商品
 * Class BidProductLogic
 * @package App\Module\Sale\Logic\Backend\Bid
 */
class BidProductLogic extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 待上架商品翻页数据
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //数据条件
        $where = $this->getPagerWhere($query);

        //获取数据
        $cols = 'pid,inway,plat,offer,bid,mid,level,pname,palias,bcode,stcstat,upshelfs,nobids,cost31';
        $list = PrdProductModel::M()->getList($where, $cols, ['stctime' => -1], $size, $idx);
        if ($list)
        {
            //提取Id
            $mids = ArrayHelper::map($list, 'mid');
            $bids = ArrayHelper::map($list, 'bid');
            $offers = ArrayHelper::map($list, 'offer');

            //获取品牌机型级别数据
            $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');
            $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');
            $lkeyDict = QtoLevelModel::M()->getDict('lkey', [], 'lname');

            //获取供应商数据
            $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offers]], 'oname');

            //补充数据
            foreach ($list as $key => $item)
            {
                $list[$key]['bname'] = $bidDict[$item['bid']]['bname'] ?? '-';
                $list[$key]['mname'] = $midDict[$item['mid']]['mname'] ?? '-';
                $list[$key]['lname'] = $lkeyDict[$item['level']]['lname'] ?? '-';
                $list[$key]['oname'] = $offerDict[$item['offer']]['oname'] ?? '-';
                $list[$key]['upshelfs'] = $item['upshelfs'] > 0 ? '是' : '否';

                //标签
                //1-流标，3-供应商，5-不良品
                $list[$key]['flag'] = [];
                if ($item['nobids'] > 0 || in_array($item['stcstat'], [33, 34]))
                {
                    $list[$key]['flag'][] = 1;
                }
                if (in_array($item['inway'], [2, 21]) && $item['plat'] != 17)
                {
                    $list[$key]['flag'][] = 3;
                }
                if ($item['cost31'] > 0)
                {
                    $list[$key]['flag'][] = 5;
                }
            }
        }
        ArrayHelper::fillDefaultValue($list, [0, '0.00', '']);

        //返回
        return $list;
    }

    /**
     * 待上架商品总数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //获取数据条件
        $where = $this->getPagerWhere($query);

        //返回
        return PrdProductModel::M()->getCount($where);
    }

    /**
     * 数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //默认条件
        $where = [
            'stcwhs' => $query['whsPermission'],
            'stcstat' => ['in' => [11, 33, 34, 35]],
            'recstat' => ['in' => [61, 7]],
            'imgstat' => 2
        ];

        //库存编码
        if ($query['bcode'])
        {
            $where['bcode'] = $query['bcode'];
        }

        //来源
        if ($query['plat'])
        {
            if ($query['plat'] == 18)
            {
                $where['inway'] = ['in' => [2, 21]];
            }
            else
            {
                $where['plat'] = $query['plat'];
            }
        }

        //供应商名称
        $oname = $query['oname'];
        if ($oname)
        {
            $offerList = CrmOfferModel::M()->getList(['oname' => ['like' => "%$oname%"]], 'oid');
            if ($offerList)
            {
                $oids = ArrayHelper::map($offerList, 'oid');
                $where['offer'] = ['in' => $oids];
            }
            else
            {
                $where['pid'] = -1;
            }
        }

        //品牌
        if ($query['bid'] > 0)
        {
            if ($query['bid'] < 10)
            {
                //品牌特殊处理，非标准化数据
                //1苹果 2华为荣耀 3小米 4OPPO 5VIVO 6其他 7平板
                $tmpBid = $query['bid'];
                $bid = 0;
                if ($tmpBid == 1) $bid = 10000;
                if ($tmpBid == 2) $bid = ['in' => [40000, 210000]];
                if ($tmpBid == 3) $bid = 50000;
                if ($tmpBid == 4) $bid = 20000;
                if ($tmpBid == 5) $bid = 80000;
                if ($tmpBid == 6) $bid = ['not in' => [10000, 40000, 210000, 50000, 20000, 80000]];
                if ($bid > 0 || is_array($bid))
                {
                    $where['ptype'] = 1;
                    $where['bid'] = $bid;
                }
                if ($tmpBid == 7)
                {
                    $where['ptype'] = 2;//平板
                }
            }
            else
            {
                $where['bid'] = $query['bid'];
            }
        }

        //机型
        if ($query['mid'])
        {
            $where['mid'] = $query['mid'];
        }

        //级别
        $where['level'] = ['<' => 40];
        if ($query['level'] > 0 && $query['level'] < 40)
        {
            $where['level'] = $query['level'];
        }

        //是否流标
        if ($query['nobids'] == 1)
        {
            $where['nobids'] = ['>' => 0];
        }
        if ($query['nobids'] == -1)
        {
            $where['nobids'] = 0;
        }

        //是否上架过
        if ($query['upshelfs'] == 1)
        {
            $where['upshelfs'] = ['>' => 0];
        }
        if ($query['upshelfs'] == -1)
        {
            $where['upshelfs'] = 0;
        }

        //电商商品不能上架
        if (empty($where['inway']))
        {
            $where['inway'] = ['!=' => 51];
        }

        //返回
        return $where;
    }

    /**
     * 上架商品
     * @param string $rid 场次id
     * @param int $type 上架类型：1-选择上架，2-搜索上架
     * @param string $pids 商品id
     * @param int $mode 竞拍模式，1-明拍，2-暗拍
     * @param array $query 查询参数
     * @param string $acc 登录用户
     * @throws
     */
    public function save(string $rid, int $type, string $pids, int $mode, array $query, string $acc)
    {
        $time = time();
        $pids = explode(',', $pids);

        //加锁，防止重复上架
        $lockKeyPre = "sale_bid_product_save_{$rid}_";
        foreach ($pids as $pid)
        {
            if ($this->redis->setnx($lockKeyPre . $pid, $time, 15) == false)
            {
                throw new AppException('操作过于频繁，请稍后重试', AppException::FAILED_LOCK);
            }
        }

        //获取场次数据
        $roundInfo = PrdBidRoundModel::M()->getRowById($rid, 'stat,tid,rname,mode,stime,etime,infield');
        if ($roundInfo == false)
        {
            throw new AppException('场次数据不存在', AppException::NO_DATA);
        }
        if ($roundInfo['stat'] != 11)
        {
            throw new AppException('当前场次不允许上架商品', AppException::OUT_OF_OPERATE);
        }

        //上架方式
        $cols = 'pid,oid,bid,mid,level,bcode,plat,inway,offer,bcost';
        if ($type == 1)
        {
            //获取数据
            $list = PrdProductModel::M()->getList(['pid' => ['in' => $pids]], $cols);
        }
        else
        {
            //条件缺失，异常处理
            $defQuery = [
                'bcode' => '',
                'plat' => 0,
                'oname' => '',
                'bid' => 0,
                'mid' => 0,
                'level' => 0,
                'nobids' => 0,
                'upshelfs' => 0,
            ];
            foreach ($defQuery as $key => $value)
            {
                if (empty($query[$key]))
                {
                    $query[$key] = $value;
                }
            }
            $where = $this->getPagerWhere($query);

            //获取数据
            $list = PrdProductModel::M()->getList($where, $cols);
        }

        //提取id
        $pids = ArrayHelper::map($list, 'pid');
        $oids = ArrayHelper::map($list, 'oid');

        //获取卖家数据
        $sellerDict = PrdOrderModel::M()->getDict('oid', ['oid' => ['in' => $oids]], 'seller');

        //获取供应数据
        $supplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'sid');

        //2020-08-25 获取已上架的机器 用于排除闲鱼寄卖商品
        $swhere = [
            'inway' => 1611,
            'stat' => ['in' => [11, 12]],
            'pid' => ['in' => $pids],
        ];
        $xyuSaleDict = PrdBidSalesModel::M()->getDict('pid', $swhere, 'pid');

        //生成竞拍商品数据
        $salesData = [];
        $waterData = [];
        foreach ($list as $key => $item)
        {
            //过滤闲鱼寄卖的数据  流程问题 会被多次上架
            if (isset($xyuSaleDict[$item['pid']]))
            {
                continue;
            }
            $sid = IdHelper::generate();
            $data = [
                'sid' => $sid,
                'plat' => $mode == 1 ? 21 : 22,
                'rid' => $rid,
                'pid' => $item['pid'],
                'yid' => $supplyDict[$item['pid']]['sid'] ?? '',
                'bid' => $item['bid'],
                'mid' => $item['mid'],
                'level' => $item['level'],
                'mode' => $mode,
                'offer' => $item['offer'],
                'seller' => $sellerDict[$item['oid']]['seller'] ?? '',
                'stat' => 11,
                'inway' => $item['inway'],
                'isatv' => 1,
                'stime' => $roundInfo['stime'],
                'etime' => $roundInfo['etime'],
                'infield' => $roundInfo['infield'],
                'spike' => 1,
                'supcmf' => 0,
                'away' => 1,
                'atime' => time(),
            ];
            if ($item['plat'] == 19)
            {
                $data['sprc'] = $item['bcost'];
            }
            $salesData[] = $data;

            //上架流水
            $waterData[] = [
                'wid' => IdHelper::generate(),
                'tid' => 912,
                'oid' => $item['oid'],
                'pid' => $item['pid'],
                'rmk' => "竞拍上架({$roundInfo['rname']})",
                'acc' => $acc,
                'atime' => $time
            ];

            //闲鱼寄卖商品上架 - 履约到闲鱼
            if ($item['plat'] == 161)
            {
                AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_join_round', [
                    'sid' => $sid
                ]);
            }
        }

        //上架商品
        PrdBidSalesModel::M()->inserts($salesData);

        //更新场次上架商品数
        $count = PrdBidSalesModel::M()->getCount(['rid' => $rid]);
        PrdBidRoundModel::M()->updateById($rid, ['upshelfs' => $count]);

        //更新商品数据
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 14, 'stctime' => $time]);

        //更新库存数据
        StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 14]);

        //添加流水
        PrdWaterModel::M()->inserts($waterData);
    }

    /**
     * 编辑商品信息
     * @param string $sid
     * @param string $rid
     * @return array
     * @throws
     */
    public function info(string $sid, string $rid)
    {
        //获取商品数据
        if ($rid)
        {
            $pid = PrdBidSalesModel::M()->getOne(['sid' => $sid, 'rid' => $rid], 'pid');
            if ($pid == false)
            {
                throw new AppException('竞拍商品信息不存在', AppException::NO_DATA);
            }
        }
        else
        {
            $pid = PrdShopSalesModel::M()->getOne(['sid' => $sid], 'pid');
            if ($pid == false)
            {
                throw new AppException('一口价商品信息不存在', AppException::NO_DATA);
            }
        }

        //获取商品信息
        $cols = 'pid,bcode,bid,mid,level,imei,pname,palias,plat,mdram,mdnet,mdcolor,mdofsale,mdwarr,offer';
        $prdInfo = PrdProductModel::M()->getRowById($pid, $cols);
        if ($prdInfo == false)
        {
            throw new AppException('商品信息不存在', AppException::NO_DATA);
        }
        $prdInfo['mname'] = QtoModelModel::M()->getOneById($prdInfo['mid'], 'mname', [], '-');
        $prdInfo['lname'] = QtoLevelModel::M()->getOneById($prdInfo['level'], 'lname', [], '-');
        $prdInfo['oname'] = CrmOfferModel::M()->getOneById($prdInfo['offer'], 'oname', [], '-');

        //返回
        return $prdInfo;
    }

    /**
     * 编辑商品信息保存
     * @param array $data
     * @param string $acc
     * @throws
     */
    public function edit(array $data, string $acc)
    {
        $sid = $data['sid'];
        $rid = $data['rid'];
        $palias = $data['palias'] ?: '-';
        $bconc = $data['bconc'] ?: '-';
        $rmk = $data['rmk'] ?: '-';

        //获取商品数据
        if ($rid)
        {
            $pid = PrdBidSalesModel::M()->getOne(['sid' => $sid, 'rid' => $rid], 'pid');
            if ($pid == false)
            {
                throw new AppException('竞拍商品信息不存在', AppException::NO_DATA);
            }
        }
        else
        {
            $pid = PrdShopSalesModel::M()->getOne(['sid' => $sid], 'pid');
            if ($pid == false)
            {
                throw new AppException('一口价商品信息不存在', AppException::NO_DATA);
            }
        }

        //获取商品信息
        $prdInfo = PrdProductModel::M()->getRowById($pid, 'oid,pname');
        if ($prdInfo == false)
        {
            throw new AppException('商品信息不存在', AppException::NO_DATA);
        }

        //更新商品信息
        PrdProductModel::M()->updateById($pid, ['palias' => $palias]);
        PrdSupplyModel::M()->update(['pid' => $pid, 'salestat' => 1], ['pname' => "{$prdInfo['pname']} $palias"]);

        //添加流水
        $waterData = [
            'wid' => IdHelper::generate(),
            'tid' => 916,
            'oid' => $prdInfo['oid'],
            'pid' => $pid,
            'rmk' => "商品别名：{$palias}， 质检备注：{$bconc}， 备注：{$rmk}",
            'acc' => $acc,
            'atime' => time()
        ];
        PrdWaterModel::M()->insert($waterData);

        //更新质检备注
        $bid = MqcBatchModel::M()->getOne(['pid' => $pid, 'tflow' => [1, 2], 'chkstat' => 3], 'bid');
        MqcReportModel::M()->update(['bid' => $bid, 'plat' => XinxinDictData::PLAT], ['bconc' => $bconc]);
    }
}