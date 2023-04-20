<?php
namespace App\Module\Sale\Controller\Backend\Order;

use App\Module\Sale\Logic\Backend\Order\OrderTaobaoMatchLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 订单配货接口
 * @Controller("/sale/backend/order/taobao/match")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class TaobaoMatchController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderTaobaoMatchLogic
     */
    private $orderTaobaoMatchLogic;

    /**
     * 获取配货列表数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 0);
        $size = $argument->get('size', 25);
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->orderTaobaoMatchLogic->getPager($query, $size, $idx);
    }

    /**
     * 获取订单数量
     * @param Argument $argument
     * @return int
     * @throws
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->orderTaobaoMatchLogic->getCount($query);
    }

    /**
     * 公共获取请求参数
     * @param Argument $argument
     * @return array
     */
    public function getPagerQuery(Argument $argument)
    {
        //返回
        return [
            'ptype' => $argument->get('ptype', 0),
            'bid' => $argument->get('bid', 0),
            'mid' => $argument->get('mid', 0),
            'level' => $argument->get('level', 0),
            'mdram' => $argument->get('mdram', 0),
            'mdcolor' => $argument->get('mdcolor', 0),
            'mdofsale' => $argument->get('mdofsale', 0),
            'mdnet' => $argument->get('mdnet', 0),
            'stcstat' => $argument->get('stcstat', 0),
            'bcode' => $argument->get('bcode', ''),
        ];
    }
}