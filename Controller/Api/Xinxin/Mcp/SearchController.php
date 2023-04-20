<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 搜索接口
 * @Controller("/sale/api/xinxin/mcp/search")
 * @Middleware(ApiResultFormat::class)
 */
class SearchController extends BeanCollector
{
    /**
     * 热门搜索关键词
     * @param Argument $argument
     */
    public function hotkey(Argument $argument)
    {

    }

    /**
     * 搜索机型品牌列表
     * @Controller("/brand/list")
     * @param Argument $argument
     */
    public function brand_list(Argument $argument)
    {

    }

    /**
     * 搜索机型品牌列表
     * @Controller("/product/pager")
     * @param Argument $argument
     */
    public function product_pager(Argument $argument)
    {

    }
}