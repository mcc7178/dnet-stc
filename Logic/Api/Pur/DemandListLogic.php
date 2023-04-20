<?php


namespace App\Module\Sale\Logic\Api\Pur;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Model\Crm\CrmMessageDotModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Model\Pur\PurPlanModel;
use App\Model\Pur\PurTaskModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Throwable;

/**
 * 用户需求单逻辑
 * Class DemandListLogic
 * @package App\Module\Api\Pur
 */
class DemandListLogic extends BeanCollector
{
    /**
     * 声明普通任务队列
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 获取需求单列表
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     * @throws
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //数据条件
        $where = $this->getWhere($query);

        //获取所需字段
        $cols = 'A.dkey,A.snum,A.pnum,A.tstat,B.ptype,B.utime,B.bid,B.mid,B.level,B.mdram,B.mdcolor,B.mdofsale,B.mdnet,B.mdwarr,A.unum';

        //获取列表数据
        $purTaskList = PurTaskModel::M()
            ->join(PurDemandModel::M(), ['A.dkey' => 'B.dkey'])
            ->getList($where, $cols, ['B.utime' => -1, 'B.dkey' => -1], $size, $idx);
        if (empty($purTaskList))
        {
            return [];
        }

        //获取机型类目选项字典
        $optCols = ['mdofsale', 'mdnet', 'mdcolor', 'mdram', 'mdwarr'];
        $optOids = ArrayHelper::maps([$purTaskList, $purTaskList, $purTaskList, $purTaskList, $purTaskList], $optCols);
        $optionsDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $optOids]], 'oname');

        //获取商品信息
        $bid = ArrayHelper::map($purTaskList, 'bid');
        $mid = ArrayHelper::map($purTaskList, 'mid');
        $level = ArrayHelper::map($purTaskList, 'level');

        //获取商品品牌，机型，级别
        $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bid]], 'bname');
        $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mid]], 'mname');
        $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $level]], 'lname');

        foreach ($purTaskList as $key => $value)
        {
            //机器扩展信息
            $modelConf = [];
            $mdofsale = $optionsDict[$value['mdofsale']]['oname'] ?? '';
            $mdnet = $optionsDict[$value['mdnet']]['oname'] ?? '';
            $mdcolor = $optionsDict[$value['mdcolor']]['oname'] ?? '';
            $mdram = $optionsDict[$value['mdram']]['oname'] ?? '';
            $mdwarr = $optionsDict[$value['mdwarr']]['oname'] ?? '';
            if ($mdofsale && !in_array($mdofsale, ['无', '其他正常']))
            {
                array_push($modelConf, $mdofsale);
            }
            if ($mdnet && !in_array($mdnet, ['无', '其他正常']))
            {
                array_push($modelConf, $mdnet);
            }
            if ($mdcolor && !in_array($mdcolor, ['无', '其他正常']))
            {
                array_push($modelConf, $mdcolor);
            }
            if ($mdram && !in_array($mdram, ['无', '其他正常']))
            {
                array_push($modelConf, $mdram);
            }
            if ($mdwarr && !in_array($mdwarr, ['无', '其他正常']))
            {
                array_push($modelConf, $mdwarr);
            }

//            foreach ($optCols as $key1 => $value1)
//            {
//                if (isset($purTaskList[$key][$value1]))
//                {
//                    $oname = $optionsDict[$value1]['oname'];
//                    if ($oname != ''&&!in_array($oname, ['无', '其他正常']))
//                        {
//                            array_push($modelConf, $oname);
//                        }
//                }
//            }

            //补充数据
            $purTaskList[$key]['utime'] = DateHelper::toString($value['utime']);
            $purTaskList[$key]['dstatName'] = PurDictData::TASK_TSTAT[$value['tstat']];
            $purTaskList[$key]['ptypeName'] = PurDictData::PUR_PLAN_TYPE[$value['ptype']];
            $qtoBrandInfo = $qtoBrand[$value['bid']]['bname'] ?? '-';
            $qtoModelInfo = $qtoModel[$value['mid']]['mname'] ?? '-';
            $qtoLevelInfo = $qtoLevel[$value['level']]['lname'] ?? '-';

            //组装采购需求信息
            $optionsName = [$mdram, $mdcolor, $mdofsale, $mdnet, $mdwarr];
            array_unshift($optionsName, $qtoBrandInfo, $qtoModelInfo, $qtoLevelInfo);
            $optionsName = array_filter($optionsName);
            $optionsData = implode($optionsName, '/');
            $purTaskList[$key]['optionsData'] = $optionsData;
        }

        //删除小红点数据
        CrmMessageDotModel::M()->delete(['plat' => 24, 'src' => '1301', 'dtype' => 13, 'uid' => $query['uid']]);

        //返回
        return $purTaskList;
    }

    /**
     * 获取需求单详情
     * @param string $dkey
     * @param string $uid
     * @return array
     */
    public function getDetail(string $dkey, string $uid)
    {
        //赋初值
        $allReturnNum = 0;
        $returnNum = 0;
        $snum = 0;
        $pnum = 0;
        $tcost = 0;
        $purInfoNum = 0;
        $purOrderList = [];

        //获取需求单详情信息
        $cols = 'dkey,ctime,ptype,utime,dstat,bid,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr';
        $purDemand = PurDemandModel::M()->getRowById($dkey, $cols);

        //计划类型
        $purDemand['ptypeName'] = PurDictData::PUR_PLAN_TYPE[$purDemand['ptype']];

        //期望交付时间
        $purDemand['utime'] = DateHelper::toString($purDemand['utime']);

        //生成时间
        $purDemand['atime'] = DateHelper::toString($purDemand['ctime']);

        //获取商品扩展信息
        $optOids = [$purDemand['mdram'], $purDemand['mdcolor'], $purDemand['mdofsale'], $purDemand['mdnet'], $purDemand['mdwarr']];
        $optionsList = QtoOptionsModel::M()->getList(['oid' => ['in' => $optOids]], 'oname');
        $optionsName = array_column($optionsList, 'oname');

        //获取商品品牌，机型，级别
        $qtoBrandInfo = QtoBrandModel::M()->getRow(['bid' => $purDemand['bid']], 'bname');
        $qtoModelInfo = QtoModelModel::M()->getRow(['mid' => $purDemand['mid']], 'mname');
        $qtoLevelInfo = QtoLevelModel::M()->getRow(['lkey' => $purDemand['level']], 'lname');

        //组装采购需求信息
        $optionsInfo = array_merge($qtoBrandInfo, $qtoModelInfo, $qtoLevelInfo, $optionsName);
        $optionsData = implode($optionsInfo, ' ');
        $purDemand['optionsData'] = $optionsData;

        //需求单计划采购数量
        $purTask = PurTaskModel::M()->getRow(['dkey' => $dkey, 'pacc' => $uid], 'tid,unum,tstat');
        $purDemand['purTaskNum'] = $purTask['unum'];

        //状态
        $purDemand['dstatName'] = PurDictData::TASK_TSTAT[$purTask['tstat']];
        if (in_array($purTask['tstat'], [3, 4]))
        {
            //完成和中止需求单按钮不显示
            $purDemand['buttonStat'] = 1;
        }
        else
        {
            //完成和中止需求单按钮显示
            $purDemand['buttonStat'] = 2;
        }

        $purDemand['snum'] = $purDemand['pnum'] = $purDemand['scost'] = $purDemand['tcost'] = $purDemand['returnNum'] = $purDemand['waitNum'] = '-';
        $purDemand['substat'] = 2;
        $purDemand['demandstat'] = 1;

        //获取需求单下的采购单信息
        $demandList = PurOdrDemandModel::M()->getList(['dkey' => $dkey, 'aacc' => $uid], 'okey,dkey,rnum,mnum,snum,pnum,okey,scost,ltime,atime');
        if ($demandList)
        {
            $okeys = ArrayHelper::map($demandList, 'okey');
            foreach ($demandList as $key => $value)
            {
//                $snum += $value['snum'];
                $pnum += $value['pnum'];
                $tcost += $value['scost'] * $value['pnum'];
            }

//            //已入库数量（已在库数量）
//            $purDemand['snum'] = $snum;

            //已完成数量
            $purDemand['pnum'] = $pnum;

            //采购总价
            $purDemand['tcost'] = $tcost;

            //采购单价
            if ($pnum == 0)
            {
                $purDemand['scost'] = '-';
                $purDemand['tcost'] = '-';
            }
            else
            {
                $purDemand['scost'] = number_format(($tcost / $pnum), 2);
            }

            //获取需求单关联的采购单商品信息
            $orderGoods = PurOdrGoodsModel::M()->getList(['okey' => ['in' => $okeys], 'dkey' => $dkey, 'aacc' => $uid], 'bcode,gstat');
            if ($orderGoods)
            {
                foreach ($orderGoods as $key => $value)
                {
                    if ($value['gstat'] == 5)
                    {
                        $allReturnNum += 1;
                    }
                }
                $bcodes = ArrayHelper::map($orderGoods, 'bcode');
                $returnNum = PrdProductModel::M()->getCount(['bcode' => ['in' => $bcodes], 'prdstat' => 3]);
            }
            $waitNum = $allReturnNum - $returnNum;

            //需求单已退货的数量
            $purDemand['returnNum'] = $returnNum;

            //需求单等待退货的数量
            $purDemand['waitNum'] = $waitNum;

            //获取需求单关联的采购单信息
            $purOrderDict = PurOdrOrderModel::M()->getDict('okey', ['okey' => ['in' => $okeys], 'aacc' => $uid], 'okey,merchant');

            //获取供应商信息
            $merchant = ArrayHelper::map($purOrderDict, 'merchant');
            $merchantDict = PurMerchantModel::M()->getDict('mid', ['mid' => ['in' => $merchant]], 'mname');

            //获取采购单对应的需求单信息
            $demandDict = PurOdrDemandModel::M()->getDict('okey', ['dkey' => $dkey, 'aacc' => $uid, 'okey' => ['in' => $okeys]], 'cstat');

            //获取采购单商品信息
            $where = [
                'dkey' => $dkey,
                'okey' => ['in' => $okeys],
                'aacc' => $uid,
                '$group' => ['okey']
            ];
            $cols = 'okey,count(*) as count,sum(gtime2>0) as snum,sum(gtime3>0) as mnum,sum(gstat=5) as allReturnNum,sum(prdstat=3) as returnedNum';
            $stcDict = PurOdrGoodsModel::M()->getDict('okey', $where, $cols);

            foreach ($demandList as $key2 => $value2)
            {
                //采购单号
                $purOrderList[$key2]['okey'] = $value2['okey'];

                //预入库数量
                $purOrderList[$key2]['stcNum'] = $stcDict[$value2['okey']]['count'] ?? 0;

                //已完成数量
                $purOrderList[$key2]['finishedNum'] = $value2['pnum'] ?? 0;

                //已质检数量
                $purOrderList[$key2]['mnum'] = $value2['mnum'] ?? 0;

                //采购单：已质检（未确认）+确认采购（质检后）+确认退货（质检后）
                $mnum = $stcDict[$value2['okey']]['mnum'] ?? 0;

                //需求单：已质检（未确认）+确认采购（质检后）+确认退货（质检后）
                $purInfoNum += $mnum;

                //提交数量
                $purOrderList[$key2]['rnum'] = $value2['rnum'];

                //入库数量
                $purOrderList[$key2]['snum'] = $stcDict[$value2['okey']]['snum'] ?? 0;

                //需求单已入库数量（已在库数量）
                $snum += $purOrderList[$key2]['snum'];
                $purDemand['snum'] = $snum;

                $merchant = $purOrderDict[$value2['okey']]['merchant'];
                $purOrderList[$key2]['merchant'] = $merchantDict[$merchant]['mname'];
                $purOrderList[$key2]['ltime'] = DateHelper::toString($value2['ltime']);
                $purOrderList[$key2]['atime'] = DateHelper::toString($value2['atime']);
                $purOrderList[$key2]['mname'] = $qtoModelInfo['mname'];

                //退货总数
                $purAllReturnNum = $stcDict[$value2['okey']]['allReturnNum'] ?? 0;

                //已退货数量
                $purReviewedNum = $stcDict[$value2['okey']]['returnedNum'] ?? 0;
                $purOrderList[$key2]['reviewedNum'] = $purReviewedNum;

                //待退货数量
                $purWaitNum = $purAllReturnNum - $purReviewedNum;
                $purOrderList[$key2]['waitNum'] = $purWaitNum;

                //审核状态
                $examineStat = $demandDict[$value2['okey']]['cstat'];
                $purOrderList[$key2]['examineStat'] = PurDictData::PUR_PDR_STAT[$examineStat];
            }
            $purDemand['purData'] = $purOrderList;

            //检查是否可以提交 (2020.11.24 分配采购数量 > 已完成数量)
            $taskPnum = PurOdrGoodsModel::M()->getCount(['tid' => $purTask['tid'], 'gstat' => 4]);
            if ($purDemand['purTaskNum'] > $taskPnum)
            {
                //显示提交采购单按钮
                $purDemand['substat'] = 2;
            }
            else
            {
                //提交采购单按钮不能点击
                $purDemand['substat'] = 1;
            }

            //判读入库数量是否等于 质检待确认+完成+已退货+待退货
            if ($snum == $purInfoNum)
            {
                //显示完成需求单和中止需求单按钮
                $purDemand['demandstat'] = $snum == 0 ? 1 : 2;
            }
            else
            {
                //完成需求单和中止需求单按钮不能点击
                $purDemand['demandstat'] = 1;
            }
            ArrayHelper::fillDefaultValue($purOrderList, [0, '0']);
        }
        $purDemand['purData'] = $purOrderList ?? [];
        ArrayHelper::fillDefaultValue($purDemand, [0, '0']);

        //返回
        return $purDemand;
    }

    /**
     * 完成需求单
     * @param string $dkey
     * @param string $uid
     * @throws
     */
    public function complete(string $dkey, string $uid)
    {
        $demandData = [];
        $purData = [];
        $okey = [];
        $time = time();
        $delay = 0;

        //获取需求单详情信息
        $demandList = PurOdrDemandModel::M()->getList(['dkey' => $dkey, 'aacc' => $uid, 'dstat' => 1], 'did,okey');
        if (!$demandList)
        {
            try
            {
                //开始事务
                Db::beginTransaction();

                //更改需求任务表状态
                PurTaskModel::M()->update(['pacc' => $uid, 'dkey' => $dkey], ['tstat' => 3]);

                //获取需求单为该需求单号的所有数据
                $task = PurTaskModel::M()->getRow(['dkey' => $dkey], 'count(*) as count,sum(tstat=3) as cnum');

                if ($task['cnum'] == $task['count'])
                {
                    //更改需求单的状态
                    PurDemandModel::M()->update(['dkey' => $dkey], ['dstat' => 5, 'ltime' => $time]);
                }

                //回滚事务
                Db::commit();
            }
            catch (Throwable $throwable)
            {
                //回滚事务
                Db::rollback();

                //抛出异常
                throw $throwable;
            }

            return;
        }
        $okeys = ArrayHelper::map($demandList, 'okey');

        //采购单的需求单总数
        $purOrderDict = PurOdrOrderModel::M()->getDict('okey', ['okey' => ['in' => $okeys], 'aacc' => $uid], 'okey,tnum');

        //获取该需求单对应的计划单
        $purPkey = PurTaskModel::M()->getOne(['dkey' => $dkey], 'pkey');

        //获取该计划单的期望交付时间
        $utime = PurPlanModel::M()->getRowById($purPkey, 'utime');
        if ($time > $utime)
        {
            $delay = 1;
        }

        foreach ($demandList as $key => $value)
        {
            array_push($okey, $value['okey']);
            $demandData[] = [
                'did' => $value['did'],
                'okey' => $value['okey'],
                'aacc' => $uid,
                'dstat' => 2,
                'ltime' => $time
            ];
        }

        try
        {
            //开始事务
            Db::beginTransaction();

            //更改需求任务表状态
            PurTaskModel::M()->update(['pacc' => $uid, 'dkey' => $dkey], ['tstat' => 3]);

            //采购单状态改成已完成
            PurOdrDemandModel::M()->inserts($demandData, true);

            //获取传过来的需求单里面的采购单对应的需求单
            $purDemandDict = PurOdrDemandModel::M()->getDict('okey', ['okey' => ['in' => $okeys], 'aacc' => $uid, '$group' => ['okey']], 'okey,sum(dstat=2) as totalNum');

            $purDemandList = PurOdrDemandModel::M()->getList(['dkey' => $dkey, 'aacc' => $uid], 'did,okey');
            foreach ($purDemandList as $key => $value)
            {

                //对应采购单的需求单总数
                $tnum = $purOrderDict[$value['okey']]['tnum'];

                //采购需求单里采购单的需求单总数
                $purDemandNum = $purDemandDict[$value['okey']]['totalNum'];
                if ($tnum == $purDemandNum)
                {
                    if (in_array($value['okey'], $okey))
                    {
                        $purData[] = [
                            'okey' => $value['okey'],
                            'aacc' => $uid,
                            'ostat' => 2,
                            'ltime' => $time,
                        ];
                    }
                }
            }

            //数量相等将采购单状态改为已完成
            PurOdrOrderModel::M()->inserts($purData, true);

            //插入小红点数据
            $this->amqpQueue($uid);

            //获取计划单状态
            $status = $this->status($purPkey);

            //获取需求单为该需求单号的所有数据
            $task = PurTaskModel::M()->getRow(['dkey' => $dkey], 'count(*) as count,sum(tstat=3) as cnum');
            if ($task['cnum'] == $task['count'])
            {
                //更改需求单的状态
                PurDemandModel::M()->updateById($dkey, ['dstat' => 5, 'ltime' => $time]);
            }

            if ($status == 1)
            {
                //更新计划单状态为已完成
                PurPlanModel::M()->updateById($purPkey, ['pstat' => 5, 'delay' => $delay, 'ptime5' => $time, 'ltime' => $time]);
            }

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 中止需求单
     * @param string $dkey
     * @param string $uid
     * @throws
     */
    public function stop(string $dkey, string $uid)
    {
        $demandData = [];
        $purData = [];
        $time = time();
        $delay = 0;

        //获取需求单详情信息
        $demandList = PurOdrDemandModel::M()->getList(['dkey' => $dkey, 'aacc' => $uid, 'dstat' => 1], 'did,okey');
        if (!$demandList)
        {
            try
            {
                //开始事务
                Db::beginTransaction();

                //更改需求任务表状态
                PurTaskModel::M()->update(['pacc' => $uid, 'dkey' => $dkey], ['tstat' => 4]);

                //获取需求单为该需求单号的所有数据
                $task = PurTaskModel::M()->getRow(['dkey' => $dkey], 'count(*) as count,sum(tstat=4) as snum');
                if ($task['snum'] == $task['count'])
                {
                    //更改需求单的状态
                    PurDemandModel::M()->update(['dkey' => $dkey], ['dstat' => 6, 'ltime' => $time]);
                }

                //回滚事务
                Db::commit();
            }
            catch (Throwable $throwable)
            {
                //回滚事务
                Db::rollback();

                //抛出异常
                throw $throwable;
            }

            return;
        }
        $okeys = ArrayHelper::map($demandList, 'okey');

        //采购单的需求单总数
        $purOrderDict = PurOdrOrderModel::M()->getDict('okey', ['okey' => ['in' => $okeys], 'aacc' => $uid], 'okey,tnum');

        //获取改需求单对应的计划单
        $purPkey = PurTaskModel::M()->getOne(['dkey' => $dkey], 'pkey');

        //获取该计划单的期望交付时间
        $utime = PurPlanModel::M()->getRowById($purPkey, 'utime');
        if ($time > $utime)
        {
            $delay = 1;
        }
        foreach ($demandList as $key => $value)
        {
            $demandData[] = [
                'did' => $value['did'],
                'aacc' => $uid,
                'dstat' => 3,
                'ltime' => time()
            ];
        }

        try
        {
            //开始事务
            Db::beginTransaction();

            //更改需求任务表状态
            PurTaskModel::M()->update(['pacc' => $uid, 'dkey' => $dkey], ['tstat' => 4]);

            //采购单状态改为已中止
            PurOdrDemandModel::M()->inserts($demandData, true);

            //获取传过来的需求单里面的采购单对应的需求单
            $purDemandDict = PurOdrDemandModel::M()->getDict('okey', ['okey' => ['in' => $okeys], 'aacc' => $uid, '$group' => ['okey']], 'okey,sum(dstat=3) as totalNum');

            $purDemandList = PurOdrDemandModel::M()->getList(['dkey' => $dkey, 'aacc' => $uid, 'dstat' => 3], 'did,okey');
            foreach ($purDemandList as $key => $value)
            {
                //对应采购单的需求单总数
                $tnum = $purOrderDict[$value['okey']]['tnum'];

                //采购需求单里采购单的需求单总数
                $purDemandNum = $purDemandDict[$value['okey']]['totalNum'];
                if ($tnum == $purDemandNum)
                {
                    $purData[] = [
                        'okey' => $value['okey'],
                        'aacc' => $uid,
                        'ostat' => 3,
                        'ltime' => $time,
                    ];
                }
            }

            //数量相等将采购单状态改为已中止
            PurOdrOrderModel::M()->inserts($purData, true);

            //插入小红点数据
            $this->amqpQueue($uid);

            //获取需求单为该需求单号的所有数据
            $task = PurTaskModel::M()->getRow(['dkey' => $dkey], 'count(*) as count,sum(tstat=4) as snum');
            if ($task['snum'] == $task['count'])
            {
                //更改需求单的状态
                PurDemandModel::M()->updateById($dkey, ['dstat' => 6, 'ltime' => $time]);
            }

            //获取计划单状态
            $status = $this->status($purPkey);
            if ($status == 2)
            {
                //更新计划单状态为已中止
                PurPlanModel::M()->updateById($purPkey, ['pstat' => 6, 'delay' => $delay, 'ptime6' => $time, 'ltime' => $time]);
            }

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 获取计划单状态
     * @param string $pkey
     * @return string
     */
    public function status(string $pkey)
    {
        $status = 0;
        $count = 0;
        $cnum = 0;
        $snum = 0;

        //获取该计划单下的所有需求单号
        $purList = PurTaskModel::M()->getList(['pkey' => $pkey, '$group' => 'tstat'], 'tstat,count(*) as count,sum(tstat=3) as cnum,sum(tstat=4) as snum');
        foreach ($purList as $key => $value)
        {
            $count += $value['count'];
            if ($value['tstat'] == 3)
            {
                $cnum = $value['cnum'];
            }
            elseif ($value['tstat'] == 4)
            {
                $snum = $value['snum'];
            }
        }
        if ($count == $cnum)
        {
            //更新计划单状态为已完成
            $status = 1;
        }
        elseif ($count == $snum)
        {
            //更新计划单状态为已中止
            $status = 2;
        }

        return $status;
    }

    /**
     * redis插入数据
     * @param $uid
     */
    public function amqpQueue(string $uid)
    {
        //组装小红点数据（首页显示 - 采购单）
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1301, 'uid' => $uid,]);
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1302, 'uid' => $uid,]);
    }

    /**
     * 获取翻页数据条件
     * @param array $query
     * @return mixed
     */
    private function getWhere(array $query)
    {
        //初始化条件
        $where = [
            'B.dstat' => ['in' => [2, 3, 5, 6]],
            'A.pacc' => $query['uid'],
        ];

        //需求单号
        if ($query['dkey'])
        {
            $where['B.dkey'] = $query['dkey'];
        }

        //类型
        if ($query['ptype'])
        {
            $where['B.ptype'] = $query['ptype'];
        }

        //状态
        if ($query['tstat'])
        {
            $where['A.tstat'] = $query['tstat'];
        }

        //返回
        return $where;
    }
}