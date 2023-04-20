<?php

namespace App\Module\Sale\Logic\Store\Order;

use App\Exception\AppException;
use App\Model\Odr\OdrQuoteModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Odr\OdrPaymentModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Crm\Logic\CrmAddressLogic;
use App\Module\Pub\Data\SysWarehouseData;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Db\Db;
use Swork\Helper\DateHelper;
use Swork\Helper\ArrayHelper;
use App\Module\Sale\Data\SaleDictData;
use Throwable;

/**
 * 销售端订单处理
 * Class OrderLogic
 * @package App\Module\Sale\Logic\Store\Order
 */
class OrderLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * @Inject()
     * @var CrmAddressLogic
     */
    private $crmAddressLogic;

    /**
     * 获取商品管理列表
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     * @throws
     */
    public function getPager(array $query, int $size = 25, int $idx = 1)
    {
        //数据条件
        $whereOrderBy = $this->getPagerWhere($query);
        $where = $whereOrderBy['where'];
        $orderBy = $whereOrderBy['orderBy'];

        //获取所需字段
        $cols = 'oid,okey,plat,tid,ostat,buyer,qty,ocost1,scost11,oamt,oamt1,profit11,payamt,ostat,atime,paytime,recver,whs';

        //获取列表数据
        $odrOrder = OdrOrderModel::M()->getList($where, $cols, $orderBy, $size, $idx);

        if ($odrOrder)
        {
            //获取分仓字典
            $whsDict = SysWarehouseData::D()->getDict();

            //补充数据
            foreach ($odrOrder as $key => $value)
            {
                $wid = $value['whs'];
                $odrOrder[$key]['oid'] = $value['oid'];
                $odrOrder[$key]['okey'] = $value['okey'];
                $odrOrder[$key]['qty'] = !empty($value['qty']) ? $value['qty'] : '-';
                $odrOrder[$key]['ocost1'] = !empty($value['ocost1']) ? number_format($value['ocost1'], 2) : '-';
                $odrOrder[$key]['oamt'] = !empty($value['oamt']) ? number_format($value['oamt'], 2) : '-';
                $odrOrder[$key]['atime'] = DateHelper::toString($value['atime']);
                $odrOrder[$key]['paytime'] = DateHelper::toString($value['paytime']);
                $odrOrder[$key]['plat'] = SaleDictData::SOLD_PLAT[$value['plat']] ?? '-';
                $odrOrder[$key]['buyerName'] = $value['recver'] ?: '-';
                $odrOrder[$key]['wname'] = $whsDict[$wid] ?? '-';
                $odrOrder[$key]['tidName'] = SaleDictData::ORDER_TYPE[$value['tid']] ?? '-';
                $odrOrder[$key]['statName'] = SaleDictData::ORDER_OSTAT[$value['ostat']] ?? '-';
                if (!in_array($value['ostat'], [0, 10, 11, 12]))
                {
                    $odrOrder[$key]['payamt'] = !empty($value['payamt']) ? number_format($value['payamt'], 2, '.', '') : '-';
                    if ($value['oamt1'] != 0)
                    {
                        $profitMargin = $value['profit11'] / $value['oamt1'];
                        $odrOrder[$key]['profitMargin'] = round($profitMargin * 100, 2);
                    }
                    else
                    {
                        $odrOrder[$key]['profitMargin'] = 0;
                    }
                }
                else
                {
                    $odrOrder[$key]['payamt'] = '-';
                    $odrOrder[$key]['profitMargin'] = 0;
                }
            }
        }

        //返回
        return $odrOrder;
    }

    /**
     * 获取列表数量
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //数据条件
        $whereOrderBy = $this->getPagerWhere($query);
        $where = $whereOrderBy['where'];

        //返回
        return OdrOrderModel::M()->getCount($where);
    }

    /**
     * 侧边栏订单状态数量
     * @return array
     */
    public function getStatList()
    {
        //获取待确认订单数量
        $confirmedList = OdrOrderModel::M()->getCount(['src' => 23, 'plat' => 23, 'tid' => 21, 'ostat' => 10], 'oid');

        //获取代付款订单数量
        $paidList = OdrOrderModel::M()->getCount(['tid' => ['in' => [11, 21, 22]], 'ostat' => 11], 'oid');
        $data = [
            'confirmedList' => $confirmedList,
            'paidList' => $paidList,
        ];

        //返回数据
        return $data;
    }

    /**
     * 所有订单 导出
     * @param array $query
     * @return array
     * @throws
     */
    public function export(array $query)
    {
        //导出需要选取时间
        if ($query['time'] == [])
        {
            throw new AppException('防止数据量过大请选择指定下单日期导出', AppException::OUT_OF_TIME);
        }

        //时间不得超过7天
        if ($query['time'])
        {
            $date = $query['time'];
            if (count($date) == 2)
            {
                //一天中开始时间和结束时间
                $stime = strtotime($date[0] . ' 00:00:00');
                $etime = strtotime($date[1] . ' 23:59:59');

                if ($etime - $stime > 604800)
                {
                    throw new AppException('所选时间不得超过7天', AppException::OUT_OF_TIME);
                }
            }
        }

        //数据条件
        $whereOrderBy = $this->getPagerWhere($query);
        $where = $whereOrderBy['where'];
        $orderBy = $whereOrderBy['orderBy'];

        //获取所需字段
        $cols = 'oid,okey,tid,ostat,buyer,qty,ocost1,oamt,payamt,ostat,atime,paytime,recver,whs';

        //获取列表数据
        $list = OdrOrderModel::M()->getDict('okey', $where, $cols, $orderBy);
        $okeys = ArrayHelper::map($list, 'okey');

        //获取订单商品信息
        $goods = OdrGoodsModel::M()->join(PrdProductModel::M(), ['bcode' => 'bcode'])
            ->getList(['A.okey' => ['in' => $okeys]], 'A.bcode,A.scost1,A.bprc,A.okey,A.paytime,A.supcost,B.mid,B.bid,B.level', ['B.mid' => -1, 'A.bprc' => -1, 'A.scost1' => -1]);

        //获取商品信息
        $bid = array_column($goods, 'bid');
        $mid = array_column($goods, 'mid');
        $level = array_column($goods, 'level');

        //获取商品品牌，机型，级别
        $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bid]], 'bname');
        $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mid], 'plat' => 0], 'mname');
        $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $level]], 'lname');

        //获取分仓字典
        $whsDict = SysWarehouseData::D()->getDict();

        //补充数据
        foreach ($goods as $key => $value)
        {
            $wid = $list[$value['okey']]['whs'] ?? '-';
            $goods[$key]['idx'] = $key + 1;
            $goods[$key]['bname'] = $qtoBrand[$value['bid']]['bname'] ?? '-';
            $goods[$key]['mname'] = $qtoModel[$value['mid']]['mname'] ?? '-';
            $goods[$key]['lname'] = $qtoLevel[$value['level']]['lname'] ?? '-';
            $goods[$key]['paytime'] = DateHelper::toString($value['paytime']);
            $goods[$key]['scost1'] = floatval($value['scost1']) ?: $value['supcost'];
            $goods[$key]['buyerName'] = $list[$value['okey']]['recver'] ?? '-';
            $goods[$key]['wname'] = $whsDict[$wid] ?? '-';
        }

        //拼装excel数据
        $data['list'] = $goods;
        $data['header'] = [
            'idx' => '序号',
            'bcode' => '库存编号',
            'wname'  => '分仓',
            'okey' => '订单号',
            'bname' => '品牌',
            'mname' => '机型',
            'lname' => '级别',
            'scost1' => '成本价',
            'bprc' => '售价',
            'paytime' => '销售时间',
            'buyerName' => '客户名称',
        ];

        //返回数据
        return $data;
    }

    /**
     * 订单详情
     * @param string $oid
     * @return array
     * @throws
     */
    public function detail(string $oid)
    {
        //获取所需字段
        $clos = 'oid,okey,plat,tid,ostat,buyer,qty,atime,ocost1,oamt,payamt,profit11,profit21,recver,rectel,whs';

        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], $clos);
        if ($odrOrder == false)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }

        //订单利润
        $profit = $odrOrder['profit11'] + $odrOrder['profit21'];
        $odrOrderProfit = !empty($profit) ? number_format($profit, 2) : '-';

        //订单主数据
        $odrOrder['tidName'] = SaleDictData::ORDER_TYPE[$odrOrder['tid']] ?? '-';
        $odrOrder['statName'] = SaleDictData::ORDER_OSTAT[$odrOrder['ostat']] ?? '-';
        $odrOrder['buyerName'] = $odrOrder['recver'] ?: '-';
        $odrOrder['mobile'] = $odrOrder['rectel'] ?: '-';
        $odrOrder['profit'] = $odrOrderProfit;
        $odrOrder['atime'] = DateHelper::toString($odrOrder['atime']);
        $odrOrder['wname'] = SysWarehouseData::D()->getName($odrOrder['whs']) ?? '-';

        //获取商品数据
        $goods = OdrGoodsModel::M()->join(PrdProductModel::M(), ['bcode' => 'bcode'])
            ->getList(['A.okey' => $odrOrder['okey']], 'A.pid,A.bcode,A.scost1,A.bprc,A.profit1,A.issup,A.supcost,B.bid,B.mid,B.level,B.stcwhs', ['B.mid' => -1, 'A.bprc' => -1, 'A.scost1' => -1]);
        if ($goods)
        {
            $pids = ArrayHelper::map($goods, 'pid');
            $bid = ArrayHelper::map($goods, 'bid');
            $mid = ArrayHelper::map($goods, 'mid');
            $level = ArrayHelper::map($goods, 'level');

            //获取商品品牌，机型，级别
            $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bid]], 'bname');
            $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mid], 'plat' => 0], 'mname');
            $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $level]], 'lname');

            //获取质检备注
            $mqcReport = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => 21], 'bconc', ['atime' => -1]);

            //获取分仓字典
            $whsDict = SysWarehouseData::D()->getDict();

            //补充数据
            $list = [];
            foreach ($goods as $key => $value)
            {
                $profit = $value['profit1'];
                $bprc = $value['bprc'];
                $stcwhs = $value['stcwhs'];
                if ($value['issup'] == 1)
                {
                    $margin = '-';
                }
                else
                {
                    $margin = round(($profit / $bprc) * 100, 2) . '%';
                }
                $list[] = [
                    'buyerName' => $odrOrder['buyerName'],
                    'mobile' => $odrOrder['mobile'],
                    'wname' => $whsDict[$stcwhs] ?? '-',
                    'pid' => $value['pid'] ?? '-',
                    'bcode' => $value['bcode'] ?? '-',
                    'bname' => $qtoBrand[$value['bid']]['bname'] ?? '-',
                    'mname' => $qtoModel[$value['mid']]['mname'] ?? '-',
                    'lname' => $qtoLevel[$value['level']]['lname'] ?? '-',
                    'scost1' => floatval($value['scost1']) ?: $value['supcost'],
                    'bprc' => !empty($bprc) ? number_format($value['bprc'], 2, '.', '') : '-',
                    'profit1' => number_format($profit, 2),
                    'margin' => $margin,
                    'rmk' => $mqcReport[$value['pid']]['bconc'] ?? '',
                ];
            }
        }
        $odrOrder['data'] = $list ?? [];

        //返回
        return $odrOrder;
    }

    /**
     * 订单详情-待成交 不成交删除订单
     * @param string $oid
     * @throws
     */
    public function delete(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,plat');
        if ($odrOrder['plat'] != 23)
        {
            throw new AppException('订单销售平台不正确', AppException::WRONG_ARG);
        }

        //获取该订单行商品信息
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'pid');
        if ($odrGoods)
        {
            $odrGoodsPid = ArrayHelper::map($odrGoods, 'pid');

            try
            {
                //开始事务
                Db::beginTransaction();

                //恢复商品待销售状态
                PrdProductModel::M()->update(['pid' => ['in' => $odrGoodsPid]], ['stcstat' => 11, 'stctime' => time()]);

                //恢复仓库商品状态
                StcStorageModel::M()->update(['pid' => ['in' => $odrGoodsPid], 'twhs' => 105], ['prdstat' => 11]);

                //删除订单下的所有订单商品记录
                OdrGoodsModel::M()->delete(['okey' => $odrOrder['okey']]);

                //删除订单
                OdrOrderModel::M()->delete(['oid' => $oid]);

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
        else
        {
            //删除订单
            OdrOrderModel::M()->delete(['oid' => $oid]);
        }
    }

    /**
     * 同意成交更改订单状态
     * @param string $oid
     * @param string $recver
     * @param int $rectel
     * @throws
     */
    public function agree(string $oid, string $recver, int $rectel)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,ostat,plat,buyer');
        if ($odrOrder['ostat'] != 10)
        {
            throw new AppException('订单状态不正确', AppException::WRONG_ARG);
        }
        if ($odrOrder['plat'] != 23)
        {
            throw new AppException('订单销售平台不正确', AppException::WRONG_ARG);
        }
        $plat = $odrOrder['plat'];
        $uid = $odrOrder['buyer'];

        try
        {
            //开启事务
            Db::beginTransaction();

            //更新地址表的相关信息
            $this->crmAddressLogic->save($plat, $uid, [
                'way' => 2,
                'lnker' => $recver,
                'lnktel' => $rectel,
                'rgnid' => '440304',
                'rgndtl' => '华强北自提'
            ]);

            //修改订单状态
            OdrOrderModel::M()->update(['oid' => $oid], ['ostat' => 11, 'paystat' => 1, 'recver' => $recver, 'rectel' => $rectel, 'otime' => time()]);

            //修改商品关联的订单状态
            OdrGoodsModel::M()->update(['okey' => $odrOrder['okey']], ['ostat' => 11, 'otime' => time()]);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //事务回滚
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 订单详情-代付款 取消订单
     * @param string $oid
     * @throws
     */
    public function cancel(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'tid,okey,ostat,plat');
        if ($odrOrder['ostat'] != 11)
        {
            throw new AppException('当前订单状态不支持取消订单', AppException::WRONG_ARG);
        }
        if (!in_array($odrOrder['tid'], [21, 22]))
        {
            throw new AppException('当前订单类型不支持取消订单', AppException::WRONG_ARG);
        }
        if ($odrOrder['plat'] != 23)
        {
            throw new AppException('当前订单销售平台不支持取消订单', AppException::WRONG_ARG);
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'pid');
        $time = time();

        //更新订单的基本信息
        $orderData = [
            'buyer' => '',
            'recver' => '',
            'rectel' => '',
            'recreg' => 0,
            'recdtl' => '',
            'ostat' => 10,
            'paystat' => 0,
            'mtime' => $time,
        ];

        if ($odrGoods)
        {
            //获取订单商品pid
            $odrGoodsPid = ArrayHelper::map($odrGoods, 'pid');

            try
            {
                //开启事务
                Db::beginTransaction();

                //恢复商品状态
                PrdProductModel::M()->update(['pid' => ['in' => $odrGoodsPid]], ['stcstat' => 15, 'prdstat' => 1, 'stctime' => $time]);

                //恢复商品仓库状态
                StcStorageModel::M()->update(['pid' => ['in' => $odrGoodsPid], 'twhs' => 105], ['prdstat' => 15]);

                //恢复订单下的所有订单商品状态
                OdrGoodsModel::M()->update(['okey' => $odrOrder['okey']], ['ostat' => 10, 'mtime' => $time]);
                if ($odrOrder['tid'] == 22)
                {
                    //将订单恢复到待成交状态
                    OdrOrderModel::M()->update(['oid' => $oid], $orderData);

                    //恢复询价表状态
                    OdrQuoteModel::M()->update(['okey' => $odrOrder['okey'], 'stat' => 3], ['stat' => 2]);
                }
                else
                {
                    //将订单恢复到待成交状态
                    OdrOrderModel::M()->update(['oid' => $oid], ['ostat' => 10, 'paystat' => 0, 'mtime' => $time]);
                }

                //提交事务
                Db::commit();
            }
            catch (Throwable $throwable)
            {
                //事务回滚
                Db::rollback();

                //抛出异常
                throw $throwable;
            }
        }
        else
        {
            //将订单恢复到待成交状态
            OdrOrderModel::M()->update(['oid' => $oid], ['ostat' => 10, 'stcstat' => 11, 'paystat' => 0, 'mtime' => $time]);
        }
    }

    /**
     * 订单详情-代付款 已支付
     * @param string $oid
     * @throws
     */
    public function paid(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,ostat,plat');
        if ($odrOrder['ostat'] != 11)
        {
            throw new AppException('当前订单状态不支持取消订单', AppException::WRONG_ARG);
        }
        if ($odrOrder['plat'] != 23)
        {
            throw new AppException('当前订单销售平台不支持取消订单', AppException::WRONG_ARG);
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //修改订单状态
            OdrOrderModel::M()->update(['oid' => $oid], ['ostat' => 12, 'paystat' => 2]);

            //修改商品关联的订单状态
            OdrGoodsModel::M()->update(['okey' => $odrOrder['okey']], ['ostat' => 12]);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //事务回滚
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 用户确认支付（线下支付）
     * @param string $oid 订单ID
     * @return bool|mixed
     * @throws
     */
    public function offRemit(string $oid)
    {
        //获取订单数据
        $order = OdrOrderModel::M()->getRowById($oid, 'oid,plat,okey,buyer,qty,ostat,paystat,payamt');
        if (empty($order))
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }
        if ($order['ostat'] != 11)
        {
            throw new AppException('订单非待支付状态', AppException::OUT_OF_OPERATE);
        }
        if ($order['paystat'] > 1)
        {
            throw new AppException("订单【{$order['okey']}】已支付或待审核，请勿重复点击", AppException::DATA_DONE);
        }

        //支付订单相关数据
        $paych = 1;
        $payId = $this->uniqueKeyLogic->getUniversal();
        $tradeNo = $this->uniqueKeyLogic->getPayTradeNo($paych);
        $payprds = OdrGoodsModel::M()->getCount(['okey' => $order['okey'], 'ostat' => 11]);

        try
        {
            //开启事务
            Db::beginTransaction();

            //创建待审核支付订单
            OdrPaymentModel::M()->insert([
                'pid' => $payId,
                'plat' => $order['plat'],
                'tid' => 2,
                'buyer' => $order['buyer'],
                'paychn' => $paych,
                'paytype' => 13,
                'payamts' => $order['payamt'] ?: $order['oamt'],
                'payodrs' => 1,
                'payprds' => $payprds,
                'payokeys' => json_encode([$order['okey']]),
                'paystat' => 1,
                'tradeno' => $tradeNo,
                'atime' => time(),
                'chkstat' => 1,
                'stime' => time()
            ]);

            //更新订单状态（待审核）
            OdrOrderModel::M()->updateById($oid, [
                'ostat' => 12,
                'paytype' => 13,
                'paystat' => 2,
                'tradeno' => $tradeNo,
                'third' => $payId,
            ]);

            //更新订单商品状态（待审核）
            OdrGoodsModel::M()->update(['okey' => $order['okey']], ['ostat' => 12]);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚
            Db::rollback();

            //抛出异常
            throw $throwable;
        }

        //补充数据
        $payment['tradeno'] = $payId;
        $payment['ptype'] = 13;
        $payment['payamts'] = number_format($order['payamt'], 2);

        //返回
        return $payment;
    }

    /**
     * 订单详情-代付款 导出明细
     * @param string $oid
     * @return array
     * @throws AppException
     */
    public function derive(string $oid)
    {
        //数据条件
        $list = $this->detail($oid);
        $data = [];
        $info = [];
        foreach ($list['data'] as $key => $value)
        {
            $info[] = $value;
        }

        //拼装excel数据
        $data['list'] = $info;
        $data['header'] = [
            'buyerName' => '客户姓名',
            'mobile' => '联系方式',
            'bcode' => '库存编号',
            'wname' => '分仓',
            'mid' => '机型',
            'level' => '级别',
            'scost1' => '成本价',
            'bprc' => '客户出价',
            'rmk' => '质检备注',
        ];

        //返回数据
        return $data;
    }

    /**
     * 订单详情-待审核 取消订单
     * @param string $oid
     * @throws
     */
    public function remove(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRowById($oid, 'oid,plat,tid,okey,ostat,paystat,src,tid,third');
        if ($odrOrder == false)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }
        if ($odrOrder['ostat'] != 12 || $odrOrder['paystat'] != 2)
        {
            throw new AppException('订单非待审核状态，不允许操作', AppException::OUT_OF_OPERATE);
        }
        if ($odrOrder['plat'] != 23 || !in_array($odrOrder['tid'], [21, 22]))
        {
            throw new AppException('订单类型不正确，不允许操作', AppException::OUT_OF_OPERATE);
        }

        //获取该订单下商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'pid');
        if (empty($odrGoods))
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }
        $odrGoodsPid = ArrayHelper::map($odrGoods, 'pid');
        $time = time();

        //更新订单的基本信息
        $orderData = [
            'buyer' => '',
            'recver' => '',
            'rectel' => '',
            'recreg' => 0,
            'recdtl' => '',
            'ostat' => 10,
            'paystat' => 0,
            'mtime' => $time,
        ];

        try
        {
            //开启事务
            Db::beginTransaction();

            //恢复商品状态
            PrdProductModel::M()->update(['pid' => ['in' => $odrGoodsPid]], ['stcstat' => 15, 'prdstat' => 1, 'stctime' => $time]);

            //恢复商品仓库状态
            StcStorageModel::M()->update(['pid' => ['in' => $odrGoodsPid], 'twhs' => 105], ['prdstat' => 15]);

            //恢复订单下的所有订单商品状态
            OdrGoodsModel::M()->update(['okey' => $odrOrder['okey']], ['ostat' => 10, 'mtime' => $time]);
            if ($odrOrder['tid'] == 22)
            {
                //将订单恢复到待成交状态
                OdrOrderModel::M()->update(['oid' => $oid], $orderData);

                //恢复询价表状态
                OdrQuoteModel::M()->update(['okey' => $odrOrder['okey'], 'stat' => 3], ['stat' => 2]);
            }
            else
            {
                //将订单恢复到待成交状态
                OdrOrderModel::M()->update(['oid' => $oid], ['ostat' => 10, 'paystat' => 0, 'mtime' => $time]);
            }

            //删除待审核支付单
            OdrPaymentModel::M()->delete(['pid' => $odrOrder['third'], 'chkstat' => 1]);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 修改用户信息
     * @param string $oid
     * @param string $buyerName
     * @param int $mobile
     * @throws
     */
    public function modify(string $oid, string $buyerName, int $mobile)
    {
        $info = [];
        $orderInfo = OdrOrderModel::M()->getRowById($oid, 'ostat,plat');
        if ($orderInfo == false)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }
        if (!in_array($orderInfo['ostat'], [11, 21]))
        {
            throw new AppException('订单非支付和发货状态，不允许操作', AppException::OUT_OF_OPERATE);
        }
        if ($orderInfo['plat'] != 23)
        {
            throw new AppException('该订单平台不能修改用户信息', AppException::OUT_OF_OPERATE);
        }
        if ($buyerName)
        {
            $info['recver'] = $buyerName;
        }
        if ($mobile)
        {
            $info['rectel'] = $mobile;
        }

        //更新订单表的相关信息
        OdrOrderModel::M()->updateById($oid, $info);
    }

    /**
     * 获取翻页数据条件
     * @param array $query
     * @return mixed
     */
    private function getPagerWhere(array $query)
    {
        //初始化条件
        $where = [];
        $where['tid'] = ['in' => [11, 12, 21, 22, 31, 32, 33, 34, 35]];
        $orderBy['atime'] = -1;

        //分仓
        if ($query['whs'])
        {
            $where['whs'] = $query['whs'];
        }

        //库存编码或IMEI
        if ($query['code'])
        {
            $when['$or'] = [
                ['bcode' => $query['code']],
                ['imei' => $query['code']]
            ];
            $productPid = PrdProductModel::M()->getOne($when, 'pid');
            if (!$productPid)
            {
                $where['okey'] = -1;
            }
            else
            {
                $odrGoodsOkey = OdrGoodsModel::M()->getDistinct('okey', ['pid' => $productPid]);
                if ($odrGoodsOkey)
                {
                    $where['okey'] = ['in' => $odrGoodsOkey];
                }
                else
                {
                    $where['okey'] = -1;
                }
            }
        }

        //订单编号
        if ($query['okey'])
        {
            if (isset($where['okey']) && $where['okey'] != $query['okey'])
            {
                $where['okey'] = -1;
            }
            else
            {
                $where['okey'] = $query['okey'];
            }
        }

        //下单人
        if ($query['name'])
        {
            $where['recver'] = ['like' => $query['name'] . '%'];
        }

        //订单类型
        if ($query['tid'])
        {
            if ($query['tid'] && in_array($query['tid'], [2111, 2211, 11, 12, 21, 22]))
            {
                $where['tid'] = $query['tid'];
                if ($query['tid'] == 2111)
                {
                    $where['tid'] = 11;
                    $where['plat'] = 21;
                }
                if ($query['tid'] == 2211)
                {
                    $where['tid'] = 11;
                    $where['plat'] = 22;
                }
            }
            else
            {
                $where['tid'] = 0;
            }
        }

        //订单状态
        if ($query['ostat'] != '')
        {
            $where['ostat'] = $query['ostat'];
        }

        //来源平台
        if (!empty($query['plat']))
        {
            $where['plat'] = $query['plat'];
        }

        //时间
        if ($query['ttype'] && $query['time'])
        {
            $date = $query['time'];
            if (count($date) == 2)
            {
                //时间类型
                $dype = $query['ttype'];

                //一天中开始时间和结束时间
                $stime = strtotime($date[0] . ' 00:00:00');
                $etime = strtotime($date[1] . ' 23:59:59');

                //1：创建时间、2：出库时间、3：最后更新时间
                $where[$dype] = ['between' => [$stime, $etime]];
            }
        }

        //列表类型
        switch ($query['list'])
        {
            case 'confirmed':
                $where['tid'] = $query['tid'] !== 0 && $query['tid'] !== 21 ? 0 : 21;
                $where['ostat'] = 10;
                break;
            case 'paid':
                if ($query['tid'] == 0)
                {
                    $where['tid'] = ['in' => [11, 21, 22]];
                }
                if ($query['tid'] == 12)
                {
                    $where['tid'] = 0;
                }
                $where['ostat'] = 11;
                break;
            case 'out':
                $where['tid'] = $query['tid'] !== 0 && $query['tid'] !== 22 ? 0 : 22;
                break;
            case 'all':
                break;
        }

        //返回
        return [
            'where' => $where,
            'orderBy' => $orderBy
        ];
    }
}
