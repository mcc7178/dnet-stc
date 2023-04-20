<?php
namespace App\Module\Sale\Logic\Backend\Order;

use App\Exception\AppException;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Stc\StcLogisticsModel;
use App\Model\Stc\StcStorageModel;
use App\Model\Xye\XyeSaleGoodsModel;
use App\Model\Xye\XyeSaleWaterModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;
use Throwable;

/**
 * 淘宝订单操作逻辑
 * @package App\Module\Sale\Logic\Backend\Order
 */
class OrderTaobaoActLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * 发货取消逻辑
     * @param string $acc
     * @param string $YxeGids
     * @throws
     */
    public function cancel(string $acc, string $YxeGids)
    {
        $gids = explode(',', $YxeGids);
        $odrGoods = OdrGoodsModel::M()->getList(['third' => ['in' => $gids]], 'gid,okey,bcode,dlykey,third');
        if (!$odrGoods)
        {
            throw new AppException('订单商品不存在', AppException::NO_DATA);
        }
        $okey = ArrayHelper::map($odrGoods, 'okey');
        $dlykey = ArrayHelper::map($odrGoods, 'dlykey');
        $odrGids = ArrayHelper::map($odrGoods, 'gid');

        //订单商品的数量
        $qty = OdrOrderModel::M()->getOne(['okey' => ['in' => $okey]], 'qty');

        //查询订单商品的数量
        $count = OdrGoodsModel::M()->getCount(['okey' => ['in' => $okey], 'dlystat' => ['in' => [2, 3, 4, 5]]]);
        $goods = XyeSaleGoodsModel::M()->getRowById($gids[0], 'tradeid');
        $bcodes = [];
        $gids1 = [];
        foreach ($odrGoods as $key => $value)
        {
            $bcodes[] = $value['bcode'] ? $value['bcode'] : "";
            if (empty($value['bcode']))
            {
                // 移除空bcode
                $key = array_search($value['gid'], $odrGids);
                array_splice($odrGids, $key, 1);
                $gids1[] = $value['gid'];
            }
        }
        $rmk = implode(",", $bcodes);
        if (count($odrGids) > 0)
        {
            $goodsList = OdrGoodsModel::M()->getList(['gid' => ['in' => $odrGids]], 'pid');
            $pids = ArrayHelper::map($goodsList, 'pid');
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //更新配货订单商品状态
            if (count($odrGids) > 0)
            {
                OdrGoodsModel::M()->update(['gid' => ['in' => $odrGids]], ['ostat' => 20, 'dlykey' => '', 'dlystat' => 2, 'mtime' => time()]);
                PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['prdstat' => 1, 'stcstat' => 15, 'stctime' => time()]);
                StcStorageModel::M()->update(['pid' => ['in' => $pids], 'prdstat' => 23], ['stat' => 1, 'prdstat' => 15]);
            }

            //更新待配货订单商品状态
            if (count($gids1) > 0)
            {
                OdrGoodsModel::M()->update(['gid' => ['in' => $gids1]], ['ostat' => 20, 'dlykey' => '', 'dlystat' => 1, 'mtime' => time()]);
            }

            $lcount = OdrGoodsModel::M()->getCount(['dlykey' => ['in' => $dlykey]]);
            if ($lcount == 0)
            {
                StcLogisticsModel::M()->delete(['lkey' => ['in' => $dlykey]]);
            }

            //流水
            $this->saveXyeOrderWater($goods['tradeid'], 4, $rmk, $acc);

            //如果全部商品已发货，改变某个商品需要把订单状态改为待配货
            if ($qty == $count)
            {
                OdrOrderModel::M()->update(['okey' => $okey], ['ostat' => 20, 'mtime' => time()]);
                OdrGoodsModel::M()->update(['okey' => $okey], ['ostat' => 20]);
            }

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 不发货逻辑
     * @param string $acc
     * @param string $xyeGid
     * @throws
     */
    public function del(string $acc, string $xyeGid)
    {
        //获取订单号
        $odrGoods = OdrGoodsModel::M()->getRow(['third' => $xyeGid], 'okey,pid,bcode');
        if (!$odrGoods)
        {
            throw new AppException('订单商品不存在', AppException::NO_DATA);
        }
        $odrOrder = OdrOrderModel::M()->getRow(['okey' => $odrGoods['okey']], 'qty,ostat,third');
        if ($odrOrder['ostat'] > 20 || !$odrOrder)
        {
            throw new AppException('订单状态不对或订单不存在', AppException::NO_DATA);
        }

        //更新订单商品的状态
        OdrGoodsModel::M()->update(['third' => $xyeGid], ['pid' => '', 'bcode' => '', 'dlystat' => 5, 'mtime' => time()]);
        $rmk = $odrGoods['bcode'] ? $odrGoods['bcode'] : "";

        //流水
        $this->saveXyeOrderWater($odrOrder['third'], 6, $rmk, $acc);

        //获取已取消商品的数量
        $count = OdrGoodsModel::M()->getCount(['okey' => $odrGoods['okey'], 'dlystat' => 5]);

        //清空淘宝子订单的商品
        XyeSaleGoodsModel::M()->updateById($xyeGid, ['pid' => '', 'bcode' => '', 'mtime' => time()]);
        if ($odrGoods['pid'])
        {
            PrdProductModel::M()->updateById($odrGoods['pid'], ['stcstat' => 11, 'stctime' => time()]);
            StcStorageModel::M()->update(['pid' => $odrGoods['pid'], 'prdstat' => 15], ['prdstat' => 11]);
        }

        //关闭订单
        if ($odrOrder['qty'] == $count)
        {
            OdrOrderModel::M()->update(['okey' => $odrGoods['okey']], ['ostat' => 51, 'mtime' => time()]);
            OdrGoodsModel::M()->update(['okey' => $odrGoods['okey']], ['ostat' => 51, 'mtime' => time()]);
        }
        $Goods = OdrGoodsModel::M()->getList(['okey' => $odrGoods['okey']], 'dlystat,ostat');
        $dlystats = ArrayHelper::map($Goods, 'dlystat');

        //判断商品状态是否为待配货、已配货，不是则执行，在判断是否存在待发货的商品，有则把订单状态改为待发货，如果其余商品都为已发货就把订单状态改为已发货
        if ($Goods[0]['ostat'] != 51 && !in_array(1, $dlystats) && !in_array(2, $dlystats))
        {
            if (!in_array(3, $dlystats))
            {
                OdrGoodsModel::M()->update(['okey' => $odrGoods['okey']], ['ostat' => 22, 'mtime' => time()]);
                OdrOrderModel::M()->update(['okey' => $odrGoods['okey']], ['ostat' => 22, 'mtime' => time()]);
            }
            else
            {
                OdrGoodsModel::M()->update(['okey' => $odrGoods['okey']], ['ostat' => 21, 'mtime' => time()]);
                OdrOrderModel::M()->update(['okey' => $odrGoods['okey']], ['ostat' => 21, 'mtime' => time()]);
            }
        }
    }

    /**
     * 配货
     * @param string $xyeGid
     * @param string $pid
     * @param string $acc
     * @throws
     */
    public function matchGoods(string $xyeGid, string $pid, string $acc)
    {
        //获取淘宝订单编号
        $tradeid = XyeSaleGoodsModel::M()->getOneById($xyeGid, 'tradeid');
        if ($tradeid == false)
        {
            throw new AppException('淘宝子订单商品数据不存在', AppException::NO_DATA);
        }

        //获取订单商品数据
        $odrGoodsInfo = OdrGoodsModel::M()->getRow(['third' => $xyeGid], 'gid,okey,pid,bcode,dlystat');
        if ($odrGoodsInfo == false || !in_array($odrGoodsInfo['dlystat'], [1, 2]))
        {
            throw new AppException('订单商品数据不存在或订单状态不允许操作', AppException::NO_DATA);
        }

        //获取商品数据
        $prdProductInfo = PrdProductModel::M()->getRowById($pid, 'pid,bcode,stcstat,stcwhs');
        if ($prdProductInfo == false || !in_array($prdProductInfo['stcstat'], [11, 13]))
        {
            throw new AppException('商品数据不存在或不在库', AppException::NO_DATA);
        }

        //提取数据
        $bcode = $prdProductInfo['bcode'];
        $whs = $prdProductInfo['stcwhs'];
        $time = time();

        //组装销售订单商品数据
        $xyeSaleGoodsData = [
            'pid' => $pid,
            'bcode' => $bcode,
            'mtime' => $time,
        ];

        //组装更新商品数据
        $prdProductData[] = [
            'pid' => $pid,
            'stcstat' => 15,
            'stctime' => time(),
        ];

        //如果是已经配货状态的，恢复原商品的在库状态
        if ($odrGoodsInfo['dlystat'] == 2)
        {
            $prdProductData[] = [
                'pid' => $odrGoodsInfo['pid'],
                'stcstat' => 11,
                'stctime' => time(),
            ];
        }

        //组装订单的商品明细数据
        $odrGoodsData = [
            'pid' => $pid,
            'bcode' => $bcode,
            'dlystat' => 2,
            'mtime' => $time,
            'whs' => $whs,
        ];

        try
        {
            //开始事务
            Db::beginTransaction();

            //更新销售单商品数据
            XyeSaleGoodsModel::M()->updateById($xyeGid, $xyeSaleGoodsData);

            //更新订单的商品数据
            OdrGoodsModel::M()->updateById($odrGoodsInfo['gid'], $odrGoodsData);

            //更新商品状态
            PrdProductModel::M()->inserts($prdProductData, true);
            foreach ($prdProductData as $value)
            {
                $stcstat = $value['stcstat'];
                $prdstat = ($stcstat == 11) ? 15 : 11;
                StcStorageModel::M()->update(['pid' => $value['pid'], 'prdstat' => $prdstat], ['prdstat' => $stcstat]);
            }

            //记录流水
            $this->saveXyeOrderWater($tradeid, 2, $bcode, $acc);

            //更新订单
            OdrOrderModel::M()->update(['okey' => $odrGoodsInfo['okey']], ['whs' => $whs]);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 删除商品
     * @param string $xyeGid
     * @param string $acc
     * @throws
     */
    public function cancelGoods(string $xyeGid, string $acc)
    {
        //获取淘宝订单编号
        $tradeid = XyeSaleGoodsModel::M()->getOneById($xyeGid, 'tradeid');
        if ($tradeid == false)
        {
            throw new AppException('淘宝子订单商品数据不存在', AppException::NO_DATA);
        }

        //获取订单商品数据
        $odrGoodsInfo = OdrGoodsModel::M()->getRow(['third' => $xyeGid], 'gid,okey,pid,bcode,dlystat');
        if ($odrGoodsInfo == false || $odrGoodsInfo['dlystat'] != 2)
        {
            throw new AppException('订单商品数据不存在或订单状态不允许操作', AppException::NO_DATA);
        }

        $pid = $odrGoodsInfo['pid'];
        $bcode = $odrGoodsInfo['bcode'];
        $time = time();

        //组装销售订单商品数据
        $xyeSaleGoodsData = [
            'pid' => '',
            'bcode' => '',
            'mtime' => $time,
        ];

        //组装订单的商品明细数据
        $odrGoodsData = [
            'pid' => '',
            'bcode' => '',
            'dlystat' => 1,
            'mtime' => $time,
            'whs' => 0,
        ];

        try
        {
            //开始事务
            Db::beginTransaction();

            //更新销售单商品数据
            XyeSaleGoodsModel::M()->updateById($xyeGid, $xyeSaleGoodsData);

            //更新订单的商品数据
            OdrGoodsModel::M()->updateById($odrGoodsInfo['gid'], $odrGoodsData);

            //更新商品状态
            PrdProductModel::M()->updateById($pid, ['stcstat' => 11, 'stctime' => time()]);
            StcStorageModel::M()->update(['pid' => $pid, 'prdstat' => 15], ['prdstat' => 11]);

            //记录流水
            $this->saveXyeOrderWater($tradeid, 5, $bcode, $acc);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 确认发货
     * @param string $xyeGid
     * @param string $okey
     * @param string $acc
     * @throws
     */
    public function confirmDelivery(string $xyeGid, string $okey, string $acc)
    {
        if (!$okey && !$xyeGid)
        {
            throw new AppException('缺少参数', AppException::MISS_ARG);
        }

        $time = time();
        $odrOrderData = $odrGoodsList = $odrOrderInfo = [];
        $bcodes = '';

        //所需字段
        $cols = 'oid,okey,ostat,plat,qty,whs,dlyway,recver,rectel,recreg,recdtl,third,dlykey';

        //如果有交易单号就是一键发货
        if ($okey)
        {
            //获取订单数据
            $odrOrderInfo = OdrOrderModel::M()->getRow(['okey' => $okey], $cols);
            if ($odrOrderInfo == false || $odrOrderInfo['ostat'] != 20)
            {
                throw new AppException('订单商品数据不存在或订单状态不允许操作', AppException::NO_DATA);
            }

            //获取订单商品数据
            $odrGoodsList = OdrGoodsModel::M()->getList(['okey' => $okey, 'dlystat' => [1, 2]], 'gid,pid,bcode,whs,dlystat');
            if (!$odrGoodsList)
            {
                throw new AppException('订单商品数据不存在或订单状态不允许操作', AppException::NO_DATA);
            }

            //获取订单商品gid字典
            $gids = ArrayHelper::map($odrGoodsList, 'gid');

            $bcodes = '';
            foreach ($odrGoodsList as $key => $value)
            {
                //流水库存编号
                if ($value['dlystat'] == 2)
                {
                    $bcodes .= $value['bcode'] . ',';
                }
            }
            $bcodes = trim($bcodes, ',');
        }
        elseif ($xyeGid)
        {
            //获取订单商品数据
            $odrGoodsList = OdrGoodsModel::M()->getList(['third' => $xyeGid], 'okey,gid,pid,bcode,whs,dlystat,third');
            if (!$odrGoodsList || count($odrGoodsList) > 1)
            {
                throw new AppException('订单商品数据不存在或订单异常', AppException::NO_DATA);
            }

            $okey = $odrGoodsList[0]['okey'];
            $dlystat = $odrGoodsList[0]['dlystat'];

            //获取订单数据
            $odrOrderInfo = OdrOrderModel::M()->getRow(['okey' => $okey], $cols);
            if ($odrOrderInfo == false || $odrOrderInfo['ostat'] != 20)
            {
                throw new AppException('订单商品数据不存在或订单状态不允许操作', AppException::NO_DATA);
            }

            $bcodes = $dlystat == 2 ? $odrGoodsList[0]['bcode'] : '';
        }

        //组装订单的商品明细数据
        $odrGoodsData = [
            'dlystat' => 3,
            'mtime' => $time
        ];

        //pid字典
        $pids = ArrayHelper::map($odrGoodsList, 'pid', '-1');

        $okey = $odrOrderInfo['okey'];

        //获取订单商品的所有发货单号
        $odrGoodsList2 = OdrGoodsModel::M()->getList(['okey' => $okey], 'dlykey,ostat');
        if (!$odrGoodsList2)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }

        //获取dlykey字典
        $dlykeys = ArrayHelper::map($odrGoodsList2, 'dlykey', -1);

        //获取待发货单
        $stcLogisticsInfo = StcLogisticsModel::M()->getRow(['lkey' => ['in' => $dlykeys], 'lstat' => 1], 'lkey');
        if ($stcLogisticsInfo != false)
        {
            //如果存在待发货的发货单，补充物流单
            $odrGoodsData['dlykey'] = $stcLogisticsInfo['lkey'];
        }
        else
        {
            //组装发货单数据
            $StcLogisticsData = [
                'lid' => IdHelper::generate(),
                'lkey' => $this->uniqueKeyLogic->getStcLG(),
                'tid' => 3,
                'plat' => $odrOrderInfo['plat'],
                'whs' => $odrOrderInfo['whs'],
                'expway' => 1,
                'recver' => $odrOrderInfo['recver'],
                'rectel' => $odrOrderInfo['rectel'],
                'recreg' => $odrOrderInfo['recreg'],
                'recdtl' => str_replace('##', ' ', $odrOrderInfo['recdtl']),
                'lway' => 1,
                'lstat' => 1,
                'ltime1' => $time,
            ];

            //补充物流单
            $odrGoodsData['dlykey'] = $StcLogisticsData['lkey'];

            //如果订单没有物流单号，则补充物流单号
            if (!$odrOrderInfo['dlykey'])
            {
                $odrOrderData['dlykey'] = $StcLogisticsData['lkey'];
                $odrOrderData['mtime'] = $time;
            }

            //如果没有whs，补充收收总仓
            if (!$odrOrderInfo['whs'])
            {
                $odrOrderData['whs'] = 101;
                $odrGoodsData['whs'] = 101;
                $StcLogisticsData['whs'] = 101;
            }
        }

        try
        {
            //开始事务
            Db::beginTransaction();

            //一键发货
            if (isset($gids))
            {
                OdrGoodsModel::M()->update(['gid' => ['in' => $gids]], $odrGoodsData);
            }
            elseif ($xyeGid)
            {
                OdrGoodsModel::M()->updateById($odrGoodsList[0]['gid'], $odrGoodsData);
            }

            //更新商品状态为已售
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['prdstat' => 2, 'stcstat' => 23, 'stctime' => $time]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'prdstat' => 15], ['stat' => 2, 'prdstat' => 23]);

            //提取交易单号
            $tradeid = $odrOrderInfo['third'];

            //记录流水
            $this->saveXyeOrderWater($tradeid, 3, $bcodes, $acc);

            //如果不存在待配货、已配货则更新订单状态
            if (OdrGoodsModel::M()->exist(['okey' => $odrOrderInfo['okey'], 'dlystat' => [1, 2]]) == false)
            {
                $odrOrderData['ostat'] = 21;
                $odrOrderData['mtime'] = $time;
                OdrGoodsModel::M()->update(['okey' => $odrOrderInfo['okey']], ['ostat' => 21, 'mtime' => $time]);
            }

            //更新订单表数据
            if ($odrOrderData)
            {
                OdrOrderModel::M()->updateById($odrOrderInfo['oid'], $odrOrderData);
            }

            //新增仓库发货单
            if (isset($StcLogisticsData))
            {
                StcLogisticsModel::M()->insert($StcLogisticsData);
            }

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 添加备注
     * @param string $okey
     * @param string $rmk
     */
    public function saveOrderRmk(string $okey, string $rmk)
    {
        OdrOrderModel::M()->update(['okey' => $okey], ['rmk2' => $rmk]);
    }

    /**
     * 保存淘宝订单的流水
     * @param string $tradeid
     * @param int $tid
     * @param string $rmk
     * @param string $wacc
     */
    public function saveXyeOrderWater(string $tradeid, int $tid, string $rmk, string $wacc)
    {
        //组装流水记录数据
        $data = [
            'wid' => IdHelper::generate(),
            'tradeid' => $tradeid,
            'tid' => $tid,
            'rmk' => $rmk,
            'wacc' => $wacc,
            'wtime' => time(),
        ];
        XyeSaleWaterModel::M()->insert($data);
    }
}