<?php

namespace App\Module\Sale\Controller\Backend\Pur;

use App\Model\Pur\PurCategoryModel;
use App\Model\Pur\PurLevelModel;
use App\Module\Sale\Logic\Backend\Pur\StockManageLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Omr\Middleware\ContextMiddleware;


/**
 * 电商库存 - 库存管理
 * Class StockController
 * @Controller("/sale/backend/pur/stockManage")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class StockManageController extends BeanCollector
{
    /**
     * @Inject()
     * @var StockManageLogic
     */
    private $stockManageLogic;

    /**
     * 获取列表数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 0);
        $size = $argument->get('size', 25);
        $query = $this->getQueryParams($argument);

        //获取数据
        $list = $this->stockManageLogic->getPager($query, $size, $idx);

        //返回
        return $list;
    }

    /**
     * 获取总数据
     * @param Argument $argument
     * @return int
     * @throws
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getQueryParams($argument);

        //获取数据
        $count = $this->stockManageLogic->getCount($query);

        //返回
        return $count;
    }

    /**
     * 列表电商分类
     * @return array
     * @throws
     */
    public function category()
    {
        // 获取数据
        $list = $this->stockManageLogic->category();

        // 返回
        return $list;
    }

    /**
     * 获取电商分类
     * @return array
     * @throws
     */
    public function categoryList()
    {
        // 获取数据
        $list = PurCategoryModel::M()->getList(['cstat' => 1], 'cid,cname', ['ctime' => -1]);
        array_unshift($list, ['cid' => '', 'cname' => '无分类']);

        // 返回
        return $list;
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
        $idx = $argument->get('idx', 0);
        $size = $argument->get('size', 25);
        $query = $this->getQueryParams($argument);

        //获取数据
        $data = $this->stockManageLogic->export($query, $size, $idx);

        //返回
        return $data;
    }

    /**
     * 公共获取请求参数
     * @param Argument $argument
     * @return array
     */
    public function getQueryParams(Argument $argument)
    {
        //外部参数
        $query = [
            'mtype' => $argument->get('mtype', 0),
            'bcode' => $argument->get('bcode', ''),
            'plat' => $argument->get('plat', 0),
            'ptype' => $argument->get('ptype', 0),
            'bid' => $argument->get('bid', 0),
            'mid' => $argument->get('mid', []),
            'stcstat' => $argument->get('stcstat', []),
            'mdram' => $argument->get('mdram', []),
            'level' => $argument->get('level', []),
            'pacc' => $argument->get('pacc', ''),
            'merchant' => $argument->get('merchant', ''),
            'whs' => $argument->get('whs', 0),
            'ttype' => $argument->get('ttype', 1),
            'time' => $argument->get('time', []),
            'ord' => $argument->get('ord', ''),
            'cid' => $argument->get('cid', ''),
            'offer' => $argument->get('offer', ''),
            'atype' => $argument->get('atype', ''),
            'plevel' => $argument->get('plevel', 0),
            'imei' => $argument->get('imei', ''),
            'round' => $argument->get('round', ''),
        ];

        //返回
        return $query;
    }

    /**
     * 转B端销售
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function trantob(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $bcode = $argument->post('bcode', '');

        //返回
        return $this->stockManageLogic->tranPrd($bcode, $acc, 1);
    }

    /**
     * 批量转B端销售
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function tranAlltob(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $bcodes = $argument->post('bcodes', '');

        //返回
        return $this->stockManageLogic->tranAllPrd($bcodes, $acc);
    }

    /**
     * 批量转C端销售
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function tranAlltoc(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $bcodes = $argument->post('bcodes', '');
        $rname = $argument->post('rname', '');

        //返回
        return $this->stockManageLogic->tranAllToPur($bcodes, $acc, $rname);
    }

    /**
     * 设置分类
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("bcodes",Validate::Required)
     * @return string
     * @throws
     */
    public function updateClassify(Argument $argument)
    {
        // 外部数据
        $bcodes = $argument->post('bcodes', '');
        $cid = $argument->post('cid', '');

        // 操作数据
        $this->stockManageLogic->updateClassify($bcodes, $cid);

        // 返回
        return 'ok';
    }

    /**
     * 转电商库存（添加到库存）
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function trantopur(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $bcode = $argument->post('bcode', '');

        //操作数据
        $this->stockManageLogic->tranPrd($bcode, $acc, 2);

        //返回
        return 'ok';
    }

    /**
     * 获取库存均价
     * @param Argument $argument
     * @return string
     */
    public function stcAvgPrc(Argument $argument)
    {
        //外部参数
        $mtype = $argument->get('mtype', 0);
        $bcode = $argument->get('bcode', '');

        //操作数据
        $data = $this->stockManageLogic->stcAvgPrc($mtype, $bcode);

        //返回
        return $data;
    }

    /**
     * 电商等级
     * @return array
     *
     */
    public function getLevel()
    {
        // 获取数据
        $list = PurLevelModel::M()->getList();

        // 返回
        return $list;
    }

    /**
     * 保存电商分类
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("bcode",Validate::Required)
     * @Validate("plevel",Validate::Required,'请选择电商等级')
     * @return string
     *
     */
    public function saveLevel(Argument $argument)
    {
        // 外部参数
        $bcode = $argument->post('bcode', '');
        $plevel = $argument->post('plevel', 0);

        // 操作数据
        $this->stockManageLogic->saveLevel($bcode, $plevel);

        // 返回
        return 'ok';
    }

    /**
     * 保存电池备注
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("bcode",Validate::Required)
     * @Validate("rmk",Validate::Required,'请填写备注')
     * @return string
     *
     */
    public function saveRmk(Argument $argument)
    {
        // 外部参数
        $bcode = $argument->post('bcode', '');
        $rmk = $argument->post('rmk', '');

        // 操作数据
        $this->stockManageLogic->saveRmk($bcode, $rmk);

        // 返回
        return 'ok';
    }
}