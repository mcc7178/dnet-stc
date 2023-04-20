<?php
namespace App\Module\Sale\Logic\Backend\Pur;

use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use App\Exception\AppException;
use App\Model\Pur\PurPlanModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use App\Model\Acc\AccUserModel;
use App\Model\Pur\PurTaskModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Model\Prd\PrdProductModel;
use Swork\Db\Db;
use App\Amqp\AmqpQueue;
use Swork\Client\Amqp;
use App\Model\Crm\CrmStaffModel;

/**
 * 新增采购计划
 * Class PlanLogic
 * @package App\Module\Sale\Logic\Backend\Pur
 */
class PlanLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * 声明普通任务队列
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 采购单列表
     * @param array $query 搜索
     * @param int $idx 页码
     * @param int $size 页量
     * @return array
     */
    public function getPager(array $query, int $idx = 1, $size = 25)
    {
        //获取翻页数据
        $where = $this->getPagerWhere($query);
        $cols = 'pkey,pname,ptype,utime,rtime,unum,rnum,ucost,rcost,pstat,delay,aacc,atime,ptime5,ptime6,ptime7';
        $orderBy = ['utime' => -1];
        $list = PurPlanModel::M()->getList($where, $cols, $orderBy, $size, $idx);
        if ($list == false)
        {
            return [];
        }

        $rnames = ArrayHelper::map($list, 'aacc');
        $rnameData = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $rnames]], 'rname');

        //数据处理
        $datas = [];
        foreach ($list as $key => $value)
        {
            if ($value['utime'] < time())
            {
                $datas[] = [
                    'pkey' => $value['pkey'],
                    'delay' => 2
                ];
            }

            $list[$key]['utime'] = DateHelper::toString($value['utime']) ?? '-';
            $list[$key]['rtime'] = DateHelper::toString($value['rtime']) ?? '-';
            $list[$key]['atime'] = DateHelper::toString($value['atime']) ?? '-';
            $list[$key]['ptime5'] = DateHelper::toString($value['ptime5']) ?? '-';
            $list[$key]['ptime6'] = DateHelper::toString($value['ptime6']) ?? '-';
            $list[$key]['ptime7'] = DateHelper::toString($value['ptime7']) ?? '-';
            $list[$key]['pstat'] = PurDictData::PUR_PSTAT[$value['pstat']] ?? '-';
            $list[$key]['ptype'] = PurDictData::PUR_PLAN_TYPE[$value['ptype']] ?? '-';
            $list[$key]['delay'] = PurDictData::PUR_DELAY[$value['delay']] ?? '-';
            $rname = $rnameData[$value['aacc']]['rname'];
            $list[$key]['aacc'] = $rname ?? '-';

        }

        // 更新逾期
        PurPlanModel::M()->inserts($datas, true);

        //返回数据
        return $list;
    }

    /**
     * 采购单总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);
        $count = PurPlanModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 新增采购单保存
     * @param array $param
     * @param string $acc 创建人
     * @return mixed
     * @throws
     */
    public function savePlan(array $param, string $acc)
    {
        $time = time();

        // 期望交付时间验证
        $param['utime'] = strtotime($param['utime']);
        if ($param['utime'] < time())
        {
            throw new AppException('交付时间应大于等于当前时间！');
        }

        foreach ($param['demand'] as $key => $val)
        {
            if (!$val['bid'])
            {
                throw new AppException('请选择品牌！');
            }
            if (!$val['mid'])
            {
                throw new AppException('请选择机型！');
            }
            if (!$val['level'])
            {
                throw new AppException('请选择品级！');
            }

            if (!is_numeric($val['unum']) || !$val['unum'])
            {
                throw new AppException('请填写正确的预计数量！');
            }
            if (!is_numeric($val['ucost']) || !$val['ucost'])
            {
                throw new AppException('请填写正确的预计成本！');
            }
            if (!is_numeric($val['uamt']) || !$val['uamt'])
            {
                throw new AppException('请填写正确的预计售价！');
            }

        }

        if ($param['pkey'])
        {
            $pkey = $param['pkey'];
            $data = [
                'pname' => $param['pname'],
                'ptype' => $param['ptype'],
                'utime' => $param['utime'],
                'lacc' => $acc,
                'rmk1' => $param['rmk1'],
                'pstat' => 1,
                'ptime1' => $time,
                'ltime' => $time
            ];
            if (!PurPlanModel::M()->update(['pkey' => $param['pkey']], $data))
            {
                throw new AppException('计划修改失败！', AppException::FAILED_UPDATE);
            }

            //删除原有采购需求单数据
            PurDemandModel::M()->delete(['pkey' => $pkey]);

            //新增修改后的需求单数据
            foreach ($param['demand'] as $key => $val)
            {
                $dkey = $this->uniqueKeyLogic->getUniversal('DE', 3);
                $demands[] = [
                    'dkey' => $dkey,
                    'pkey' => $pkey,
                    'bid' => $val['bid'] ?: 0,
                    'mid' => $val['mid'] ?: 0,
                    'level' => $val['level'] ?: 0,
                    'mdram' => $val['mdram'] ?: 0,
                    'mdcolor' => $val['mdcolor'] ?: 0,
                    'mdofsale' => $val['mdofsale'] ?: 0,
                    'mdnet' => $val['mdnet'] ?: 0,
                    'mdwarr' => $val['mdwarr'] ?: 0,
                    'unum' => $val['unum'] ?: 0,
                    'ucost' => $val['ucost'] ?: 0,
                    'uamt' => $val['uamt'] ?: 0,
                    'instc' => $val['count'] ?: 0,
                    'incost' => $val['prdcost'] ?: 0,
                    'ltime' => $time,
                    'dstat' => 1
                ];
            }
            PurDemandModel::M()->inserts($demands, 1);
        }
        else
        {
            // 新增计划
            //参数校验
            if (mb_strlen($param['pname']) > 20)
            {
                throw new AppException('计划名称长度为20个字！');
            }
            if (PurPlanModel::M()->exist(['pname' => $param['pname']]))
            {
                throw new AppException('计划名称已存在，请重新输入！');
            }
            // 缺少预计总数量、预计总价保存数据
            $pkey = $this->uniqueKeyLogic->getUniversal('PL', 3);
            $data = [
                'pkey' => $pkey,
                'pname' => $param['pname'],
                'ptype' => $param['ptype'],
                'utime' => $param['utime'],
                'aacc' => $acc,
                'rmk1' => $param['rmk1'],
                'pstat' => 1,
                'delay' => 1,
                'ptime1' => $time,
                'atime' => $time
            ];
            $pkey = PurPlanModel::M()->insert($data);
            if (!$pkey)
            {
                throw new AppException('计划创建失败！', AppException::FAILED_UPDATE);
            }

            // 新增需求
            foreach ($param['demand'] as $key => $val)
            {
                $where = [
                    'bid' => $val['bid'],
                    'mid' => $val['mid'],
                    'level' => $val['level'],
                    'prdstat' => 1,
                    'stcstat' => 11
                ];
                if ($val['mdram'])
                {
                    $where['mdram'] = $val['mdram'];
                }
                if ($val['mdcolor'])
                {
                    $where['mdcolor'] = $val['mdcolor'];
                }
                if ($val['mdofsale'])
                {
                    $where['mdofsale'] = $val['mdofsale'];
                }
                if ($val['mdnet'])
                {
                    $where['mdnet'] = $val['mdnet'];
                }
                if ($val['mdwarr'])
                {
                    $where['mdwarr'] = $val['mdwarr'];
                }

                $prdData = PrdProductModel::M()->getRow($where, 'count(1) as count,sum(prdcost) as prdcost');
                $dkey = $this->uniqueKeyLogic->getUniversal('DE', 3);
                $demands[] = [
                    'dkey' => $dkey,
                    'pkey' => $pkey,
                    'bid' => $val['bid'],
                    'ptype' => $param['ptype'],
                    'utime' => $param['utime'],
                    'mid' => $val['mid'],
                    'level' => $val['level'],
                    'mdram' => intval($val['mdram']),
                    'mdcolor' => intval($val['mdcolor']),
                    'mdofsale' => intval($val['mdofsale']),
                    'mdnet' => intval($val['mdnet']),
                    'mdwarr' => intval($val['mdwarr']),
                    'unum' => $val['unum'],
                    'ucost' => $val['ucost'],
                    'uamt' => $val['uamt'],
                    'instc' => $prdData['count'],
                    'incost' => intval($prdData['prdcost']),
                    'atime' => $time,
                    'dstat' => 1
                ];
            }
            PurDemandModel::M()->inserts($demands);
        }

        //统计采购计划总预计成本
        $planUsum = PurDemandModel::M()->getRow(['pkey' => $pkey], 'sum(unum) as unum,sum(unum*ucost) as ucost');
        PurPlanModel::M()->update(['pkey' => $pkey], [
            'unum' => intval($planUsum['unum']),
            'ucost' => intval($planUsum['ucost'])
        ]);

        // 添加小红点
        $accData = CrmStaffModel::M()
            ->join(AccUserModel::M(), ['acc' => 'aid'])
            ->getList(['B.permis' => ['like' => '%sale_backend_pur0002%']], 'aid', [], 20);
        foreach ($accData as $acc)
        {
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1501, 'uid' => $acc['aid']]);
        }
    }

    /**
     * 获取当前库存和成本
     * @param array $param
     * @return array
     *
     */
    public function getPrd($param)
    {
        $where = [];
        if ($param['bid'])
        {
            $where['bid'] = $param['bid'];
        }
        if ($param['mid'])
        {
            $where['mid'] = $param['mid'];
        }
        if ($param['level'])
        {
            $where['level'] = $param['level'];
        }
        if ($param['mdram'])
        {
            $where['mdram'] = $param['mdram'];
        }
        if ($param['mdcolor'])
        {
            $where['mdcolor'] = $param['mdcolor'];
        }
        if ($param['mdofsale'])
        {
            $where['mdofsale'] = $param['mdofsale'];
        }
        if ($param['mdnet'])
        {
            $where['mdnet'] = $param['mdnet'];
        }
        if ($param['mdwarr'])
        {
            $where['mdwarr'] = $param['mdwarr'];
        }
        $where['prdstat'] = 1;
        $where['stcstat'] = 11;
        $prdData = PrdProductModel::M()->getRow($where, 'count(*) as count,sum(prdcost) as prdcost');
        $prdData['count'] = $prdData['count'] ?: 0;
        $prdData['prdcost'] = $prdData['prdcost'] ?: 0;

        return $prdData;
    }

    /**
     * 取消计划
     * @param string $pkey
     * @param string $rmk
     * @param string $acc
     * @return string
     */
    public function cancelPlan(string $pkey, string $rmk, string $acc)
    {
        $time = time();
        try
        {
            // 开启事务
            Db::beginTransaction();
            // 更新计划表状态
            PurPlanModel::M()->update(['pkey' => $pkey], ['pstat' => 7, 'rmk1' => $rmk, 'lacc' => $acc, 'ltime' => $time, 'ptime7' => $time]);
            // 更新需求表状态
            PurDemandModel::M()->update(['pkey' => $pkey], ['dstat' => 7, 'ltime' => $time]);
            // 更新需求计划表状态
            PurTaskModel::M()->update(['pkey' => $pkey], ['tstat' => 3]);

            // 提交事务
            Db::commit();
        }
        catch (\Throwable $exception)
        {
            // 回滚事务
            Db::rollback();

            // 抛出异常
            throw $exception;
        }

        return 'ok';
    }

    /**
     * 中止计划
     * @param string $pkey
     * @param string $rmk
     * @param string $acc
     * @return string
     */
    public function stopPlan(string $pkey, string $rmk, string $acc)
    {
        $time = time();
        try
        {
            // 开启事务
            Db::beginTransaction();
            // 更新计划表状态
            PurPlanModel::M()->update(['pkey' => $pkey], ['pstat' => 6, 'rmk1' => $rmk, 'lacc' => $acc, 'ltime' => $time, 'ptime6' => $time]);
            // 更新需求表状态
            PurDemandModel::M()->update(['pkey' => $pkey], ['dstat' => 6, 'ltime' => $time]);
            // 更新需求计划表状态
            PurTaskModel::M()->update(['pkey' => $pkey], ['tstat' => 4]);
            // 采购详情
            PurOdrDemandModel::M()->update(['pkey' => $pkey], ['dstat' => 3]);
            // 采购单
            $orderList = PurOdrDemandModel::M()->getList(['pkey' => $pkey], 'okey');
            $orderData = [];
            foreach ($orderList as $list)
            {
                $orderData[] = [
                    'okey' => $list['okey'],
                    'ostat' => 3
                ];
            }
            PurOdrOrderModel::M()->inserts($orderData, 1);

            // 提交事务
            Db::commit();
        }
        catch (\Throwable $exception)
        {
            // 回滚事务
            Db::rollback();

            // 抛出异常
            throw $exception;
        }

        return 'ok';
    }

    /**
     * 完成计划
     * @param string $pkey
     * @param string $rmk
     * @param string $acc
     * @return string
     */
    public function completePlan(string $pkey, string $rmk, string $acc)
    {
        $time = time();
        try
        {
            // 开启事务
            Db::beginTransaction();
            // 更新计划表状态
            PurPlanModel::M()->update(['pkey' => $pkey], ['pstat' => 5, 'rmk1' => $rmk, 'lacc' => $acc, 'ltime' => $time, 'ptime5' => $time]);
            // 更新需求表状态
            PurDemandModel::M()->update(['pkey' => $pkey], ['dstat' => 5, 'ltime' => $time]);
            // 更新需求计划表状态
            PurTaskModel::M()->update(['pkey' => $pkey], ['tstat' => 3]);

            // 提交事务
            Db::commit();
        }
        catch (\Throwable $exception)
        {
            // 回滚事务
            Db::rollback();

            // 抛出异常
            throw $exception;
        }

        return 'ok';
    }

    /**
     * 计划单详情
     * @param string $pkey
     * @return array
     * @throws
     */
    public function planDeail(string $pkey)
    {
        $data = [];

        $planCols = 'pkey,pname,ptype,utime,pstat,aacc,rmk1,rmk2,atime,unum,ucost,lacc,cacc,ctime';

        $planData = PurPlanModel::M()->getRow(['pkey' => $pkey], $planCols);
        $pstat = $planData['pstat'];
        $planData['pstat'] = PurDictData::PUR_PSTAT[$planData['pstat']] ?? '-';
        $planData['ptype'] = PurDictData::PUR_PLAN_TYPE[$planData['ptype']] ?? '-';
        $planData['atime'] = DateHelper::toString($planData['atime']) ?? '-';
        $planData['utime'] = DateHelper::toString($planData['utime']) ?? '-';
        $planData['ctime'] = DateHelper::toString($planData['ctime']) ?? '-';

        // 计划单提交人
        $rname = AccUserModel::M()->getRow(['aid' => $planData['aacc']], 'rname');
        $planData['aacc'] = $rname['rname'];

        // 最后修改人
        $lname = AccUserModel::M()->getRow(['aid' => $planData['lacc']], 'rname');
        $planData['lacc'] = $lname['rname'] ?? '-';

        // 审核人
        $lname = AccUserModel::M()->getRow(['aid' => $planData['cacc']], 'rname');
        $planData['cacc'] = $lname['rname'];

        // 预计数量
        $plannumData = PurDemandModel::M()->getRow(['pkey' => $pkey], 'SUM(unum) as sumunum');
        $planData['unum'] = $plannumData['sumunum'];

        // 预计成本总价
        $usumcost = PurDemandModel::M()->getRow(['pkey' => $pkey], 'SUM(unum * ucost) as usumcost');
        $planData['usumcost'] = sprintf("%.2f", $usumcost['usumcost']);

        // 预计销售总价
        $usumamt = PurDemandModel::M()->getRow(['pkey' => $pkey], 'SUM(unum * uamt) as usumamt');
        $planData['usumamt'] = sprintf("%.2f", $usumamt['usumamt']);

        // 采购数量
        // 采购总价
        $ordersData = PurOdrDemandModel::M()->getRow(['pkey' => $pkey], 'SUM(pnum) as sumpnum, SUM(pnum * scost) as sumnum');

        if ($pstat == 7)
        {
            $ordersData = PurOdrDemandModel::M()->getRow(['pkey' => $pkey], 'SUM(rnum) as sumpnum, SUM(rnum * scost) as sumnum');
        }

        // 采购单价
        $dpnum = $ordersData['sumpnum'] ?? 0;
        $dsumnum = $ordersData['sumnum'] ?? 0;
        if (intval($dpnum) > 0)
        {
            $scost = sprintf("%.2f", intval($dsumnum) / intval($dpnum)) ?? 0;
        }

        // 当前总库存/总成本
        $inData = PrdProductModel::M()->getRow(['inway' => 51, 'prdstat' => 1], 'count(1) as suminstc,sum(prdcost) sumincost');
        $inData['suminstc'] = $inData['suminstc'] ?? 0;
        $inData['sumincost'] = sprintf("%.2f", $inData['sumincost']) ?? 0;

        // 组装计划详情列表数据
        if ($pstat == 4)
        {
            $planInfo[] = ['label' => '计划单号', 'lname' => $pkey];
            $planInfo[] = ['label' => '计划名称', 'lname' => $planData['pname']];
            $planInfo[] = ['label' => '计划类型', 'lname' => $planData['ptype']];
            $planInfo[] = ['label' => '状态', 'lname' => $planData['pstat']];
            $planInfo[] = ['label' => '创建时间', 'lname' => $planData['atime']];
            $planInfo[] = ['label' => '期望交付时间', 'lname' => $planData['utime']];
            $planInfo[] = ['label' => '创建人', 'lname' => $planData['aacc']];
            $planInfo[] = ['label' => '当前总库存/成本', 'lname' => $inData['suminstc'] . '/' . $inData['sumincost']];
            $planInfo[] = ['label' => '预计数量', 'lname' => $planData['unum']];
            $planInfo[] = ['label' => '预计成本总价', 'lname' => $planData['usumcost'] ?? 0];
            $planInfo[] = ['label' => '预计销售总价', 'lname' => $planData['usumamt'] ?? 0];
        }
        else
        {
            $planInfo[] = ['label' => '计划单号', 'lname' => $pkey];
            $planInfo[] = ['label' => '计划名称', 'lname' => $planData['pname']];
            $planInfo[] = ['label' => '计划类型', 'lname' => $planData['ptype']];
            $planInfo[] = ['label' => '状态', 'lname' => $planData['pstat']];
            $planInfo[] = ['label' => '创建时间', 'lname' => $planData['atime']];
            $planInfo[] = ['label' => '期望交付时间', 'lname' => $planData['utime']];
            $planInfo[] = ['label' => '创建人', 'lname' => $planData['aacc']];
            $planInfo[] = ['label' => '当前总库存/成本', 'lname' => $inData['suminstc'] . '/' . $inData['sumincost']];
            $planInfo[] = ['label' => '预计数量', 'lname' => $planData['unum']];
            $planInfo[] = ['label' => '预计成本总价', 'lname' => $planData['usumcost'] ?? 0];
            $planInfo[] = ['label' => '预计销售总价', 'lname' => $planData['usumamt'] ?? 0];
            $planInfo[] = ['label' => '采购单价', 'lname' => $scost ?? 0];
            $planInfo[] = ['label' => '采购数', 'lname' => $dpnum ?? 0];
            $planInfo[] = ['label' => '采购费用', 'lname' => $dsumnum ?? 0];
        }

        $data['planInfo'] = $planInfo;
        $data['pstat'] = $pstat;
        $data['pkey'] = $pkey;
        $data['cacc'] = $planData['cacc'] ?? '-';
        $data['lacc'] = $planData['lacc'] ?? '-';
        $data['rmk1'] = $planData['rmk1'] ?? '-';
        $data['rmk2'] = $planData['rmk2'] ?? '-';
        $data['ctime'] = $planData['ctime'] ?? '-';
        $demandCols = 'dkey,bid,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr,unum,ucost,uamt,instc,incost,dstat';
        $demandList = PurDemandModel::M()->getList(['pkey' => $pkey], $demandCols);

        if (!$demandList)
        {
            throw new AppException('对应的需求单不存在');
        }

        //获取机型类目选项字典
        $optCols = ['mdofsale', 'mdnet', 'mdcolor', 'mdram', 'mdwarr'];
        $optOids = ArrayHelper::maps([$demandList, $demandList, $demandList, $demandList, $demandList], $optCols);
        $optionsDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $optOids]], 'oid,oname');

        // 获取需求单号字典
        $dkeys = ArrayHelper::map($demandList, 'dkey');
        // 已完成
        $finishNum = PurOdrGoodsModel::M()->getDict('dkey', ['dkey' => ['in' => $dkeys], 'gstat' => 4, '$group' => 'dkey'], 'count(gid) as finishNum');
        // 已入库
        $stcNum = PurOdrGoodsModel::M()->getDict('dkey', ['dkey' => ['in' => $dkeys], 'prdstat' => ['in' => [1,2,3]], '$group' => 'dkey'], 'count(gid) as stcNum');
        // 已退货
        $rejectNum = PurOdrGoodsModel::M()->getDict('dkey', ['dkey' => ['in' => $dkeys], 'gstat' => 5,  'prdstat' => 3, '$group' => 'dkey'], 'count(gid) as rejectNum');
        // 已完成单价
        $fscost = PurOdrDemandModel::M()->getDict('dkey', ['dkey' => ['in' => $dkeys], 'dstat' => ['in' => [1,2]], '$group' => 'dkey'], 'SUM(pnum * scost) as sumnum');

        foreach ($demandList as $key => $value)
        {
            $bname = QtoBrandModel::M()->getRow(['bid' => $value['bid']], 'bname');
            $mname = QtoModelModel::M()->getRow(['mid' => $value['mid']], 'mname');
            $lname = QtoLevelModel::M()->getRow(['lkey' => $value['level']], 'lname');
            $deitemData = [
                'bname' => $bname['bname'],
                'mname' => $mname['mname'],
                'lname' => $lname['lname'],
                'mdram' => $optionsDict[$value['mdram']]['oname'] ?? '',
                'mdcolor' => $optionsDict[$value['mdcolor']]['oname'] ?? '',
                'mdofsale' => $optionsDict[$value['mdofsale']]['oname'] ?? '',
                'mdnet' => $optionsDict[$value['mdnet']]['oname'] ?? '',
                'mdwarr' => $optionsDict[$value['mdwarr']]['oname'] ?? '',
            ];

            $deitem = implode('/', array_filter($deitemData));
            $demandList[$key]['deitem'] = $deitem;

            $demandList[$key]['dstat'] = PurDictData::PUR_DSTAT[$value['dstat']] ?? '-';
            $demandList[$key]['pnum'] = PurOdrDemandModel::M()->getSum(['pkey' => $pkey, 'dkey' => $value['dkey'], 'cstat' => 2], 'pnum');
            $sql = "SELECT SUM(pnum * scost) as sumnum FROM `pur_odr_demand` WHERE pkey = '" . $pkey . "'" . "AND dkey = '" . $value['dkey'] . "'" . "AND cstat = 2";
            $demandList[$key]['sumnum'] = PurOdrDemandModel::M()->doQuery($sql);
            $demandList[$key]['sumnum'] = $demandList[$key]['sumnum']['Results'][0]['sumnum'] ?? 0;
            $demandList[$key]['pnum'] = $demandList[$key]['pnum'][0]['pnum'] ?? 0;

            // 预计总成本
            $demandList[$key]['usumcost'] = sprintf("%.2f", $demandList[$key]['unum'] * $demandList[$key]['ucost']);

            // 预计总售价
            $demandList[$key]['usumamt'] = sprintf("%.2f", $demandList[$key]['unum'] * $demandList[$key]['uamt']);

            // 入库数量、采购单价
            $demandsData = PurOdrDemandModel::M()->getRow(['pkey' => $pkey, 'dkey' => $value['dkey']], 'SUM(snum) as ssnum');
            $demandList[$key]['snum'] = $demandsData['ssnum'] ?? 0;

            // 采购数量
            // 采购总价
            $orderData = PurOdrDemandModel::M()->getRow(['dkey' => $value['dkey']], 'SUM(pnum) as sumpnum, SUM(pnum * scost) as sumnum');

            if ($pstat == 7)
            {
                $orderData = PurOdrDemandModel::M()->getRow(['dkey' => $value['dkey']], 'SUM(rnum) as sumpnum, SUM(rnum * scost) as sumnum');
            }

            // 采购单价
            $dpnum = $orderData['sumpnum'] ?? 0;
            $dsumnums = $orderData['sumnum'] ?? 0;
            if (intval($dpnum) > 0)
            {
                $demandList[$key]['scost'] = sprintf("%.2f", intval($dsumnums) / intval($dpnum)) ?? 0;
            }
            $demandList[$key]['scost'] = $demandList[$key]['scost'] ?? 0;
            // $demandList[$key]['snum'] = $dpnum;
            $demandList[$key]['scostSum'] = $dsumnums;

            // 完成数量
            $pnum = PurOdrDemandModel::M()->getRow(['dkey' => $value['dkey']], 'SUM(pnum) as sumpnum');
            // 退货数量
            $goodscost = PurOdrGoodsModel::M()->getCount(['pkey' => $pkey, 'dkey' => $value['dkey'], 'gstat' => 5]);
            $demandList[$key]['pnum'] = $pnum['sumpnum'] ?? 0;
            $demandList[$key]['goodscost'] = $goodscost ?? 0;

            // 已完成
            $demandList[$key]['finishNum'] = $finishNum[$value['dkey']]['finishNum'] ?? 0;

            // 已入库
            $demandList[$key]['stcNum'] = $stcNum[$value['dkey']]['stcNum'] ?? 0;

            // 已退货
            $demandList[$key]['rejectNum'] = $rejectNum[$value['dkey']]['rejectNum'] ?? 0;

            // 已完成单价
            if ($demandList[$key]['finishNum'])
            {
                $demandList[$key]['fscost'] = sprintf("%.2f", $fscost[$value['dkey']]['sumnum'] / $finishNum[$value['dkey']]['finishNum']);
            }
            $demandList[$key]['fscost'] = $demandList[$key]['fscost'] ?? 0;

            // 完成时间
            if ($value['dstat'] == 5)
            {
                $ltime = PurOdrDemandModel::M()->getRow(['pkey' => $pkey, 'dkey' => $value['dkey']], 'ltime');
            }
            $demandList[$key]['ltime'] = DateHelper::toString($ltime['ltime'] ?? 0);
        }
        $data['demandList'] = $demandList;

        // 返回数据
        return $data;
    }

    /**
     * 保存采购机型数据条件
     * @param array $query
     * @return array
     */
    private function saveData(array $query)
    {
        $data = [];

        if (!empty($query['bid']))
        {
            $data['bid'] = $query['bid'];
        }

        if (!empty($query['mid']))
        {
            $data['mid'] = $query['mid'];
        }

        if (!empty($query['level']))
        {
            $data['level'] = $query['level'];
        }

        if (!empty($query['mdram']))
        {
            $data['mdram'] = $query['mdram'];
        }

        if (!empty($query['mdcolor']))
        {
            $data['mdcolor'] = $query['mdcolor'];
        }

        if (!empty($query['mdofsale']))
        {
            $data['mdofsale'] = $query['mdofsale'];
        }

        if (!empty($query['mdnet']))
        {
            $data['mdnet'] = $query['mdnet'];
        }

        if (!empty($query['mdwarr']))
        {
            $data['mdwarr'] = $query['mdwarr'];
        }

        if (!empty($query['unum']))
        {
            $data['unum'] = $query['unum'];
        }

        if (!empty($query['ucost']))
        {
            $data['ucost'] = $query['ucost'];
        }

        if (!empty($query['uamt']))
        {
            $data['uamt'] = $query['uamt'];
        }

        return $data;

    }

    /**
     * 采购计划列表翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        // 搜索查询条件
        $where = [];
        if ($query['pkey'])
        {
            $where['pkey'] = $query['pkey'];
        }

        if ($query['pname'])
        {
            $where['pname'] = $query['pname'];
        }

        if ($query['dkey'])
        {
            $pkey = PurDemandModel::M()->getRowById($query['dkey'], 'pkey');
            $where['pkey'] = $pkey['pkey'];
        }

        if ($query['okey'])
        {
            $purOdrDemand = PurOdrDemandModel::M()->getRow(['okey' => $query['okey']], 'pkey');
            $where['pkey'] = $purOdrDemand['pkey'];
        }

        if ($query['did'])
        {
            $where['did'] = $query['did'];
        }

        if ($query['aacc'])
        {
            $aid = AccUserModel::M()->getRow(['rname' => $query['aacc']], 'aid');
            $where['aacc'] = $aid['aid'];
        }

        if ($query['pstat'])
        {
            $where['pstat'] = $query['pstat'];
        }

        if ($query['ptype'])
        {
            $where['ptype'] = $query['ptype'];
        }

        if ($query['delay'])
        {
            $where['delay'] = $query['delay'];
        }

        if ($query['timetype'])
        {
            $time = [strtotime($query['time'][0] . '00:00:00'), strtotime($query['time'][1] . '23:59:59')];
            if ($query['timetype'] == 1)
            {
                $where['atime'] = ['between' => $time];
            }
            elseif ($query['timetype'] == 2)
            {
                $where['ctime'] = ['between' => $time];
            }
            elseif ($query['timetype'] == 3)
            {
                $where['ptime7'] = ['between' => $time];
            }
            elseif ($query['timetype'] == 4)
            {
                $where['ptime4'] = ['between' => $time];
            }
            elseif ($query['timetype'] == 5)
            {
                $where['ptime5'] = ['between' => $time];
            }
            elseif ($query['timetype'] == 6)
            {
                $where['ptime6'] = ['between' => $time];
            }
        }

        // 返回
        return $where;
    }
}