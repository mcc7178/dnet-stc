<?php

namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Dnet\PrdFavoriteModel;
use App\Model\Dnet\PrdRoundModel;
use App\Model\Dnet\PrdSalesModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Service;

/**
 * 竞拍场次结束后处理任务
 * @package App\Module\Sale\Task\Round
 */
class RoundEndAfterTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject("amqp_realtime_task")
     * @var Amqp
     */
    private $amqp;

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     * @throws
     */
    function execute(array $data)
    {
        try
        {
            $time = time();

            //加锁，锁定1秒
            $lockKey = "round_end_after_$time";
            if ($this->redis->setnx($lockKey, $time, 1) == false)
            {
                return true;
            }

            //数据条件
            $where = [
                'stat' => 13,
                'etime' => ['between' => [$time - 3600, $time]]
            ];

            //获取需要结束的场次数据
            $rounds = PrdBidRoundModel::M()->getList($where, 'rid,plat,mode,_id');
            if ($rounds == false)
            {
                return true;
            }

            //提取场次ID
            $rids = array_column($rounds, 'rid');

            Service::$logger->info(__CLASS__ . '-[新系统场次结束]', $rids);

            //更新场次相关数据竞拍状态
            $updateWhere = ['rid' => ['in' => $rids]];
            PrdBidRoundModel::M()->update($updateWhere, ['stat' => 14, 'ord' => 0]);
            PrdBidSalesModel::M()->update($updateWhere, ['isatv' => 0], ['stat' => '(case when bprc>=sprc then 21 else 22 end)']);
            PrdBidFavoriteModel::M()->update($updateWhere, ['stat' => 14]);

            //同步老系统开场（用于新老系统过渡）
            $this->syncOldRoundData($rounds);

            //写入场次结束处理队列
            foreach ($rids as $rid)
            {
                $data = ['rid' => $rid];
                AmqpQueue::deliver($this->amqp, 'sale_round_end_order', $data);
                AmqpQueue::deliver($this->amqp, 'sale_round_end_process', $data);
            }

            //解锁
            $this->redis->del($lockKey);
        }
        catch (\Throwable $throwable)
        {
            //输出错误日志
            Service::$logger->error($throwable->getMessage());
        }

        //返回
        return true;
    }

    /**
     * 用于新老系统过渡
     * 迁移完老系统可删除这段代码
     * @param $rounds
     */
    private function syncOldRoundData($rounds)
    {
        //提取场次ID
        $rids = array_column($rounds, '_id');

        //打印日志
        Service::$logger->info(__CLASS__ . '-[老系统场次结束]', $rids);

        //更新场次相关数据为竞拍中
        $updateWhere = ['rid' => ['in' => $rids], 'stat' => 13];
        PrdRoundModel::M()->update($updateWhere, ['stat' => 14, 'ord' => 0]);
        PrdSalesModel::M()->update($updateWhere, [], ['stat' => '(case when bprc>=sprc then 21 else 22 end)']);

        //更新商品关注数据为竞拍中
        $updateWhere = ['rid' => ['in' => $rids], 'stat' => 2];
        PrdFavoriteModel::M()->update($updateWhere, ['stat' => 3]);
    }
}