<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 我的-竞拍记录接口
 * @Controller("/sale/api/xinxin/mcp/mine/auction")
 * @Middleware(ApiResultFormat::class)
 */
class AuctionController extends BeanCollector
{
    /**
     * 竞拍记录翻页列表
     * @param Argument $argument
     */
    public function pager(Argument $argument)
    {

    }
}