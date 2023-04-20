<?php
namespace App\Module\Sale\Middleware\Pur;

use App\Exception\AppException;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Middleware\BeforeMiddlewareInterface;
use Swork\Server\ArgumentInterface;

/**
 * 检查是否登录中间件
 */
class LoginMiddleware extends BeanCollector implements BeforeMiddlewareInterface
{
    /**
     * 中间件处理层
     * @param ArgumentInterface $argument
     * @throws
     */
    public function process(ArgumentInterface $argument)
    {
        $uid = Context::get('userId');
        if (empty($uid))
        {
            throw new AppException('用户未登录', AppException::NO_LOGIN);
        }
    }
}