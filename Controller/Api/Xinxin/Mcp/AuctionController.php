<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 竞拍接口
 * @Controller("/sale/api/xinxin/mcp/auction")
 * @Middleware(ApiResultFormat::class)
 */
class AuctionController extends BeanCollector
{
    /**
     * 竞拍场次列表
     * @Controller("/round/list")
     * @param Argument $argument
     */
    public function round_list(Argument $argument)
    {

    }

    /**
     * 竞拍场次分组
     * @Controller("/round/group")
     * @param Argument $argument
     */
    public function round_group(Argument $argument)
    {

    }

    /**
     * 竞拍商品列表
     * @param Argument $argument
     */
    public function product(Argument $argument)
    {

    }

    /**
     * 竞拍出价
     * @param Argument $argument
     */
    public function bid(Argument $argument)
    {

    }
}