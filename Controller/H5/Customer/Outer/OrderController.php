<?php
namespace App\Module\Sale\Controller\H5\Customer\Outer;

use App\Module\Sale\Logic\H5\Customer\Outer\OuterOrderLogic;
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
 * 客户-外发订单相关接口
 * @Controller("/sale/h5/customer/outer/order")

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
     * 外发订单详情接口
     * @Validate(Method::"Get")
     * @Validate("oid", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $acc = '5fbe28e7724ee53c186a0b38';
        $oid = $argument->get('oid', '');

        //返回
        return $this->outerOrderLogic->getInfo($oid, $acc);
    }

    /**
     * 出价接口
     * @Validate(Method::"Post")
     * @Validate("gid", Validate::Required)
     * @Validate("bprc", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function bid(Argument $argument)
    {
        //外部参数
        $acc = '5fbe28e7724ee53c186a0b38';
        $data = [
            'gid' => $argument->post('gid', ''),
            'bprc' => $argument->post('bprc', 0),
        ];

        //调用接口
        $this->outerOrderLogic->bid($data, $acc);

        //返回
        return 'success';
    }

    /**
     * 确认出价接口
     * @Validate(Method::"Post")
     * @Validate("oid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function confirm(Argument $argument)
    {
        //外部参数
        $acc = '5fbe28e7724ee53c186a0b38';
        $oid = $argument->post('oid', '');

        //调用接口
        $this->outerOrderLogic->confirm($oid, $acc);

        //返回
        return 'success';
    }
}