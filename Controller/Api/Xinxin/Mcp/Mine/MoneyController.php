<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 资金流水接口
 * @Controller("/sale/api/xinxin/mcp/mine/money")
 * @Middleware(ApiResultFormat::class)
 */
class MoneyController extends BeanCollector
{
    /**
     * 资金流水翻页列表
     * @param Argument $argument
     */
    public function pager(Argument $argument)
    {

    }
}