<?php
namespace App\Module\Sale\Logic\Api\Pur;

use App\Model\Crm\CrmMessageDotModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcInoutSheetModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class PurReturnLopgic extends BeanCollector
{

    /**
     * @param array $query
     * @param int $idx
     * @param int $size
     * @return array
     */
    public function getPager(array $query, int $idx, int $size)
    {
        $where = $this->getPagerWhere($query);

        //获取商品已数据
        $purOdrGoodsList = PurOdrGoodsModel::M()->getList($where, 'rtnskey,count(1) as num,merchant,prdstat', ['rtnskey' => -1], $size, $idx);
        if ($purOdrGoodsList)
        {
            //供货商字典
            $merchants = ArrayHelper::map($purOdrGoodsList, 'merchant');

            //退货单号字典
            $rtnskeys = ArrayHelper::map($purOdrGoodsList, 'rtnskey');

            //获取供货商
            $purMerchantDict = PurMerchantModel::M()->getDict('mid', ['mid' => ['in' => $merchants]], 'mname');

            //获取退货单信息
            $stcInoutSheetDict = StcInoutSheetModel::M()->getDict('skey', ['skey' => $rtnskeys], 'ftime,atime');

            //补充数据
            foreach ($purOdrGoodsList as $key => $value)
            {
                if (!$value['rtnskey'])
                {
                    unset($purOdrGoodsList[$key]);
                    continue;
                }
                $merchant = $value['merchant'];
                $rtnskey = $value['rtnskey'];
                $prdstat = $value['prdstat'] == 1 ? 1 : ($value['prdstat'] == 3 ? 2 : 0);
                $mtime = $prdstat == 1 ? $stcInoutSheetDict[$rtnskey]['atime'] : $stcInoutSheetDict[$rtnskey]['ftime'];
                $purOdrGoodsList[$key]['merchant'] = $purMerchantDict[$merchant]['mname'] ?? '-';
                $purOdrGoodsList[$key]['mtime'] = DateHelper::toString($mtime);
                $purOdrGoodsList[$key]['stat'] = PurDictData::PUR_RTN_STAT[$prdstat] ?? '-';
            }
        }

        //返回
        return $purOdrGoodsList;
    }

    /**
     * 退货单对应商品列表（分页获取数据）
     * @param array $query
     * @param int $idx
     * @param int $size
     * @return array
     */
    public function getGoodsList(array $query, int $idx, int $size)
    {
        //固定参数
        $where = ['aacc' => $query['acc'], 'gstat' => 5];

        //退货单号
        if ($query['skey'])
        {
            $where['rtnskey'] = $query['skey'];
        }

        //采购单号
        if ($query['okey'])
        {
            $where['okey'] = $query['okey'];
        }

        //状态
        if ($query['stat'])
        {
            $stat = $query['stat'];
            $where['prdstat'] = $stat == 1 ? 1 : ($stat == 2 ? 3 : 0);
        }

        //获取商品已数据
        $purOdrGoodsList = PurOdrGoodsModel::M()->getList($where, 'rtnskey,dkey,bcode,merchant,prdstat', [], $size, $idx);

        //获取dkey字典
        $deys = ArrayHelper::map($purOdrGoodsList, 'dkey', '-1');

        //采购计划-需求数据
        $purDemandDict = PurDemandModel::M()->getDict('dkey', ['dkey' => ['in' => $deys]], 'mid');

        //获取mid字典
        $mids = ArrayHelper::map($purDemandDict, 'mid', '-1');

        //获取机型数据
        $qtoModelDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

        //退货单号字典
        $rtnskeys = ArrayHelper::map($purOdrGoodsList, 'rtnskey', '-1');

        //获取退货单信息
        $stcInoutSheetDict = StcInoutSheetModel::M()->getDict('skey', ['skey' => ['in' => $rtnskeys]], 'ftime,atime');

        //补充数据
        foreach ($purOdrGoodsList as $key => $value)
        {
            $dkey = $value['dkey'];
            $rtnskey = $value['rtnskey'];
            $prdstat = $value['prdstat'] == 1 ? 1 : ($value['prdstat'] == 3 ? 2 : 0);
            $mtime = $prdstat == 1 ? $stcInoutSheetDict[$rtnskey]['atime'] : ($prdstat == 2 ? $stcInoutSheetDict[$rtnskey]['ftime'] : 0);
            $mid = $purDemandDict[$dkey]['mid'] ?? '-';
            $purOdrGoodsList[$key]['midName'] = $qtoModelDict[$mid]['mname'] ?? '-';
            $purOdrGoodsList[$key]['mtime'] = DateHelper::toString($mtime);
        }

        //获取其中一条数据
        $purOdrFirst = $purOdrGoodsList[0];

        //获取供货商
        $merchant = PurMerchantModel::M()->getOneById($purOdrFirst['merchant'], 'mname') ?: '-';

        //获取当前状态
        $prdstat = $purOdrFirst['prdstat'] == 1 ? 1 : ($purOdrFirst['prdstat'] == 3 ? 2 : 0);

        //返回
        return [
            'merchant' => $merchant,
            'num' => count($purOdrGoodsList),
            'stime' => $purOdrFirst['mtime'],
            'stat' => PurDictData::PUR_RTN_STAT[$prdstat] ?? '-',
            'goods' => $purOdrGoodsList,
        ];
    }

    /**
     * 查询条件
     * @param array $query
     * @return array
     */
    public function getPagerWhere(array $query)
    {
        //查询固定条件
        $where = [
            'aacc' => $query['acc'],
            'gstat' => 5,
            '$group' => 'rtnskey'
        ];

        //固定小红点条件
        $hotsWhere = [
            'uid' => $query['acc'],
            'plat' => 24
        ];

        //采购单号
        if ($query['okey'])
        {
            $where['okey'] = $query['okey'];
            $hotsWhere['bid'] = $query['okey'];
            $hotsWhere['dtype'] = 14;
        }

        //供货商
        if ($query['merchant'])
        {
            $where['merchant'] = $query['merchant'];
        }

        //库存编码
        if ($query['bcode'])
        {
            $where['bcode'] = $query['bcode'];
        }

        //退货单号
        if ($query['skey'])
        {
            $where['rtnskey'] = $query['skey'];
        }

        //状态
        if ($query['stat'])
        {
            //待退货
            if ($query['stat'] == 1)
            {
                $where['prdstat'] = 1;
                $hotsWhere['src'] = 1405;
            }

            //已退货
            if ($query['stat'] == 2)
            {
                $where['prdstat'] = 3;
                $hotsWhere['src'] = 1408;
            }
        }

        //日期
        if ($query['mtime'])
        {
            $stime = strtotime($query['mtime'][0] . ' 00:00:00');
            $etime = strtotime($query['mtime'][1] . ' 23:59:59');
            $where['gtime5'] = ['between' => [$stime, $etime,]];
        }

        //删除小红点数据
        CrmMessageDotModel::M()->delete($hotsWhere);

        //返回
        return $where;
    }
}