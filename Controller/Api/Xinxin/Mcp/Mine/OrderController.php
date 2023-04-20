<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine\OrderLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 我的-订单相关接口
 * @Controller("/sale/api/xinxin/mcp/mine/order")
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
     * 订单翻页列表
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $query = [
//            'acc' => Context::get('acc'),
            'acc' => $argument->get('uid', ''),
            'type' => $argument->get('type', 0),
            'idx' => $argument->get('idx', 1),
            'size' => $argument->get('size', 10)
        ];

        //获取数据
        $pager = $this->orderLogic->getPager($query);

        //返回数据
        return $pager;
    }

    /**
     * 订单详情
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function detail(Argument $argument)
    {
        //外部参数
//        $acc = Context::get('acc');
        $acc = $argument->get('uid', '');
        $oid = $argument->get('oid', '');

        //获取数据
        $detail = $this->orderLogic->detail($oid, $acc);

        //返回数据
        return $detail;
    }

    /**
     * 订单动态
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function dynamic(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);
        $oids = $argument->post('oids', '');

        //获取数据
        $detail = $this->orderLogic->dynamic($uid, $oids);

        //返回数据
        return $detail;
    }

    /**
     * 用户确认收货--订单详情页
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function confirmReceipt(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);
        $oid = $argument->post('oid', '');

        //处理数据
        $confirm = $this->orderLogic->confirmReceipt($uid, $oid);

        //返回数据
        return $confirm;
    }
}