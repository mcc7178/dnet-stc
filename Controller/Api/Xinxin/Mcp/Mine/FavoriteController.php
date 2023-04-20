<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine\FavoriteLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;

/**
 * 我的-收藏接口
 * @Controller("/sale/api/xinxin/mcp/mine/favorite")
 * @Middleware(ApiResultFormat::class)
 */
class FavoriteController extends BeanCollector
{
    /**
     * @Inject()
     * @var FavoriteLogic
     */
    private $favoriteLogic;

    /**
     * 收藏翻页列表
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $query = [
            'uid' => $argument->post('uid', 0),
            'bid' => $argument->post('bid', 0),
            'mid' => $argument->post('mid', 0),
        ];

        //查询参数
        $size = $argument->post('size', 10);
        $idx = $argument->post('idx', 1);

        //获取数据
        $pager = $this->favoriteLogic->getPager($query, $size, $idx);

        //返回数据
        return $pager;
    }

    /**
     * 收藏商品（或取消收藏）-旧系统同步至新系统
     * @param Argument $argument
     * @return bool
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $query = [
            'uid' => $argument->post('uid', 0),
            'bid' => $argument->post('bid', 0),
            'mid' => $argument->post('mid', 0)
        ];

        //获取数据
        $result = $this->favoriteLogic->save($query);

        //返回数据
        return $result;
    }

    /**
     * 关注/取消关注某个品牌
     * @param Argument $argument
     * @return bool
     * @throws
     */
    public function focusmodel(Argument $argument)
    {
        $uid = $argument->post('uid', 0);
        $mid = $argument->post('mid', 0);
        $rid = $argument->post('rid', '');

        //处理数据
        $result = $this->favoriteLogic->focusModel($uid, $mid, $rid);

        //返回数据
        return $result;
    }

    /**
     * 获取用户关注的品牌列表
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function brands(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);

        //获取数据
        $brands = $this->favoriteLogic->getBrands($uid);

        //返回数据
        return $brands;
    }

    /**
     * 获取用户关注的机型列表
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function models(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);
        $bid = $argument->post('bid', 0);

        //获取数据
        $models = $this->favoriteLogic->getModels($uid, $bid);

        //返回数据
        return $models;
    }

    /**
     * 关注按钮跳转类型
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function focustype(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);

        //获取数据
        $type = $this->favoriteLogic->focusType($uid);

        //返回数据
        return $type;
    }
}