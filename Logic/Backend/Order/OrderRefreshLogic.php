<?php
namespace App\Module\Sale\Logic\Backend\Order;

use App\Exception\AppException;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Stc\StcLogisticsModel;
use App\Model\Sys\SysRegionModel;
use App\Model\Xye\XyeSaleGoodsModel;
use App\Model\Xye\XyeSaleOrderModel;
use App\Model\Xye\XyeTaobaoAccountModel;
use App\Model\Xye\XyeTaobaoShopModel;
use App\Module\Xye\Data\Pub\XyePubData;
use App\Module\Xye\Data\Pub\XyeTaobaoData;
use App\Module\Xye\Logic\Pub\Taobao\TaobaoOrderLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;

class OrderRefreshLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject()
     * @var TaobaoOrderLogic
     */
    private $taobaoOrderLogic;

    /**
     * 刷新淘宝订单状态
     * @param string $tid
     * @return array
     * @throws
     */
    public function refresh(string $tid)
    {
        $time = time();

        //加锁
        $lockKey = "sale_order_refresh_lock_{$tid}";
        if ($this->redis->setnx($lockKey, $time, 30) == false)
        {
            throw new AppException('操作过于频繁了，请稍后重试', AppException::FAILED_LOCK);
        }

        //验证订单是否存在
        $xyeSaleOrder = XyeSaleOrderModel::M()->getRow(['tradeid' => $tid], 'oid,tbshop,status');
        if (false == $xyeSaleOrder)
        {
            throw new AppException("淘宝订单数据不存在", AppException::NO_DATA);
        }

        //获取店铺授权token
        $account = XyeTaobaoShopModel::M()->getOneById($xyeSaleOrder['tbshop'] ?? XyePubData::FETCH_SHOP, 'account');
        if (false == $account)
        {
            throw new AppException("店铺账号数据不存在tbshop:{$xyeSaleOrder['tbshop']}", AppException::NO_DATA);
        }
        $token = XyeTaobaoAccountModel::M()->getOneById($account, 'token');

        try
        {
            //刷新订单数据
            $detail = $this->taobaoOrderLogic->getDetail($token, ['tid' => $tid]);

            //比较主订单状态
            $this->sync($detail);

            //子订单
            $subOrders = $detail['orders']['order'] ?? [];
            $subOrders = isset($subOrders[0]) ? $subOrders : [$subOrders];

            //子订单商品字典
            $goodsDict = XyeSaleGoodsModel::M()->getDict('orderid', ['tradeid' => $tid], 'gid,orderid,odramt,disamt,payamt,refstat');

            //比较子订单数据
            $batchXyeGoods = [];
            foreach ($subOrders as $item)
            {
                //如果相关金额、退款状态无改变，则不更新
                $num = $item['num'] ?? 1;
                $oid = $item['oid'] ?? '';
                $gid = $goodsDict[$oid]['gid'] ?? '';
                $gids = $gid ? [$gid] : [];
                $oldOdrAmt = $goodsDict[$oid]['odramt'] ?? 0.00;
                $oldDisAmt = $goodsDict[$oid]['disamt'] ?? 0.00;
                $oldPayAmt = round($goodsDict[$oid]['payamt'] / $num, 2);
                $oldRefStat = $goodsDict[$oid]['refstat'] ?? 0;

                $odrAmt = $item['price'] ?? 0.00;
                $disAmt = $item['discount_fee'] ?? 0.00;
                $payAmt = $item['payment'] ?? 0.00;
                $refundId = $item['refund_id'] ?? '';
                $refStat = XyeTaobaoData::GOODS_REFUND_STATUS[$item['refund_status']] ?? 0;
                if (isset($goodsDict[$oid]) && ($oldOdrAmt == $odrAmt && $oldDisAmt == $disAmt && $oldPayAmt == $payAmt && $oldRefStat == $refStat))
                {
                    continue;
                }

                //组装更新数据
                if ($num > 1)
                {
                    //如果子订单有多个商品，查询该子订单的所有商品
                    $gidList = XyeSaleGoodsModel::M()->getList(['orderid' => $oid], 'gid');
                    $gids = array_column($gidList, 'gid');
                }
                foreach ($gids as $gid)
                {
                    $batchXyeGoods[] = [
                        'gid' => $gid,
                        'odramt' => $odrAmt,
                        'disamt' => $disAmt,
                        'payamt' => round($payAmt / $num, 2),
                        'refundid' => $refundId,
                        'refstat' => $refStat,
                        'mtime' => $time
                    ];
                }

                //更新数据
                if ($batchXyeGoods)
                {
                    XyeSaleGoodsModel::M()->inserts($batchXyeGoods, true);
                }
            }

            //解锁
            $this->redis->del($lockKey);

            //返回
            return $detail;
        }
        catch (\Throwable $throwable)
        {
            //解锁
            $this->redis->del($lockKey);

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 同步最新数据到odr
     * @param array $detail
     * @throws
     */
    private function sync(array $detail)
    {
        $tradeId = $detail['tid'];
        $status = XyeTaobaoData::ODR_STATUS_MAPPING_SALE_STAT[$detail['status']] ?? 0;
        $ostat = 20;
        $time = time();

        //获取优品淘宝订单数据
        $saleOrder = XyeSaleOrderModel::M()->getRow(['tradeid' => $tradeId]);
        if (false == $saleOrder)
        {
            throw new AppException('淘宝订单数据不存在', AppException::DATA_MISS);
        }

        //获取内部订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['third' => $tradeId], 'oid,okey,ostat,dlykey');
        if (false == $odrOrder)
        {
            throw new AppException('订单数据不存在', AppException::DATA_MISS);
        }

        //交易完成
        if ($status == 21 && isset($detail['consign_time']) && $odrOrder['ostat'] != 21)
        {
            $ostat = 23;
        }

        //检查订单收件人信息是否改变
        $updateOdrOrder = $this->checkRecInfo($saleOrder, $detail);

        //订单完成,补充更新数据
        if ($ostat == 23)
        {
            //订单状态
            $updateOdrOrder['ostat'] = $ostat;
            $updateOdrOrder['otime23'] = $time;
            $updateOdrOrder['mtime'] = $time;

            //签收时间
            if ($updateOdrOrder['dlytime5'])
            {
                StcLogisticsModel::M()->update(['lkey' => $odrOrder['dlykey']], ['ltime5' => $updateOdrOrder['dlytime5']]);
            }

            //商品订单状态
            $updateOdrGoods = [
                'ostat' => $ostat,
                'mtime' => $time
            ];
        }
        if ($updateOdrOrder)
        {
            OdrOrderModel::M()->updateById($odrOrder['oid'], $updateOdrOrder);
        }
        if (isset($updateOdrGoods))
        {
            OdrGoodsModel::M()->update(['okey' => $odrOrder['okey']], $updateOdrGoods);
        }

        //返回
        return;
    }

    /**
     * 比较收件人信息是否更改
     * @param array $saleOrder 已保存的订单信息
     * @param array $detail 请求API获取的订单详情
     * @return array
     * @throws
     */
    private function checkRecInfo(array $saleOrder, array $detail)
    {
        //匹配收货地址所在地区
        $recReg = 0;
        foreach (['receiver_state', 'receiver_city', 'receiver_district'] as $recKey)
        {
            if (!empty($detail[$recKey]))
            {
                $regionId = SysRegionModel::M()->getOne(['pid' => $recReg, 'rname' => $detail[$recKey]], 'rid');
                if ($regionId)
                {
                    $recReg = $regionId;
                }
            }
        }

        //补充收货人信息
        $newData['recver'] = $detail['receiver_name'];
        $newData['rectel'] = $detail['receiver_mobile'];
        $newData['recreg'] = $recReg;
        $newData['recdtl'] = join('##', array_filter([
            $detail['receiver_state'] ?? '',
            $detail['receiver_city'] ?? '',
            $detail['receiver_district'] ?? '',
            $detail['receiver_town'] ?? '',
            $detail['receiver_address'] ?? '',
        ]));

        //比较数据
        $diff = [];
        foreach (['recver', 'rectel', 'recreg', 'recdtl'] as $recKey)
        {
            if ($newData[$recKey] != $saleOrder[$recKey])
            {
                $diff[$recKey] = $newData[$recKey];
            }
        }

        //更新淘宝订单数据
        if ($diff)
        {
            XyeSaleOrderModel::M()->updateById($saleOrder['oid'], $diff);
        }

        //签收时间
        $diff['dlytime5'] = $detail['status'] == 'TRADE_FINISHED' ? strtotime($detail['end_time']) : 0;

        //返回
        return $diff;
    }
}