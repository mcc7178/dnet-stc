<?php


namespace App\Module\Sale\Controller\Store\Order;

use App\Module\Sale\Logic\Store\Order\OrderLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Validate;
use Swork\Context;
use Swork\Server\Http\Argument;
use Swork\Bean\BeanCollector;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;

/**
 * 订单首页
 * @Controller("/sale/store/order")
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
     * 获取翻页数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //查询参数
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->orderLogic->getPager($query, $size, $idx);
    }

    /**
     * 获取列表数量
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->orderLogic->getCount($query);
    }

    /**
     * 获取侧边栏订单状态数量
     * @param Argument $argument
     * @return array
     */
    public function getList(Argument $argument)
    {
        //返回
        return $this->orderLogic->getStatList();
    }

    /**
     * 所有订单 导出
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function export(Argument $argument)
    {
        //外部参数
        $params = $this->getPagerQuery($argument);

        //返回
        return $this->orderLogic->export($params);
    }

    /**
     * 订单详情
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function detail(Argument $argument)
    {
        //外部参数
        $oid = $argument->get('oid', '');

        //返回
        return $this->orderLogic->detail($oid);
    }

    /**
     * 订单详情-待成交 不成交删除订单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //删除数据
        $this->orderLogic->delete($oid);

        //返回
        return 'success';
    }

    /**
     * 同意成交更改订单状态
     * @param Argument $argument
     * @Validate(Method::POST)
     * @Validate("recver", Validate::Required, '联系人不能为空')
     * @Validate("rectel", Validate::Required[Mobile], '手机号码格式不对')
     * @return string
     * @throws
     */
    public function agree(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $recver = $argument->post('recver', '');
        $rectel = $argument->post('rectel', '');

        //保存数据
        $this->orderLogic->agree($oid, $recver, $rectel);

        //返回
        return 'success';
    }

    /**
     * 订单详情-代付款 取消订单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function cancel(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //删除数据
        $this->orderLogic->cancel($oid);

        //返回
        return 'success';
    }

    /**
     * 订单详情-代付款 已支付
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function paid(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //保存数据
        $this->orderLogic->paid($oid);

        //返回
        return 'success';
    }

    /**
     * 确认线下支付
     * @Validate(Method::Post)
     * @Validate("oid", Validate::Required, '订单oid不能为空')
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function offremit(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //确认已支付
        $payment = $this->orderLogic->offRemit($oid);

        //返回
        return $payment;
    }

    /**
     * 订单详情-代付款 导出明细
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function derive(Argument $argument)
    {
        //外部参数
        $oid = $argument->get('oid', '');

        //返回
        return $this->orderLogic->derive($oid);
    }

    /**
     * 订单详情-待审核 取消订单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function remove(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //返回
        return $this->orderLogic->remove($oid);
    }

    /**
     * 修改下单人和联系电话
     * @param Argument $argument
     * @Validate(Method::POST)
     * @Validate("oid", Validate::Required, '订单号不能为空')
     * @Validate("buyerName", Validate::Required, '用户名不能为空')
     * @Validate("mobile", Validate::Required[Mobile], '手机号码格式不对')
     * @return string
     * @throws
     */
    public function modify(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $buyerName = $argument->post('buyerName', '');
        $mobile = $argument->post('mobile', 0);

        //修改用户信息
        $this->orderLogic->modify($oid, $buyerName, $mobile);

        //返回
        return 'success';
    }

    /**
     * 获取分页参数
     */
    private function getPagerQuery(Argument $argument)
    {
        return [
            'list' => $argument->get('list', ''),
            'code' => $argument->get('code', ''),
            'okey' => $argument->get('okey', ''),
            'name' => $argument->get('name', ''),
            'plat' => $argument->get('plat',0),
            'tid' => $argument->get('tid', 0),
            'whs' => $argument->get('whs', 0),
            'ostat' => $argument->get('ostat', ''),
            'ttype' => $argument->get('ttype', 'atime'),
            'time' => $argument->get('time', []),
        ];
    }
}
