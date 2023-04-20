<?php
namespace App\Module\Sale\Task\Order;

use App\Amqp\ActInterface;
use App\Exception\AppException;
use App\Amqp\AmqpQueue;
use App\Model\Crm\CrmBuyerModel;
use App\Model\Crm\CrmMoneyModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Odr\OdrPaymentModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Sale\Logic\Order\LogisticsLogic;
use App\Module\Smb\Data\SmbNodeKeyData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;
use Swork\Service;

class PaymentTask extends BeanCollector implements ActInterface
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
     * 处理支付成功订单
     * @param array $data
     * @return bool|void
     * @throws
     */
    public function execute(array $data)
    {
        //提取数据
        $tradeno = $data['tradeno'] ?? '';
        $paychn = $data['paychn'] ?? 0;
        $paytype = $data['paytype'] ?? 0;
        $payno = $data['payno'] ?? '';
        $payamt = $data['payamt'] ?? 0;
        $paytime = $data['paytime'] ?? 0;
        $chktime = $data['chktime'] ?? time();
        $chkuser = $data['chkuser'] ?? '';
        $chkrmk = $data['chkrmk'] ?? '';
        $oids = ArrayHelper::toArray($data['paysrcids'] ?? '');

        //加锁防止重复打款
        $lockKey = 'sale_payment_' . $tradeno;
        if (!$this->redis->setnx($lockKey, time(), 60))
        {
            return true;
        }

        //获取支付订单数据
        $info = OdrPaymentModel::M()->getRowById($tradeno, 'pid,plat,buyer,payodrs,payamts,paystat');
        if ($info == false)
        {
            $this->redis->del($lockKey);
            Service::$logger->error('找不到支付单数据', $data);
            return true;
        }

        //检查支付数据
        $checkResult = $this->checkData($info, $tradeno, $payamt, $oids);
        if ($checkResult['errorMsg'] != '')
        {
            $this->redis->del($lockKey);
            Service::$logger->error($checkResult['errorMsg'], $data);

            return true;
        }
        $orders = $checkResult['orders'];

        //公共参数
        $plat = $info['plat'];
        $buyer = $info['buyer'];
        $payodrs = $info['payodrs'];
        $tid = $orders[0]['tid'] ?? 0; //订单类型
        $okey = $orders[0]['okey'] ?? ''; //订单号
        $qty = $orders[0]['qty'] ?? 1; //商品数量

        //更新订单相关数据
        try
        {
            //开启事务
            Db::beginTransaction();

            //支付单更新数据
            $payUpdate = [
                'paychn' => $paychn,
                'paytype' => $paytype,
                'paytime' => $paytime,
                'payno' => $payno,
                'paystat' => 2,
            ];

            //线下支付，补充更新支付单数据
            if ($paytype == 13)
            {
                $payUpdate['chkstat'] = 2;
                $payUpdate['chktime'] = $chktime;
                $payUpdate['chkuser'] = $chkuser;
                $payUpdate['chkrmk'] = $chkrmk;
            }

            //更新支付单数据
            $res = OdrPaymentModel::M()->updateById($tradeno, $payUpdate);
            if ($res == false)
            {
                throw new AppException("商户单号:$tradeno 更新支付单失败", AppException::FAILED_UPDATE);
            }

            //更新订单数据
            if (in_array($tid, [21, 22], 23))
            {
                $ostat = 23;
            }
            else
            {
                $ostat = $tid == 10 ? 13 : 21;
            }
            $res = OdrOrderModel::M()->update(['oid' => ['in' => $oids]], [
                'ostat' => $ostat,
                'paystat' => 3,
                'paychn' => $paychn,
                'paytype' => $paytype,
                'paytime' => $paytime,
                'payno' => $payno,
                'tradeno' => $tradeno,
                'mtime' => time(),
            ]);
            if ($res == false)
            {
                throw new AppException("商户单号:$tradeno 更新订单失败", AppException::FAILED_UPDATE);
            }

            //提交事务
            Db::commit();
        }
        catch (\Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //输出异常日志
            Service::$logger->error($throwable->getMessage(), $data);

            //解锁
            $this->redis->del($lockKey);

            //更新失败，直接返回
            return true;
        }

        //如果是缴纳保证金订单则额外处理
        if (count($oids) == 1 && $tid == 10)
        {
            AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_recharge', [
                'plat' => $plat,
                'uid' => $buyer,
                'amount' => $info['payamts'],
                'okey' => $okey,
                'qty' => $qty,
                'payid' => $tradeno,
            ]);

            //解锁
            $this->redis->del($lockKey);

            return true;
        }

        try
        {
            //更新订单商品相关数据
            $this->handleOdrGoods($orders, $paytime);

            //更新买家销售数据
            $params = [
                'plat' => $plat,
                'buyer' => $buyer,
                'payodrs' => $payodrs,
                'payamt' => $payamt,
                'tradeno' => $tradeno,
                'paytime' => $paytime,
            ];
            $this->updateSellerData($orders, $params);

            //解锁
            $this->redis->del($lockKey);
        }
        catch (\Throwable $throwable)
        {
            $this->redis->del($lockKey);
            Service::$logger->error($throwable->getMessage(), $data);
        }

        //返回
        return true;
    }

    /**
     * 检查数据是否正确
     * @param array $info 支付单
     * @param string $tradeno 交易号
     * @param float $payamt 付款金额
     * @param array $oids 付款订单oid合集
     * @return array
     */
    private function checkData(array $info, string $tradeno, float $payamt, array $oids)
    {
        $errorMsg = '';
        if ($info == false)
        {
            $errorMsg = "商户单号:$tradeno 支付单不存在";
        }

        //检查支付单状态
        if ($info['paystat'] == 2)
        {
            $errorMsg = "商户单号:$tradeno 支付单已支付";
        }

        //检查支付金额是否一致
        if (floatval($info['payamts']) != floatval($payamt))
        {
//            $errorMsg = "商户单号:$tradeno 回调支付金额与支付单金额不一致";
        }

        if ($errorMsg != '')
        {
            //返回
            return [
                'orders' => [],
                'errorMsg' => $errorMsg,
            ];
        }

        //所需字段
        $cols = 'oid,plat,src,buyer,tid,okey,qty,paystat,payamt,recver,rectel,recreg,recdtl,dlyway';

        //获取关联订单
        $orders = OdrOrderModel::M()->getList(['oid' => ['in' => $oids]], $cols);
        if (count($orders) == 0)
        {
            $errorMsg = "商户单号:$tradeno 关联订单不存在";
        }

        //检查订单（只要有其中一个订单有异常就返回）
        foreach ($orders as $key => $item)
        {
            if ($item['paystat'] == 3)
            {
                $errorMsg = "商户单号:$tradeno 关联订单 {$item['okey']} 已完成支付";
                break;
            }
        }

        //返回
        return [
            'orders' => $orders,
            'errorMsg' => $errorMsg,
        ];
    }

    /**
     * 付款完成 更新卖家数据
     * @param array $orders
     * @param array $params
     * @throws
     */
    private function updateSellerData(array $orders, array $params)
    {
        //提取参数
        $plat = $params['plat'];
        $buyer = $params['buyer'];
        $payodrs = $params['payodrs'];
        $payamt = $params['payamt'];
        $tradeno = $params['tradeno'];
        $paytime = $params['paytime'];

        $time = time();
        $crmBuyer = CrmBuyerModel::M()->getRow(['plat' => $plat, 'acc' => $buyer], 'bid,acc,plat,firstodr');
        if ($crmBuyer != false)
        {
            //更新买家信息
            $buyUpdate = [
                'lastodr' => $time,
            ];
            if ($crmBuyer['firstodr'] == 0)
            {
                $buyUpdate['firstodr'] = $time; //首单时间
            }
            CrmBuyerModel::M()->updateById($crmBuyer['acc'], $buyUpdate, [
                'payodrs' => "payodrs + $payodrs",
                'payamts' => "payamts + $payamt",
            ]);
        }

        //新增买家支付流水记录
        $moneys = [];
        foreach ($orders as $key => $item)
        {
            $payamt = $item['payamt'];
            $wid = IdHelper::generate();
            $moneys[] = [
                'wid' => $wid,
                'plat' => $plat,
                'acc' => $buyer,
                'tid' => 11,
                'amts' => -$payamt,
                'okey' => $item['okey'],
                'wtime' => time(),
                'rmk' => "订单支付：$payamt 元",
                '_id' => $wid,
            ];
        }
        if (count($moneys) > 0)
        {
            CrmMoneyModel::M()->inserts($moneys);
        }

        /*
         * 处理平台19
         * 1、恢复用户保证金或者免保证金次数
         * 2、支付成功奖励免保次数
         */
        if ($plat == 19)
        {
            $okeys = ArrayHelper::map($orders, 'okey', '-1');

            //恢复用户保证金或者免保证金次数
            AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_restore', ['okey' => $okeys, 'src' => 10102]);

            //支付成功奖励免保次数
            AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_award', [
                'plat' => $plat,
                'uid' => $buyer,
                'payid' => $tradeno,
                'paytime' => $paytime,
                'payodrs' => $payodrs,
                'act' => 'pay',
            ]);

            //投递对应业务节点
            AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                'node' => SmbNodeKeyData::ODR_PAYMENT_SUCCESS,
                'args' => ['pid' => $tradeno],
            ]);
            foreach ($okeys as $okey)
            {
                AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                    'node' => SmbNodeKeyData::ODR_OSTAT_21,
                    'args' => ['okey' => $okey],
                ]);
            }
        }
    }

    /**
     * 1、更新订单商品销售数据
     * 2、生成物流发货单
     * @param array $orders 订单信息
     * @param int $paytime 支付信息
     */
    private function handleOdrGoods(array $orders, int $paytime)
    {
        $time = time();

        //获取订单商品数据
        $okeys = ArrayHelper::map($orders, 'okey', '-1');
        $goods = OdrGoodsModel::M()->getList(['okey' => ['in' => $okeys], 'rtntype' => 0], 'gid,plat,okey,tid,pid,yid,sid,bprc,profit1');
        if (count($goods) == false)
        {
            //输出异常日志
            Service::$logger->error('关联商品不存在 订单号：' . implode("','", $okeys));

            return;
        }

        //提取产品ID
        $pids = ArrayHelper::map($goods, 'pid', '-1');
        $sids = ArrayHelper::map($goods, 'sid', '-1');

        //更新订单商品数据
        OdrGoodsModel::M()->update(['okey' => ['in' => $okeys], 'rtntype' => 0], ['ostat' => 21, 'paytime' => $paytime, 'mtime' => $time]);

        //更新产品销售数据
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], [
            'saletime' => $paytime,
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
        $recregDict = array_column($orders, 'recreg', 'okey');

        //更新供应库销售数据
        $batchUpdateSupply = [];
        foreach ($goods as $key => $value)
        {
            $saleprvid = 0;
            $salecityid = 0;
            $saleareaid = $recregDict[$value['okey']] ?? 0;
            if ($saleareaid > 0)
            {
                $saleprvid = str_pad(substr($saleareaid, 0, 2), strlen($saleareaid), 0);
                $salecityid = str_pad(substr($saleareaid, 0, 4), strlen($saleareaid), 0);
            }

            //组装批量更新数据
            $batchUpdateSupply[] = [
                'sid' => $value['yid'],
                'salestat' => 2,
                'salechn' => $value['plat'],
                'saleway' => $value['tid'],
                'saleamt' => $value['bprc'],
                'saletime' => $paytime,
                'profit' => $value['profit1'],
                'saleprvid' => $saleprvid,
                'salecityid' => $salecityid,
                'saleareaid' => $saleareaid,
            ];
        }
        PrdSupplyModel::M()->inserts($batchUpdateSupply, true);

        //生成物流发货单
        $this->logisticsLogic->create($orders);

        //处理smb平台帮卖商品
        $this->handleSmbProduct($pids);

        //处理闲鱼寄卖商品
        $this->handleXianyuSmbProduct($pids);

        //处理一口价商品
        $this->handleXinxinShopSales($sids);
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
     * 处理一口价商品
     * @param array $sids
     */
    private function handleXinxinShopSales(array $sids)
    {
        PrdShopSalesModel::M()->update(['sid' => $sids], ['stat' => 33]);
    }
}