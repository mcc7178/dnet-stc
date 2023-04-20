<?php
namespace App\Module\Sale\Controller\Customer;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Customer\GoodsLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 订单商品相关接口
 * @Controller("/sale/customer/goods")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class GoodsController extends BeanCollector
{
    /**
     * @Inject()
     * @var GoodsLogic
     */
    private $goodsLogic;

    /**
     * 出价接口
     * @Validate(Method::"Post")
     * @Validate("bcode", Validate::Required)
     * @Validate("bprc", Validate::Required, "出价不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function bid(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $bcode = $argument->post('bcode');
        $bprc = $argument->post('bprc', 0);
        $oid = $argument->post('oid', '');

        //返回
        return $this->goodsLogic->bid($acc, $bcode, $bprc, $oid);
    }

    /**
     * 待提交-商品删除
     * @Validate(Method::"Post")
     * @Validate("oid", Validate::Required)
     * @Validate("pid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->post('oid', '');
        $pid = $argument->post('pid', '');

        $this->goodsLogic->delete($oid, $pid, $acc);

        //返回
        return 'ok';
    }
}