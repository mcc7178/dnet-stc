<?php

namespace App\Module\Sale\Controller\Backend\Pur;

use App\Model\Crm\CrmMessageDotModel;
use App\Model\Pur\PurUserModel;
use App\Module\Sale\Logic\Backend\Pur\ExaminePlanLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Data\PurDictData;
use App\Module\Omr\Middleware\ContextMiddleware;

/**
 * 采购计划审核
 * Class ExaminePlanController
 * @Controller("/sale/backend/pur/examinePlan")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class ExaminePlanController extends BeanCollector
{
    /**
     * @Inject()
     * @var ExaminePlanLogic
     */
    private $examinePlanLogic;

    /**
     * 采购计划审核列表
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

        // 删除小红点
        $acc = Context::get('acc');
        CrmMessageDotModel::M()->delete(['uid' => $acc, 'src' => '1501']);
        //获取数据
        $list = $this->examinePlanLogic->getPager($query, $idx, $size);

        //返回
        return $list;
    }

    /**
     * 采购计划审核列表总数量
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //获取数据
        $count = $this->examinePlanLogic->getCount($query);

        //返回
        return $count;
    }

    /**
     * 采购计划审核详情
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写计划单号")
     * @return array
     * @throws
     */
    public function planDeail(Argument $argument)
    {
        $pkey = $argument->post('pkey', '');
        $pstat = $argument->post('pstat', '');

        //返回
        return $this->examinePlanLogic->planDeail($pkey, $pstat);
    }

    /**
     * 采购人列表
     * @return array
     * @throws
     * */
    public function getLacc()
    {
        return PurUserModel::M()->getList(['stat' => 1], 'acc,rname');
    }

    /**
     * 添加采购人信息
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写采购计划编号")
     * @Validate("dkey", Validate::Required, "请填写需求编号")
     * @return mixed
     * @throws
     */
    public function saveLacc(Argument $argument)
    {
        $pkey = $argument->post('pkey', '');
        $dkey = $argument->post('dkey', '');
        $taskList = $argument->post('taskList', []);

        return $this->examinePlanLogic->saveLacc($pkey, $dkey, $taskList);
    }

    /**
     * 采购计划通过审核
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写采购计划编号")
     * @return mixed
     * @throws
     * */
    public function agreePlan(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', '');
        $demandList = $argument->post('demandList', []);
        $rmk = $argument->post('rmk', '');

        return $this->examinePlanLogic->updateStat($pkey, $rmk, $acc, $demandList);
    }

    /**
     * 计划单修改
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写采购计划编号")
     * @return mixed
     * @throws
     * */
    public function editPlan(Argument $argument)
    {
        $pkey = $argument->post('pkey', '');

        return $this->examinePlanLogic->editPlan($pkey);
    }

    /**
     * 计划单驳回
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写采购计划编号")
     * @Validate("rmk", Validate::Required, "请填写驳回备注")
     * @return mixed
     * @throws
     * */
    public function rejectPlan(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', '');
        $rmk = $argument->post('rmk', '');

        return $this->examinePlanLogic->rejectPlan($pkey, $rmk, $acc);
    }

    /**
     * 需求分配详情
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写采购计划编号")
     * @Validate("dkey", Validate::Required, "请填写需求编号")
     * */
    public function taskDetail(Argument $argument)
    {
        $pkey = $argument->post('pkey', '');
        $dkey = $argument->post('dkey', '');

        return $this->examinePlanLogic->taskDetail($pkey, $dkey);
    }

    /**
     * 采购人分配需求详情
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写采购计划编号")
     * @Validate("dkey", Validate::Required, "请填写需求编号")
     * @return array
     * @throws
     * */
    public function taskDemand(Argument $argument)
    {
        $pkey = $argument->post('pkey', '');
        $dkey = $argument->post('dkey', '');

        return $this->examinePlanLogic->taskDemand($pkey, $dkey);
    }

    /**
     * 完成结果展示
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("pkey", Validate::Required, "请填写采购计划编号")
     * @return mixed
     * @throws
     * */
    public function getComplete(Argument $argument)
    {
        $pkey = $argument->post('pkey', '');

        return $this->examinePlanLogic->getComplete($pkey);

    }

    /**
     * 获取审核状态
     * @return array
     * */
    public function getCstat()
    {
        $cstatData['cstat'] = PurDictData::PUR_PLAN_CSTAT;

        return $cstatData;
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
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        return [
            'pkey' => $argument->get('pkey', ''),
            'dkey' => $argument->get('dkey', ''),
            'okey' => $argument->get('okey', ''),
            'pname' => $argument->get('pname', ''),
            'ptype' => $argument->get('ptype', 0),
            'timetype' => $argument->get('timetype', 0),//时间类型
            'time' => $argument->get('time', []),
            'pstat' => $argument->get('pstat', 0),
            'aacc' => $argument->get('aacc', ''),
        ];
    }
}
