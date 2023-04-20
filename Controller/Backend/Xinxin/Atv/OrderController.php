<?php

namespace App\Module\Sale\Controller\Backend\Xinxin\Atv;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\Xinxin\Atv\OrderLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 拼团订单
 * @Controller("/sale/backend/xinxin/atv/order")
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
     * 拼团订单列表
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        // 查询条件
        $query = [
            'gname' => $argument->get('gname', ''),
            'mname' => $argument->get('mname', ''),
            'stime' => $argument->get('stime', []),
            'stat' => $argument->get('stat', 0),
        ];

        //返回
        return $this->orderLogic->getPager($query, $idx, $size);
    }

    /**
     * 拼团订单详情
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $gkey = $argument->get('gkey', '');

        //返回
        return $this->orderLogic->getInfo($gkey);
    }

    /**
     * 订单列表
     * @param Argument $argument
     * @return array
     */
    public function orderList(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 10);

        // 查询条件
        $query = [
            'gkey' => $argument->get('gkey', ''),
            'okey' => $argument->get('okey', ''),
            'uname' => $argument->get('uname', ''),
            'recver' => $argument->get('recver', ''),
            'rectel' => $argument->get('rectel', ''),
            'stat' => $argument->get('stat', ''),
        ];

        //返回
        return $this->orderLogic->getOrderList($query, $idx, $size);
    }

    /**
     * 拼团失败全部退款
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function refundAll(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $gkey = $argument->post('gkey', '');

        //返回
        return $this->orderLogic->refundAll($acc, $gkey);
    }

    /**
     * 拼团订单单独退款
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function refundOne(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $oid = $argument->post('oid', '');

        //返回
        return $this->orderLogic->refundOne($acc, $oid);
    }
}
