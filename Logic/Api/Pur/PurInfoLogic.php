<?php
namespace App\Module\Sale\Logic\Api\Pur;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmMessageDotModel;
use App\Model\Crm\CrmStaffModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Model\Pur\PurTaskModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class PurInfoLogic extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 采购单详情
     * @param string $aacc
     * @param string $okey
     * @param int $ostat
     * @param int $cstat
     * @return array
     * @throws
     */
    public function purData(string $aacc, string $okey, int $ostat, int $cstat)
    {
        if ($cstat)
        {
            $where['cstat'] = $cstat;
        }
        $where['okey'] = $okey;
        $where['aacc'] = $aacc;
        $col = 'okey,tnum,snum,fnum,ostat,cstat,stock,merchant,pretime,ctime,atime';
        $purOdrOrder = PurOdrOrderModel::M()->getRow($where, $col, ['pretime' => -1, 'stctime' => -1]);
        if (!$purOdrOrder)
        {
            throw new AppException('采购单不存在', AppException::NO_DATA);
        }

        //获取供应商
        $merchant = PurMerchantModel::M()->getRow(['mid' => $purOdrOrder['merchant']], 'mname,mobile');
        $purOdrOrder['mname'] = $merchant['mname'];
        $purOdrOrder['mobile'] = $merchant['mobile'];
        $purOdrOrder['atime'] = DateHelper::toString($purOdrOrder['atime']);

        //获取采购单需求单
        $cols = 'did,tid,dkey,unum,rnum,snum,mnum,pnum,scost,tcost,cstat,dstat,rmk';
        $purOdrDemand = PurOdrDemandModel::M()->getList(['okey' => $purOdrOrder['okey'], 'aacc' => $aacc], $cols);

        //获取需求单字典
        $dids = ArrayHelper::map($purOdrDemand, 'did');
        $purOdrGoods = PurOdrGoodsModel::M()->getDict('did', ['did' => ['in' => $dids], 'aacc' => $aacc, '$group' => ['did']], 'did,count(*) as num');

        //获取需求表字典
        $dkeys = ArrayHelper::map($purOdrDemand, 'dkey');
        $purDemand = PurDemandModel::M()->getList(['dkey' => ['in' => $dkeys]]);

        //获取任务表字典
        $tids = ArrayHelper::map($purOdrDemand, 'tid');
        $purTask = PurTaskModel::M()->getDict('tid', ['tid' => ['in' => $tids]], 'snum,pnum');

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
        $purOdrOrder['tnum'] = 0;
        $purOdrOrder['onum'] = 0;

        foreach ($purOdrDemand as $key => $value)
        {
            $dkey = $value['dkey'];
            $purOdrDemand[$key]['utime'] = DateHelper::toString($purDemand[$dkey]['utime']);
            $purOdrDemand[$key]['bname'] = $qtoBrand[$purDemand[$dkey]['bid']]['bname'];
            $purOdrDemand[$key]['mname'] = $qtoModel[$purDemand[$dkey]['mid']]['mname'];
            $purOdrDemand[$key]['num'] = $purOdrGoods[$value['did']]['num'] ?? '-';
            $purOdrDemand[$key]['ocost'] = Utility::formatNumber($value['pnum'] * $value['scost'], 2);
            $purOdrDemand[$key]['cstatName'] = PurDictData::PUR_PDR_STAT[$value['cstat']];
            $purOdrDemand[$key]['snum'] = $purTask[$value['tid']]['snum'];
            $purOdrDemand[$key]['pnum'] = $purTask[$value['tid']]['pnum'];
            $purOdrDemand[$key]['needle'] = [
                $qtoLevel[$purDemand[$dkey]['level']]['lname'] ?? '',
                $optionsDict[$purDemand[$dkey]['mdofsale']]['oname'] ?? '',
                $optionsDict[$purDemand[$dkey]['mdnet']]['oname'] ?? '',
                $optionsDict[$purDemand[$dkey]['mdcolor']]['oname'] ?? '',
                $optionsDict[$purDemand[$dkey]['mdram']]['oname'] ?? '',
                $optionsDict[$purDemand[$dkey]['mdwarr']]['oname'] ?? ''
            ];
            $purOdrDemand[$key]['needle'] = array_filter($purOdrDemand[$key]['needle']);
            $purOdrDemand[$key]['need'] = implode(' ', $purOdrDemand[$key]['needle']);
            unset($purOdrDemand[$key]['needle']);
            $purOdrOrder['tnum'] += $value['pnum'];
            $purOdrOrder['onum'] += $value['rnum'];
        }
        $purOdrOrder['ctime'] = DateHelper::toString($purOdrOrder['ctime']);

        if ($ostat == 2)
        {
            $dot = CrmMessageDotModel::M()->getRow(['plat' => 24, 'src' => '1403', 'dtype' => 13, 'bid' => $okey, 'uid' => $aacc]);
            if ($dot)
            {
                //删除小红点数据
                CrmMessageDotModel::M()->delete(['plat' => 24, 'src' => '1403', 'dtype' => 13, 'bid' => $okey, 'uid' => $aacc]);
            }
        }
        if ($cstat == 1)
        {
            $dot = CrmMessageDotModel::M()->getRow(['plat' => 24, 'src' => '1401', 'dtype' => 13, 'bid' => $okey, 'uid' => $aacc]);
            if ($dot)
            {
                //删除小红点数据
                CrmMessageDotModel::M()->delete(['plat' => 24, 'src' => '1401', 'dtype' => 13, 'bid' => $okey, 'uid' => $aacc]);
            }
        }
        //返回
        return [
            'purOdrOrder' => $purOdrOrder,
            'purOdrDemand' => $purOdrDemand
        ];
    }

    /**
     * 显示需求单修改前数据
     * @param string $aacc
     * @param string $did
     * @return array
     * @throws
     */
    public function getDemandInfo(string $aacc, string $did)
    {
        //获取需求单数据
        $purOdrDemand = PurOdrDemandModel::M()->getRowById($did, 'okey,dkey,rnum,unum,scost,tcost,aacc');
        if (!$purOdrDemand)
        {
            throw new AppException('需求单不存在', AppException::NO_DATA);
        }
        if ($purOdrDemand['aacc'] != $aacc)
        {
            throw new AppException('你没有权限', AppException::NO_RIGHT);
        }

        //获取采购需求单数据
        $purDemand = PurDemandModel::M()->getRowById($purOdrDemand['dkey'], 'bid,mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr');

        //获取商品品牌，机型，级别
        $qtoModel = QtoModelModel::M()->getRow(['mid' => $purDemand['mid']], 'mname');
        $qtoLevel = QtoLevelModel::M()->getRow(['lkey' => $purDemand['level']], 'lname');

        //获取机型类目选项字典
        $optOids = [$purDemand['mdnet'], $purDemand['mdofsale'], $purDemand['mdwarr'], $purDemand['mdcolor'], $purDemand['mdram']];
        $optionsDict = QtoOptionsModel::M()->getList(['oid' => ['in' => $optOids]], 'oname');
        $optionsDict = array_column($optionsDict, 'oname');
        $options = array_merge($qtoLevel, $optionsDict);

        //组装数据
        $purOdrDemand['need'] = implode(' ', array_filter($options));
        $purOdrDemand['mname'] = $qtoModel['mname'];

        //返回
        return $purOdrDemand;
    }

    /**
     * 保存需求单修改数据
     * @param string $aacc
     * @param string $dkey
     * @param string $okey
     * @param int $rnum
     * @param int $scost
     * @throws
     */
    public function saveDemand(string $aacc, string $dkey, string $okey, int $rnum, int $scost)
    {
        //出价不能为0
        if ($scost == 0)
        {
            throw new AppException('出价不能为0');
        }

        //判断修改价格是否超过分配数量
        $snum = PurOdrDemandModel::M()->getRow(['dkey' => $dkey, 'okey' => $okey, 'aacc' => $aacc], 'rnum');
        $purOdrDemand = PurOdrDemandModel::M()->getRow(['dkey' => $dkey, 'okey' => $okey, 'aacc' => $aacc], 'sum(rnum) as num,unum,tid,cstat');
        if (!$purOdrDemand)
        {
            throw new AppException('需求单不存在');
        }

        $num = $purOdrDemand['num'] - $snum['rnum'] + $rnum;
        if ($num > $purOdrDemand['unum'])
        {
            throw new AppException('数量不能超过分配值');
        }
        $tcost = $rnum * $scost;
        $data = [
            'rnum' => $rnum,
            'scost' => $scost,
            'tcost' => $tcost,
            'cstat' => 1
        ];

        //更新需求单、任务表、需求表、采购单
        $purOdrOrder = PurOdrOrderModel::M()->getRowById($okey, 'fnum');
        if ($purOdrDemand['cstat'] == 3)
        {
            $data = [
                'fnum' => $purOdrOrder['fnum'] - 1,
                'cstat' => 1,
                'ltime' => time()
            ];
            PurOdrOrderModel::M()->update(['okey' => $okey], $data);
        }
        PurOdrDemandModel::M()->update(['aacc' => $aacc, 'okey' => $okey, 'dkey' => $dkey], $data);
        PurTaskModel::M()->updateById($purOdrDemand['tid'], ['rnum' => $num]);
        $num = PurOdrDemandModel::M()->getRow(['dkey' => $dkey], 'sum(rnum) as rnum');
        PurDemandModel::M()->updateById($dkey, ['rnum' => $num['rnum']]);

        //组装小红点数据（首页显示 - 采购单）
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1302, 'uid' => $aacc,]);

        //组装小红点数据（首页显示 - 需求单）
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1301, 'uid' => $aacc,]);

        $dotsAccs = CrmStaffModel::M()->join(AccUserModel::M(), ['acc' => 'aid'])->getList(['B.permis' => ['like' => '%sale_backend_pur0003%']], 'aid', [], 20);
        foreach ($dotsAccs as $dotAcc)
        {
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1502, 'uid' => $dotAcc['aid']]);
        }
    }
}

