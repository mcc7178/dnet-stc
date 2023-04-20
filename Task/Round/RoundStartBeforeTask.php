<?php

namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Crm\CrmMessageModel;
use App\Model\Crm\CrmRemindModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Module\Smb\Data\SmbNodeKeyData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;

/**
 * 竞拍场次结束前处理任务
 * 注意：因新老系统过渡，当前任务是直接操作老系统的数据库
 * @package App\Module\Sale\Task\Round
 */
class RoundStartBeforeTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject("amqp_message_task")
     * @var Amqp
     */
    private $amqp_message;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     */
    function execute(array $data)
    {
        //加锁，防止重复执行
        $lockKey = 'round_start_before_task_lock';
        if ($this->redis->setnx($lockKey, time(), 10))
        {
            return true;
        }

        //获取5分钟后开拍的场次
        $where = [
            'stime' => ['>=' => (time() + 300)],
            'stat' => 12,
        ];
        $rounds = PrdBidRoundModel::M()->getList($where, 'rid,rname,plat,stime,etime,mode,_id', ['stime' => 1]);var_dump(66666,$rounds);
        foreach ($rounds as $round)
        {
            if ($round['plat'] == 21)
            {
                $this->sendXinXinRoundStartRemind($round);
            }
            $this->sendSmbRoundStartRemind($round);
        }

        //解锁
        $this->redis->del($lockKey);

        //返回
        return true;
    }

    /**
     * 发送新新场次开场提醒通知
     * @param array $round
     */
    public function sendXinXinRoundStartRemindOld(array $round)
    {
        $today = strtotime(date('Y-m-d 00:00:00'));
        $time = time();

        /*
         * 获取设置开场提醒的用户
         * 1：仅获取当天没有提醒过的用户
         * 2：每次取200条，避免以后有大量用户设置了提醒
         */
        $remindSize = 200;
        $remindWhere = [
            'rway' => 1,
            'tid' => 1,
            'atv' => 1,
            'stime' => ['<' => $today],
        ];
        $remindList = CrmRemindModel::M()->getList($remindWhere, '*', ['stime' => 1, 'rid' => -1], $remindSize);
        if ($remindList == false)
        {
            return;
        }

        //获取当前场次上架商品数
        $count = PrdBidSalesModel::M()->getCount(['rid' => $round['rid']]);

        //消息数据
        $data = [
            'rname' => $round['rname'],
            'stime' => $round['stime'],
            'etime' => $round['etime'],
            'num' => $count,
        ];
        $data = json_encode($data, JSON_UNESCAPED_UNICODE);

        //提醒时间（开场前5分钟提醒）
        $remindTime = $round['stime'] - 300;

        //组装数据
        $msgData = [];
        foreach ($remindList as $value)
        {
            $msgData[] = [
                'platform' => 1,
                'acc' => $value['acc'],
                'tid' => 11,
                'data' => $data,
                'msrc' => $round['_id'],
                'mtime' => $remindTime,
                'stat' => 0,
            ];
        }

        //批量写入数据
        CrmMessageModel::M()->inserts($msgData);

        //更新当前批量用户已添加提醒（防止重复发送）
        $remindIds = array_column($remindList, 'rid');
        CrmRemindModel::M()->update(['rid' => ['in' => $remindIds]], ['stime' => $time]);
    }

    /**
     * 发送小槌子手机拍卖场次开场提醒通知
     * @param array $round
     */
    public function sendSmbRoundStartRemind(array $round)
    {
        $rid = $round['rid'];
        $rname = $round['rname'];
        $stime = $round['stime'];
        $mode = $round['mode'];
        $time = time();
        $lockTime = $stime - $time + 10;

        //检查是否已经处理过
        if ($this->redis->setnx("send_smb_round_start_remind_$rid", $time, $lockTime) == false)
        {
            return;
        }

        //获取关注当前场次的用户
        $favorites = PrdBidFavoriteModel::M()->getList(['rid' => $rid], 'distinct buyer');
        foreach ($favorites as $value)
        {
            AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                'node' => SmbNodeKeyData::BID_ROUND_START_BEFORE_5M,
                'args' => [
                    'rid' => $rid,
                    'rname' => $rname,
                    'mode' => $mode,
                    'buyer' => $value['buyer'],
                ]
            ]);
        }
    }

    /**
     * 发送新新快拍场次开场提醒通知
     * @param array $round
     */
    public function sendXinXinRoundStartRemind(array $round)
    {
        $rid = $round['rid'];
        $rname = $round['rname'];
        $stime = $round['stime'];
        $time = time();
        $lockTime = $stime - $time + 10;

        //检查是否已经处理过
        if ($this->redis->setnx("send_xinxin_round_start_remind_$rid", $time, $lockTime) == false)
        {
            return;
        }

        /*
         * 获取设置开场提醒的用户
         * 1：仅获取当天没有提醒过的用户
         * 2：每次取200条，避免以后有大量用户设置了提醒
         */
        $remindWhere = [
            'plat' => 21,
            'rway' => 1,
            'rtype' => 101,
            'isatv' => 1,
            'rtime' => 0
        ];
        $remindList = CrmRemindModel::M()->getList($remindWhere, '*', ['rid' => -1]);
        if ($remindList == false)
        {
            return;
        }

        //获取当前场次上架商品数
        $count = PrdBidSalesModel::M()->getCount(['rid' => $round['rid']]);

        //更新当前批量用户已添加提醒（防止重复发送）
        $remindIds = array_column($remindList, 'rid');
        //CrmRemindModel::M()->update(['rid' => ['in' => $remindIds]], ['rtime' => $time]);

        //投递发送任务
        foreach ($remindList as $value)
        {
            AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                'node' => SmbNodeKeyData::BID_ROUND_START_BEFORE_5M,
                'args' => [
                    'rid' => $rid,
                    'rname' => $rname,
                    'acc' => $value['acc'],
                    'stime' => $round['stime'],
                    'etime' => $round['etime'],
                    'num' => $count,
                    'remindId' => $value['rid']
                ]
            ]);
        }
    }
}