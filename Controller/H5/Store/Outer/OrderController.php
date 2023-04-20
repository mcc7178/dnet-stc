<?php
namespace App\Module\Sale\Controller\H5\Store\Outer;

use App\Module\Sale\Logic\H5\Store\Outer\OuterOrderLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 门店-外单订单相关接口
 * @Controller("/sale/h5/store/outer/order")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OrderController extends BeanCollector
{
    /**
     * @Inject()
     * @var OuterOrderLogic
     */
    private $outerOrderLogic;

    /**
     * 分发报价详情接口
     * @Validate(Method::"Get")
     * @Validate("oid", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->get('oid', '');

        //返回
        return $this->outerOrderLogic->getInfo($oid, $acc);
    }
}