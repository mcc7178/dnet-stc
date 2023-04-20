<?php
namespace App\Module\Sale\Controller\Backend\Shop;

use App\Module\Sale\Logic\Backend\Shop\ShopRoundLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 竞拍场次数据
 * @Controller("/sale/backend/shop/round")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class RoundController extends BeanCollector
{
    /**
     * @Inject()
     * @var ShopRoundLogic
     */
    private $shopRoundLogic;

    /**
     * 场次日期数据
     * @return array
     */
    public function dates()
    {
        return $this->shopRoundLogic->dates();
    }

    /**
     * 场次数据
     * @param Argument $argument
     * @return
     * @throws
     */
    public function list(Argument $argument)
    {
        //外部参数
        $date = $argument->get('date', '');

        //获取场次数据
        $list = $this->shopRoundLogic->list($date);

        //返回
        return $list;
    }
}