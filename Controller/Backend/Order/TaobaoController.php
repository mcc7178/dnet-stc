<?php
namespace App\Module\Sale\Controller\Backend\Order;

use App\Module\Sale\Logic\Backend\Order\OrderTaobaoActLogic;
use App\Module\Sale\Logic\Backend\Order\OrderTaobaoRefLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\Order\OrderTaobaoLogic;

/**
 * 淘宝订单接口
 * @Controller("/sale/backend/order/taobao")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class TaobaoController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderTaobaoLogic
     */
    private $orderTaobaoLogic;

    /**
     * @Inject()
     * @var OrderTaobaoActLogic
     */
    private $orderTaobaoActLogic;

    /**
     * @Inject()
     * @var OrderTaobaoRefLogic
     */
    private $orderTaobaoRefLogic;

    /**
     * 获取翻页列表数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        // 分页参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        // 查询参数
        $query = $this->getPagerQuery($argument);

        // 获取数据
        $pager = $this->orderTaobaoLogic->getPager($query, $size, $idx);

        // 返回
        return $pager;
    }

    /**
     * 获取翻页总数
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        // 查询参数
        $query = $this->getPagerQuery($argument);

        // 获取数据
        $count = $this->orderTaobaoLogic->getCount($query);

        // 返回
        return $count;
    }

    /**
     * 导出订单
     * @param Argument $argument
     * @return array
     */
    public function export(Argument $argument)
    {
        // 查询参数
        $query = $this->getPagerQuery($argument);

        // 导出表格内容
        $list = $this->orderTaobaoLogic->export($query);

        // 返回
        return $list;
    }

    /**
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        $query = [
            'bcode' => $argument->get('bcode', ''),
            'okey' => $argument->get('okey', ''),
            'outerid' => $argument->get('outerid', ''),
            'ostat' => $argument->get('ostat', 0),
            'status' => $argument->get('status', 0),
            'ttype' => $argument->get('ttype', 0),
            'otime' => $argument->get('otime', []),
        ];

        return $query;
    }

    /**
     * 获取订单详情数据
     * @validate(Method:Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $tradeid = $argument->get('tradeid', '');

        //获取数据
        $data = $this->orderTaobaoLogic->getInfo($tradeid);

        //返回
        return $data;
    }

    /**
     * 获取快递物流动态
     * @Validate(Method::Get)
     * @Validate("expno", Validate::Required, "快递单号不能为空")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function dynamic(Argument $argument)
    {
        /**
         * 快递单号
         * @var string
         * @required
         * @sample 2020123456789
         */
        $expno = $argument->get('expno', '');

        //获取数据
        $list = $this->orderTaobaoLogic->getRoute($expno);

        //API返回
        /**
         * [{
         *      "accept_time": "2019-04-23 09:44:25",
         *      "accept_address": "清远市",
         *      "opcode": "36",
         *      "remark": "快件已发车"
         * }]
         */
        return $list;
    }

    /**
     * 获取淘宝订单操作流水及备注列表
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return mixed
     * @throws
     */
    public function water(Argument $argument)
    {
        //外部参数
        $pkey = $argument->post('tradeid', 0);

        //返回
        return $this->orderTaobaoLogic->getWater($pkey);
    }

    /**
     * 单个取消和一键取消按钮
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function cancel(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $YxeGids = $argument->post('gids', '');

        $this->orderTaobaoActLogic->cancel($acc, $YxeGids);

        return 'ok';
    }

    /**
     * 不发货按钮
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function del(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $YxeGid = $argument->post('gid', '');

        $this->orderTaobaoActLogic->del($acc, $YxeGid);

        return 'ok';
    }

    /**
     * 内部备注
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     */
    public function rmk(Argument $argument)
    {
        //外部参数
        $okey = $argument->post('okey', '');
        $rmk = $argument->post('rmk', '');

        $this->orderTaobaoActLogic->saveOrderRmk($okey, $rmk);

        return 'ok';
    }

    /**
     * 配货
     * @Validate(Method::Post)
     * @Validate("gid", Validate::Required)
     * @Validate("pid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function matchGoods(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $gid = $argument->post('gid', '');
        $pid = $argument->post('pid', '');

        //操作数据
        $this->orderTaobaoActLogic->matchGoods($gid, $pid, $acc);

        //返回
        return 'ok';
    }

    /**
     * 删除
     * @Validate(Method::Post)
     * @Validate("gid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function cancelGoods(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $gid = $argument->post('gid', '');

        //操作数据
        $this->orderTaobaoActLogic->cancelGoods($gid, $acc);

        //返回
        return 'ok';
    }

    /**
     * 确认发货/一键发货
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function confirmDelivery(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $gid = $argument->post('gid', '');
        $okey = $argument->post('okey', '');

        //操作数据
        $this->orderTaobaoActLogic->confirmDelivery($gid, $okey, $acc);

        //返回
        return 'ok';
    }

    /**
     * 实时刷新淘宝订单状态
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function refresh(Argument $argument)
    {
        //外部参数
        $tid = $argument->get('tid', '');

        //返回
        return $this->orderTaobaoRefLogic->refresh($tid);
    }
}