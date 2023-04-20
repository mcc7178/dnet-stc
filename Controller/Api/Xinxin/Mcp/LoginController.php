<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 用户登录接口
 * @Controller("/sale/api/xinxin/mcp/login")
 * @Middleware(ApiResultFormat::class)
 */
class LoginController extends BeanCollector
{
    /**
     * @param Argument $argument
     */
    public function index(Argument $argument)
    {

    }
}