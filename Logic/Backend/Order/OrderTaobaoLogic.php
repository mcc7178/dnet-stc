<?php
namespace App\Module\Sale\Logic\Backend\Order;

use App\Exception\AppException;
use App\Lib\Express\Sf;
use App\Model\Acc\AccUserModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use App\Model\Stc\StcLogisticsModel;
use App\Model\Sys\SysRegionModel;
use App\Model\Xye\XyeSaleGoodsModel;
use App\Model\Xye\XyeSaleOrderModel;
use App\Model\Xye\XyeSaleWaterModel;
use App\Model\Xye\XyeTaobaoShopModel;
use App\Module\Pub\Data\SysWarehouseData;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 淘宝订单数据接口（废铁战士的时光店铺）
 * @package App\Module\Sale\Logic\Backend\Order
 */
class OrderTaobaoLogic extends BeanCollector
{
    /**
     * 翻页数据
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        // 数据条件
        $where = $this->getPagerWhere($query);

        // 获取数据
        $cols = 'A.okey,A.qty,A.rmk1,A.rmk2,A.recver,A.rectel,A.recreg,A.otime,A.paytime,A.ostat,B.tradeid,B.status,B.tbshop,B.odrtime,B.paytime,B.dlytime,B.saletime';
        $list = OdrOrderModel::M()->join(XyeSaleOrderModel::M(), ['third' => 'tradeid'])
            ->getList($where, $cols, ['B.odrtime' => -1], $size, $idx);
        if (!$list)
        {
            return [];
        }

        // 淘宝订单状态字典
        $tradeids = ArrayHelper::map($list, 'tradeid');
        // 淘宝商品属性
        $propertiesDict = XyeSaleGoodsModel::M()->getDicts('tradeid', ['tradeid' => ['in' => $tradeids]], 'properties');

        // 淘宝店铺名字典
        $tbshops = ArrayHelper::map($list, 'tbshop');
        $tbshopsDict = XyeTaobaoShopModel::M()->getDict('shop', ['shop' => ['in' => $tbshops]], 'shop,shopname');

        // 省区字典
        $recregs = ArrayHelper::map($list, 'recreg');
        $province = [];
        foreach ($recregs as $addr)
        {
            $province[] = intval(substr($addr, 0, 2) . '0000');
        }
        $provinceDict = SysRegionModel::M()->getDict('rid', ['rid' => ['in' => $province]], 'rname');

        // 商品金额、实付金额
        $amtDict = XyeSaleGoodsModel::M()->getDict('tradeid', ['tradeid' => ['in' => $tradeids], '$group' => 'tradeid'], 'sum(odramt) as odramt,sum(payamt) as payamt');

        // 组装数据
        foreach ($list as $key => $value)
        {

            $list[$key]['ostat'] = SaleDictData::ODR_TAOBAO_OSTAT[$value['ostat']];
            $list[$key]['status'] = in_array($value['status'], [-1, 11, 100]) ? '-' : SaleDictData::TAOBAO_STATUS[$value['status']];
            $list[$key]['tbshop'] = $value['tbshop'] ? $tbshopsDict[$value['tbshop']]['shopname'] : '-';
            $list[$key]['odrtime'] = substr(date('Y-m-d H:i:s', $value['odrtime']), 0, -3) ?? '-';
            $list[$key]['paytime'] = DateHelper::toString($value['paytime']) ?? '-';

            // 待配货、待发货无发货、签收时间
            if (in_array($value['ostat'], [20, 21]))
            {
                $list[$key]['dlytime'] = '-';
                $list[$key]['saletime'] = '-';
            }
            else
            {
                $list[$key]['dlytime'] = DateHelper::toString($value['dlytime']) ?? '-';
                $list[$key]['saletime'] = DateHelper::toString($value['saletime']) ?? '-';
            }

            // 订单列表属性
            $properties = [];
            if ($value['tradeid'])
            {
                foreach($propertiesDict[$value['tradeid']] as $v)
                {
                    $properties[] = $v['properties'];
                }
            }
            else
            {
                $properties[] = '-';
            }
            $list[$key]['properties'] = $properties;

            $recreg = intval(substr($value['recreg'], 0, 2) . '0000');
            $list[$key]['province'] = $provinceDict[$recreg]['rname'] ?? '-';
            $list[$key]['odramt'] = $value['tradeid'] ? $amtDict[$value['tradeid']]['odramt'] : '-';
            $list[$key]['payamt'] = $value['tradeid'] ? $amtDict[$value['tradeid']]['payamt'] : '-';

            $list[$key]['rmk1'] = $value['rmk1'] ?: '-';
            $list[$key]['rmk2'] = $value['rmk2'] ?: '-';

            // 隐藏电话号码中间几位
            $list[$key]['rectel'] = $value['rectel'] ? substr_replace($value['rectel'], '****', 3, 4) : '-';
        }

        foreach ($list as $val)
        {
            if (in_array($val['status'], [31, 32]))
            {
                $val['dlytime'] = '-';
                $val['saletime'] = '-';
            }
            elseif ($val['status'] == 33)
            {
                $val['saletime'] = '-';
            }
        }

        // 返回数据
        return $list;
    }

    /**
     * 总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        // 查询条件
        $where = $this->getPagerWhere($query);

        // 获取数据
        $count = OdrOrderModel::M()->join(XyeSaleOrderModel::M(), ['third' => 'tradeid'])->getCount($where);

        // 返回
        return $count;
    }

    /**
     * 导出
     * @param array $query
     * @return array
     */
    public function export(array $query)
    {
        // 设置表头
        $head = [
            'okey' => '内部订单号',
            'tradeid' => '外部订单号',
            'tbshop' => '店铺名',
            'properties' => '属性',
            'qty' => '商品数量',
            'ostat' => '发货状态',
            'status' => '淘宝状态',
            'rmk1' => '订单备注',
            'rmk2' => '内部备注',
            'recver' => '收件人',
            'rectel' => '电话',
            'province' => '省/自治区/直辖市',
            'odramt' => '商品金额',
            'payamt' => '实付金额',
            'odrtime' => '创建时间',
            'paytime' => '付款时间',
            'dlytime' => '发货时间',
            'saletime' => '确认收货时间'
        ];

        // 数据条件
        $where = $this->getPagerWhere($query);

        // 获取数据
        $cols = 'A.okey,A.qty,A.rmk1,A.rmk2,A.recver,A.rectel,A.recreg,A.otime,A.paytime,A.ostat,B.tradeid,B.status,B.tbshop,B.odrtime,B.paytime,B.dlytime,B.saletime';
        $list = OdrOrderModel::M()->join(XyeSaleOrderModel::M(), ['third' => 'tradeid'])
            ->getList($where, $cols, ['B.odrtime' => -1]);
        if (empty($list))
        {
            return [
                'head' => $head,
                'list' => []
            ];
        }

        // 淘宝订单状态字典
        $tradeids = ArrayHelper::map($list, 'tradeid');
        // 淘宝商品属性
        $propertiesDict = XyeSaleGoodsModel::M()->getDicts('tradeid', ['tradeid' => ['in' => $tradeids]], 'properties');

        // 淘宝店铺名字典
        $tbshops = ArrayHelper::map($list, 'tbshop');
        $tbshopsDict = XyeTaobaoShopModel::M()->getDict('shop', ['shop' => ['in' => $tbshops]], 'shop,shopname');

        // 省区字典
        $recregs = ArrayHelper::map($list, 'recreg');
        $province = [];
        foreach ($recregs as $addr)
        {
            $province[] = intval(substr($addr, 0, 2) . '0000');
        }
        $provinceDict = SysRegionModel::M()->getDict('rid', ['rid' => ['in' => $province]], 'rname');

        // 商品金额、实付金额
        $amtDict = XyeSaleGoodsModel::M()->getDict('tradeid', ['tradeid' => ['in' => $tradeids], '$group' => 'tradeid'], 'sum(odramt) as odramt,sum(payamt) as payamt');

        // 组装数据
        foreach ($list as $key => $value)
        {

            $list[$key]['ostat'] = SaleDictData::ODR_TAOBAO_OSTAT[$value['ostat']];
            $list[$key]['status'] = in_array($value['status'], [-1, 11, 100]) ? '-' : SaleDictData::TAOBAO_STATUS[$value['status']];
            $list[$key]['tbshop'] = $value['tbshop'] ? $tbshopsDict[$value['tbshop']]['shopname'] : '-';
            $list[$key]['odrtime'] = substr(date('Y-m-d H:i:s', $value['odrtime']), 0, -3) ?? '-';
            $list[$key]['paytime'] = DateHelper::toString($value['paytime']) ?? '-';

            // 待配货、待发货无发货、签收时间
            if (in_array($value['ostat'], [20, 21]))
            {
                $list[$key]['dlytime'] = '-';
                $list[$key]['saletime'] = '-';
            }
            else
            {
                $list[$key]['dlytime'] = DateHelper::toString($value['dlytime']) ?? '-';
                $list[$key]['saletime'] = DateHelper::toString($value['saletime']) ?? '-';
            }

            // 订单列表属性
            $properties = [];
            if ($value['tradeid'])
            {
                foreach($propertiesDict[$value['tradeid']] as $v)
                {
                    $properties[] = $v['properties'];
                }
            }
            else
            {
                $properties[] = '-';
            }
            $list[$key]['properties'] = $properties;

            $list[$key]['properties'] = $properties;
            $recreg = intval(substr($value['recreg'], 0, 2) . '0000');
            $list[$key]['province'] = $provinceDict[$recreg]['rname'] ?? '-';
            $list[$key]['odramt'] = $value['tradeid'] ? $amtDict[$value['tradeid']]['odramt'] : '-';
            $list[$key]['payamt'] = $value['tradeid'] ? $amtDict[$value['tradeid']]['odramt'] : '-';

            $list[$key]['rmk1'] = $value['rmk1'] ?: '-';
            $list[$key]['rmk2'] = $value['rmk2'] ?: '-';
        }

        foreach ($list as $val)
        {
            if (in_array($val['status'], [31, 32]))
            {
                $val['dlytime'] = '-';
                $val['saletime'] = '-';
            }
            elseif ($val['status'] == 33)
            {
                $val['saletime'] = '-';
            }
        }

        // 返回
        return [
            'head' => $head,
            'list' => $list
        ];
    }

    /**
     * 翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //B2C订单-淘宝订单
        $where['A.tid'] = 34;

        // 库存编号
        if (!empty($query['bcode']))
        {
            $tradeidData = XyeSaleGoodsModel::M()->getList(['$or' => [['bcode' => $query['bcode']], ['outerid' => $query['bcode']]]], 'tradeid');
            if (!$tradeidData)
            {
                $where['B.tradeid'] = -1;
            }
            else
            {
                $tradeids = ArrayHelper::map($tradeidData, 'tradeid');
                $where['B.tradeid'] = ['in' => $tradeids];
            }
        }

        // 订单编号
        if (!empty($query['okey']))
        {
            $where['$or'] = [
                ['A.okey' => $query['okey']],
                ['B.tradeid' => $query['okey']],
            ];
        }

        // 发货状态
        if ($query['ostat'] == 0)
        {
            $where['A.ostat'] = ['in' => [20, 21, 22, 23, 51]];
        }
        if (!empty($query['ostat']))
        {
            $where['A.ostat'] = $query['ostat'];
        }

        // 淘宝状态
        if (!empty($query['status']))
        {
            if ($query['status'] == 32)
            {
                $where['B.status'] = ['in' => [32, 33]];
            }
            else
            {
                $where['B.status'] = $query['status'];
            }
        }

        // 时间类型和时间范围
        if (!empty($query['ttype']))
        {
            $otime = $query['otime'];
            if (count($otime) == 2)
            {
                $stime = strtotime($otime[0]);
                $etime = strtotime($otime[1]) + 86399;
                switch ($query['ttype'])
                {
                    case 1:
                        $where['B.odrtime'] = ['between' => [$stime, $etime]];
                        break;
                    case 2:
                        $where['B.paytime'] = ['between' => [$stime, $etime]];
                        break;
                    case 3:
                        $where['B.dlytime'] = ['between' => [$stime, $etime]];
                        break;
                    case 4:
                        $where['B.saletime'] = ['between' => [$stime, $etime]];
                        break;
                }
            }
        }

        // 返回
        return $where;
    }

    /**
     * 订单详情数据
     * @param string $tradeid
     * @return array
     * @throws
     */
    public function getInfo(string $tradeid)
    {
        //获取订单信息
        $orderCols = 'okey,ostat,paytime,dlytime3,dlytime5,payamt,rmk1,rmk2,third';
        $order = OdrOrderModel::M()->getRow(['third' => $tradeid], $orderCols);
        if (!$order)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }
        $saleOrderCols = 'tbshop,status,buyer,odrtime,recver,rectel,recreg,recdtl';
        $saleOrder = XyeSaleOrderModel::M()::M()->getRow(['tradeid' => $tradeid], $saleOrderCols);
        if (!$saleOrder)
        {
            throw new AppException('淘宝订单数据不存在', AppException::NO_DATA);
        }

        //获取订单商品信息
        $saleGoodsCols = 'gid,pid,bcode,title,properties,refstat,odramt,disamt,outerid,promotion,itemid';
        $orderGoods = XyeSaleGoodsModel::M()->getList(['tradeid' => $tradeid], $saleGoodsCols);
        if ($orderGoods)
        {
            //获取商品信息
            $pids = ArrayHelper::map($orderGoods, 'pid', -1);
            $productDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'stcwhs,mid,mdofsale,mdram,mdcolor,mdnet');

            //机器基础配置
            $mdofsaleOids = array_column($productDict, 'mdofsale');
            $mdramOids = array_column($productDict, 'mdram');
            $mdcolorOids = array_column($productDict, 'mdcolor');
            $mdnetOids = array_column($productDict, 'mdnet');
            $oids = array_unique(array_merge($mdcolorOids, $mdramOids, $mdnetOids, $mdofsaleOids));
            $optionDict = [];
            if ($oids)
            {
                $optionDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $oids]], 'oname');
            }


            //获取分仓字典
            $whsDict = SysWarehouseData::D()->getDict();

            //获取机型字典
            $mids = ArrayHelper::map($productDict, 'mid', -1);
            $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

            //获取订单商品字典
            $gids = ArrayHelper::map($orderGoods, 'gid', -1);
            $goodsDict = OdrGoodsModel::M()->getDict('third', ['third' => ['in' => $gids]], 'dlystat,dlykey');

            //获取物流单号
            $lkeys = ArrayHelper::map($goodsDict, 'dlykey', -1);
            $stclogisticsDict = StcLogisticsModel::M()->getDict('lkey', ['lkey' => ['in' => $lkeys]], 'expno');

            foreach ($orderGoods as $key => $value)
            {
                $stcwhs = $productDict[$value['pid']]['stcwhs'] ?? '';
                $mid = $productDict[$value['pid']]['mid'] ?? '';
                $mdram = $productDict[$value['pid']]['mdram'] ?? '';
                $mdofsale = $productDict[$value['pid']]['mdofsale'] ?? '';
                $mdcolor = $productDict[$value['pid']]['mdcolor'] ?? '';
                $mdnet = $productDict[$value['pid']]['mdnet'] ?? '';
                $dstat = $goodsDict[$value['gid']]['dlystat'] ?? '';
                $dlykey = $goodsDict[$value['gid']]['dlykey'] ?? '-';
                $orderGoods[$key]['dstat'] = $dstat ?? '-';
                $orderGoods[$key]['dstatName'] = SaleDictData::ORDER_DSTAT[$dstat] ?? '-';
                $orderGoods[$key]['refstatName'] = SaleDictData::XYE_SALE_GOODS_REFSTAT[$value['refstat']] ?? '-';
                $orderGoods[$key]['dlykey'] = $dlykey;
                $orderGoods[$key]['bcode'] = $value['bcode'];
                $orderGoods[$key]['expno'] = '-';
                if (isset($dlykey) && !empty($stclogisticsDict[$dlykey]['expno']))
                {
                    $orderGoods[$key]['expno'] = $stclogisticsDict[$dlykey]['expno'];
                }
                $orderGoods[$key]['wname'] = $whsDict[$stcwhs] ?? '';
                $orderGoods[$key]['mname'] = $midDict[$mid]['mname'] ?? '';
                $orderGoods[$key]['mdofsale'] = $optionDict[$mdofsale]['oname'] ?? '';
                $orderGoods[$key]['mdram'] = $optionDict[$mdram]['oname'] ?? '';
                $orderGoods[$key]['mdcolor'] = $optionDict[$mdcolor]['oname'] ?? '';
                $orderGoods[$key]['mdnet'] = $optionDict[$mdnet]['oname'] ?? '';
                $orderGoods[$key]['merchantCode'] = $value['outerid'] ?: '-';
                $orderGoods[$key]['odramt'] = $value['odramt'] ?: '-';
                $orderGoods[$key]['title'] = $value['title'] ?: '-';
                $orderGoods[$key]['properties'] = $value['properties'] ?: '-';
                $orderGoods[$key]['distribution'] = '-';
                $orderGoods[$key]['discount'] = '-';

                //拼接配货库存
                if (in_array($dstat, [2, 3, 4]))
                {
                    $splicingCols = [
                        $orderGoods[$key]['mname'], $orderGoods[$key]['mdofsale'], $orderGoods[$key]['mdram'], $orderGoods[$key]['mdcolor'], $orderGoods[$key]['mdnet']
                    ];
                    $model = implode(' ', $splicingCols);
                    if ($orderGoods[$key]['wname'] != '' && $orderGoods[$key]['bcode'] != '' && $model != '')
                    {
                        $orderGoods[$key]['distribution'] = [
                            'whouse' => $orderGoods[$key]['wname'],
                            'bcode' => $orderGoods[$key]['bcode'],
                            'model' => $model
                        ];
                    }
                    else
                    {
                        $orderGoods[$key]['discount'] = '-';
                    }

                }

                //商品优惠
                if ($value['promotion'] && $value['disamt'])
                {
                    $orderGoods[$key]['discount'] = $value['promotion'] . ':' . $value['disamt'];
                }
            }
        }

        //补充数据
        $shopName = XyeTaobaoShopModel::M()->getOneById($saleOrder['tbshop'], 'shopname');
        $order['shopName'] = $shopName ?: '-';
        $order['ostatName'] = SaleDictData::ODR_TAOBAO_OSTAT[$order['ostat']] ?? '-';
        $order['atime'] = DateHelper::toString($saleOrder['odrtime'], 'Y-m-d H:i:s');
        $order['paytime'] = DateHelper::toString($order['paytime'], 'Y-m-d H:i:s');
        $order['dlytime3'] = DateHelper::toString($order['dlytime3'], 'Y-m-d H:i:s');
        $order['dlytime5'] = DateHelper::toString($order['dlytime5'], 'Y-m-d H:i:s');
        $order['statusName'] = SaleDictData::TAOBAO_STATUS[$saleOrder['status']] ?? '-';
        $order['status'] = $saleOrder['status'] ?? '-';
        $saleOrder['recdtl'] = str_replace('##', ' ', $saleOrder['recdtl']);
        $addressCols = [$saleOrder['recver'], $saleOrder['rectel'], $saleOrder['recdtl']];
        $order['address'] = implode(' ', $addressCols);
        $order['buyerName'] = $saleOrder['buyer'] ?? '-';
        $order['data'] = $orderGoods;

        //返回
        return $order;
    }

    /**
     * 获取物流信息
     * @param string $expno
     * @return array
     */
    public function getRoute(string $expno)
    {
        //获取用户手机号
        $mobile = StcLogisticsModel::M()->getOne(['expno' => $expno], 'rectel', [], '');
        try
        {
            $list = Sf::getRoute($expno, $mobile);
        }
        catch (\Throwable $exception)
        {
            $list = [];
        }

        //返回
        return $list;
    }

    /**
     * 获取操作流水
     * @param string $tradeid 淘宝交易编号
     * @return mixed
     * @throws
     */
    public function getWater(string $tradeid)
    {
        //检查参数
        if (empty($tradeid))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取流水信息
        $waterList = XyeSaleWaterModel::M()->getList(['tradeid' => $tradeid], '*', ['wtime' => -1]);
        if ($waterList)
        {
            $aids = ArrayHelper::map($waterList, 'wacc');
            $accDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $aids]], 'rname,uname');

            //补充数据
            foreach ($waterList as $key => $value)
            {
                $rname = $accDict[$value['wacc']]['rname'] ?? '';
                $uname = $accDict[$value['wacc']]['uname'] ?? '-';
                $waterList[$key]['aname'] = empty($rname) ? $uname : $rname;
                $waterList[$key]['atime'] = DateHelper::toString($value['wtime']);
                $waterList[$key]['type'] = SaleDictData::TAOBAO_WATER[$value['tid']];
                $waterList[$key]['rmk'] = empty($value['rmk']) ? '-' : $value['rmk'];
            }
        }

        //返回
        return $waterList;
    }
}