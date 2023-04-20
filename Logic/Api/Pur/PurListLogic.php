<?php


namespace App\Module\Sale\Logic\Api\Pur;

use App\Model\Crm\CrmMessageDotModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 用户采购单逻辑
 * Class PurListLogic
 * @package App\Module\Api\Pur
 */
class PurListLogic extends BeanCollector
{
    /**
     * 获取采购单列表
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
        $uid = $query['uid'];

        //获取所需字段
        $cols = 'okey,ostat,ltime,cstat,snum,merchant,atime,tnum';

        //获取采购单列表数据
        $purOrderist = PurOdrOrderModel::M()->getList($where, $cols, ['ltime' => -1], $size, $idx);
        if (empty($purOrderist))
        {
            return [];
        }
        $okeys = ArrayHelper::map($purOrderist, 'okey');

        //获取供应商信息
        $merchant = ArrayHelper::map($purOrderist, 'merchant');
        $merchantDict = PurMerchantModel::M()->getDict('mid', ['mid' => ['in' => $merchant]], 'mname');
        $where = [
            'okey' => ['in' => $okeys],
            'aacc' => $uid,
            '$group' => ['okey']
        ];

        //获取采购单对应的需求单信息
        $cols = 'dkey,sum(rnum) as rnum,sum(snum) as snum,sum(mnum) as mnum,sum(pnum) as pnum,sum(dstat=2) as totalNum';
        $demandDict = PurOdrDemandModel::M()->getDict('okey', $where, $cols);

        //获取采购单商品数量
        $cols = 'okey,count(*) as count,sum(gstat=3) as waitNum,sum(gstat=5) as allReturnNum,sum(prdstat=3) as returnedNum';
        $stcDict = PurOdrGoodsModel::M()->getDict('okey', $where, $cols);

        //查询条件
        $where = [
            'bid' => ['in' => $okeys],
            'uid' => $uid,
            'dtype' => 14,
            'plat' => 24,
        ];

        //查询所有更新数据
        $messageList = CrmMessageDotModel::M()->getList($where, 'bid,src');
        $messageData = [];
        foreach ($messageList as $value)
        {
            $messageData[$value['bid']][] = $value['src'];
        }

        //补充数据
        foreach ($purOrderist as $key2 => $value2)
        {
            $rnum = $demandDict[$value2['okey']]['rnum'] ?? '-';
            $pnum = $demandDict[$value2['okey']]['pnum'] ?? '-';
            $snum = $demandDict[$value2['okey']]['snum'] ?? '-';
            $mnum = $demandDict[$value2['okey']]['mnum'] ?? '-';
            $stcNum = $stcDict[$value2['okey']]['count'] ?? '-';
            $waitNum = $stcDict[$value2['okey']]['waitNum'] ?? '-';
            $allReturnNum = $stcDict[$value2['okey']]['allReturnNum'] ?? '0';
            $returnedNum = $stcDict[$value2['okey']]['returnedNum'] ?? '0';

            //初始化状态
            $purOrderist[$key2]['stat'] = [
                'examineStat' => 0,
                'checkedStat' => 0,
                'finishedStat' => 0,
                'waitStat' => 0,
                'waitGoodsStat' => 0,
                'stcStat' => 0,
                'instcStat' => 0,
                'returnedStat' => 0,
            ];

            //提交数量
            $purOrderist[$key2]['subNum'] = $rnum;

            //已完成数量
            $purOrderist[$key2]['finishedNum'] = $pnum;

            //入库数量
            $purOrderist[$key2]['instcNum'] = $snum;

            //已质检数量
            $purOrderist[$key2]['checkedNum'] = $mnum;

            //预入库数量
            $purOrderist[$key2]['stcNum'] = $stcNum;

            //质检待确认
            $purOrderist[$key2]['waitNum'] = $waitNum;
            if ($waitNum == 0)
            {
                //删除小红点数据
                CrmMessageDotModel::M()->delete(['plat' => 24, 'src' => 1404, 'uid' => $uid, 'bid' => $value2['okey']]);
            }

            //已退货
            $purOrderist[$key2]['returnedNum'] = $returnedNum;

            //待退货
            $waitGoodsNum = $allReturnNum - $returnedNum;
            $purOrderist[$key2]['waitGoodsNum'] = $waitGoodsNum;
            if ($waitGoodsNum == 0)
            {
                //删除小红点数据
                CrmMessageDotModel::M()->delete(['plat' => 24, 'src' => 1405, 'uid' => $uid, 'bid' => $value2['okey']]);
            }

            //获取数据更新状态
            if (isset($messageData[$value2['okey']]))
            {
                foreach ($messageData[$value2['okey']] as $value3)
                {
                    switch ($value3)
                    {
                        case 1401:
                            $purOrderist[$key2]['stat']['examineStat'] = 1; //审核
                            break;
                        case 1402:
                            $purOrderist[$key2]['stat']['checkedStat'] = 1; //已质检
                            break;
                        case 1403:
                            $purOrderist[$key2]['stat']['finishedStat'] = 1; //已完成
                            break;
                        case 1404:
                            $purOrderist[$key2]['stat']['waitStat'] = 1; //质检待确认
                            break;
                        case 1405:
                            $purOrderist[$key2]['stat']['waitGoodsStat'] = 1; //待退货
                            break;
                        case 1406:
                            $purOrderist[$key2]['stat']['stcStat'] = 1; //预入库
                            break;
                        case 1407:
                            $purOrderist[$key2]['stat']['instcStat'] = 1; //入库
                            break;
                        case 1408:
                            $purOrderist[$key2]['stat']['returnedStat'] = 1; //已退货
                            break;
                    }
                }
            }
            $purOrderist[$key2]['merchant'] = $merchantDict[$value2['merchant']]['mname'];
            $purOrderist[$key2]['ostatName'] = PurDictData::PUR_ORDER_OSTAT[$value2['ostat']];
            $purOrderist[$key2]['cstatName'] = PurDictData::PUR_CSTAT[$value2['cstat']];
            $purOrderist[$key2]['ltime'] = DateHelper::toString($value2['ltime']);
            $purOrderist[$key2]['atime'] = DateHelper::toString($value2['atime']);
        }
        ArrayHelper::fillDefaultValue($purOrderist, [0, '0']);

        //删除小红点数据
        CrmMessageDotModel::M()->delete(['plat' => 24, 'src' => '1302', 'dtype' => 13, 'uid' => $query['uid']]);

        //返回
        return $purOrderist;
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
            'aacc' => $query['uid']
        ];

        //查询交集okey数据
        $intersectOkeys = [];

        //采购单号
        if ($query['okey'])
        {
            $intersectOkeys[] = [$query['okey']];
        }

        //供应商
        if ($query['merchant'])
        {
            $where['merchant'] = $query['merchant'];
        }

        //采购状态
        if ($query['ostat'])
        {
            $where['ostat'] = $query['ostat'];
        }

        //库存编码
        if ($query['bcode'])
        {
            $okey = PurOdrGoodsModel::M()->getOne(['bcode' => $query['bcode'], 'aacc' => $query['uid']], 'okey');
            if (!$okey)
            {
                $where['okey'] = -1;
            }
            else
            {
                $intersectOkeys[] = [$okey];
            }
        }

        //需求单号
        if ($query['dkey'])
        {
            $odrDemands = PurOdrDemandModel::M()->getList(['dkey' => $query['dkey'], 'aacc' => $query['uid']], 'okey');
            if (!$odrDemands)
            {
                $where['okey'] = -1;
            }
            else
            {
                $intersectOkeys[] = array_column($odrDemands, 'okey');
            }
        }

        //品牌
        $dWhere = [];
        if ($query['bid'])
        {
            $dWhere['bid'] = $query['bid'];
        }
        if ($query['mid'])
        {
            $dWhere['mid'] = $query['mid'];
        }
        if ($dWhere)
        {
            $purDkeys = PurDemandModel::M()->getList($dWhere, 'dkey');
            if (!$purDkeys)
            {
                $where['okey'] = -1;
            }
            else
            {
                $dkeys = ArrayHelper::map($purDkeys, 'dkey');
                $odrDemands = PurOdrDemandModel::M()->getList(['dkey' => ['in' => $dkeys], 'aacc' => $query['uid']], 'okey');
                if (!$odrDemands)
                {
                    $where['okey'] = -1;
                }
                else
                {
                    $intersectOkeys[] = array_column($odrDemands, 'okey');
                }
            }
        }

        //计算以上筛选条件获取到的采购单号合集
        $newOkeys = [];
        if (count($intersectOkeys) > 0)
        {
            foreach ($intersectOkeys as $key => $value)
            {
                if ($key == 0)
                {
                    $newOkeys = $value;
                }
                else
                {
                    $newOkeys = array_intersect($newOkeys, $value);
                }
            }
            if (empty($newOkeys))
            {
                $where['okey'] = -1;
            }
            else
            {
                $where['okey'] = ['in' => $newOkeys];
            }
        }

        //返回
        return $where;
    }
}