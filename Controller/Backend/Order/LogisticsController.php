<?php
namespace App\Module\Sale\Controller\Backend\Order;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\Order\OrderLogisticsLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 订单管理-订单物流
 * @Controller("/sale/backend/order/logistics")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class LogisticsController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderLogisticsLogic
     */
    private $orderLogisticsLogic;

    /**
     * 查看物流信息
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function route(Argument $argument)
    {
        //外部参数
        $expno = $argument->post('expno', '');

        //返回
        return $this->orderLogisticsLogic->getRoute($expno);
    }
}
