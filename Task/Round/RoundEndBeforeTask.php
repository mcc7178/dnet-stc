<?php

namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use Swork\Bean\BeanCollector;

/**
 * 竞拍场次结束前处理任务
 * @package App\Module\Sale\Task\Round
 */
class RoundEndBeforeTask extends BeanCollector implements ActInterface
{
    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     */
    function execute(array $data)
    {
//        echo 'App\Module\Sale\Task\RoundEndBeforeTask' . PHP_EOL;
        return true;
    }
}