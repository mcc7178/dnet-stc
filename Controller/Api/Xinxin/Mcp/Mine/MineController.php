<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 我的-个人中心接口
 * @Controller("/sale/api/xinxin/mcp/mine")
 * @Middleware(ApiResultFormat::class)
 */
class MineController extends BeanCollector
{
    /**
     * @param Argument $argument
     */
    public function index(Argument $argument)
    {

    }
}