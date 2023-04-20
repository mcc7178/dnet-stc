<?php
namespace App\Module\Sale\Controller\Backend\Shop;

use App\Module\Sale\Logic\Backend\Shop\ShopSalesLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 一口价商品
 * @Controller("/sale/backend/shop/sales")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class SalesController extends BeanCollector
{
    /**
     * @Inject()
     * @var ShopSalesLogic
     */
    private $shopSalesLogic;

    /**
     * 一口价商品翻页数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //分页参数
        $size = $argument->get('size', 25);
        $idx = $argument->get('idx', 1);

        $query = $this->getQuery($argument);

        //tab页参数
        $tabtype = $argument->get('tabtype', 'all');

        //获取数据
        $pager = $this->shopSalesLogic->getPager($tabtype, $query, $size, $idx, $acc);

        //返回
        return $pager;
    }

    /**
     * 一口价商品条数
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //查询参数
        $query = $this->getQuery($argument);

        //tab页参数
        $tabtype = $argument->get('tabtype', 'all');

        //返回
        return $this->shopSalesLogic->getCount($tabtype, $query);
    }

    /**
     * 下架商品
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function remove(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $type = $argument->post('type', 1);
        $tabtype = $argument->post('tabtype', '');
        $sids = $argument->post('sids', '');
        $query = $argument->post('query', []);

        //下架商品
        $this->shopSalesLogic->remove($type, $tabtype, $sids, $query, $acc);

        //返回
        return 'success';
    }

    /**
     * 公开/取消公开
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function stat(Argument $argument)
    {
        //外部参数
        $sids = $argument->post('sids', '');
        $type = $argument->post('type', 0);
        $stat = $argument->post('stat', 0);
        $ptime = $argument->post('ptime', '');
        $query = $argument->post('query', []);

        //操作数据
        $this->shopSalesLogic->stat($sids, $type, $stat, $ptime, $query);

        //返回
        return 'success';
    }

    /**
     * 一口价商品转场
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function shift(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $type = $argument->post('type', 1);
        $tabtype = $argument->post('tabtype', '');
        $rid = $argument->post('rid', '');
        $sids = $argument->post('sids', '');
        $query = $argument->post('query', []);

        //商品转场
        $this->shopSalesLogic->shift($type, $tabtype, $rid, $sids, $query, $acc);

        //返回
        return 'success';
    }

    /**
     * 编辑中商品品牌列表
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function brands()
    {
        return $this->shopSalesLogic->getBrands();
    }

    /**
     * 活动/特价标记
     * @Validate(Method::Post)
     * @Validate("sids", Validate::Required)
     * @Validate("tid", Validate::Required)
     * @Validate("tid", Validate::Ins[1|2])
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function mark(Argument $argument)
    {
        //外部参数
        $sids = $argument->post('sids', '');
        $tid = $argument->post('tid', 0);
        $type = $argument->post('type', 1);
        $query = $argument->post('query', []);

        //操作数据
        $this->shopSalesLogic->mark($sids, $tid, $type, $query);

        //返回
        return 'success';
    }

    /**
     * 填价
     * @Validate(Method::Post)
     * @Validate("sid", Validate::Required)
     * @Validate("price", Validate::Required[GreaterZero])
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function price(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');

        //外部参数
        $sid = $argument->post('sid', '');
        $price = $argument->post('price', 0);

        //操作数据
        $this->shopSalesLogic->price($sid, $price, $acc);

        //返回
        return 'success';
    }

    /**
     * 导出数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function export(Argument $argument)
    {
        //外部参数
        $tabType = $argument->get('tabType', 'all');
        $size = $argument->get('size', 25);
        $idx = $argument->get('idx', 1);
        $query = $this->getQuery($argument);

        //获取数据
        $data = $this->shopSalesLogic->export($tabType, $query, $size, $idx);

        //返回
        return $data;
    }

    /**
     * 公共外部参数
     * @param Argument $argument
     * @return array
     */
    private function getQuery(Argument $argument)
    {
        //查询参数
        $query = [
            'bcode' => $argument->get('bcode', ''),
            'bid' => $argument->get('bid', 0),
            'mid' => $argument->get('mid', 0),
            'lkey' => $argument->get('level', 0),
            'stat' => $argument->get('stat', 0),
            'isprc' => $argument->get('isprc', 0),
            'ttype' => $argument->get('ttype', 0),
            'plat' => $argument->get('plat', 0),
            'oname' => $argument->get('oname', ''),
            'time' => $argument->get('time', []),
            'tid' => $argument->get('tid', 0),
            'from' => $argument->get('from', ''),
        ];

        //返回
        return $query;
    }
}