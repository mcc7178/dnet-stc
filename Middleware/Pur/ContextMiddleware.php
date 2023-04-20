<?php
namespace App\Module\Sale\Middleware\Pur;

use App\Module\Acc\Logic\AccTokenLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Middleware\BeforeMiddlewareInterface;
use Swork\Server\ArgumentInterface;

/**
 * 上下文中间件
 */
class ContextMiddleware extends BeanCollector implements BeforeMiddlewareInterface
{
    /**
     * @Inject()
     * @var AccTokenLogic
     */
    private $accTokenLogic;

    /**
     * 中间件处理层
     * @param ArgumentInterface $argument
     * @throws
     */
    public function process(ArgumentInterface $argument)
    {
        //外部参数
        $plat = $argument->get('plat', 0);
        $token = $argument->getHeader('authorization');
        if (empty($token))
        {
            $token = $argument->get('token', '');
        }

        //解析token
        $userInfo = $this->accTokenLogic->parseJWT($plat, $token);

        //提取用户参数
        $userId = $userInfo['uid'] ?? '';

        //写入上下文
        Context::put('userId', $userId); //新系统用户ID
        Context::put('plat', $plat); //新系统用户ID
    }
}