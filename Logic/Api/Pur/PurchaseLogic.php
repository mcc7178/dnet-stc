<?php
namespace App\Module\Sale\Logic\Api\Pur;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmStaffModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Model\Pur\PurPlanModel;
use App\Model\Pur\PurTaskModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;
use Throwable;

class PurchaseLogic extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * 保存采购单
     * @param array $query
     * @param string $acc
     * @throws
     */
    public function save(array $query, string $acc)
    {
        //提取数据
        $okey = $query['okey']; //采购编号
        $merchant = $query['merchant']; //供货商
        $demand = $query['demand'];  //需求单数组
        $time = time();
        $purOdrDemandData = [];
        $purTaskData = [];
        $purPlanData = [];
        $purDemandData = [];
        $skeys = [];

        //判断供货商
        $merchantExist = PurMerchantModel::M()->existById($merchant);
        if ($merchantExist == false)
        {
            throw new AppException('该供货商不存在，请选择其他的供货商', AppException::OUT_OF_OPERATE);
        }

        if ($okey)
        {
            //获取采购单
            $purDemandInfo = PurOdrOrderModel::M()->existById($okey);
            if ($purDemandInfo == false)
            {
                throw new AppException('采购单不存在', AppException::NO_DATA);
            }
        }

        //提取dkey
        $dkeys = ArrayHelper::map($demand, 'dkey', '-1');

        //获取需求单数据
        $purDemandDict = PurDemandModel::M()->getDict('dkey', ['dkey' => ['in' => $dkeys]], 'rnum');

        //把一级数组列表形式变成字典形式
        $demand = ArrayHelper::dict($demand, 'dkey');

        //有采购单则为修改，否则是新增
        if (isset($purDemandInfo))
        {
            //所需字段
            $cols = 'did,dkey,okey,pkey,tid,atime,cstat,rnum,unum';

            //获取采购需求数据
            $purOdrDemandList = PurOdrDemandModel::M()->getList(['okey' => $okey, 'dkey' => ['in' => $dkeys], 'aacc' => $acc, 'cstat' => ['!=' => 3]], $cols);

            //获取每个采购任务已完成的数量
            $tids = ArrayHelper::map($purOdrDemandList, 'tid');
            $purSuccessDict = PurOdrGoodsModel::M()->getDict('tid', ['tid' => ['in' => $tids], 'gstat' => 4, '$group' => 'tid'], 'count(1) as pnum');

            foreach ($purOdrDemandList as $key => $value)
            {
                $dkey = $value['dkey'];
                $pkey = $value['pkey'];
                $tid = $value['tid'];
                $did = $value['did'];
                $rnum = $value['rnum'];//
                $unum = $value['unum'];//分配采购数量
                $taskPnum = $purSuccessDict[$tid]['pnum'];//采购任务已确认采购数量（已完成数量）

                if (isset($demand[$dkey]))
                {
                    $trnum = $demand[$dkey]['rnum'];//当前采购单提交数量
                    $scost = $demand[$dkey]['scost'];//提交单价
                    $tcost = $scost * $trnum;//提交成本
                    if ($trnum <= 0)
                    {
                        throw new AppException('提交数量必须大于0', AppException::OUT_OF_USING);
                    }
                    if ($scost <= 0)
                    {
                        throw new AppException('单价必须大于0', AppException::OUT_OF_USING);
                    }

                    //判断是否重复添加
                    if (in_array($dkey, $skeys))
                    {
                        throw new AppException('请勿重复添加', AppException::DATA_EXIST);
                    }
                    array_push($skeys, $dkey);

                    //检查提交的数量(2020.11.24 最大提交数量 = 计划任务数 - 已完成数量)
                    $maxRnum = $unum - $taskPnum;
                    if ($trnum > $maxRnum)
                    {
                        $demandConf = $this->getDemandConf($dkey);
                        $erMsg = $demandConf['mname'] . $demandConf['opts'];
                        throw new AppException("$erMsg 提交数量超过{$maxRnum}，请重新提交", AppException::WRONG_ARG);
                    }

                    //组装需求单数据
                    $purOdrDemandData[] = [
                        'did' => $did,
                        'okey' => $okey,
                        'pkey' => $pkey,
                        'dkey' => $dkey,
                        'tid' => $tid,
                        'unum' => $unum,
                        'rnum' => $trnum,
                        'scost' => $scost,
                        'tcost' => $tcost,
                        'cstat' => 1,
                        'dstat' => 1,
                        'aacc' => $acc,
                        'lacc' => $acc,
                        'ltime' => $time,
                    ];

                    //组装计划参数
                    $purPlanData[] = [
                        'pkey' => $pkey,
                        'pstat' => 3,
                        'ltime' => $time,
                    ];

                    //获取需求单实际数量 = 原需求单提交数量 - 当前采购单需求数量 + 最新采购数量
                    $purDemandRnum = $purDemandDict[$dkey]['rnum'] - $rnum + $trnum;

                    //组装需求参数
                    $purDemandData[] = [
                        'dkey' => $dkey,
                        'dstat' => 3,
                        'rnum' => $purDemandRnum,
                        'ltime' => $time,
                    ];
                }
            }

            //组装采购单数据
            $orderData = [
                'okey' => $okey,
                'tnum' => count($purOdrDemandData),
                'ostat' => 1,
                'cstat' => 1,
                'merchant' => $merchant,
                'aacc' => $acc,
                'lacc' => $acc,
                'ltime' => $time
            ];
        }
        else
        {
            //生成okey
            $okey = $this->uniqueKeyLogic->getUniversal('OR', 3);

            //获取采购计划数据
            $purTackInfo = PurTaskModel::M()->getList(['dkey' => ['in' => $dkeys], 'tstat' => ['in' => [1, 2]], 'pacc' => $acc], 'tid,pkey,dkey,unum,rnum');

            $tids = ArrayHelper::map($purTackInfo, 'tid');
            $purSuccessDict = PurOdrGoodsModel::M()->getDict('tid', ['tid' => ['in' => $tids], 'gstat' => 4, '$group' => 'tid'], 'count(1) as pnum');

            foreach ($purTackInfo as $value)
            {
                $dkey = $value['dkey'];
                $pkey = $value['pkey'];
                $rnum = $value['rnum'];
                $unum = $value['unum'];
                $tid = $value['tid'];
                $taskPnum = $purSuccessDict[$tid]['pnum'];//采购任务已确认采购数量（已完成数量）

                if (isset($demand[$dkey]))
                {
                    $trnum = $demand[$dkey]['rnum'];
                    $scost = $demand[$dkey]['scost'];
                    $tcost = $scost * $trnum;

                    //单价不能为0
                    if ($scost == 0)
                    {
                        throw new AppException('单价不能为0', AppException::OUT_OF_USING);
                    }

                    //判断是否重复添加
                    if (in_array($dkey, $skeys))
                    {
                        throw new AppException('请勿重复添加', AppException::DATA_EXIST);
                    }
                    array_push($skeys, $dkey);

                    //检查提交的数量(2020.11.24 最大提交数量 = 计划任务数 - 已完成数量)
                    $maxRnum = $unum - $taskPnum;
                    if ($trnum > $maxRnum)
                    {
                        $demandConf = $this->getDemandConf($dkey);
                        $erMsg = $demandConf['mname'] . $demandConf['opts'];
                        throw new AppException("$erMsg 提交数量超过{$maxRnum}，请重新提交", AppException::WRONG_ARG);
                    }

                    //生成did
                    $did = IdHelper::generate();

                    //组装需求单数据
                    $purOdrDemandData[] = [
                        'did' => $did,
                        'okey' => $okey,
                        'pkey' => $pkey,
                        'dkey' => $dkey,
                        'tid' => $tid,
                        'unum' => $unum,
                        'rnum' => $trnum,
                        'scost' => $scost,
                        'tcost' => $tcost,
                        'cstat' => 1,
                        'dstat' => 1,
                        'aacc' => $acc,
                        'ltime' => $time,
                        'atime' => $time,
                    ];

                    //组装计划参数
                    $purPlanData[] = [
                        'pkey' => $pkey,
                        'pstat' => 3,
                        'ltime' => $time,
                    ];

                    //获取需求单实际数量
                    $purDemandRnum = $purDemandDict[$dkey]['rnum'] + $trnum;

                    //组装需求参数
                    $purDemandData[] = [
                        'dkey' => $dkey,
                        'dstat' => 3,
                        'rnum' => $purDemandRnum,
                        'ltime' => $time,
                    ];
                }
            }
            //组装采购单数据
            $orderData = [
                'okey' => $okey,
                'tnum' => count($purOdrDemandData),
                'ostat' => 1,
                'cstat' => 1,
                'merchant' => $merchant,
                'aacc' => $acc,
                'ltime' => $time,
                'atime' => $time
            ];
        }

        try
        {
            //开始事务
            Db::beginTransaction();

            //采购单
            $purOdrOrder = PurOdrOrderModel::M()->insert($orderData, true);
            if ($purOdrOrder == false)
            {
                throw new AppException('操作失败', AppException::FAILED_OPERATE);
            }

            //需求订单
            $purOdrDemand = PurOdrDemandModel::M()->inserts($purOdrDemandData, true);
            if ($purOdrDemand == false)
            {
                throw new AppException('操作失败', AppException::FAILED_OPERATE);
            }

            //更新采购任务的提交数量
            $purTaskData = [];
            $tids = ArrayHelper::map($purOdrDemandData, 'tid');
            $rnumDict = PurOdrDemandModel::M()->getList(['tid' => ['in' => $tids]], 'tid,sum(rnum) as rnum');
            foreach ($rnumDict as $value)
            {
                $purTaskData[] = [
                    'tid' => $value['tid'],
                    'rnum' => $value['rnum'],
                    'tstat' => 2,
                ];
            }
            if ($purTaskData)
            {
                $purTask = PurTaskModel::M()->inserts($purTaskData, true);
                if ($purTask == false)
                {
                    throw new AppException('操作失败', AppException::FAILED_OPERATE);
                }
            }

            //更新计划单
            $purPlan = PurPlanModel::M()->inserts($purPlanData, true);
            if ($purPlan == false)
            {
                throw new AppException('操作失败', AppException::FAILED_OPERATE);
            }

            //更新需求单
            $purDemand = PurDemandModel::M()->inserts($purDemandData, true);
            if ($purDemand == false)
            {
                throw new AppException('操作失败', AppException::FAILED_OPERATE);
            }

            //组装小红点数据（首页显示 - 采购单）
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1302, 'uid' => $acc]);

            //组装小红点数据（首页显示 - 需求单）
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1301, 'uid' => $acc]);

            //增加PC价格审核红点数据
            $dotsAccs = CrmStaffModel::M()
                ->join(AccUserModel::M(), ['acc' => 'aid'])
                ->getList(['B.permis' => ['like' => '%sale_backend_pur0003%']], 'aid', [], 20);

            foreach ($dotsAccs as $dotAcc)
            {
                AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1502, 'uid' => $dotAcc['aid']]);
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
     * 获取型号数据
     * @param string $acc
     * @param string $idx
     * @param string $size
     * @return array
     */
    public function getModel(string $acc, string $idx, string $size)
    {
        //获取当前用户采购计划
        $purTackDict = PurTaskModel::M()->getDict('dkey', ['pacc' => $acc, 'tstat' => ['in' => [1, 2]], ':rnum' => ['!=' => 'unum']], 'tid,dkey,pkey,unum,snum,pnum');
        if (!$purTackDict)
        {
            return [];
        }

        //获取dkey、tid字典
        $dkeys = ArrayHelper::map($purTackDict, 'dkey');

        //所需字段
        $demandCols = 'dkey,pkey,utime,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr';

        //获取采购计划-需求
        $purDemandList = PurDemandModel::M()->getList(['dkey' => ['in' => $dkeys], 'dstat' => ['in' => [2, 3]]], $demandCols, ['utime' => -1], $size, $idx);
        if (!$purDemandList)
        {
            return [];
        }

        //获取对应的字典
        $mids = ArrayHelper::map($purDemandList, 'mid', '-1');
        $levels = ArrayHelper::map($purDemandList, 'level', '-1');
        $optCols = ['mdram', 'mdcolor', 'mdofsale', 'mdnet', 'mdwarr'];
        $optOids = ArrayHelper::maps([$purDemandList, $purDemandList, $purDemandList, $purDemandList, $purDemandList], $optCols, '-1');

        //获取机型数据
        $qtoModelDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

        //获取等级数据
        $qtoLevelDict = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lname');

        //获取类目选项数据
        $qtoOptionsDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $optOids]], 'oname');

        $data = [];
        //补充数据
        foreach ($purDemandList as $key => $value)
        {
            $dkey = $value['dkey'];
            $mid = $value['mid'];
            $level = $value['level'];
            $utime = $value['utime'];

            //提取类目选项
            $options = [];
            foreach ($optCols as $col)
            {
                if (isset($qtoOptionsDict[$value[$col]]))
                {
                    $oname = $qtoOptionsDict[$value[$col]]['oname'] ?? '';
                    if ($oname != '' && !in_array($oname, ['无', '其他正常']))
                    {
                        $options[] = $oname;
                    }
                }
            }

            //组装返回数据
            $data[] = [
                'dkey' => $dkey,
                'midName' => $qtoModelDict[$mid]['mname'] ?? '-',
                'levelName' => $qtoLevelDict[$level]['lname'] ?? '-',
                'snum' => $purTackDict[$dkey]['snum'] ?? '0',
                'pnum' => $purTackDict[$dkey]['pnum'] ?? '0',
                'taskNum' => $purTackDict[$dkey]['unum'] ?? '0',
                'utime' => DateHelper::toString($utime),
                'options' => implode('/', $options),
            ];
        }

        //补充默认值
        ArrayHelper::fillDefaultValue($data);

        //返回
        return $data;
    }

    /**
     * 采购详情
     * @param string $acc
     * @param string $dkey
     * @return array
     * @throws
     */
    public function getInfo(string $acc, string $dkey)
    {
        if ($dkey)
        {
            //获取采购单需求表数据
            $purOdrDemandList = PurOdrDemandModel::M()->getList(['aacc' => $acc, 'dkey' => $dkey], 'rnum,cstat');
            $rnumTotal = 0;

            //计算总的分配采购数量、实际采购数量
            foreach ($purOdrDemandList as $key => $value)
            {
                if ($value['cstat'] == 3)
                {
                    unset($purOdrDemandList[$key]);
                    continue;
                }
                $rnumTotal += $value['rnum'];
            }

            $unumTotal = PurTaskModel::M()->getOne(['pacc' => $acc, 'dkey' => $dkey], 'unum');
            if ($unumTotal == false)
            {
                throw new AppException('对应的采购计划-需求单不存在', AppException::NO_DATA);
            }

            //剩下的数量
            $rnum = $unumTotal - $rnumTotal;

            //所需字段
            $demandCols = 'dkey,pkey,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr';

            //获取需求单数据
            $purDemandInfo = PurDemandModel::M()->getRowById($dkey, $demandCols);
            if ($purDemandInfo == false)
            {
                throw new AppException('对应的需求单不存在', AppException::NO_DATA);
            }

            //获取机型
            $midName = QtoModelModel::M()->getOneById($purDemandInfo['mid'], 'mname', [], '');

            //获取等级
            $levelName = QtoLevelModel::M()->getOneById($purDemandInfo['level'], 'lname', [], '');

            //类目选项所需字段
            $optCols = ['mdram', 'mdcolor', 'mdofsale', 'mdnet', 'mdwarr'];

            $optOids = [$purDemandInfo['mdram'], $purDemandInfo['mdcolor'], $purDemandInfo['mdofsale'], $purDemandInfo['mdnet'], $purDemandInfo['mdwarr']];

            //获取类目选项数据
            $qtoOptionsDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $optOids]]);

            //提取类目选项
            $options = [];
            foreach ($optCols as $col)
            {
                if (isset($qtoOptionsDict[$purDemandInfo[$col]]))
                {
                    $oname = $qtoOptionsDict[$purDemandInfo[$col]]['oname'] ?? '';
                    if ($oname != '' && !in_array($oname, ['无', '其他正常']))
                    {
                        $options[] = $oname;
                    }
                }
            }
            $options = implode('/', $options);
        }

        //返回
        return [
            'dkey' => $dkey ?: '',
            'midName' => $midName ?? '',
            'levelName' => $levelName ?? '',
            'rnum' => $rnum ?? '',
            'options' => $options ?? '',
        ];
    }

    /**
     * 获取采购需求对应选项
     * @param string $dkey 需求单号
     * @param string $glue 分割符号
     * @return array
     */
    private function getDemandConf(string $dkey, string $glue = ' ')
    {
        //获取采购需求
        $demand = PurDemandModel::M()->getRowById($dkey, 'mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr');

        //获取级别
        $level = QtoLevelModel::M()->getOneById($demand['level'], 'lname');

        //获取采购机型需求
        $opts = [$demand['mdram'], $demand['mdcolor'], $demand['mdofsale'], $demand['mdnet'], $demand['mdwarr']];
        $opts = QtoOptionsModel::M()->getList(['oid' => ['in' => $opts], 'plat' => 0], 'oname');
        $opts = ArrayHelper::toJoin($opts, 'oname', $glue);
        $opts = $level . $glue . $opts;

        //获取机型名称
        $mname = QtoModelModel::M()->getOneById($demand['mid'], 'mname');

        //返回
        return [
            'mname' => $mname,
            'opts' => $opts
        ];
    }
}