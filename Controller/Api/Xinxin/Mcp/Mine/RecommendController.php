<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine\RecommendLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use App\Middleware\ApiResultFormat;
use Swork\Server\Http\Argument;

/**
 * 我的-推荐列表接口
 * @Controller("/sale/api/xinxin/mcp/mine/recommend")
 * @Middleware(ApiResultFormat::class)
 */
class RecommendController extends BeanCollector
{
    /**
     * @Inject()
     * @var RecommendLogic
     */
    private $recommendLogic;

    /**
     * 获取推荐的一口价商品数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function shopsales(Argument $argument)
    {
        //外部参数
        $query = [
            'bid' => $argument->post('bid', 0),
            'mid' => $argument->post('mid', 0),
            'uid' => $argument->post('uid', 0),
        ];
        $idx = $argument->post('idx', 1);
        $size = $argument->post('size', 10);

        //获取数据
        $shopSales = $this->recommendLogic->getShopSales($query, $idx, $size);

        //返回数据
        return $shopSales;
    }

    /**
     * 获取推荐的竞拍商品数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function bidsales(Argument $argument)
    {
        //外部参数
        $query = [
            'bid' => $argument->post('bid', 0),
            'mid' => $argument->post('mid', 0),
            'uid' => $argument->post('uid', 0)
        ];
        $idx = $argument->post('idx', 1);
        $size = $argument->post('size', 10);

        //获取数据
        $shopSales = $this->recommendLogic->getBidSales($query, $idx, $size);

        //返回数据
        return $shopSales;
    }
}