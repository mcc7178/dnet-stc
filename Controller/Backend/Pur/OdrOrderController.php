<?php

namespace App\Module\Sale\Controller\Backend\Pur;

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
use App\Module\Sale\Logic\Backend\Pur\OdrOrderLogic;
use App\Module\Sale\Data\PurDictData;
use App\Model\Crm\CrmMessageDotModel;

/**
 * 采购单管理
 * Class OdrOrderController
 * @Controller("/sale/backend/pur/order")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OdrOrderController extends BeanCollector
{
    /**
     * @Inject()
     * @var OdrOrderLogic
     */
    private $odrOrderLogic;

    /*
     * 采购单列表
     * @param Argument $argument
     * @return array
     * @throws
     * */
    public function pager(Argument $argument)
    {
        // 外部参数
        $idx = $argument->get('idx', 0);
        $size = $argument->get('size', 25);
        $query = $this->getPagerQuery($argument);

        // 删除小红点
        $acc = Context::get('acc');
        CrmMessageDotModel::M()->delete(['uid' => $acc, 'src' => '1502']);

        // 获取数据
        $list = $this->odrOrderLogic->getPager($query, $idx, $size);

        // 返回
        return $list;
    }

    /**
     * 采购单列表总数量
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        // 外部参数
        $query = $this->getPagerQuery($argument);

        // 获取数据
        $count = $this->odrOrderLogic->getCount($query);

        // 返回
        return $count;
    }

    /**
     * 采购单详情
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("okey", Validate::Required, "请填写采购单号")
     */
    public function orderDetail(Argument $argument)
    {
        $okey = $argument->post('okey', '');

        return $this->odrOrderLogic->orderDetail($okey);
    }

    /**
     * 采购单审核通过
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("okey", Validate::Required, "请填写采购单号")
     * @Validate("pkey", Validate::Required, "请填写采购计划号")
     * @Validate("dkey", Validate::Required, "请填写采购需求号")
     * @return mixed
     * @throws
     */
    public function agreeOrder(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $okey = $argument->post('okey', '');
        $pkey = $argument->post('pkey', '');
        $dkey = $argument->post('dkey', '');

        return $this->odrOrderLogic->agreeOrder($okey, $pkey, $dkey, $acc);
    }

    /**
     * 采购单审核驳回
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("okey", Validate::Required, "请填写采购单号")
     * @Validate("pkey", Validate::Required, "请填写采购计划号")
     * @Validate("dkey", Validate::Required, "请填写采购需求号")
     * @Validate("rmk", Validate::Required, "请填写采购单驳回备注")
     * @return mixed
     * @throws
     */
    public function rejectOrder(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $okey = $argument->post('okey', '');
        $pkey = $argument->post('pkey', '');
        $dkey = $argument->post('dkey', '');
        $rmk = $argument->post('rmk', '');

        return $this->odrOrderLogic->rejectOrder($okey, $pkey, $dkey, $rmk, $acc);
    }

    /**
     * 查询计划类型
     * @return array
     * */
    public function getPtype()
    {
        $ptypeData['ptype'] = PurDictData::PUR_PLAN_TYPE;

        return $ptypeData;
    }

    /**
     * 查询计划类型
     * @return array
     * */
    public function getCstat()
    {
        $cstatData['cstat'] = PurDictData::PUR_CSTAT;

        return $cstatData;
    }



    /**
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        return [
            'okey'  => $argument->get('okey', ''),
            'dkey'  => $argument->get('dkey', ''),
            'ptype'  => $argument->get('ptype', ''),
            'cstat' => $argument->get('cstat', 0),
            'aacc' => $argument->get('aacc', ''),
            'timetype' => $argument->get('timetype', 0), // 时间类型
            'time'  => $argument->get('time', []),
        ];
    }
}