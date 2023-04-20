<?php
namespace App\Module\Sale\Logic\Order;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Model\Crm\CrmBuyerModel;
use App\Model\Crm\CrmMoneyModel;
use App\Model\Dnet\CrmMessageModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Odr\OdrPaymentModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Smb\Data\SmbNodeKeyData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;

/**
 * 订单支付相关逻辑
 * @package App\Module\Sale\Logic\Order
 */
class OrderPaySuccessLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject("amqp_realtime_task")
     * @var Amqp
     */
    private $amqp_realtime;

    /**
     * @Inject("amqp_message_task")
     * @var Amqp
     */
    private $amqp_message;

    /**
     * @Inject()
     * @var LogisticsLogic
     */
    private $logisticsLogic;

    /**
     * 支付成功处理逻辑
     * @param array $payData 支付回调参数
     * @throws
     */
    public function handle(array $payData)
    {
        //提取数据
        $payType = $payData['paytype'] ?? 0;
        $payTime = $payData['paytime'] ?? 0;
        $payNo = $payData['payno'] ?? '';
        $payId = $payData['paybid'] ?? '';

        //加锁，防止重复处理
        $lockKey = 'sale_pay_success_lock_' . $payId;
        if (!$this->redis->setnx($lockKey, time(), 60))
        {
            throw new AppException('找不到支付单数据', AppException::FAILED_LOCK);
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //获取支付单数据
            $paymentOrder = OdrPaymentModel::M()->getRowById($payId);
            if ($paymentOrder == false)
            {
                throw new AppException('找不到支付单数据', AppException::NO_DATA);
            }

            //检查支付单是否待支付
            if ($paymentOrder['paystat'] != 1)
            {
                throw new AppException('支付单状态异常', AppException::OUT_OF_OPERATE);
            }

            //组装更新数据
            $updatePayData = [
                'paytype' => $payType,
                'paytime' => $payTime,
                'payno' => $payNo,
                'paystat' => 2,
            ];

            //线下支付时补充审核数据
            if ($payType == 13)
            {
                $updatePayData['chkstat'] = 2;
                $updatePayData['chktime'] = $payData['chktime'];
                $updatePayData['chkuser'] = $payData['chkuser'];
                $updatePayData['chkrmk'] = $payData['chkrmk'];
            }

            //更新支付单数据
            OdrPaymentModel::M()->updateById($payId, $updatePayData);

            //获取支付单数据
            $paymentOrder = OdrPaymentModel::M()->getRowById($payId);

            //检查支付单是否支付成功
            if ($paymentOrder['paystat'] != 2)
            {
                throw new AppException('支付单状态异常', AppException::OUT_OF_OPERATE);
            }

            //提取参数
            $payTid = $paymentOrder['tid'];
            $payTime = $paymentOrder['paytime'];

            //检查支付订单数据
            $this->checkPayOrderData($paymentOrder, $orderList, $goodsInfo);

            //更新订单相关数据
            $this->updateOrderData($paymentOrder, $orderList);

            //更新买家相关数据
            $this->updateSellerData($paymentOrder, $orderList);

            //更新商品相关数据
            if ($payTid == 2)
            {
                $this->updateProductData($orderList, $payTime);
            }

            //提交事务
            Db::commit();
        }
        catch (\Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //解锁
            $this->redis->del($lockKey);

            //抛出异常
            throw $throwable;
        }

        //业务数据更新成功以后，才能执行
        $this->updateDataSuccessAfter($paymentOrder, $orderList, $goodsInfo);

        //解锁
        $this->redis->del($lockKey);
    }

    /**
     * 检查支付订单数据
     * @param array $paymentOrder 支付单数据
     * @param $orderList
     * @param $goodsInfo
     * @throws
     */
    private function checkPayOrderData(array $paymentOrder, &$orderList, &$goodsInfo)
    {
        //提取参数
        $payOkeys = ArrayHelper::toArray($paymentOrder['payokeys']);
        $payBuyer = $paymentOrder['buyer'];
        $payTid = $paymentOrder['tid'];
        $payOdrQty = $paymentOrder['payodrs'];
        $payPrdQty = $paymentOrder['payprds'];
        $payAmounts = $paymentOrder['payamts'];

        //订单数据所需字段
        $orderCols = 'oid,plat,src,buyer,tid,okey,qty,ostat,paystat,payamt,recver,rectel,recreg,recdtl,dlyway,whs,_id';

        //获取关联订单数据
        $orderList = OdrOrderModel::M()->getList(['okey' => $payOkeys], $orderCols);
        if ($orderList == false)
        {
            throw new AppException('缺少关联订单数据', AppException::NO_DATA);
        }

        $odrPayAmounts = 0;
        $odrGoodsQty = 0;
        $goodsInfo = [];

        //检查订单数据是否正确
        foreach ($orderList as $value)
        {
            if (!in_array($value['ostat'], [11, 12]))
            {
                throw new AppException("关联订单[{$value['okey']}]的订单状态不是待支付或待审核", AppException::OUT_OF_OPERATE);
            }
            elseif ($value['buyer'] != $payBuyer)
            {
                throw new AppException("关联订单[{$value['okey']}]的买家与支付单买家不一致", AppException::OUT_OF_OPERATE);
            }

            $odrPayAmounts += floatval($value['payamt']);
            $odrGoodsQty += $value['qty'];
        }

        //检查支付单支付数据是否与实际的一致
        if (count($orderList) != $payOdrQty)
        {
            throw new AppException('支付单支付订单数与实际订单数不一致', AppException::OUT_OF_OPERATE);
        }
        if ($payTid == 1)
        {
            if ($payAmounts != $odrPayAmounts)
            {
                throw new AppException('支付单支付金额与实际订单应付金额不一致', AppException::OUT_OF_OPERATE);
            }
        }
        elseif ($payTid == 2)
        {
            //订单商品数据所需字段
            $goodsCols = 'group_concat(pid) as pidStr,sum(bprc) as totalAmt,count(1) as totalQty';

            //获取关联订单商品数据
            $goodsInfo = OdrGoodsModel::M()->getRow(['okey' => $payOkeys], $goodsCols);
            if ($goodsInfo == false)
            {
                $goodsInfo['pidStr'] = '';
                $goodsInfo['totalAmt'] = 0;
                $goodsInfo['totalQty'] = 0;
            }

            if (($payPrdQty != $odrGoodsQty) || ($payPrdQty != $goodsInfo['totalQty']))
            {
                throw new AppException('支付单支付商品数与实际订单商品数不一致', AppException::OUT_OF_OPERATE);
            }
            if (($payAmounts != $odrPayAmounts) || ($payAmounts != $goodsInfo['totalAmt']))
            {
                throw new AppException('支付单支付金额与实际订单应付金额不一致', AppException::OUT_OF_OPERATE);
            }
        }
    }

    /**
     * 更新订单相关数量
     * @param array $paymentOrder 支付单数据
     * @param array $orderList 关联订单数据
     */
    private function updateOrderData(array $paymentOrder, array $orderList)
    {
        //提取参数
        $payOkeys = ArrayHelper::toArray($paymentOrder['payokeys']);
        $payTime = $paymentOrder['paytime'];
        $payType = $paymentOrder['paytype'];
        $payChn = $paymentOrder['paychn'];
        $payNo = $paymentOrder['payno'];
        $tradeNo = $paymentOrder['tradeno'];
        $odrTid = $orderList[0]['tid'];
        $time = time();

        //映射订单状态
        $statDict = [
            10 => 13, //保证金订单
            21 => 23, //线下订单
            22 => 23, //外发订单
        ];
        $odrStat = $statDict[$odrTid] ?? 21;

        //更新订单数据
        OdrOrderModel::M()->update(['okey' => $payOkeys], [
            'ostat' => $odrStat,
            'paystat' => 3,
            'paychn' => $payChn,
            'paytype' => $payType,
            'paytime' => $payTime,
            'payno' => $payNo,
            'tradeno' => $tradeNo,
            'mtime' => $time,
        ]);
        if ($odrTid != 10)
        {
            OdrGoodsModel::M()->update(['okey' => $payOkeys], [
                'ostat' => $odrStat,
                'paytime' => $payTime,
                'mtime' => $time
            ]);
        }

        //创建发货单
        if ($odrStat == 21)
        {
            $this->logisticsLogic->create($orderList, $payTime);
        }
    }

    /**
     * 更新买家相关数据
     * @param array $paymentOrder 支付单数据
     * @param array $orderList 关联订单数据
     * @throws
     */
    private function updateSellerData(array $paymentOrder, array $orderList)
    {
        //提取参数
        $plat = $paymentOrder['plat'];
        $buyer = $paymentOrder['buyer'];
        $payTid = $paymentOrder['tid'];
        $payOdrQty = $paymentOrder['payodrs'];
        $payAmounts = $paymentOrder['payamts'];
        $time = time();

        //如果是保证金支付单
        if ($payTid == 1)
        {
            return;
        }

        //新增买家资金流水
        $moneyData = [];
        foreach ($orderList as $key => $item)
        {
            $payAmt = floatval($item['payamt']);
            $wid = IdHelper::generate();
            $moneyData[] = [
                'wid' => $wid,
                'plat' => $plat,
                'acc' => $buyer,
                'tid' => 11,
                'amts' => -$payAmt,
                'okey' => $item['okey'],
                'wtime' => $time,
                'rmk' => "订单支付：$payAmt 元",
                '_id' => $wid,
            ];
        }
        CrmMoneyModel::M()->inserts($moneyData);

        //更新买家支付数据
        CrmBuyerModel::M()->update(['plat' => $plat, 'acc' => $buyer], [
            'lastodr' => $time,
        ], [
            'payodrs' => "payodrs + $payOdrQty",
            'payamts' => "payamts + $payAmounts",
            'firstodr' => "IF(firstodr=0,$time,firstodr)",
        ]);
    }

    /**
     * @param array $orderList 关联订单数据
     * @param int $payTime 支付时间
     */
    private function updateProductData(array $orderList, int $payTime)
    {
        $time = time();

        //提取订单号
        $okeys = ArrayHelper::map($orderList, 'okey', '-1');

        //获取订单商品数据
        $goodsList = OdrGoodsModel::M()->getList(['okey' => $okeys], 'gid,plat,okey,tid,pid,yid,sid,bprc,profit1');
        if (count($goodsList) == false)
        {
            return;
        }

        //提取产品ID
        $pids = ArrayHelper::map($goodsList, 'pid', '-1');

        //更新产品销售数据
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], [
            'saletime' => $payTime,
            'prdstat' => 2,
            'stcstat' => 23,
            'stctime' => $time,
        ]);

        //更新库存表商品状态
        StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], [
            'stat' => 2,
            'prdstat' => 23,
        ]);

        //提取订单收货地址
        $regionDict = array_column($orderList, 'recreg', 'okey');

        $batchUpdateSupply = [];
        $xinXinShopSids = [];

        //更新供应库销售数据
        foreach ($goodsList as $key => $value)
        {
            $prvId = 0;
            $cityId = 0;
            $areaId = $regionDict[$value['okey']] ?? 0;
            if ($areaId > 0)
            {
                $prvId = str_pad(substr($areaId, 0, 2), strlen($areaId), 0);
                $cityId = str_pad(substr($areaId, 0, 4), strlen($areaId), 0);
            }

            //组装批量更新数据
            $batchUpdateSupply[] = [
                'sid' => $value['yid'],
                'salestat' => 2,
                'salechn' => $value['plat'],
                'saleway' => $value['tid'],
                'saleamt' => $value['bprc'],
                'saletime' => $payTime,
                'profit' => $value['profit1'],
                'saleprvid' => $prvId,
                'salecityid' => $cityId,
                'saleareaid' => $areaId,
            ];

            //一口价商品ID
            if ($value['tid'] == 12)
            {
                $xinXinShopSids[] = $value['sid'];
            }
        }
        PrdSupplyModel::M()->inserts($batchUpdateSupply, true);

        //处理新新一口价商品
        if (count($xinXinShopSids) > 0)
        {
            $this->updateXinXinShopProduct($orderList[0]['buyer'], $xinXinShopSids);
        }
    }

    /**
     * 处理一口价商品
     * @param string $buyer
     * @param array $sids
     */
    private function updateXinXinShopProduct(string $buyer, array $sids)
    {
        PrdShopSalesModel::M()->update(['sid' => $sids], ['stat' => 33, 'isatv' => 0, 'mtime' => time()]);
    }

    /**
     * 业务数据更新成功以后，才能执行
     * @param array $paymentOrder
     * @param array $orderList
     * @param array $goodsInfo
     */
    private function updateDataSuccessAfter(array $paymentOrder, array $orderList, array $goodsInfo)
    {
        //提取参数
        $payOkeys = ArrayHelper::toArray($paymentOrder['payokeys']);
        $plat = $paymentOrder['plat'];
        $buyer = $paymentOrder['buyer'];
        $payId = $paymentOrder['pid'];
        $payTid = $paymentOrder['tid'];
        $payAmounts = $paymentOrder['payamts'];
        $payOdrQty = $paymentOrder['payodrs'];
        $payTime = $paymentOrder['paytime'];

        if ($payTid == 1)
        {
            //保证金充值
            AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_recharge', [
                'plat' => $plat,
                'uid' => $buyer,
                'amount' => $payAmounts,
                'okey' => $orderList[0]['okey'],
                'qty' => $orderList[0]['qty'],
                'payid' => $payId,
            ]);
        }
        elseif ($payTid == 2)
        {
            /*
             * 处理平台19
             * 1、恢复用户保证金或者免保证金次数
             * 2、支付成功奖励免保次数
             */
            if ($plat == 19)
            {
                //恢复用户保证金或者免保证金次数
                AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_restore', [
                    'okey' => $payOkeys,
                    'src' => 10102,
                ]);

                //支付成功奖励免保次数
                AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_award', [
                    'plat' => $plat,
                    'uid' => $buyer,
                    'payid' => $payId,
                    'paytime' => $payTime,
                    'payodrs' => $payOdrQty,
                    'act' => 'pay',
                ]);

                //投递对应业务节点
                AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                    'node' => SmbNodeKeyData::ODR_PAYMENT_SUCCESS,
                    'args' => ['pid' => $payId],
                ]);
                foreach ($payOkeys as $okey)
                {
                    AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                        'node' => SmbNodeKeyData::ODR_OSTAT_21,
                        'args' => ['okey' => $okey],
                    ]);
                }
            }

            //分割商品ID
            $pidArr = explode(',', $goodsInfo['pidStr']);

            //处理闲鱼拍卖商品（小槌子手机拍卖）
            $this->handleSmbProduct($pidArr);

            //处理闲鱼寄卖商品
            $this->handleXianyuSmbProduct($pidArr);

            //删除未发送的支付提醒消息
            $this->tmpDelMessage($orderList);
        }
    }

    /**
     * 处理smb平台帮卖商品
     * @param array $pids 产品ID
     */
    private function handleSmbProduct(array $pids)
    {
        //数据条件
        $where = [
            'pid' => ['in' => $pids],
            'plat' => 19,
            'recstat' => 63,
            'inway' => 91,
        ];

        //获取闲鱼帮卖商品数据
        $products = PrdProductModel::M()->getList($where, 'pid');
        foreach ($products as $value)
        {
            AmqpQueue::deliver($this->amqp_realtime, 'smb_goods_sold', [
                'pid' => $value['pid'],
                'type' => 1,
            ]);
        }
    }

    /**
     * 处理闲鱼寄卖商品
     * @param array $pids 产品ID
     */
    private function handleXianyuSmbProduct(array $pids)
    {
        //数据条件
        $where = [
            'pid' => ['in' => $pids],
            'plat' => 161,
            'recstat' => 63,
            'inway' => 1611,
        ];

        //获取闲鱼寄卖商品数据
        $products = PrdProductModel::M()->getList($where, 'pid,oid');
        foreach ($products as $value)
        {
            //投递帮卖回款任务队列
            AmqpQueue::deliver($this->amqp_realtime, 'smb_pay_xianyu_seller', [
                'oid' => $value['oid'],
                'saleType' => 1
            ]);
        }
    }

    /**
     * 临时代码（待切换完老系统后，这里要删除）
     * @param array $orderList
     */
    private function tmpDelMessage(array $orderList)
    {
        //提取老系统订单ID
        $_ids = ArrayHelper::map($orderList, '_id', '-1');

        //删除未发送的支付提醒消息
        CrmMessageModel::M()->delete([
            'tid' => 23,
            'stat' => 0,
            'msrc' => $_ids,
        ]);
    }
}