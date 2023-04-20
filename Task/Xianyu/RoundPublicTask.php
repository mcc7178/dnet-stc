<?php

namespace App\Module\Sale\Task\Xianyu;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdWaterModel;
use App\Module\Sale\Logic\Backend\Bid\BidRoundLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Helper\IdHelper;
use Swork\Service;

/**
 * 闲鱼寄卖 内部场+特价场次公开任务
 * @package App\Module\Sale\Task\Xianyu
 */
class RoundPublicTask extends BeanCollector implements ActInterface
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
    private $confirmLater = 3600;

    /**
     * @Inject()
     * @var BidRoundLogic
     */
    private $bidRoundLogc;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     */
    function execute(array $data)
    {
        try
        {
            //检查场次数据（内部场）
            $this->checkData1();

            //检查场次数据（特价场）
            $this->checkData2();
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage(), [__CLASS__]);
        }

        //返回
        return true;
    }

    /**
     * 检查一个小时后开拍的闲鱼寄卖内部场次商品
     * @param string $sid
     * @throws
     */
    private function checkData1()
    {
        $stime = time();
        $etime = time() + $this->confirmLater;

        //获取一个小时后的 && 闲鱼寄卖 && 待开场 && 暗拍 场次商品数据
        $where = [
            'plat' => ['in' => [21, 22]],
            'inway' => 1611,
            'stat' => 11,
            'infield' => 1,
            'stime' => ['between' => [$stime, $etime]],
        ];
        $sales = PrdBidSalesModel::M()->getList($where, 'sid,pid,rid');
        if (empty($sales))
        {
            return;
        }

        foreach ($sales as $value)
        {
            //同步场次信息到闲鱼
            AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_join_round', [
                'sid' => $value['sid']
            ]);

            //标记场次是否已处理过
            if ($this->redis->setnx('sale_xianyu_round_xcz_public_' . $value['rid'], 1, 300) == false)
            {
                continue;
            }

            try
            {
                //公开场次
                $this->bidRoundLogc->changeStat($value['rid'], '', 0, 1);
            }
            catch (\Throwable $throwable)
            {
                Service::$logger->error($throwable->getMessage(), [__CLASS__]);

                $data = [
                    'wid' => IdHelper::generate(),
                    'tid' => 201,
                    'oid' => '',
                    'pid' => $value['pid'],
                    'rmk' => "场次公开失败：" . $throwable->getMessage(),
                    'atime' => time(),
                ];
                PrdWaterModel::M()->insert($data);
            }
        }
    }

    /**
     * 检查即将公开的特价商品
     * @param string $sid
     * @throws
     */
    private function checkData2()
    {
        $stime = time();
        $etime = time() + 60;

        //获取特价数据
        $where = [
            'plat' => 22,
            'inway' => 1611,
            'stat' => ['in' => [11, 12]],
            'infield' => 0,
            'tid' => 2,
            'ptime' => ['between' => [$stime, $etime]],
        ];
        $sales = PrdBidSalesModel::M()->getList($where, 'sid,pid,rid');
        if (empty($sales))
        {
            return;
        }

        foreach ($sales as $value)
        {
            //同步场次信息到闲鱼 (竞拍中)
            AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_round_start', [
                'sid' => $value['sid']
            ]);

            PrdBidSalesModel::M()->updateById($value['sid'], [
                'stat' => 13,
            ]);
        }

        //商品加入，且满足公开条件时，公开场次
        $rids = array_column($sales, 'rid');
        PrdBidRoundModel::M()->update(['rid' => ['in' => $rids], 'stat' => ['in' => [11, 12]]], ['stat' => 13]);
    }
}