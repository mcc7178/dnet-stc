<?php

namespace App\Module\Sale\Task;

use App\Model\Crm\CrmPurchaseModel;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\TimerTask;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;

/**
 * 定期清理已过期的需求任务
 * @package App\Task
 */
class PcsExpireTask extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 过期采购单自动变更状态
     * @TimerTask(10000)
     */
    public function process()
    {
        $time = time();
        $lockKey = 'pcs_update_expired_' . date('Ymd');

        //加锁，每天只执行一次
        $lock = $this->redis->setnx($lockKey, $time, 86400);
        if ($lock == false)
        {
            return;
        }

        //数据条件
        $where = [
            'expired' => ['<' => $time],
            'stat' => 1,
            'pcsstat' => 1,
        ];

        //更新数据
        $data = [
            'stat' => 2,
            'mtime' => $time
        ];
        CrmPurchaseModel::M()->update($where, $data);
    }
}