<?php
namespace App\Module\Sale\Controller\Backend\Bid;

use App\Module\Sale\Logic\Backend\Bid\BidSalesLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 场次商品
 * @Controller("/sale/backend/bid/sales")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class SalesController extends BeanCollector
{
    /**
     * @Inject()
     * @var BidSalesLogic
     */
    private $bidSalesLogic;

    /**
     * 场次商品列表
     * @Validate(Method::Get)
     * @Validate("rid", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function list(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $size = $argument->get('size', 0);
        $idx = $argument->get('idx', 0);
        $query = $this->getPagerQuery($argument);

        //获取数据
        $list = $this->bidSalesLogic->getPager($acc, $size, $idx, $query);

        //返回
        return $list;
    }

    /**
     * 场次商品数量
     * @Validate(Method::Get)
     * @Validate("rid", Validate::Required)
     * @param Argument $argument
     * @return int
     * @throws
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->bidSalesLogic->getCount($query);
    }

    /**
     * 竞拍商品填价
     * @Validate(Method::Post)
     * @Validate("sid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function price(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $sid = $argument->post('sid', '');
        $sprc = $argument->post('sprc', 0);
        $kprc = $argument->post('kprc', 0);
        $aprc = $argument->post('aprc', 0);

        //保存价格
        $this->bidSalesLogic->savePrice($sid, $sprc, $kprc, $aprc, $acc);

        //返回
        return 'success';
    }

    /**
     * 下架商品
     * @Validate(Method::Post)
     * @Validate("sids", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function remove(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $sids = $argument->post('sids', '');

        //下架商品
        $this->bidSalesLogic->remove($sids, $acc);

        //返回
        return 'success';
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

        //操作数据
        $this->bidSalesLogic->mark($sids, $tid);

        //返回
        return 'success';
    }

    /**
     * 修改商品信息
     * @Validate(Method::Post)
     * @param Argument $argument
     * @throws
     */
    public function edit(Argument $argument)
    {

    }

    /**
     * 修改商品信息-保存
     * @Validate(Method::Post)
     * @Validate("pid", Validate::Required)
     * @Validate("alias", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $pid = $argument->post('pid', '');
        $palias = $argument->post('palias', '');

        //保存数据
        $this->bidSalesLogic->save($pid, $palias);

        //返回
        return 'success';
    }

    /**
     * 商品转场
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function trans(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $sids = $argument->post('sids', '');
        $frid = $argument->post('frid', '');
        $trid = $argument->post('trid', '');
        $shop = $argument->post('shop', 0);
        $lose = $argument->post('lose', 0);

        //转场数据
        $this->bidSalesLogic->trans($sids, $frid, $trid, $shop, $lose, $acc);

        //返回
        return 'success';
    }

    /**
     * 竞拍商品排序
     * @Validate(Method::Post)
     * @Validate("rid", Validate::Required)
     * @Validate("sids", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function sort(Argument $argument)
    {
        //外部参数
        $rid = $argument->post('rid', '');
        $sids = $argument->post('sids', []);

        //数据排序
        $this->bidSalesLogic->sort($rid, $sids);

        //返回
        return 'success';
    }

    /**
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    public function getPagerQuery(Argument $argument)
    {
        return [
            'rid' => $argument->get('rid', ''),
            'tid' => $argument->get('tid', 0),
            'lose' => $argument->get('lose', 0),
            'bcodes' => $argument->get('bcodes', ''),
            'plat' => $argument->get('plat', 0),
            'oname' => $argument->get('oname', ''),
            'bid' => $argument->get('bid', 0),
            'level' => $argument->get('level', 0),
            'nobids' => $argument->get('nobids', 0),
            'onshelf' => $argument->get('onshelf', 0),
        ];
    }
}