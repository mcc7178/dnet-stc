<?php
namespace App\Module\Sale\Logic\Backend\Order;

use App\Model\Crm\CrmOfferModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Module\Pub\Data\SysWarehouseData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use App\Module\Sale\Data\SaleDictData;

/**
 * 淘宝订单 - 配货列表
 * @package App\Module\Sale\Logic\Backend\Order
 */
class OrderTaobaoMatchLogic extends BeanCollector
{
    /**
     * 获取分页数据
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //获取查询条件
        $where = $this->getPagerWhere($query);

        //获取配货列表
        $cols = 'bcode,plat,optype,ptype,bid,mid,level,mdram,mdcolor,mdofsale,mdnet,stcstat,rectime4,stcwhs,supcost,prdcost,offer,prdstat,pid';
        $products = PrdProductModel::M()->getList($where, $cols, ['rectime4' => -1], $size, $idx);

        //获取品牌字典
        $bids = ArrayHelper::map($products, 'bid', -1);
        $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bid,bname');

        //获取级别字典
        $levels = ArrayHelper::map($products, 'level', -1);
        $levelDict = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lkey,lname');

        //获取机型字典
        $mids = ArrayHelper::map($products, 'mid', -1);
        $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mid,mname');

        //获取机型类目选项字典
        $optCols = ['mdofsale', 'mdnet', 'mdcolor', 'mdram'];
        $optOids = ArrayHelper::maps([$products, $products, $products, $products], $optCols, -1);
        $optionsDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $optOids]], 'oname');

        //获取供应商字典
        $offers = ArrayHelper::map($products, 'offer', -1);
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offers]], 'oname');

        //商品品类
        $ptypeData = [
            1 => '手机',
            2 => '平板',
            3 => '电脑',
            4 => '数码',
            5 => '配件',
        ];

        foreach ($products as $key => $value)
        {
            $products[$key]['plat'] = SaleDictData::SOURCE_PLAT[$value['plat']] ?? '-';
            if ($value['plat'] == 18)
            {
                $offerName = $offerDict[$value['offer']]['oname'] ?? '';
                $products[$key]['plat'] .= '-' . $offerName;
            }
            if ($value['ptype'] == 0)
            {
                $value['ptype'] = $value['optype'];
            }
            $products[$key]['typeName'] = $ptypeData[$value['ptype']] ?? '-';
            $products[$key]['bname'] = $bidDict[$value['bid']]['bname'] ?? '-';
            $products[$key]['mname'] = $midDict[$value['mid']]['mname'] ?? '-';
            $products[$key]['lname'] = $levelDict[$value['level']]['lname'] ?? '-';
            $products[$key]['mdram'] = $optionsDict[$value['mdram']]['oname'] ?? '-';
            $products[$key]['mdcolor'] = $optionsDict[$value['mdcolor']]['oname'] ?? '-';
            $products[$key]['mdofsale'] = $optionsDict[$value['mdofsale']]['oname'] ?? '-';
            $products[$key]['mdnet'] = $optionsDict[$value['mdnet']]['oname'] ?? '-';
            $products[$key]['stcstat'] = SaleDictData::PRD_STORAGE_STAT[$value['stcstat']] ?? '-';

            //在库天数 (最小单位 0.5)
            $intime = '-';
            if ($value['prdstat'] == 1 && $value['rectime4'] > 0)
            {
                $intime = (time() - $value['rectime4']) / 86400;
                $intDays = intval($intime);
                if ($intime > ($intDays + 0.5))
                {
                    $intime = $intDays + 0.5;
                }
                else
                {
                    $intime = $intDays;
                }
            }
            $products[$key]['intime'] = $intime;
            $products[$key]['stcwhs'] = SysWarehouseData::D()->getName($value['stcwhs']) ?? '-';

            //供应商回收成本展示，部分prdcost=0
            if ($value['plat'] == 18 && $value['prdcost'] == 0)
            {
                $value['prdcost'] = $value['supcost'];
            }

            $products[$key]['recoveryPrice'] = $value['prdcost'];
            $products[$key]['purPrice'] = $value['supcost'];
            if ($value['plat'] == 24)
            {
                //采购商品
                $products[$key]['recoveryPrice'] = '-';
            }
            else
            {
                //回收商品
                $products[$key]['purPrice'] = '-';
            }
        }

        //返回
        return $products;
    }

    /**
     * 获取数据总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //获取查询条件
        $where = $this->getPagerWhere($query);

        //获取总数
        $count = PrdProductModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 获取查询where条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //初始化
        $where = [
            'inway' => 51,
            'prdstat' => 1,
            'recstat' => 7,
            'stcstat' => ['in' => [11, 13]]
        ];

        //条件筛选
        $queryCols = ['ptype', 'bid', 'mid', 'level', 'mdram', 'mdcolor', 'mdofsale', 'mdnet', 'stcstat'];
        foreach ($queryCols as $col)
        {
            if ($query[$col] > 0)
            {
                $where[$col] = $query[$col];
            }
        }

        //库存编码搜索
        if ($query['bcode'])
        {
            $where['bcode'] = $query['bcode'];
        }

        //返回
        return $where;
    }
}