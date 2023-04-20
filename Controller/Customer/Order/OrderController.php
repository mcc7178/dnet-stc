<?php
namespace App\Module\Sale\Controller\Customer\Order;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Customer\Order\OrderLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * @Controller("/sale/customer/order")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OrderController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderLogic
     */
    private $orderLogic;

    /**
     * 客户端我的订单列表
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        // 查询条件
        $query = $this->getPagerParams($argument);

        //返回
        return $this->orderLogic->getPager($query, $acc, $size, $idx);
    }

    /**
     * 获取翻页总数
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        // 外部参数
        $acc = Context::get('acc');
        $query = $this->getPagerParams($argument);

        //返回
        return $this->orderLogic->getCount($query, $acc);
    }

    /**
     * 订单详情
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
        return $this->orderLogic->getInfo($acc, $oid);
    }

    /**
     * 商品详情
     * @Validate("bcode", Validate::Required)
     * @Validate("oid", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function prdInfo(Argument $argument)
    {
        //外部参数
        $bcode = $argument->get('bcode', '');
        $oid = $argument->get('oid', '');

        //返回
        return $this->orderLogic->getPrdInfo($bcode, $oid);
    }

    /**
     * 获取分页参数
     * @param Argument $argument
     * @return array
     */
    private function getPagerParams(Argument $argument)
    {
        return [
            'bcode' => $argument->get('bcode', ''),
            'okey' => $argument->get('okey', ''),
            'ostat' => $argument->get('ostat', ''),
            'ttype' => $argument->get('ttype', 'atime'),
            'time' => $argument->get('time', []),
        ];
    }

    /**
     * 导出我的订单列表
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function export(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');

        // 查询条件
        $query = $this->getPagerParams($argument);

        //返回
        return $this->orderLogic->export($query, $acc);
    }

    /**
     * 待确认订单订单撤回
     * @Validate(Method::"Post")
     * @Validate("oid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function cancel(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->post('oid', '');

        $this->orderLogic->cancel($oid, $acc);

        //返回
        return 'ok';
    }
}