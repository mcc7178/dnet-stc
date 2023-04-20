<?php

namespace App\Module\Sale\Task\Xianyu;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Module\Sale\Data\XchuiziDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Service;

/**
 * 同步场次信息节点：
 * 1、卖家确认寄卖后一个小时同步场次信息
 * 2、如果商品所在的场次已经公开，场次信息还未同步，则在公开场次的同时推送场次信息
 * @package App\Module\Sale\Task\Xianyu
 */
class XianyuConfirmSmbTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 确认寄卖后延迟多长时间同步 单位：秒
     * @var int
     */
    private $confirmLater = 60;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     */
    function execute(array $data)
    {
        try
        {
            $this->checkData();
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage(), [__CLASS__]);
        }

        //返回
        return true;
    }

    /**
     * 检查卖家确认竞拍后一个的数据
     * @param string $sid
     * @throws
     */
    private function checkData()
    {
        $etime = time() - $this->confirmLater;
        $stime = $etime - 3600;

        //获取一个小时前的确认竞拍数据
        $where = [
            'plat' => 161,
            'recstat' => 61,
            'rectime61' => ['between' => [$stime, $etime]],
        ];
        $orderDict = PrdOrderModel::M()->getDict('oid', $where, 'oid,rectime61');
        if (empty($orderDict))
        {
            return;
        }

        //检查订单是否已处理过
        $oids = [];
        foreach ($orderDict as $value)
        {
            if (!$this->redis->exists('xianyu_confirm_smb_' . $value['oid']))
            {
                $oids[] = $value['oid'];
            }
        }
        if (empty($oids))
        {
            return;
        }

        if (XchuiziDictData::XYU_ROUND_RULE_TYPE == 1)
        {
            //获取订单对应商品数据 - 不良品单独任务处理
            $where = [
                'oid' => ['in' => $oids],
                'level' => ['<' => 40],
            ];
        }
        else
        {
            $where = [
                'oid' => ['in' => $oids],
            ];
        }
        $products = PrdProductModel::M()->getList($where, 'oid,pid,bid,mid,level');
        if (empty($products))
        {
            return;
        }
        $pids = ArrayHelper::map($products, 'pid');

        //获取场次商品字典
        $saleDict = PrdBidSalesModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'inway' => 1611], 'sid,pid');
        foreach ($products as $value)
        {
            $oid = $value['oid'];
            $sid = $saleDict[$value['pid']]['sid'] ?? '';
            if ($sid == '')
            {
                continue;
            }

            //同步场次信息到闲鱼
            AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_join_round', [
                'sid' => $sid
            ]);

            //标记订单已处理
            if ($this->redis->setnx('xianyu_confirm_smb_' . $oid, time(), 7200) == false)
            {
                continue;
            }
        }
    }
}