<?php
namespace App\Module\Sale\Controller\Customer\Home;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Customer\Home\HomeLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 客户端首页订单相关接口
 * @Controller("/sale/customer/home")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class HomeController extends BeanCollector
{
    /**
     * @Inject()
     * @var HomeLogic
     */
    private $homeLogic;

    /**
     * 客户端查询商品详情接口
     * @Validate(Method::"Post")
     * @Validate("bcode", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function detail(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $bcode = $argument->post('bcode');

        //返回
        return $this->homeLogic->getDetail($acc, $bcode);
    }

    /**
     * 出价接口
     * @Validate(Method::"Post")
     * @Validate("bcode", Validate::Required)
     * @Validate("bprc", Validate::Required)
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
        return $this->homeLogic->bid($acc, $bcode, $bprc, $oid);
    }

    /**
     * 待提交明细界面
     * @Validate(Method::"Post")
     * @Validate("oid", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function submitInfo(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->post('oid', '');

        //返回
        return $this->homeLogic->submitInfo($acc, $oid);
    }

    /**
     * 待提交-出价
     * @Validate(Method::"Post")
     * @Validate("oid", Validate::Required, "订单编号不能为空")
     * @Validate("pid", Validate::Required, "库存编码不能为空")
     * @Validate("bprc", Validate::Required, "价格不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function offer(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->post('oid', '');
        $pid = $argument->post('pid', '');
        $bprc = $argument->post('bprc', 0);

        $this->homeLogic->offer($oid, $pid, $bprc, $acc);

        //返回
        return 'ok';
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
    public function prdDelete(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->post('oid', '');
        $pid = $argument->post('pid', '');

        $this->homeLogic->prdDelete($oid, $pid, $acc);

        //返回
        return 'ok';
    }

    /**
     * 确认提交
     * @Validate(Method::"Post")
     * @Validate("oid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function confirm(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->post('oid', '');

        $this->homeLogic->confirm($oid, $acc);

        //返回
        return 'ok';
    }
}