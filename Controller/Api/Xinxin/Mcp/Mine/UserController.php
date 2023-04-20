<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 我的-用户接口
 * @Controller("/sale/api/xinxin/mcp/mine/user")
 * @Middleware(ApiResultFormat::class)
 */
class UserController extends BeanCollector
{
    /**
     * 绑定手机号码
     * @Controller("/bind/mobile")
     * @param Argument $argument
     */
    public function bind_mobile(Argument $argument)
    {

    }

    /**
     * 更新用户信息（头像、昵称）
     * @param Argument $argument
     */
    public function update(Argument $argument)
    {

    }
}