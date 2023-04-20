<?php

namespace App\Module\Sale\Logic\Backend\Pur;

use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurUserModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\BeanCollector;
use App\Exception\AppException;
use App\Model\Pur\PurPlanModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurOdrDemandModel;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use App\Model\Acc\AccUserModel;
use App\Model\Pur\PurTaskModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use Swork\Db\Db;
use Swork\Helper\IdHelper;

/**
 * 新增采购计划
 * Class ExaminePlanLogic
 * @package App\Module\Sale\Logic\Backend\Pur
 */
class ExaminePlanLogic extends BeanCollector
{

    /**
     * 采购计划列表
     * @param array $query 搜索
     * @param int $idx 页码
     * @param int $size 页量
     * @return array
     */
    public function getPager(array $query, int $idx = 1, $size = 25)
    {
        //获取翻页数据
        $where = $this->getPagerWhere($query);
        $cols = 'pkey,pname,ptype,utime,unum,rnum,ucost,rcost,pstat,delay,aacc,cacc,atime,ptime2,ctime';
        $orderBy = ['pstat' => 1, 'atime' => -1];

        // 获取列表数据
        $list = PurPlanModel::M()->getList($where, $cols, $orderBy, $size, $idx);

        // 数据处理
        foreach ($list as $key => $value)
        {
            $list[$key]['utime'] = DateHelper::toString($value['utime']) ?? '-';
            $list[$key]['atime'] = DateHelper::toString($value['atime']) ?? '-';
            $list[$key]['ctime'] = DateHelper::toString($value['ctime']) ?? '-';
            $list[$key]['ptime2'] = DateHelper::toString($value['ptime2']) ?? '-';
            $list[$key]['delay'] = PurDictData::PUR_DELAY[$value['delay']] ?? '-';
            $list[$key]['ptype'] = PurDictData::PUR_PLAN_TYPE[$value['ptype']] ?? '-';
            $rname = AccUserModel::M()->getRow(['aid' => $value['aacc']], 'rname');
            $ename = AccUserModel::M()->getRow(['aid' => $value['cacc']], 'rname');
            $list[$key]['aacc'] = $rname['rname'] ?? '-';
            $list[$key]['cacc'] = $ename['rname'] ?? '-';
            if ($value['unum'] > 0)
            {
                $list[$key]['uprice'] = sprintf("%.2f", $value['ucost'] / $value['unum']) ?? 0;
            }
            $list[$key]['uprice'] = $list[$key]['uprice'] ?? 0;

            if (in_array($value['pstat'], [2, 3, 5, 6, 7]))
            {
                $list[$key]['pstat'] = '已通过';
            }
            else
            {
                $list[$key]['pstat'] = PurDictData::PUR_PSTAT[$value['pstat']] ?? '-';
            }
        }

        // 返回数据
        return $list;
    }

    /**
     * 采购计划审核总条数
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
     * 采购计划审核详情
     * @param string $pkey
     * @param string $pstat
     * @return mixed
     * @throws
     */
    public function planDeail(string $pkey, string $pstat)
    {
        $data = [];
        if ($pstat != 4)
        {
            $planCols = 'pkey,pname,ptype,utime,pstat,aacc,rmk1,atime,unum,ucost,lacc,cacc,ctime';
        }
        else
        {
            $planCols = 'pkey,pname,ptype,utime,pstat,aacc,rmk2,atime,unum,ucost,lacc,cacc,ctime';
        }
        $planData = PurPlanModel::M()->getRow(['pkey' => $pkey], $planCols);
        $pstat = $planData['pstat'];

        if (in_array($pstat, [2, 3, 5, 6, 7]))
        {
            $planData['pstat'] = '已通过';
        }
        else
        {
            $planData['pstat'] = PurDictData::PUR_PSTAT[$planData['pstat']] ?? '-';
        }

        $planData['ptype'] = PurDictData::PUR_PLAN_TYPE[$planData['ptype']] ?? '-';
        $planData['atime'] = DateHelper::toString($planData['atime']) ?? '-';
        $planData['utime'] = DateHelper::toString($planData['utime']) ?? '-';
        $planData['ctime'] = DateHelper::toString($planData['ctime']) ?? '-';

        // 计划单提交人
        $rname = AccUserModel::M()->getRow(['aid' => $planData['aacc']], 'rname');
        $planData['aacc'] = $rname['rname'];

        // 最后修改人
        $lname = AccUserModel::M()->getRow(['aid' => $planData['lacc']], 'rname');
        $planData['lacc'] = $lname['rname'];

        // 审核人
        $lname = PurUserModel::M()->getRow(['acc' => $planData['cacc']], 'rname');
        $planData['cacc'] = $lname['rname'];
        $data['cacc'] = $planData['cacc'] ?? '-';
        $data['lacc'] = $planData['lacc'] ?? '-';
        $data['ctime'] = $planData['ctime'];
        $data['rmk1'] = $planData['rmk1'];

        // 预计销售总价
        $sql = "SELECT SUM(unum * uamt) as usumamt FROM pur_demand WHERE pkey = '" . $pkey . "'";
        $data = PurDemandModel::M()->doQuery($sql);
        $planData['usumamt'] = $data['Results'][0]['usumamt'];

        //采购总价
        $odWhere = [
            'pkey' => $pkey,
            'cstat' => 2,
        ];
        $sumnum = PurOdrDemandModel::M()->getRow($odWhere,'SUM(pnum * scost) as sumcost,sum(pnum) as pnum');

        // 采购单价计算
        $dpnum = $sumnum['pnum'] ?? 0;//采购数量
        $dsumcost = $sumnum['sumcost'] ?? 0;//采购总价
        if (intval($dpnum) > 0)
        {
            $scost = sprintf("%.2f", intval($dsumcost) / intval($dpnum)) ?? 0;
        }

        // 当前总库存/总成本 (已确认采购)
        $inData = PrdProductModel
            ::M()->getRow(['recstat' => 7, 'inway' => 51, 'prdstat' => 1], 'count(1) as suminstc,sum(prdcost) sumincost');
        $inData['suminstc'] = $inData['suminstc'] ?? 0;
        $inData['sumincost'] = sprintf("%.2f", $inData['sumincost']) ?? 0;

        // 组装计划详情列表数据
        $planInfo[] = ['label' => '计划单号', 'lname' => $pkey];
        $planInfo[] = ['label' => '计划名称', 'lname' => $planData['pname']];
        $planInfo[] = ['label' => '计划类型', 'lname' => $planData['ptype']];
        $planInfo[] = ['label' => '状态', 'lname' => $planData['pstat']];
        $planInfo[] = ['label' => '创建时间', 'lname' => $planData['atime']];
        $planInfo[] = ['label' => '期望交付时间', 'lname' => $planData['utime']];
        $planInfo[] = ['label' => '创建人', 'lname' => $planData['aacc']];
        $planInfo[] = ['label' => '当前总库存/成本', 'lname' => $inData['suminstc'] . '/' . $inData['sumincost']];
        $planInfo[] = ['label' => '预计数量', 'lname' => $planData['unum']];
        $planInfo[] = ['label' => '预计成本总价', 'lname' => $planData['ucost']];
        $planInfo[] = ['label' => '预计销售总价', 'lname' => $planData['usumamt'] ?? 0];

        $data['planInfo'] = $planInfo;
        $data['pstat'] = $pstat;
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

        //级别字典
        $levelDict = QtoLevelModel::M()->getDict('lkey');

        //获取品牌+机型字典
        $mids = ArrayHelper::map($demandList, 'mid', -1);
        $bids = ArrayHelper::map($demandList, 'bid', -1);
        $modelDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');
        $brandDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');

        foreach ($demandList as $key => $value)
        {
            $deitemData = [
                'bname' => $brandDict[$value['bid']]['bname'],
                'mname' => $modelDict[$value['mid']]['mname'],
                'lname' => $levelDict[$value['level']]['lname'] ?? '',
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

            //分配任务
            $taskList = PurTaskModel::M()->getList(['pkey' => $pkey, 'dkey' => $value['dkey']], 'pacc, unum');
            $taskData = [];
            foreach ($taskList as $k => $v)
            {
                $lname = PurUserModel::M()->getRow(['acc' => $v['pacc']], 'rname');
                $v['pacc'] = $lname['rname'];
                $taskData[$k] = implode(' ', $v);
            }
            $demandList[$key]['task'] = implode(';', $taskData);

            // 完成单价
            //$demandList[$key]['scost'] = sprintf("%.2f", $value['scost']) ?? 0;

            // 预计成本总价
            $demandList[$key]['usumcost'] = sprintf("%.2f", $value['unum'] * $value['ucost']);

            // 预计成本总价
            $demandList[$key]['usumamt'] = sprintf("%.2f", $value['unum'] * $value['uamt']);

            // 最近30天入库、销量
            $where = [
                'bid' => $value['bid'],
                'mid' => $value['mid'],
                'level' => $value['level'],
            ];
            if ($value['mdram'])
            {
                $where['mdram'] = $value['mdram'];
            }
            if ($value['mdcolor'])
            {
                $where['mdcolor'] = $value['mdcolor'];
            }
            if ($value['mdofsale'])
            {
                $where['mdofsale'] = $value['mdofsale'];
            }
            if ($value['mdnet'])
            {
                $where['mdnet'] = $value['mdnet'];
            }
            if ($value['mdwarr'])
            {
                $where['mdwarr'] = $value['mdwarr'];
            }
            $time = [strtotime(date("Y-m-d", strtotime("-30 day")) . "00:00:00"), strtotime(date("Y-m-d") . "23:59:59")];
            $where['rectime4'] = ['between' => $time];

            //最近三十天数据，保留暂时不要
//            $moninstc = PrdProductModel::M()->getRow($where, 'count(1) as count');
//            unset($where['rectime4']);
//
//            $where['saletime'] = ['between' => $time];
//            $monincost = PrdProductModel::M()->getRow($where, 'count(1) as count');
//            if ($moninstc['count'] > 0)
//            {
//                $ratio = sprintf("%.2f", $monincost['count'] / $moninstc['count']) * 100 . '%';
//            }
//            $demandList[$key]['moninstc'] = $moninstc['count'] ?? 0;
//            $demandList[$key]['monincost'] = $monincost['count'] ?? 0;
//            $demandList[$key]['ratio'] = $ratio ?? 0;
        }
        $data['demandList'] = $demandList;

        // 返回数据
        return $data;
    }

    /**
     * 保存采购人信息
     * @param string $pkey
     * @param string $dkey
     * @param array $taskList
     * @throws
     */
    public function saveLacc(string $pkey, string $dkey, array $taskList)
    {
        if (empty($taskList))
        {
            throw new AppException('请分配采购人！');
        }

        $newData = array_unique(array_values(array_column($taskList, 'pacc')));
        if (array_diff_assoc(array_values(array_column($taskList, 'pacc')), $newData))
        {
            throw new AppException('同一采购人不能选择多次！');
        }

        $unum = PurDemandModel::M()->getRow(['pkey' => $pkey, 'dkey' => $dkey], 'unum');
        $numData = array_column($taskList, 'unum');
        if (array_sum($numData) != $unum['unum'])
        {
            throw new AppException('分配数量要等于需求单预计数量！');
        }
        //删除原有分配信息
        PurTaskModel::M()->delete(['pkey' => $pkey, 'dkey' => $dkey]);
        foreach ($taskList as $key => $val)
        {
            $taskData[] = [
                'tid' => IdHelper::generate(),
                'pkey' => $pkey,
                'dkey' => $dkey,
                'pacc' => $val['pacc'],
                'unum' => $val['unum'],
                'tstat' => 1
            ];
        }
        PurTaskModel::M()->inserts($taskData);
    }

    /**
     * 更新审核状态
     * @param string $pkey
     * @param string $rmk
     * @param string $acc
     * @param array $demandList
     * @return mixed
     */
    public function updateStat(string $pkey, string $rmk, string $acc, array $demandList)
    {
        if ($demandList)
        {
            foreach ($demandList as $v)
            {
                if (empty($v['task']))
                {
                    throw new AppException('请选择采购人！');
                }
            }
        }
        else
        {
            throw new AppException('请添加需求单数据！');
        }

        try
        {
            // 开启事务
            Db::beginTransaction();
            // 更新计划表状态
            $time = time();
            PurPlanModel::M()->update(['pkey' => $pkey], ['pstat' => 2, 'rmk1' => $rmk, 'cacc' => $acc, 'ctime' => $time, 'ptime2' => $time, 'ltime' => $time]);
            // 更新需求表状态
            PurDemandModel::M()->update(['pkey' => $pkey], ['dstat' => 2, 'ctime' => $time, 'ltime' => $time]);
            // 更新需求计划表状态
            PurTaskModel::M()->update(['pkey' => $pkey], ['tstat' => 1]);

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
     * 计划单修改
     * @param string $pkey
     * @return array
     */
    public function editPlan(string $pkey)
    {
        $data = [];
        $planCols = 'pname,ptype,utime,pstat,rmk1';
        $data['planData'] = PurPlanModel::M()->getRow(['pkey' => $pkey], $planCols);
        $data['planData']['utime'] = DateHelper::toString($data['planData']['utime']) ?? '-';
        $demandCols = 'dkey,bid,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr,unum,ucost,uamt,instc,incost';
        $demandList = PurDemandModel::M()->getList(['pkey' => $pkey], $demandCols);

        foreach ($demandList as $key => $value)
        {
            $demandList[$key]['bid'] = $value['bid'] == 0 ? '' : $value['bid'];
            $demandList[$key]['mid'] = $value['mid'] == 0 ? '' : $value['mid'];
            $demandList[$key]['level'] = $value['level'] == 0 ? '' : $value['level'];
            $demandList[$key]['mdram'] = $value['mdram'] == 0 ? '' : $value['mdram'];
            $demandList[$key]['mdcolor'] = $value['mdcolor'] == 0 ? '' : $value['mdcolor'];
            $demandList[$key]['mdofsale'] = $value['mdofsale'] == 0 ? '' : $value['mdofsale'];
            $demandList[$key]['mdnet'] = $value['mdnet'] == 0 ? '' : $value['mdnet'];
            $demandList[$key]['mdwarr'] = $value['mdwarr'] == 0 ? '' : $value['mdwarr'];
        }
        $data['demandData'] = $demandList;

        // 返回数据
        return $data;
    }

    /**
     * 计划单驳回
     * @param string $pkey
     * @param string $rmk
     * @param string $acc
     * @return string
     */
    public function rejectPlan(string $pkey, string $rmk, string $acc)
    {
        $time = time();
        try
        {
            // 开启事务
            Db::beginTransaction();
            // 更新计划表状态
            PurPlanModel::M()->update(['pkey' => $pkey], ['pstat' => 4, 'rmk2' => $rmk, 'cacc' => $acc, 'ctime' => $time, 'ptime4' => $time, 'ltimes' => $time]);
            // 更新需求表状态
            PurDemandModel::M()->update(['pkey' => $pkey], ['dstat' => 4, 'ctime' => $time, 'ltime' => $time]);

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
     * 需求单分配详情
     * @param string $pkey
     * @param string $dkey
     * @return mixed
     */
    public function taskDetail(string $pkey, string $dkey)
    {
        //获取采购单数据
        $list = PurOdrDemandModel::M()->join(PurOdrOrderModel::M(), ['okey' => 'okey'])
            ->getList(['A.pkey' => $pkey, 'A.dkey' => $dkey, 'A.cstat' => 2], 'A.unum,A.okey,A.dkey,A.pnum,A.scost,A.snum,B.aacc,B.ostat,B.merchant,B.ltime');
        //提取ID集合
        $merchants = ArrayHelper::map($list, 'merchant', '-1');
        $aaccs = ArrayHelper::map($list, 'aacc', '-1');

        //获取字典
        $merchantDict = PurMerchantModel::M()->getDict('mid', ['mid' => ['in' => $merchants]], 'mid,mname');
        $userDict = PurUserModel::M()->getDict('acc', ['acc' => ['in' => $aaccs]], 'acc,rname');

        //按采购人组装数据
        $purData = [];
        foreach ($list as $value)
        {
            if ($value['pnum'] == 0)
            {
                $value['scost'] = 0;
            }
            $data = $value;
            $data['merchant'] = $merchantDict[$value['merchant']]['mname'] ?? '-';
            $data['pacc'] = $userDict[$value['aacc']]['rname'] ?? '-';
            $data['ltime'] = DateHelper::toString($value['ltime'] ?? 0);
            $data['sumcost'] = sprintf("%.2f", $value['scost'] * $value['pnum']);

            // 退货数量
            $rtnnum = PurOdrGoodsModel::M()->getCount(['okey' => $value['okey'], 'dkey' => $value['dkey'], 'gstat' => 5]);
            $data['rtnnum'] = $rtnnum ?? '-';
            $data['ostat'] = PurDictData::PUR_ORDER_OSTAT[$value['ostat']] ?? '-';

            $purData[$value['aacc']][] = $data;
        }

        // 已分配采购任务，但未提交采购单，只显示分配信息
        $list = PurTaskModel::M()->getList(['pkey' => $pkey, 'dkey' => $dkey], 'pacc, unum');
        $aaccs = ArrayHelper::map($list, 'pacc', '-1');
        $userDict = PurUserModel::M()->getDict('acc', ['acc' => ['in' => $aaccs]], 'acc,rname');
        foreach ($list as $value)
        {
            if (!$purData[$value['pacc']])
            {
                $data = $value;
                $data['pacc'] = $userDict[$value['pacc']]['rname'] ?? '-';
                $data['unum'] = $value['unum'];
                $data['okey'] = '-';
                $data['merchant'] = '-';
                $data['pnum'] = '-';
                $data['scost'] = '-';
                $data['sumcost'] = '-';
                $data['snum'] = '-';
                $data['rtnnum'] = '-';
                $data['ltime'] = '-';
                $data['ostat'] = '-';
                $purData[$value['pacc']][] = $data;
            }
        }

        //重组数据
        $detailData = [];
        foreach ($purData as $key => $value)
        {
            foreach ($value as $value2)
            {
                $detailData[] = $value2;
            }
        }

        //返回
        return $detailData;
    }

    /**
     * 需求单分配详情
     * @param string $pkey
     * @param string $dkey
     * @return array
     */
    public function taskDemand(string $pkey, string $dkey)
    {
        $taskList = PurTaskModel::M()->getList(['pkey' => $pkey, 'dkey' => $dkey], 'pacc, unum');

        foreach ($taskList as $key => $val)
        {
            $rname = PurUserModel::M()->getRow(['acc' => $val['pacc']], 'rname');
            $taskList[$key]['rname'] = $rname['rname'];
        }

        return $taskList;
    }

    /**
     * 采购完成数据展示
     * @param string $pkey
     * @return array
     */
    public function getComplete(string $pkey)
    {
        $planData = [];
        // 预计采购
        $uData = PurPlanModel::M()->getRow(['pkey' => $pkey], 'unum,ucost');
        if ($uData['unum'] > 0)
        {
            $uData['uprice'] = sprintf("%.2f", $uData['ucost'] / $uData['unum']);
        }
        $uData['uprice'] ?: 0;
        $uData['cancel'] = '-';
        $uData['complete'] = '-';
        $uData['enter'] = '-';
        $uData['sell'] = '-';
        $uData['exist'] = '-';

        //计算已完成数量 + 完成总成本
        $sumnum = PurOdrDemandModel::M()->getRow(['pkey' => $pkey, 'cstat' => 2], 'sum(pnum) as pnum,SUM(pnum*scost) as totalcost');

        // 采购单价
        $rData['rnum'] = $sumnum['pnum'] ?? 0;
        $rData['rcost'] = $sumnum['totalcost'] ?? 0;
        if (intval($rData['rnum']) > 0)
        {
            $rData['rprice'] = sprintf("%.2f", intval($rData['rcost']) / intval($rData['rnum'])) ?? 0;
        }
        $rData['rprice'] = $rData['rprice'] ?? 0;

        // 实际入库
        $rData['enter'] = PurOdrGoodsModel::M()->getCount(['pkey' => $pkey, 'gtime2' => ['>' => 0]]);

        // 实际退货
        $rData['cancel'] = PurOdrGoodsModel::M()->getCount(['pkey' => $pkey, 'gstat' => 5]);

        // 实际完成
        $rData['complete'] = PurOdrGoodsModel::M()->getCount(['pkey' => $pkey, 'gstat' => 4]);

        // 实际售出
        $goodsDatas = PurOdrGoodsModel::M()->getRow(['pkey' => $pkey, 'gstat' => 4, 'prdstat' => 2], 'count(1) as count');
        $rData['sell'] = $goodsDatas['count'] ?: 0;

        // 实际现存
        $goodsDatas = PurOdrGoodsModel::M()->getRow(['pkey' => $pkey, 'gstat' => 4, 'prdstat' => 1], 'count(1) as count');
        $rData['exist'] = $goodsDatas['count'] ?: 0;

        $planData['uData'] = $uData;
        $planData['rData'] = $rData;

        // 返回结果
        return $planData;
    }

    /**
     * 采购计划审核列表翻页数据条件
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

        if ($query['dkey'])
        {
            $peky = PurDemandModel::M()->getRow(['dkey' => $query['dkey']], 'pkey');
            $where['pkey'] = $peky['pkey'];
        }

        if ($query['okey'])
        {
            $where['okey'] = $query['okey'];
        }

        if ($query['pname'])
        {
            $where['pname'] = $query['pname'];
        }

        if ($query['aacc'])
        {
            $aid = AccUserModel::M()->getRow(['rname' => $query['aacc']], 'aid');
            $where['aacc'] = $aid['aid'];
        }

        if ($query['pstat'])
        {
            if ($query['pstat'] == 2)
            {
                $where['pstat'] = [2, 3, 5, 6, 7];
            }
            elseif ($query['pstat'] == 3)
            {
                $where['pstat'] = 4;
            }
            else
            {
                $where['pstat'] = $query['pstat'];
            }
        }

        if ($query['ptype'])
        {
            $where['ptype'] = $query['ptype'];
        }

        if ($query['timetype'])
        {
            $time = [strtotime($query['time'][0] . '00:00:00'), strtotime($query['time'][1] . '23:59:59')];
            if ($query['timetype'] == 1)
            {
                $where['utime'] = ['between' => $time];
            }
            elseif ($query['timetype'] == 2)
            {
                $where['atime'] = ['between' => $time];
            }
            elseif ($query['timetype'] == 3)
            {
                $where['ctime'] = ['between' => $time];
            }
        }

        // 返回
        return $where;
    }
}