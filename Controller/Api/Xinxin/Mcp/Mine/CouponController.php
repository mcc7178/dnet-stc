<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 我的-优惠券接口
 * @Controller("/sale/api/xinxin/mcp/mine/coupon")
 * @Middleware(ApiResultFormat::class)
 */
class CouponController extends BeanCollector
{
    /**
     * 优惠券翻页列表
     * @param Argument $argument
     */
    public function pager(Argument $argument)
    {

    }
}