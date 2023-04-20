<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp\Mine;

use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine\MinePurchaseLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 我的-采购需求接口
 * @Controller("/sale/api/xinxin/mcp/mine/purchase")
 * @Middleware(ApiResultFormat::class)
 */
class PurchaseController extends BeanCollector
{
    /**
     * @Inject()
     * @var MinePurchaseLogic
     */
    private $minePurchaseLogic;

    /**
     * 获取品牌
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function list(Argument $argument)
    {
        //获取外部数据
        $uid = $argument->post('uid', '');

        //返回
        return $this->minePurchaseLogic->list($uid);
    }

    /**
     * 获取详情
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //获取外部数据
        $pkey = $argument->post('pkey', '');

        //返回
        return $this->minePurchaseLogic->info($pkey);
    }

    /**
     * 获取品牌
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function brands(Argument $argument)
    {
        //接收外部参数
        $type = $argument->post('type', 1);
        $plat = $argument->post('plat', 1);

        //返回
        return $this->minePurchaseLogic->getBrands($plat, $type);
    }

    /**
     * 获取机型列表
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function models(Argument $argument)
    {
        //接收外部参数
        $bid = $argument->post('bid', 1);

        //返回
        return $this->minePurchaseLogic->getModels($bid);
    }

    /**
     * 获取选项（内存、版本、颜色、网络知识、成色）
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function options(Argument $argument)
    {
        //接收外部参数
        $mid = $argument->post('mid', 0);
        $plat = $argument->post('plat', 0);

        //返回
        return $this->minePurchaseLogic->options($plat, $mid);
    }

    /**
     * 保存采购需求
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function save(Argument $argument)
    {
        //接收外部参数
        $sdata = $argument->post('sdata', '');
        $uid = $argument->post('uid', '');

        //返回
        return $this->minePurchaseLogic->save($sdata, $uid);
    }

    /**
     * 取消采购需求
     * @param Argument $argument
     * @return boolean
     * @throws
     */
    public function cancel(Argument $argument)
    {
        //接收外部参数
        $pkey = $argument->post('pkey', '');

        //返回
        return $this->minePurchaseLogic->cancel($pkey);
    }

    /**
     * 获取采购需求状态
     * @param Argument $argument
     * @return int
     * @throws
     */
    public function stat(Argument $argument)
    {
        //接收外部参数
        $uid = $argument->post('uid', '');

        //返回
        return $this->minePurchaseLogic->stat($uid);
    }

    /**
     * 获取符合用户条件的在售商品
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function sale(Argument $argument)
    {
        //接收外部参数
        $pkey = $argument->post('pkey', 0);

        //返回
        return $this->minePurchaseLogic->sale($pkey);
    }

    /**
     * 获取匹配上架商品的采购需求
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function demand(Argument $argument)
    {
        //接收外部参数
        $pids = $argument->post('pids', '');
        $pids = json_decode($pids, true);

        //返回
        return $this->minePurchaseLogic->demand($pids);
    }
}