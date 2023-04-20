<?php

namespace App\Module\Sale\Task\Xinxin;

use App\Model\Crm\CrmBuyerModel;
use App\Model\Crm\CrmMoneyModel;
use App\Model\Dnet\CrmMessageModel;
use App\Model\Odr\OdrOrderModel;
use App\Module\Sale\Data\XinxinDictData;
use App\Module\Sale\Logic\Xinxin\XinXinGroupBuyLogic;
use App\Params\RedisKey;
use App\Service\Acc\AccUserInterface;
use App\Service\Crm\CrmBuyerInterface;
use App\Traits\RedisQueueTrait;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\Annotation\TimerTask;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;

/**
 * 拼团活动相关业务定时任务
 * @package App\Task
 */
class XinxinGroupBuyTask extends BeanCollector
{
    /**
     * 引入Redis队列
     */
    use RedisQueueTrait;

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject()
     * @var XinXinGroupBuyLogic
     */
    private $xinXinGroupBuyLogic;

    /**
     * @Reference()
     * @var CrmBuyerInterface
     */
    private $crmBuyerInterface;

    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * 处理拼团活动状态变更任务（每1秒运行）
     * @TimerTask(1000)
     */
    public function processGroupBuyStatChange()
    {
        $this->xinXinGroupBuyLogic->groupBuyStatChange();
    }

    /**
     * 处理拼团活动服务通知任务（每1秒运行）
     * @TimerTask(1000)
     */
    public function processGroupBuyNotify()
    {
        $this->popQueueDeliverTask($this->redis, RedisKey::SALE_GROUP_BUY_NOTIFY_PROCESS_3, XinXinGroupBuyLogic::class, 'processGroupBuyNotify3');
        $this->popQueueDeliverTask($this->redis, RedisKey::SALE_GROUP_BUY_NOTIFY_PROCESS_4, XinXinGroupBuyLogic::class, 'processGroupBuyNotify4');
    }

    /**
     * 发送拼团活动服务通知任务（每3秒运行）
     * @TimerTask(3000)
     */
    public function sendGroupBuyNotify()
    {
        $this->popQueueDeliverTask($this->redis, RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_1, XinXinGroupBuyLogic::class, 'sendGroupBuyNotify1');
        $this->popQueueDeliverTask($this->redis, RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_2, XinXinGroupBuyLogic::class, 'sendGroupBuyNotify2');
        $this->popQueueDeliverTask($this->redis, RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_3, XinXinGroupBuyLogic::class, 'sendGroupBuyNotify3');
        $this->popQueueDeliverTask($this->redis, RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_4, XinXinGroupBuyLogic::class, 'sendGroupBuyNotify4');
    }

    /**
     * 取消超时未支付的订单
     * @TimerTask(1000)
     */
    public function cancelGroupBuyOrder()
    {
        //获取团购取消时间配置项
        $cancelTime = XinxinDictData::GROUP_ORDER_CANCEL_TIME['order'];

        //计算查询时间范围
        $time = time();
        $stime = $time - 86400;
        $etime = $time - $cancelTime;

        //获取所有未支付的团购订单
        $where = ['tid' => 13, 'plat' => 21, 'ostat' => 11, 'paystat' => 1, 'otime' => ['between' => [$stime, $etime]]];
        $orders = OdrOrderModel::M()->getList($where, 'oid,okey,buyer,groupbuy,_id', ['otime' => -1]);
        if (!$orders)
        {
            return;
        }

        //提取字段
        $oids = ArrayHelper::map($orders, 'oid');
        $accs = ArrayHelper::map($orders, 'buyer');

        //获取用户字典
        $accDict = $this->accUserInterface->getAccDict($accs, 'aid,_id');

        //获取保证金字典
        $depositDict = CrmBuyerModel::M()->getDict('acc', ['plat' => 21, 'acc' => ['in' => $accs]], 'acc,deposit');

        //取消拼团订单
        OdrOrderModel::M()->update(['tid' => 13, 'plat' => 21, 'oid' => ['in' => $oids]], ['ostat' => 51, 'paystat' => 0]);

        //扣除保证金
        OdrOrderModel::M()->update(['buyer' => ['in' => $accs], 'tid' => 10, 'ostat' => 1], ['ostat' => 52]);

        //处理超时订单
        $moneyData = [];
        $msgData = [];
        foreach ($orders as $value)
        {
            //提取参数
            $oid = $value['oid'];
            $okey = $value['okey'];
            $acc = $value['buyer'];
            $oldAcc = isset($accDict[$acc]) ? $accDict[$acc]['_id'] : 0;
            $deposit = isset($depositDict[$acc]) ? $depositDict[$acc]['deposit'] : 0;

            //写入保证金流水
            if ($deposit > 0)
            {
                //资金流水数据
                $wid = IdHelper::generate();
                $moneyData[] = [
                    'wid' => $wid,
                    'plat' => 21,
                    'acc' => $acc,
                    'tid' => 23,
                    'amts' => -$deposit,
                    'okey' => $oid,
                    'wtime' => $time,
                    'rmk' => "拼团单号：{$value['groupbuy']},订单号:$okey 共扣除保证金: {$deposit}元",
                    '_id' => $wid,
                ];
            }

            //消息通知数组
            $msgData[] = [
                'platform' => 1,
                'acc' => $oldAcc,
                'tid' => 24,
                'data' => '',
                'msrc' => $value['_id'],
                'mtime' => $time,
                'stat' => 0
            ];
        }

        //扣除保证金
        CrmBuyerModel::M()->update(['acc' => ['in' => $accs]], ['deposit' => 0]);

        //写入资金流水
        if ($moneyData)
        {
            CrmMoneyModel::M()->inserts($moneyData);
        }

        //写入消息数据
        if ($msgData)
        {
            CrmMessageModel::M()->inserts($msgData);
        }
    }
}