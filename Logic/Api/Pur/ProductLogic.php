<?php
namespace App\Module\Sale\Logic\Api\Pur;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Mqc\MqcBatchModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Model\Pur\PurPlanModel;
use App\Model\Pur\PurTaskModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoCategoryModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsMirrorModel;
use App\Model\Qto\QtoOptionsModel;
use App\Model\Stc\StcInoutGoodsModel;
use App\Model\Stc\StcInoutSheetModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

/**
 * 商品详情
 * Class ProductLogic
 * @package App\Module\Sale\Logic\Api\Pur
 */
class ProductLogic extends BeanCollector
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
     * 获取供应商翻页数据
     * @param int $idx
     * @param int $size
     * @param array $query
     * @param string $aacc
     * @return array
     */
    public function getPager(string $aacc, array $data, int $size, int $idx)
    {
        $where = $this->getWhere($data);
        $where['aacc'] = $aacc;

        //搜索商品表
        $purOdrGoods = PurOdrGoodsModel::M()->getList($where, '*', ['atime' => -1], $size, $idx);
        if (!$purOdrGoods)
        {
            return [];
        }

        //获取需求单号字典
        $dkeys = ArrayHelper::map($purOdrGoods, 'dkey');
        $purDemand = PurDemandModel::M()->getList(['dkey' => ['in' => $dkeys]], 'dkey,utime,bid,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr');

        //获取供应商信息
        $merchants = ArrayHelper::map($purOdrGoods, 'merchant');
        $purMerchant = PurMerchantModel::M()->getDict('mid', ['mid' => ['in' => $merchants]], 'mname');

        //获取商品品牌，机型，级别字典
        $bid = ArrayHelper::map($purDemand, 'bid', -1);
        $mid = ArrayHelper::map($purDemand, 'mid', -1);
        $level = ArrayHelper::map($purDemand, 'level', -1);

        //获取商品品牌，机型，级别
        $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bid]], 'bname');
        $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mid]], 'mname');
        $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $level]], 'lname');

        //获取机型类目选项字典
        $optCols = ['mdofsale', 'mdnet', 'mdcolor', 'mdram', 'mdwarr'];
        $optOids = ArrayHelper::maps([$purDemand, $purDemand, $purDemand, $purDemand, $purDemand], $optCols);
        $optionsDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $optOids]], 'oid,oname');

        $purDemand = ArrayHelper::dict($purDemand, 'dkey');
        foreach ($purDemand as $key => $value)
        {
            //机器扩展信息
            $purDemand[$key]['mdofsale'] = $optionsDict[$value['mdofsale']]['oname'] ?? '';
            $purDemand[$key]['mdnet'] = $optionsDict[$value['mdnet']]['oname'] ?? '';
            $purDemand[$key]['mdcolor'] = $optionsDict[$value['mdcolor']]['oname'] ?? '';
            $purDemand[$key]['mdram'] = $optionsDict[$value['mdram']]['oname'] ?? '';
            $purDemand[$key]['mdwarr'] = $optionsDict[$value['mdwarr']]['oname'] ?? '';
            $purDemand[$key]['needle'] = [
                $qtoLevel[$value['level']]['lname'] ?? '',
                $optionsDict[$value['mdofsale']]['oname'] ?? '',
                $optionsDict[$value['mdnet']]['oname'] ?? '',
                $optionsDict[$value['mdcolor']]['oname'] ?? '',
                $optionsDict[$value['mdram']]['oname'] ?? '',
                $optionsDict[$value['mdwarr']]['oname'] ?? ''
            ];
            $purDemand[$key]['needle'] = array_filter($purDemand[$key]['needle']);
            $purDemand[$key]['bname'] = $qtoBrand[$value['bid']]['bname'] ?? '-';
            $purDemand[$key]['modelName'] = $qtoModel[$value['mid']]['mname'] ?? '-';
            $purDemand[$key]['lname'] = $qtoLevel[$value['level']]['lname'] ?? '-';
            $purDemand[$key]['need'] = implode('/', $purDemand[$key]['needle']);
        }

        foreach ($purOdrGoods as $key => $value)
        {
            $purOdrGoods[$key]['manme'] = $purMerchant[$value['merchant']]['mname'] ?? '-';
            $purOdrGoods[$key]['need'] = $purDemand[$value['dkey']]['need'];
            $purOdrGoods[$key]['modelName'] = $purDemand[$value['dkey']]['modelName'];
            $purOdrGoods[$key]['utime'] = DateHelper::toString($purDemand[$value['dkey']]['utime']);
            $purOdrGoods[$key]['gstat'] = PurDictData::PUR_ODR_GOODS[$value['gstat']];
            $purOdrGoods[$key]['gtime4'] = DateHelper::toString($value['gtime4']);

            //判断商品状态
            if ($value['gstat'] == 1)
            {
                $purOdrGoods[$key]['gstatName'] = '已预入库';
            }
            if ($value['gstat'] == 2)
            {
                $purOdrGoods[$key]['gstatName'] = '已入库';
            }
            if ($value['gstat'] == 3)
            {
                $purOdrGoods[$key]['gstatName'] = '质检待确认';
            }
            if ($value['gstat'] == 5 && $value['prdstat'] == 1)
            {
                $purOdrGoods[$key]['gstatName'] = '待退货';
            }
            if ($value['gstat'] == 4)
            {
                $purOdrGoods[$key]['gstatName'] = '待上架 ';
            }
            if ($value['gstat'] == 5 && $value['prdstat'] == 3)
            {
                $purOdrGoods[$key]['gstatName'] = '已退货';
            }
            if ($value['gstat'] == 4 && $value['prdstat'] == 2)
            {
                $purOdrGoods[$key]['gstatName'] = '已销售';
            }

            //取最新更新时间
            if ($value['gtime1'] != 0)
            {
                $purOdrGoods[$key]['gtime'] = DateHelper::toString($value['gtime1']);
            }
            if ($value['gtime2'] != 0)
            {
                $purOdrGoods[$key]['gtime'] = DateHelper::toString($value['gtime2']);
            }
            if ($value['gtime3'] != 0)
            {
                $purOdrGoods[$key]['gtime'] = DateHelper::toString($value['gtime3']);
            }
            if ($value['gtime4'] != 0)
            {
                $purOdrGoods[$key]['gtime'] = DateHelper::toString($value['gtime4']);
            }
            if ($value['gtime5'] != 0)
            {
                $purOdrGoods[$key]['gtime'] = DateHelper::toString($value['gtime5']);
            }
        }

        return $purOdrGoods;
    }

    /**
     * 退货、采购接口
     * @param int $stat
     * @param string $bcode
     * @throws
     */
    public function confirm(int $stat, string $bcode)
    {
        //商品详情
        $cols = 'pid,inway,plat,offer,bid,mid,pname,palias,level,mdram,mdcolor,mdofsale,mdnet,mdwarr,prdcost,cost11,cost12,cost13,cost14,cost32,costuptime';
        $prdProduct = PrdProductModel::M()->getRow(['bcode' => $bcode], $cols);

        $purOdrGoods = PurOdrGoodsModel::M()->getRow(['bcode' => $bcode], 'did,tid,gstat,aacc,okey,dkey,pkey');
        if ($purOdrGoods['gstat'] != 3)
        {
            throw new AppException('当前商品非确认状态');
        }
        $okey = $purOdrGoods['okey'];
        $toAcc = $purOdrGoods['aacc'];
        $did = $purOdrGoods['did'];
        $tid = $purOdrGoods['tid'];
        $dkey = $purOdrGoods['dkey'];
        $pkey = $purOdrGoods['pkey'];
        $time = time();

        //首页显示 - 采购单 + 需求单
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1301, 'uid' => $toAcc]);
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1302, 'uid' => $toAcc]);

        if ($stat == 2)
        {
            //更新采购商品状态
            PurOdrGoodsModel::M()->update(['bcode' => $bcode], ['gstat' => 4, 'gtime4' => $time]);

            //更新采购需求表已完成+1
            PurOdrDemandModel::M()->updateById($did, [], ['pnum' => 'pnum+1']);

            //获取任务任务完成商品数量
            $taskPnum = PurOdrGoodsModel::M()->getCount(['tid' => $tid, 'gstat' => 4]);
            $taskUnum = PurTaskModel::M()->getOneById($tid, 'unum');
            $taskData = ['pnum' => $taskPnum];
            if ($taskUnum == $taskPnum)
            {
                //任务分配数量 = 任务实际已完成数量  =》 任务完成
                $taskData['tstat'] = 3;
            }
            PurTaskModel::M()->updateById($tid, $taskData);

            //商品更新为成交
            PrdProductModel::M()->updateById($prdProduct['pid'], ['recstat' => 7, 'rectime7' => $time]);

            //分配数量 = 确认完成数量，任务达标，变更为已完成
            $demandPnum = PurOdrGoodsModel::M()->getCount(['did' => $did, 'gstat' => 4]);
            $odrDeamnd = PurOdrDemandModel::M()->getRowById($did, 'unum,rnum');
            if ($odrDeamnd['unum'] == $demandPnum || $odrDeamnd['rnum'] == $demandPnum)
            {
                //当前需求单提交采购数量 = 需求单商品已完成数量
                if ($odrDeamnd['rnum'] == $demandPnum)
                {
                    PurOdrDemandModel::M()->updateById($did, ['dstat' => 2, 'ltime' => $time]);
                }

                //当前采购单完成的需求单数量 = 采购单对应的需求单数量 =》 采购单变更为已完成
                $finishDemand = PurOdrDemandModel::M()->getCount(['okey' => $okey, 'dstat' => 2]);
                $odrTnum = PurOdrOrderModel::M()->getOneById($okey, 'tnum');
                if ($odrTnum == $finishDemand)
                {
                    PurOdrOrderModel::M()->updateById($okey, ['ostat' => 2, 'ltime' => $time]);
                }

                //需求单分配任务全部完成，需求单变更为已完成
                $task = PurTaskModel::M()->getRow(['dkey' => $dkey], 'count(1) as total,sum(tstat=3) as fnum');
                if ($task['total'] == $task['fnum'])
                {
                    PurDemandModel::M()->updateById($dkey, ['dstat' => 5, 'ltime' => $time]);
                }

                //需求单全部完成，采购计划完成
                $demand = PurDemandModel::M()->getRow(['pkey' => $pkey], 'count(1) as total,sum(dstat=5) as fnum,utime');
                if ($demand['total'] == $demand['fnum'])
                {
                    $delay = 0;
                    if ($time > $demand['utime'])
                    {
                        $delay = 1;
                    }
                    PurPlanModel::M()->updateById($pkey, ['pstat' => 5, 'ptime5' => $time, 'ltime' => $time, 'delay' => $delay]);
                }
            }

            //判断supply是否存在
            $prdSupply = PrdSupplyModel::M()->getList(['pid' => $prdProduct['pid'], 'inway' => $prdProduct['inway']]);
            if (!$prdSupply)
            {
                //处理为确认采购
                $pname = trim($prdProduct['pname']);
                if (trim($prdProduct['palias']))
                {
                    $pname .= ' ' . trim($prdProduct['palias']);
                }

                $sid = IdHelper::generate();
                $supplyData = [
                    'sid' => $sid,
                    'inway' => $prdProduct['inway'],
                    'plat' => $prdProduct['plat'],
                    'offer' => $prdProduct['offer'],
                    'pid' => $prdProduct['pid'],
                    'bid' => $prdProduct['bid'],
                    'mid' => $prdProduct['mid'],
                    'pname' => $pname,
                    'level' => $prdProduct['level'],
                    'sbid' => $prdProduct['bid'],
                    'smid' => $prdProduct['mid'],
                    'slevel' => $prdProduct['level'],
                    'mdram' => $prdProduct['mdram'],
                    'mdnet' => $prdProduct['mdnet'],
                    'mdcolor' => $prdProduct['mdcolor'],
                    'mdofsale' => $prdProduct['mdofsale'],
                    'mdwarr' => $prdProduct['mdwarr'],
                    'pcost' => $prdProduct['prdcost'],
                    'cost11' => $prdProduct['cost11'],
                    'cost12' => $prdProduct['cost12'],
                    'cost13' => $prdProduct['cost13'],
                    'cost14' => $prdProduct['cost14'],
                    'cost32' => $prdProduct['cost32'],
                    'ryccost' => $prdProduct['prdcost'],
                    'prdcost' => $prdProduct['prdcost'],
                    'costuptime' => $prdProduct['costuptime'],
                    'salestat' => 1,
                    'atime' => $time,
                ];
                PrdSupplyModel::M()->insert($supplyData);
            }

            //更新计划完成数据
            $purOdrDemand = PurOdrDemandModel::M()->getRow(['pkey' => $pkey], 'sum(pnum) as num,sum(pnum*scost) as cost');
            PurPlanModel::M()->updateById($pkey, ['rnum' => $purOdrDemand['num'], 'rcost' => $purOdrDemand['cost'], 'ltime' => time()]);

            //采购单列表 - 1403：采购单-已完成
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1403, 'uid' => $toAcc, 'bid' => $okey]);
        }
        else
        {
            //处理为待退货
            $pid = $prdProduct['pid'];

            //获取未出库的出库单
            $stcSheet = StcInoutSheetModel::M()->getRow(['aacc' => PurDictData::STC_RTN_USER, 'fstat' => 1], 'sid,skey');
            $stcSid = $stcSheet['sid'] ?? false;
            $skey = $stcSheet['skey'] ?? false;
            if (!$stcSid)
            {
                //新增出库单
                $stcSid = IdHelper::generate();//入库单ID
                $skey = $this->uniqueKeyLogic->getStcCR();//入库单单号
                $data = [
                    'sid' => $stcSid,
                    'skey' => $skey,
                    'offer' => '',
                    'tid' => 1,
                    'fwhs' => 101,
                    'fstat' => 1,
                    'aacc' => PurDictData::STC_RTN_USER,
                    'atime' => $time,
                ];
                StcInoutSheetModel::M()->insert($data);
            }

            //商品更新为出库中
            PrdProductModel::M()->updateById($pid, ['stcstat' => 13, 'stctime' => $time]);

            //新增出库单商品
            $data = [
                'gid' => IdHelper::generate(),
                'sid' => $stcSid,
                'pid' => $pid,
                'facc' => PurDictData::STC_RTN_USER,
            ];
            StcInoutGoodsModel::M()->insert($data);

            //更新出库单商品数量
            $count = StcInoutGoodsModel::M()->getCount(['sid' => $stcSid]);
            StcInoutSheetModel::M()->updateById($stcSid, ['qty' => $count]);

            //标记为待退货
            PurOdrGoodsModel::M()->update(['bcode' => $bcode], ['gstat' => 5, 'gtime5' => $time, 'rtnskey' => $skey]);

            //采购单列表 - 1405：采购单-待退货
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1405, 'uid' => $toAcc, 'bid' => $okey]);
        }
    }

    /**
     * 获取商品详情
     * @param string $bcode
     * @param string $okey
     * @param int $stat
     * @return array
     */
    public function getDetail(string $bcode, string $okey = '', int $stat = 0)
    {
        $purOdrGood = PurOdrGoodsModel::M()->getRow(['bcode' => $bcode]);

        $col = 'pid,bid,mid,plat,level,palias,salecost,imei,bcode,prdstat,saletime,chkstat';
        $prdProduct = PrdProductModel::M()->getRow(['bcode' => $purOdrGood['bcode']], $col);

        //获取供应库信息
        $prdSupply = PrdSupplyModel::M()->getRow(['pid' => $prdProduct['pid']], 'saleamt,imgpack');
        $imgpack = ArrayHelper::toArray($prdSupply['imgpack'] ?? '[]');
        foreach ($imgpack as $key2 => $value2)
        {
            $imgpack[$key2]['src'] = Utility::supplementProductImgsrc($value2['src']);
        }

        //获取需求信息
        $purDemand = PurDemandModel::M()->getRow(['dkey' => $purOdrGood['dkey']], 'utime,bid,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr');

        //获取采购单价
        $purOdrDemand = PurOdrDemandModel::M()->getRow(['did' => $purOdrGood['did']], 'scost');

        //获取供应商信息
        $purMerchant = PurMerchantModel::M()->getRow(['mid' => $purOdrGood['merchant']], 'mname');

        //获取商品品牌，机型，级别
        $qtoBrand = QtoBrandModel::M()->getRow(['bid' => $purDemand['bid']], 'bname');
        $qtoModel = QtoModelModel::M()->getRow(['mid' => $purDemand['mid']], 'mname');
        $lname = QtoLevelModel::M()->getOne(['lkey' => $prdProduct['level']], 'lname');
        if ($prdProduct['chkstat'] != 3)
        {
            $lname = '-';
        }

        //组装数据
        $purOdrGood['imei'] = $prdProduct['imei'] == '' ? '-' : $prdProduct['imei'];
        $purOdrGood['bcode'] = $prdProduct['bcode'] ?? '-';
        $purOdrGood['scost'] = $purOdrDemand['scost'] ?? '-';
        $purOdrGood['saleamt'] = $prdSupply['saleamt'] ?? '-';
        $purOdrGood['merchantName'] = $purMerchant['mname'] ?? '-';
        $purOdrGood['bname'] = $qtoBrand['bname'] ?? '-';
        $purOdrGood['mname'] = $qtoModel['mname'] ?? '-';
        $purOdrGood['lname'] = $lname;
        $purOdrGood['imgpack'] = $imgpack;

        //组装时间节点
        $timeAlias = [];
        if ($purOdrGood['gtime1'] > 0)
        {
            $timeAlias[] = ['lable' => '预入库', 'time' => DateHelper::toString($purOdrGood['gtime1'])];
        }
        if ($purOdrGood['gtime2'] > 0)
        {
            $timeAlias[] = ['lable' => '入库', 'time' => DateHelper::toString($purOdrGood['gtime2'])];
        }
        if ($purOdrGood['gtime3'] > 0)
        {
            $timeAlias[] = ['lable' => '质检', 'time' => DateHelper::toString($purOdrGood['gtime3'])];
        }
        if ($purOdrGood['gstat'] == 4 && $purOdrGood['gtime4'] > 0)
        {
            $timeAlias[] = ['lable' => '待上架', 'time' => DateHelper::toString($purOdrGood['gtime4'])];
        }
        if ($purOdrGood['gstat'] == 4 && $prdProduct['saletime'] > 0)
        {
            $timeAlias[] = ['lable' => '已销售', 'time' => DateHelper::toString($prdProduct['saletime'])];
        }
        if ($purOdrGood['gtime5'] > 0)
        {
            $timeAlias[] = ['lable' => '待退货', 'time' => DateHelper::toString($purOdrGood['gtime5'])];
        }
        if ($purOdrGood['gstat'] == 5 && $purOdrGood['prdstat'] == 3)
        {
            $rtntime = StcInoutSheetModel::M()->getOne(['skey' => $purOdrGood['rtnskey']], 'ftime');
            $timeAlias[] = ['lable' => '已退货', 'time' => DateHelper::toString($rtntime ?? 0)];
        }
        //最后一个选项高亮
        $timeAlias[count($timeAlias) - 1]['stat'] = 1;

        //判断商品状态
        if ($purOdrGood['gstat'] == 1)
        {
            $purOdrGood['gstatName'] = '已预入库';
        }
        if ($purOdrGood['gstat'] == 2)
        {
            $purOdrGood['gstatName'] = '已入库';
        }
        if ($purOdrGood['gstat'] == 3)
        {
            $purOdrGood['gstatName'] = '质检待确认';
        }
        if ($purOdrGood['gstat'] == 5 && $purOdrGood['prdstat'] == 1)
        {
            $purOdrGood['gstatName'] = '待退货';
        }
        if ($purOdrGood['gstat'] == 4)
        {
            $purOdrGood['gstatName'] = '待上架 ';
        }
        if ($purOdrGood['gstat'] == 5 && $purOdrGood['prdstat'] == 3)
        {
            $purOdrGood['gstatName'] = '已退货';
        }
        if ($purOdrGood['gstat'] == 4 && $purOdrGood['prdstat'] == 2)
        {
            $purOdrGood['gstatName'] = '已销售';
        }

        //获取需求对应的机型类目选项字典
        $optOids = [$purDemand['mdnet'], $purDemand['mdofsale'], $purDemand['mdwarr'], $purDemand['mdcolor'], $purDemand['mdram']];
        $purOptionsDict = QtoOptionsModel::M()->getDict('cid', ['oid' => ['in' => $optOids]], 'oid,cid,oname');

        //组装质检报告数据
        $newList = [];
        if (!empty($purOptionsDict))
        {
            //获取类目字典
            $cids = array_column($purOptionsDict, 'cid', -1);
            $catDict = QtoCategoryModel::M()->getDict('cid', ['cid' => ['in' => $cids]], 'cname');

            //质检备注
            $qcReport = MqcReportModel::M()->getRow(['pid' => $prdProduct['pid'], 'plat' => 21], 'bconc,bmkey', ['atime' => -1]);
            $mqcBatch = MqcBatchModel::M()->getRow(['pid' => $prdProduct['pid']], 'beval', ['etime' => -1]);
            if ($mqcBatch)
            {
                //类目映射 基础库 => 质检报告类目
                $cidMap = [
                    17000 => [400000, 11150000, 13150000],//内存
                    15000 => [100000, 11110000, 13110000],//机型网络制式
                    16000 => [300000, 11140000, 13140000],//颜色
                    14000 => [200000, 11120000, 13120000],//销售地 -> 购买渠道
                    24000 => [11130000, 13130000],//保修
                ];

                //获取质检详情
                $bevel = $mqcBatch['beval'];
                $bevel = explode('#', $bevel);

                //获取质检对应的机型类目选项字典
                $mqcOptionsDict = QtoOptionsModel::M()->getDict('cid', ['oid' => ['in' => $bevel], 'plat' => 0, 'cid' => ['in' => $cids]], 'oid,cid,oname');
                $purOdrGood['pname'] = $qtoModel['mname'] . '' . implode(' ', array_column($mqcOptionsDict, 'oname'));

                //组装数据
                $newList = [];
                $useCids = [11520000, 13520000];
                foreach ($mqcOptionsDict as $cid => $value)
                {
                    $moname = $value['oname'];//质检选项
                    $doname = $purOptionsDict[$cid]['oname'];//采购需求选项

                    //是否变色
                    if ($doname != '' && $moname != $doname)
                    {
                        $moname = '<span style="color: #ff0000">' . $moname . '</span>';
                    }

                    $newList[] = [
                        'cname' => $catDict[$cid]['cname'],
                        'moname' => $moname,
                        'doname' => $doname,
                    ];

                    //记录已用过的关联类目
                    $useCids = array_merge($useCids, $cidMap[$cid]);
                }

                //补充其他数据（排除已比对过的数据）
                $mqcContent = QtoOptionsMirrorModel::M()->getOneById($qcReport['bmkey'], 'content');
                $mqcContent = json_decode($mqcContent, true);
                foreach ($mqcContent as $value)
                {
                    if (in_array($value['cid'], $useCids))
                    {
                        continue;
                    }
                    //质检选项
                    $moname = array_column($value['opts'], 'oname');
                    $moname = implode('', $moname);
                    $newList[] = [
                        'cname' => $value['cname'],
                        'moname' => $moname,
                        'doname' => '-',
                    ];
                }
            }
            $purOdrGood['qcReport'] = $qcReport['bconc'] ?? '';

            //补充质检选项没有的数据 / 已入库未质检
            $donames = array_column($newList, 'doname');
            foreach ($purOptionsDict as $value)
            {
                if (!in_array($value['oname'], $donames))
                {
                    $newList[] = [
                        'cname' => $catDict[$value['cid']]['cname'],
                        'moname' => '-',
                        'doname' => $value['oname'],
                    ];
                }
            }
        }

        $preBcode = '';//上一个库存编码
        $nextBcode = '';//下一个库存编码
        $total = 0;//总数
        $index = 0;//当前位置
        if ($stat == 2)
        {
            //获取当前所有要确认的数据
            $goods = PurOdrGoodsModel::M()->getList(['okey' => $okey, 'gstat' => 3], 'bcode', ['atime' => 1]);
            $total = count($goods);//总数
            foreach ($goods as $key => $value)
            {
                if ($value['bcode'] == $bcode)
                {
                    $index = $key + 1;
                }
            }
            if ($index > 1)
            {
                $preBcode = $goods[$index - 2]['bcode'];
            }
            if ($index < $total)
            {
                $nextBcode = $goods[$index]['bcode'];
            }
        }

        //返回
        return [
            'purOdrGood' => $purOdrGood,
            'newList' => $newList,
            'preBcode' => $preBcode,
            'nextBcode' => $nextBcode,
            'total' => $total,
            'index' => $index,
            'timeAlias' => $timeAlias,
        ];
    }

    /**
     * 获取查询字段
     * @param $data
     * @return array
     */
    public function getWhere(array $data)
    {
        $where = [];

        //判断是否从主页查询
        if ($data['ishome'] == 1)
        {
            //判断是否主页供货商查询
            $mid = PurMerchantModel::M()->getRow(['mname' => ['like' => "%{$data['message']}%"]], 'mid');
            if ($mid)
            {
                $where['merchant'] = $mid['mid'];
            }
            else
            {
                //库存编码、需求单编号、采购单号查询
                $where['$or'] = [
                    ['bcode' => $data['message']],
                    ['okey' => $data['message']],
                    ['dkey' => $data['message']],
                    ['did' => $data['message']]
                ];
            }
        }
        else
        {
            //归属采购商家
            if ($data['merchant'])
            {
                $where['merchant'] = $data['merchant'];
            }

            //商品库存编号
            if ($data['bcode'])
            {
                $where['bcode'] = $data['bcode'];
            }

            //采购单
            if ($data['okey'])
            {
                $where['okey'] = $data['okey'];
            }

            //需求单
            if ($data['dkey'])
            {
                $where['dkey'] = $data['dkey'];
            }

            //判断退货单号
            if ($data['skey'])
            {
                $sid = StcInoutSheetModel::M()->getRow(['skey' => $data['skey']], 'sid');
                if ($sid)
                {
                    $stcGoods = StcInoutGoodsModel::M()->getList(['sid' => $sid['sid']], 'pid');
                    $pids = ArrayHelper::map($stcGoods, 'pid', '-1');
                    $prds = PrdProductModel::M()->getList(['pid' => ['in' => $pids]], 'bcode');
                    $bcodes = ArrayHelper::map($prds, 'bcode', '-1');
                    if ($data['bcode'] != '')
                    {
                        $bcodes = array_intersect([$data['bcode']], $bcodes);
                    }
                    if (empty($bcodes))
                    {
                        $where['bcode'] = -1;
                    }
                    else
                    {
                        $where['bcode'] = ['in' => $bcodes];
                    }
                }
            }

            //判断商品状态 搜索商品状态 1已预入库 2已入库 3质检待确认 4已完成 5待退货 6待上架 7已退货(prdstat=3) 8已销售(prdstat=2)
            switch ($data['stat'])
            {
                case 1:
                    //1已预入库
                    $where['gstat'] = 1;
                    break;
                case 2:
                    //2已入库
                    $where['gstat'] = ['!=' => 1];
                    break;
                case 3:
                    //3质检待确认
                    $where['gstat'] = 3;
                    break;
                case 4:
                    //4已完成
                    $where['gstat'] = 4;
                    break;
                case 5:
                    //5待退货
                    $where['gstat'] = 5;
                    $where['prdstat'] = 1;
                    break;
                case 6:
                    //6待上架
                    $where['gstat'] = 4;
                    $where['prdstat'] = 1;
                    break;
                case 7:
                    //7已退货
                    $where['gstat'] = 5;
                    $where['prdstat'] = 3;
                    break;
                case 8:
                    //8已销售
                    $where['gstat'] = 4;
                    $where['prdstat'] = 2;
                    break;
            }

            //时间类型 1、入库时间 2质检时间 3采购完成时间 4销售时间
            if ($data['gtype'])
            {
                $stime = strtotime($data['gtime'] . ' 00:00:00');
                $etime = strtotime($data['gtime'] . ' 23:59:59');
                $betweenTime = ['between' => [$stime, $etime]];
                switch ($data['gtype'])
                {
                    case 1:
                        //入库时间
                        $where['gtime2'] = $betweenTime;
                        break;
                    case 2:
                        //2质检时间
                        $where['gtime3'] = $betweenTime;
                        break;
                    case 3:
                        //3采购完成时间
                        $where['gtime4'] = $betweenTime;
                        break;
                    case 4:
                        //4销售时间
                        $salePrds = PrdProductModel::M()->getList(['inway' => PurDictData::PUR_INWAY1, 'saletime' => $betweenTime], 'bcode');
                        $bcodes = ArrayHelper::map($salePrds, 'bcode', '-1');
                        $where['bcode'] = ['in' => $bcodes];
                        break;
                }
            }
        }

        return $where;
    }
}