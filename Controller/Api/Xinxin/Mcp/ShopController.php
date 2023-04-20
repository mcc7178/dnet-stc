<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 一口价商城接口
 * @Controller("/sale/api/xinxin/mcp/shop/product")
 * @Middleware(ApiResultFormat::class)
 */
class ShopController extends BeanCollector
{
    /**
     * 一口价商品翻页列表
     * @param Argument $argument
     */
    public function pager(Argument $argument)
    {

    }

    /**
     * 一口价商品详情
     * @param Argument $argument
     */
    public function detail(Argument $argument)
    {

    }

    /**
     * 一口价商品同步
     * @param Argument $argument
     */
    public function sync(Argument $argument)
    {

    }

    /**
     * 一口价商品品牌
     * @param Argument $argument
     */
    public function brand(Argument $argument)
    {

    }

    /**
     * 一口价商品机型
     * @param Argument $argument
     */
    public function model(Argument $argument)
    {

    }

    /**
     * 一口价商品购买（锁定未创建订单）
     * @param Argument $argument
     */
    public function buy(Argument $argument)
    {

    }
}