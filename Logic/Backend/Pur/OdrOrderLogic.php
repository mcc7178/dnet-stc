<?php

namespace App\Module\Sale\Logic\Backend\Pur;

use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurUserModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\BeanCollector;
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
use App\Model\Pur\PurOdrOrderModel;
use Swork\Db\Db;
use App\Exception\AppException;

/**
 * 采购单管理
 * Class OdrOrderLogic
 * @package App\Module\Sale\Logic\Backend\Pur
 */
class OdrOrderLogic extends BeanCollector
{
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
        $cols = 'okey,tnum,snum,fnum,cstat,aacc,cacc,atime,ctime';
        $orderBy = ['cstat' => 1, 'atime' => -1, 'ctime' => -1];

        // 获取列表数据
        $list = PurOdrOrderModel::M()->getList($where, $cols, $orderBy, $size, $idx);

        // 数据处理
        foreach ($list as $key => $value)
        {
            $list[$key]['atime'] = DateHelper::toString($value['atime']) ?? '-';
            $list[$key]['ctime'] = DateHelper::toString($value['ctime']) ?? '-';
            // 部分通过无驳回数据更改状态为待审核
            if ($value['snum'] + $value['fnum'] != $value['tnum'])
            {
                $value['cstat'] = 1;
            }
            $list[$key]['cstat'] = PurDictData::PUR_ODR_ORDER[$value['cstat']] ?? '-';
            $rname = PurUserModel::M()->getRow(['acc' => $value['aacc']], 'rname');
            $aname = AccUserModel::M()->getRow(['aid' => $value['cacc']], 'rname');
            $list[$key]['aacc'] = $rname['rname'] ?? '-';
            $list[$key]['cacc'] = $aname['rname'] ?? '-';
        }

        // 返回数据
        return $list;
    }

    /**
     * 采购单总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        // 查询条件
        $where = $this->getPagerWhere($query);
        // 获取列表数据
        $count = PurOdrOrderModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 需求单分配详情
     * @param string $okey
     * @return array
     * @throws
     */
    public function orderDetail(string $okey)
    {
        $orderList = [];
        // 采购单信息
        $orderData = PurOdrOrderModel::M()->getRow(['okey' => $okey], 'okey, aacc, atime,merchant');
        $rname = PurUserModel::M()->getRow(['acc' => $orderData['aacc']], 'rname');
        $orderData['aacc'] = $rname['rname'];
        $orderData['atime'] = DateHelper::toString(intval($orderData['atime'])) ?? '-';

        // 采购单详情
        $cols = 'A.pnum,A.scost,A.tcost,A.rmk,A.rnum,A.scost,A.cstat,A.ctime,B.pkey,B.dkey,B.bid,B.mid,B.level,
        B.mdram,B.mdcolor,B.mdofsale,B.mdnet,B.mdwarr,B.unum,B.ucost,B.uamt,B.instc,B.incost';
        $orderDetailList = PurOdrDemandModel::M()
            ->join(PurDemandModel::M(), ['dkey' => 'dkey'])
            ->getList(['A.okey' => $okey], $cols);

        if (!$orderDetailList)
        {
            throw new AppException('对应的采购计划不存在');
        }

        //获取采购需求对应的选项字典
        $mdOids = [];
        foreach ($orderDetailList as $value)
        {
            $mdOids[] = $value['mdram'];
            $mdOids[] = $value['mdcolor'];
            $mdOids[] = $value['mdofsale'];
            $mdOids[] = $value['mdnet'];
            $mdOids[] = $value['mdwarr'];
        }
        $mdOids = array_unique($mdOids);
        $optDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $mdOids]], 'oid,oname');

        //级别字典
        $levelDict = QtoLevelModel::M()->getDict('lkey');

        //获取品牌+机型字典
        $mids = ArrayHelper::map($orderDetailList,'mid',-1);
        $bids = ArrayHelper::map($orderDetailList,'bid',-1);
        $modelDict = QtoModelModel::M()->getDict('mid',['mid' => ['in' => $mids]],'mname');
        $brandDict = QtoBrandModel::M()->getDict('bid',['bid' => ['in' => $bids]],'bname');
        foreach ($orderDetailList as $key => $value)
        {
            $deitemData = [
                'bname' => $brandDict[$value['bid']]['bname'] ?? '-',
                'mname' => $modelDict[$value['mid']]['mname'] ?? '-',
                'lname' => $levelDict[$value['level']]['lname'] ?? '',
                'mdram' => $optDict[$value['mdram']]['oname'] ?? '',
                'mdcolor' => $optDict[$value['mdcolor']]['oname'] ?? '',
                'mdofsale' => $optDict[$value['mdofsale']]['oname'] ?? '',
                'mdnet' => $optDict[$value['mdnet']]['oname'] ?? '',
                'mdwarr' => $optDict[$value['mdwarr']]['oname'] ?? '',
            ];
            $deitem = implode('/', array_filter($deitemData));
            $orderDetailList[$key]['deitem'] = $deitem;

            // 预计总价
            $orderDetailList[$key]['usum'] = sprintf("%.2f", $value['unum'] * $value['ucost']);

            // 供货商信息
            $merchantData = PurMerchantModel::M()->getRow(['mid' => $orderData['merchant']], 'mname,mobile');
            $merchant = implode('/', $merchantData);

            // 当前库存和库存成本
            $where = [
                'bid' => $value['bid'],
                'mid' => $value['mid'],
                'level' => $value['level'],
                'mdram' => $value['mdram'],
                'mdcolor' => $value['mdcolor'],
                'mdofsale' => $value['mdofsale'],
                'mdnet' => $value['mdnet'],
                'mdwarr' => $value['mdwarr'],
                'prdstat' => 1,
                'stcstat' => 11
            ];
            $prdData = PrdProductModel::M()->getRow($where, 'count(1) as count,sum(prdcost) as prdcost');
            $orderDetailList[$key]['cnum'] = $prdData['count'];
            $orderDetailList[$key]['ccost'] = intval($prdData['prdcost']);

            // 采购详情
            $orderDetailList[$key]['merchant'] = $merchant;

            // 驳回备注
            $orderDetailList[$key]['rmk'] = $value['rmk'] ?? '-';

            // 提交总价
            $orderDetailList[$key]['rsumcost'] = sprintf("%.2f", $value['rnum'] * $value['scost']);

            // 已完成数量
            $pnum = PurTaskModel::M()->getRow(['pkey' => $value['pkey'], 'dkey' => $value['dkey']], 'pnum');
            $orderDetailList[$key]['pnum'] = $pnum['pnum'];

            // 已完成总价
            $sql = "SELECT SUM(pnum * scost) as csumcost FROM pur_odr_demand WHERE dkey = '" . $value['dkey'] . "'";
            $csumcostData = PurDemandModel::M()->doQuery($sql);
            $csumcost = $csumcostData['Results'][0]['csumcost'];
            $orderDetailList[$key]['csumcost'] = sprintf("%.2f", $csumcost) ?? 0;

            // 已完成单价
            $scosts = 0;
            if ($orderDetailList[$key]['pnum'] > 0 && $orderDetailList[$key]['csumcost'] > 0)
            {
                $scosts = sprintf("%.2f", $orderDetailList[$key]['csumcost'] / $orderDetailList[$key]['pnum']);
            }
            $orderDetailList[$key]['scosts'] = $scosts;

            // 审核状态
            $orderDetailList[$key]['cstat'] = PurDictData::PUR_ODR_DEMEND[$value['cstat']] ?? '';

            // 获取备注
            $prmk = PurPlanModel::M()->getRow(['pkey' => $value['pkey']], 'rmk1,rmk2');
            $drmk = PurDemandModel::M()->getRow(['dkey' => $value['dkey']], 'rmk1,rmk2');
            $rmkData = [
                'prmk1' => $prmk['rmk1'],
                'prmk2' => $prmk['rmk2'],
                'drmk1' => $drmk['rmk1'],
            ];
            $orderDetailList[$key]['remarks'] = implode(',', array_filter($rmkData));
        }

        $orderList['orderData'] = $orderData;
        $orderList['orderDetailList'] = $orderDetailList;

        return $orderList;
    }

    /**
     * 采购单审核通过
     * @param string $okey
     * @param string $pkey
     * @param string $dkey
     * @param string $acc
     * @return mixed
     * @throws
     */
    public function agreeOrder(string $okey, string $pkey, string $dkey, string $acc)
    {
        $time = time();
        try
        {
            // 开启事务
            Db::beginTransaction();
            // 更新计划表状态
            //外部参数
            $data = [
                'dstat' => 1,
                'cstat' => 2,
                'cacc' => $acc,
                'ctime' => $time,
                'ltime' => $time
            ];

            // 更新采购单详情单条状态
            PurOdrDemandModel::M()->update(['okey' => $okey, 'pkey' => $pkey, 'dkey' => $dkey], $data);
            $count = PurOdrDemandModel::M()->getCount(['okey' => $okey, 'cstat' => 2]);
            // 更新采购单审核通过数量信息
            $data = [
                'snum' => $count,
                'cacc' => $acc,
                'ctime' => $time,
                'ltime' => $time
            ];
            PurOdrOrderModel::M()->update(['okey' => $okey], $data);

            // 获取需求总数量和审核通过数量，并根据通过数量更新采购单状态
            $orderData = PurOdrOrderModel::M()->getRow(['okey' => $okey], 'tnum, snum');
            if ($orderData['tnum'] == $orderData['snum'])
            {
                PurOdrOrderModel::M()->update(['okey' => $okey], ['cstat' => 3]);
            }
            else
            {
                PurOdrOrderModel::M()->update(['okey' => $okey], ['cstat' => 2]);
            }

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
     * 采购单审核驳回
     * @param string $okey
     * @param string $pkey
     * @param string $dkey
     * @param string $rmk
     * @param string $acc
     * @return mixed
     * @throws
     */
    public function rejectOrder(string $okey, string $pkey, string $dkey, string $rmk, string $acc)
    {
        $time = time();
        try
        {
            // 开启事务
            Db::beginTransaction();
            // 更新计划表状态
            //外部参数
            $data = [
                'cstat' => 3,
                'cacc' => $acc,
                'rmk' => $rmk,
                'ctime' => $time,
                'ltime' => $time
            ];

            // 更新采购单详情单条状态
            PurOdrDemandModel::M()->update(['okey' => $okey, 'pkey' => $pkey, 'dkey' => $dkey], $data);
            $count = PurOdrDemandModel::M()->getCount(['okey' => $okey, 'cstat' => 3]);

            // 更新采购单审核驳回数量信息
            $data = [
                'fnum' => $count,
                'cacc' => $acc,
                'ctime' => $time,
                'ltime' => $time
            ];
            PurOdrOrderModel::M()->update(['okey' => $okey], $data);

            // 根据采购单驳回总数量和需求总数量更新，采购单驳回状态
            $orderData = PurOdrOrderModel::M()->getRow(['okey' => $okey], 'tnum');
            if ($count == $orderData['tnum'])
            {
                PurOdrOrderModel::M()->update(['okey' => $okey], ['cstat' => 4]);
            }

            //驳回时，减少驳回采购单提交的数量
            $rejectDemands = PurOdrDemandModel::M()->getList(['okey' => $okey, 'pkey' => $pkey, 'dkey' => $dkey], 'rnum,tid');
            foreach ($rejectDemands as $value)
            {
                PurTaskModel::M()->updateById($value['tid'], [], ['rnum' => 'rnum-' . $value['rnum']]);
            }

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
     * 采购计划审核列表翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        // 搜索查询条件
        $where = [];
        if ($query['okey'])
        {
            $where['okey'] = $query['okey'];
        }

        if ($query['dkey'])
        {
            $purOdrDemand = PurOdrDemandModel::M()->getList(['dkey' => $query['dkey']], 'okey');
            $okeys = ArrayHelper::map($purOdrDemand, 'okey');
            $where['okey'] = ['in' => $okeys];
        }

        if ($query['ptype'])
        {
            $purPlan = PurPlanModel::M()
                ->join(PurOdrDemandModel::M(), ['pkey' => 'pkey'])
                ->getList(['A.ptype' => $query['ptype']], 'B.okey');
            $okeys = ArrayHelper::map($purPlan, 'okey');
            $where['okey'] = ['in' => $okeys];
        }

        if ($query['aacc'])
        {
            $aid = PurUserModel::M()->getRow(['rname' => $query['aacc']], 'acc');
            $where['aacc'] = $aid['acc'];
        }

        if ($query['cstat'])
        {
            $where['cstat'] = $query['cstat'];
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
        }

        // 返回
        return $where;
    }
}
