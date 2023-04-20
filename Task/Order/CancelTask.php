<?php
namespace App\Module\Sale\Task\Order;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmBuyerModel;
use App\Model\Dnet\CrmMessageModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdGoodsModel;
use App\Model\Prd\PrdOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Api\Data\V2\FixedData;
use App\Module\Pub\Data\SysConfData;
use App\Module\Smb\Data\SmbNodeKeyData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Service;

/**
 * 取消超时未支付的订单，并且扣除保证金
 * @package App\Module\Sale\Task\Order
 */
class CancelTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject()
     * @var CrmMessageModel
     */
    private $oldCrmMessageModel;

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
     * 处理超时未支付的订单
     * @param array $data
     * @return bool|void
     * @throws
     */
    public function execute(array $data)
    {
        try
        {
            $this->handleTask(19);
            $this->handleTask(21);
            $this->handleTask(21, 12);
            $this->handleTask(22);
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage());
        }
        return true;
    }

    /**
     * 处理取消订单任务
     * @param int $plat
     * @param int $tid 11：竞拍订单  12：一口价订单
     */
    private function handleTask(int $plat, int $tid = 11)
    {
        //获取超时时间
        $timeout = SysConfData::D()->get($plat, 'orderTimeoutCancelTime');
        $timeout = $timeout ?: 43200;

        //获取一天范围的数据
        $time = time();
        $etime = $time - $timeout;
        if ($tid == 12)
        {
            $etime = $time - FixedData::SHOP_SALES_CANCEL_TIME;
        }
        $stime = $etime - 86400;

        //超时数据条件
        $where = [
            'plat' => $plat,
            'tid' => $tid,
            'ostat' => 11,
            'otime' => ['between' => [$stime, $etime]],
        ];

        //获取超时未支付的订单
        $orders = OdrOrderModel::M()->getList($where, 'okey', ['otime' => 1], 50);
        if ($orders == false)
        {
            return;
        }

        //处理取消订单逻辑
        $cancelledOrders = $this->handleCancelOrder($orders);
        if ($cancelledOrders == false)
        {
            return;
        }

        //处理商品相关逻辑
        $this->handleProductData($cancelledOrders);

        //处理保证金相关逻辑
        $this->handleDepositData($cancelledOrders);

        //更新买家取消订单数、商品数
        $this->updateBuyerData($cancelledOrders);

        //新增取消订单消息数据
        if (in_array($plat, [21, 22]))
        {
            $this->tmpSaveMessageNotice($cancelledOrders);
        }
var_dump(8888888,$cancelledOrders);
        //投递对应业务节点
        if ($plat == 19 || $plat == 21)
        {
            foreach ($cancelledOrders as $order)
            {
                AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                    'node' => SmbNodeKeyData::ODR_OSTAT_51,
                    'args' => ['okey' => $order['okey']]
                ]);
            }
        }
    }

    /**
     * 处理取消订单逻辑
     * @param array $orders 超时未支付的订单
     * @return array
     */
    private function handleCancelOrder(array $orders)
    {
        $okeys = [];
        foreach ($orders as $order)
        {
            $okey = $order['okey'];

            //检查是否正在支付（调支付时写入）
            if ($this->redis->exists("odr_order_paying_$okey"))
            {

                continue;
            }

            //写入可取消数组
            $okeys[] = $okey;
        }

        //数据条件
        $where = ['okey' => $okeys, 'ostat' => 11];

        //更新订单数据
        OdrOrderModel::M()->update($where, [
            'ostat' => 51,
            'paystat' => 0,
            'otime51' => time(),
            'cway' => 1,
            'rmk2' => '超时未支付，系统自动取消订单'
        ]);
        OdrGoodsModel::M()->update($where, [
            'ostat' => 51,
            'rtntype' => 3,
            'rtntime' => time(),
        ]);

        //替换条件
        $where['ostat'] = 51;

        //获取成功取消的订单
        return OdrOrderModel::M()->getList($where, 'oid,plat,tid,src,okey,buyer,qty,oamt,_id');
    }

    /**
     * 处理商品相关逻辑
     * @param array $orders 超时未支付的订单
     */
    private function handleProductData(array $orders)
    {
        //提取订单号
        $okeys = ArrayHelper::map($orders, 'okey');
        $tid = $orders[0]['tid'] ?? 11;

        //获取订单商品数据
        $goods = OdrGoodsModel::M()->getList(['okey' => ['in' => $okeys], 'tid' => $tid], 'pid,sid');
        if ($goods == false)
        {
            return;
        }

        //提取商品ID
        $pids = ArrayHelper::map($goods, 'pid');

        //获取商品数据
        $products = PrdProductModel::M()->getList(['pid' => ['in' => $pids]], 'pid,plat,recstat,oid,_id');
        if ($products == false)
        {
            return;
        }

        //更新商品状态为超时取消(一口价订单除外)
        if ($tid != 12)
        {
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 34, 'stctime' => time()]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'prdstat' => 32], ['prdstat' => 34]);
        }

        $smbOrders = []; //小槌子手机拍卖的订单id
        $smbXianyuProducts = []; //闲鱼寄卖的商品id

        //提取所需要的参数
        foreach ($products as $value)
        {
            if ($value['plat'] == 19 && in_array($value['recstat'], [61, 62, 63, 64]))
            {
                $smbOrders[] = $value['oid'];
            }
            if ($value['plat'] == 161 && in_array($value['recstat'], [61, 62, 63, 64]))
            {
                $smbXianyuProducts[] = $value['pid'];
            }
        }

        //小槌子手机拍卖
        if (count($smbOrders) > 0)
        {
            //数据条件
            $where = ['oid' => ['in' => $smbOrders]];
            $time = time();

            //更新相关数据为待销售
            PrdOrderModel::M()->update($where, [
                'recstat' => 61,
                'rectime61' => $time,
                'rectime62' => 0,
                'rectime63' => 0,
                'rectime64' => 0,
            ]);
            PrdGoodsModel::M()->update($where, [
                'recstat' => 61,
                'rectime61' => $time,
                'rectime62' => 0,
                'rectime63' => 0,
                'rectime64' => 0,
            ]);
            PrdProductModel::M()->update($where, [
                'recstat' => 61,
                'rectime61' => $time,
            ]);

            //投递对应业务节点
            foreach ($smbOrders as $value)
            {
                AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                    'node' => SmbNodeKeyData::PRD_RECSTAT_63_61,
                    'args' => ['oid' => $value]
                ]);
            }
        }

        //闲鱼寄卖 - 超时未付款兜底
        foreach ($smbXianyuProducts as $value)
        {
            AmqpQueue::deliver($this->amqp_realtime, 'smb_xianyu_goods_sold', [
                'pid' => $value,
                'type' => 3,
            ]);
        }

        //新新一口价 - 恢复一口价商品为销售中
        if ($tid == 12)
        {
            $sids = ArrayHelper::map($goods, 'sid');
            PrdShopSalesModel::M()->update(['sid' => ['in' => $sids]], [
                'stat' => 31,
                'luckbuyer' => '',
                'lucktime' => 0,
                'luckname' => '',
                'luckrgn' => 0,
                'luckodr' => '',
                'mtime' => time()
            ]);
        }
    }

    /**
     * 处理保证金相关数据
     * @param array $orders 超时未支付的订单
     */
    private function handleDepositData(array $orders)
    {
        $srcDict = [
            1101 => 1,
            1102 => 2,
            1103 => 3,
        ];
        $buyerOkeysDict = [];
        foreach ($orders as $value)
        {
            $plat = $value['plat'];
            $buyer = $value['buyer'];
            $okey = $value['okey'];

            //扣除保证金来源ID
            $src = $srcDict[$value['src']] ?? 0;

            //根据平台处理逻辑
            if ($plat == 19)
            {
                $buyerOkeysDict[$buyer][] = $okey;
            }
            if (in_array($plat, [19, 21, 22]))
            {
                //写入扣除保证金队列
                AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_deduction', [
                    'plat' => $plat,
                    'buyer' => $buyer,
                    'okey' => $okey,
                    'src' => $src,
                ]);
            }
        }

        //写入重置免保证金数量队列
        foreach ($buyerOkeysDict as $buyer => $okeys)
        {
            AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_reset', [
                'plat' => 19,
                'uid' => $buyer,
                'okeys' => $okeys,
            ]);
        }
    }

    /**
     * 更新买家取消数据
     * @param array $orders 超时未支付的订单
     */
    private function updateBuyerData(array $orders)
    {
        foreach ($orders as $order)
        {
            CrmBuyerModel::M()->increase(['acc' => $order['buyer'], 'plat' => $order['plat']], [
                'canodrs' => 1,
                'canprds' => $order['qty'],
            ]);
        }
    }

    /**
     * 新增取消订单消息数据（迁移完老系统要重构这）
     * @param array $orders 超时未支付的订单
     */
    private function tmpSaveMessageNotice(array $orders)
    {
        $time = time();
        $messageData = [];
        $sendData = [
            'tid' => 24,
            'mtime' => $time,
            'stat' => 0
        ];

        //提取用户表字典
        $buyers = ArrayHelper::map($orders, 'buyer');
        $accDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $buyers]], 'aid,_id');

        //组装消息数据
        foreach ($orders as $value)
        {
            $plat = $value['plat'];
            $okey = $value['okey'];
            $oamt = $value['oamt'];

            //平台转换
            $platform = 1;
            if ($plat == 22)
            {
                $platform = 2;
            }
            if ($plat == 19)
            {
                $platform = 3;
            }

            //转换旧表数字ID
            $oldBuyer = $accDict[$value['buyer']]['_id'] ?? 0;
            if (!is_numeric($oldBuyer))
            {
                continue;
            }

            //组装发送消息列表
            $sendData['platform'] = $platform;
            $sendData['acc'] = $oldBuyer;
            $sendData['data'] = json_encode(['okey' => $okey, 'oamt' => $oamt, 'ctime' => $time]);
            $sendData['msrc'] = $value['_id'];

            //补充批量新增数组
            $messageData[] = $sendData;
        }

        //老系统批量插入消息数据
        if (count($messageData))
        {
            $this->oldCrmMessageModel::M()->inserts($messageData);
        }
    }
}