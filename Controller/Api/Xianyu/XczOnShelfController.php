<?php
namespace App\Module\Sale\Controller\Api\Xianyu;

use App\Amqp\AmqpQueue;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use App\Middleware\ApiResultFormat;
use Swork\Client\Amqp;
use Swork\Server\Http\Argument;

/**
 * 旧系统非良品端上架信息同步到闲鱼(临时使用 - 旧系统非良品端停用后废弃)
 * @Controller("/sale/api/xianyu/xczonshelf")
 * @Middleware(ApiResultFormat::class)
 */
class XczOnShelfController extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 同步非良品上架信息
     * @return mixed
     */
    public function sync(Argument $argument)
    {
        $sid = $argument->get('sid', '');

        AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_join_round', [
            'sid' => $sid
        ]);
    }
}