<?php
namespace App\Module\Sale\Controller\Customer\Order;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Customer\Order\OrderPayLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * @Controller("/sale/customer/order/pay")
 * @Middleware(ApiResultFormat::class)
 */
//* @Middleware(LoginMiddleware::class)
class PayController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderPayLogic
     */
    private $orderPayLogic;

    /**
     * 支付预下单
     * @Validate(Method::"Post")
     * @Validate("oid", Validate::Required)
     * @Validate("paytype", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function create(Argument $argument)
    {
        //上下文参数
        $plat = Context::get('plat');
        $uid = Context::get('acc');
        $plat = 23;
        $uid = '596834871f991d17b5000002';
        //外部参数
        $oid = $argument->post('oid', '');
        $payType = $argument->post('paytype', 11);

        //组装参数
        $params = [
            'plat' => $plat,
            'uid' => $uid,
            'oid' => $oid,
            'payType' => $payType
        ];

        //支付预下单
        $data = $this->orderPayLogic->create($params);

        //返回
        return $data;
    }
}