<?php

namespace App\Module\Sale\Controller\Backend\Pur;

use App\Module\Sale\Logic\Backend\Pur\PlanLogic;
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
use App\Module\Sale\Data\PurDictData;

/**
 * 采购计划管理
 * Class PlanController
 * @Controller("/sale/backend/pur/plan")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PlanController extends BeanCollector
{
    /**
     * @Inject()
     * @var PlanLogic
     */
    private $planLogic;

    /**
     * 采购单列表
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 0);
        $size = $argument->get('size', 25);
        $query = $this->getPagerQuery($argument);
        //获取数据
        $list = $this->planLogic->getPager($query, $idx, $size);

        //返回
        return $list;
    }

    /**
     * 采购单列表总数量
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //获取数据
        $count = $this->planLogic->getCount($query);

        //返回
        return $count;
    }

    /**
     * 查询状态类型
     * @return array
     * */
    public function getPstat()
    {
        $pstatData['pstat'] = PurDictData::PUR_PSTAT;

        return $pstatData;
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
     * 查询是否逾期
     * @return array
     * */
    public function getDelay()
    {
        $delayData['delay'] = PurDictData::PUR_DELAY;

        return $delayData;
    }

    /**
     * 查询时间类型
     * @return array
     * */
    public function getTimeType()
    {
        $timeType['timeType'] = PurDictData::PUR_TIMR;

        return $timeType;
    }

    /**
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        return [
            'pkey'  => $argument->get('pkey', ''),
            'pname' => $argument->get('pname', ''),
            'ptype' => $argument->get('ptype', 0),
            'dkey'  => $argument->get('dkey', ''),
            'okey'  => $argument->get('okey', ''),
            'did'  => $argument->get('did', ''),
            'timetype' => $argument->get('timetype', 0),//时间类型
            'time'  => $argument->get('time', []),
            'unum'  => $argument->get('unum', 0),
            'rnum'  => $argument->get('rnum', 0),
            'ucost' => $argument->get('ucost', 0),
            'rcost' => $argument->get('rcost', 0),
            'pstat' => $argument->get('pstat', 0),
            'delay' => $argument->get('delay', 0),
            'aacc'  => $argument->get('aacc', ''),
            'atime' => $argument->get('atime', 0),
            'utime' => $argument->get('utime', 0),
            'rtime' => $argument->get('rtime', 0)
        ];
    }

    /**
     * 保存采购计划
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pname", Validate::Required, "请填写计划名称")
     * @Validate("ptype", Validate::Required, "请选择计划类型")
     * @Validate("utime", Validate::Required, "请选择期望交付时间")
     * @Validate("demand", Validate::Required, "请选择机型参数")
     * @return string
     * @throws
     */
    public function savePlan(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $params = [
            'pkey' => $argument->post('pkey', ''),
            'pname' => $argument->post('pname', ''),
            'ptype' => $argument->post('ptype', 0),
            'utime' => $argument->post('utime', ''),
            'rmk1' => $argument->post('rmk1', ''),
            'demand' => $argument->post('demand', []),
        ];
        //保存数据
        $this->planLogic->savePlan($params, $acc);

        //返回
        return 'ok';
    }

    /**
     * 获取当前库存和成本
     * @param Argument $argument
     * @return array
     */
    public function getPrd(Argument $argument)
    {
        $param = [
            'bid'      => $argument->get('bid', 0),
            'mid'      => $argument->get('mid', 0),
            'level'    => $argument->get('level', 0),
            'mdram'    => $argument->get('mdram', 0),
            'mdcolor'  => $argument->get('mdcolor', 0),
            'mdofsale' => $argument->get('mdofsale', 0),
            'mdnet'    => $argument->get('mdnet', 0),
            'mdwarr'   => $argument->get('mdwarr', 0)
        ];

        return $this->planLogic->getPrd($param);
    }

    /**
     * 取消计划
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写计划编号")
     * @Validate("rmk", Validate::Required, "请填写计划备注")
     * @return string
     * */
    public function cancelPlan(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', '');
        $rmk = $argument->post('rmk', '');
        //保存数据
        $this->planLogic->cancelPlan($pkey, $rmk, $acc);

        //返回
        return 'ok';
    }

    /**
     * 中止计划
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写计划编号")
     * @Validate("rmk", Validate::Required, "请填写计划备注")
     * @return string
     * */
    public function stopPlan(Argument $argument)
    {
        // 外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', '');
        $rmk = $argument->post('rmk', '');
        // 保存数据
        $this->planLogic->stopPlan($pkey, $rmk, $acc);

        // 返回
        return 'ok';
    }

    /**
     * 完成计划
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写计划编号")
     * @Validate("rmk", Validate::Required, "请填写计划备注")
     * @return string
     * */
    public function completePlan(Argument $argument)
    {
        // 外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', '');
        $rmk = $argument->post('rmk', '');
        // 保存数据
        $this->planLogic->completePlan($pkey, $rmk, $acc);

        // 返回
        return 'ok';
    }

    /**
     * 查看计划详情
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写计划编号")
     * @return string
     * */
    public function planDeail(Argument $argument)
    {
        $pkey = $argument->post('pkey', '');
        //返回
        return $this->planLogic->planDeail($pkey);
    }
}
