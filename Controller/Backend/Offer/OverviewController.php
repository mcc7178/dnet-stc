<?php
namespace App\Module\Sale\Controller\Backend\Offer;

use App\Module\Sale\Logic\Backend\Offer\OfferOverviewLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 供应商概况接口
 * @Controller("/sale/backend/offer/overview")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OverviewController extends BeanCollector
{
    /**
     * @Inject()
     * @var OfferOverviewLogic
     */
    private $overviewLogic;

    /**
     * 获取翻页列表数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        //分页参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //查询参数
        $query = [
            'oname' => $argument->get('oname', ''),
            'mobile' => $argument->get('mobile', ''),
        ];

        //获取数据
        $pager = $this->overviewLogic->getPager($query, $size, $idx);

        //返回
        return $pager;
    }

    /**
     * 获取翻页总数
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //查询参数
        $query = [
            'oname' => $argument->get('oname', ''),
            'mobile' => $argument->get('mobile', ''),
        ];

        //获取数据
        $count = $this->overviewLogic->getCount($query);

        //返回
        return $count;
    }

    /**
     * @Validate(Method::Get)
     * @Validate("oid",Validate::Required,"缺少供应商ID参数")
     * @param Argument $argument
     * @return bool|mixed
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $oid = $argument->get('oid', '');

        //获取数据
        $info = $this->overviewLogic->getInfo($oid);

        //返回
        return $info;
    }

    /**
     * 现存商品列表
     * @Validate(Method::Get)
     * @Validate("oid",Validate::Required,"缺少供应商ID参数")
     * @param Argument $argument
     * @return array
     */
    public function goods(Argument $argument)
    {
        //分页参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //外部参数
        $oid = $argument->get('oid', '');
        $stcwhs = $argument->get('stcwhs', 0);

        //获取数据
        $list = $this->overviewLogic->getGoodsPager($oid, $stcwhs, $size, $idx);

        //返回
        return $list;
    }

    /**
     * 现存商品总数量
     * @Validate(Method::Get)
     * @Validate("oid",Validate::Required,"缺少供应商ID参数")
     * @param Argument $argument
     * @return int
     */
    public function count2(Argument $argument)
    {
        //外部参数
        $oid = $argument->get('oid', '');
        $stcwhs = $argument->get('stcwhs', 0);

        //获取数据
        $count = $this->overviewLogic->getGoodsCount($oid, $stcwhs);

        //返回
        return $count;
    }
}