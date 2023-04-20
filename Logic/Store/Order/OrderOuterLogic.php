<?php


namespace App\Module\Sale\Logic\Store\Order;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmAddressModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Odr\OdrQuoteModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcBorrowGoodsModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Crm\Logic\CrmAddressLogic;
use App\Module\Pub\Data\SysWarehouseData;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Configer;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;
use Throwable;
use App\Module\Sale\Data\SaleDictData;

/**
 * 销售端外发订单
 * Class OrderOuterLogic
 * @package App\Module\Sale\Logic\Store\Order
 */
class OrderOuterLogic extends BeanCollector
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
     *新增外单 返回生成的订单主键
     * @return string
     * @throws
     */
    public function generate()
    {
        $data = [
            'oid' => IdHelper::generate(),
            'okey' => $this->uniqueKeyLogic->getUniversal(),
            'tid' => 22,
            'plat' => 23,
            'src' => 23,
            'whs' => 105,
            'ostat' => 0,
            'atime' => time(),
        ];
        OdrOrderModel::M()->insert($data);
        return $data['oid'];
    }

    /**
     * 验证并返回当前数据
     * @param string $bcode 库存编码
     * @param string $oid 订单编号
     * @param float $rate 金额加成
     * @return array
     * @throws
     */
    public function check(string $bcode, string $oid, float $rate)
    {
        if ($bcode == '')
        {
            throw new AppException('请输入或扫描库存编号', AppException::DATA_MISS);
        }

        $clos = 'pid,bid,bcode,mid,level,stcstat,plat,salecost,supcost,stcwhs,prdstat,inway,offer';
        $prdProduct = PrdProductModel::M()->getRow(['bcode' => $bcode], $clos);

        if ($prdProduct == false)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }

        $productPid = $prdProduct['pid'];
        $stcstat = $prdProduct['stcstat'];
        $prdstat = $prdProduct['prdstat'];
        $stcwhs = $prdProduct['stcwhs'];
        $offerId = $prdProduct['offer'];
        $inway = $prdProduct['inway'];
        $salecost = $prdProduct['salecost'] ?: $prdProduct['supcost'];
        $saleamt = number_format($prdProduct['salecost'] * (1 + $rate), 2, '.', '') ?? 0.00;
        $prdProduct['saleamt'] = $saleamt;
        $plat = 23;
        $issup = 0;
        $supprof = 0;

        //检查库存和商品状态
        if ($prdstat != 1 || $stcwhs != 105 || !in_array($stcstat, [11, 33, 34, 35]))
        {
            throw new AppException("库存状态不在库-[$stcstat]", AppException::NO_DATA);
        }

        //检查商品来源是否允许录单销售
        if (in_array($inway, [71, 91]))
        {
            throw new AppException("帮卖来源的商品不允许录单-[$inway]", AppException::NO_RIGHT);
        }

        //检查商品是否在外借中
        $borrowGoods = StcBorrowGoodsModel::M()->getList(['pid' => $productPid, 'rstat' => 1]);
        if ($borrowGoods)
        {
            throw new AppException('商品外借未回仓，无法操作', AppException::OUT_OF_OPERATE);
        }

        //获取订单数据
        $orderInfo = OdrOrderModel::M()->getRowById($oid, 'okey');

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getRow(['bcode' => $bcode], 'okey,pid,issup,rtntype');

        //有其他订单
        if ($odrGoods)
        {
            if (in_array($odrGoods['rtntype'], [0, 2]))
            {
                throw new AppException('商品已在其它订单中，无法操作', AppException::OUT_OF_OPERATE);
            }
        }

        //提取供应商品数据
        $supplyInfo = PrdSupplyModel::M()->getRow(['pid' => $productPid, 'salestat' => 1], 'sid,pid');
        if ($supplyInfo == false)
        {
            throw new AppException('缺少供应商品数据', AppException::NO_DATA);
        }

        //获取供应商数据
        $offerInfo = CrmOfferModel::M()->getRow(['oid' => $offerId, 'tid' => 2], 'oid,exts');

        /*
         * 销售毛利说明
         * 供应商商品时：毛利 = 佣金 - 成本
         * 自有商品时：毛利 = 销售价 - 成本
         */
        if ($inway == 21)
        {
            $issup = 1;
            $supprof = $this->calculateOfferCommission($plat, $saleamt, $offerInfo);
            $profit = $supprof - $salecost;
        }
        else
        {
            $profit = $saleamt - $salecost;
        }
        $prdProduct['profit'] = $profit;
        $prdProduct['issup'] = $issup;
        $prdProduct['supprof'] = $supprof;

        //将商品数据插入订单商品表
        $this->AddorderGoodData($prdProduct, $orderInfo, $supplyInfo);

        //获取返回的订单总成本和总金额
        $totalAmount = $this->updateOrderAmount($orderInfo['okey']);

        //获取质检备注
        $mqcReport = MqcReportModel::M()->getRow(['pid' => $productPid, 'plat' => 21], 'bconc', ['atime' => -1]);

        //获取商品品牌，机型，级别
        $qtoBrand = QtoBrandModel::M()->getRow(['bid' => $prdProduct['bid']], 'bname');
        $qtoModel = QtoModelModel::M()->getRow(['mid' => $prdProduct['mid']], 'mname');
        $qtoLevel = QtoLevelModel::M()->getRow(['lkey' => $prdProduct['level']], 'lname');

        //补充说明
        $good['bcode'] = $bcode;
        $good['pid'] = $prdProduct['pid'] ?? '-';
        $good['plat'] = SaleDictData::SOURCE_PLAT[$prdProduct['plat']] ?? '-';
        $good['bname'] = $qtoBrand['bname'] ?? '-';
        $good['mname'] = $qtoModel['mname'] ?? '-';
        $good['lname'] = $qtoLevel['lname'] ?? '-';
        $good['report'] = $mqcReport['bconc'] ?? '';
        $good['scost1'] = floatval($prdProduct['salecost']) ?: $prdProduct['supcost'];
        $good['bprc'] = number_format(ceil($prdProduct['saleamt']), 2, '.', '');
        $good['ocost1'] = number_format($totalAmount['ocost1'], 2, '.', '');
        $good['oamt'] = number_format($totalAmount['oamt'], 2, '.', '');
        $good['atime'] = time();

        //返回
        return $good;
    }

    /**
     * 显示导入订单
     * @param string $oid 订单主键
     * @return array
     * @throws
     */
    public function getPager(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid, 'ostat' => 0, 'tid' => 22], 'okey,ocost1,oamt,exts');
        if ($odrOrder == false)
        {
            return [];
        }

        //判断是否勾选
        if ($odrOrder['exts'])
        {
            $extraFields = ArrayHelper::toArray($odrOrder['exts']);
            $change_price = $extraFields['change_price'];
            $internal_price = $extraFields['internal_price'];
            $odrOrder['change_price'] = $change_price;
            $odrOrder['internal_price'] = $internal_price;
        }
        else
        {
            $odrOrder['change_price'] = 0;
            $odrOrder['internal_price'] = 0;
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'gid,okey,pid,plat,bprc,scost1,bcode,supcost', ['atime' => -1]);

        //该订单下没有商品就返回空
        if ($odrGoods == false)
        {
            return [];
        }
        $goodsPid = ArrayHelper::map($odrGoods, 'pid');

        //获取商品数据
        $productDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $goodsPid]], 'pid,bid,mid,level,offer');

        //获取质检备注
        $mqcReport = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $goodsPid], 'plat' => 21], 'bconc', ['atime' => -1]);

        //获取商品品牌，机型，级别
        $bid = ArrayHelper::map($productDict, 'bid');
        $mid = ArrayHelper::map($productDict, 'mid');
        $level = ArrayHelper::map($productDict, 'level');

        $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bid]], 'bname');
        $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mid]], 'mname');
        $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $level]], 'lname');

        //补充说明
        $orderGoods = [];
        foreach ($odrGoods as $key => $value)
        {
            $orderGoods[] = [
                'pid' => $value['pid'],
                'bcode' => $value['bcode'] ?? '-',
                'plat' => SaleDictData::SOURCE_PLAT[$value['plat']] ?? '-',
                'bname' => $qtoBrand[$productDict[$value['pid']]['bid']]['bname'] ?? '-',
                'mname' => $qtoModel[$productDict[$value['pid']]['mid']]['mname'] ?? '-',
                'lname' => $qtoLevel[$productDict[$value['pid']]['level']]['lname'] ?? '-',
                'report' => $mqcReport[$productDict[$value['pid']]['pid']]['bconc'] ?? '',
                'scost1' => floatval($value['scost1']) ?: $value['supcost'],
                'bprc' => number_format($value['bprc'], 2, '.', ''),
            ];
        }
        $odrOrder['data'] = $orderGoods;

        //返回
        return $odrOrder;
    }

    /**
     * 订单导入计算按钮
     * @param string $oid 订单主键
     * @param float $rate 出价加成
     * @return array|void
     * @throws
     */
    public function calculate(string $oid, float $rate)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid, 'ostat' => 0, 'tid' => 22], 'okey,ocost1');
        if ($odrOrder == false)
        {
            throw new AppException('没有订单数据', AppException::NO_DATA);
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getDict('pid', ['okey' => $odrOrder['okey']], 'gid,okey,pid,plat,bprc,scost1,bcode,offer,supcost,issup', ['atime' => -1]);
        if ($odrGoods == false)
        {
            throw new AppException('当前订单没有商品数据', AppException::NO_DATA);
        }
        $goodsPid = ArrayHelper::map($odrGoods, 'pid');

        //获取商品数据
        $productDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $goodsPid]], 'pid,bid,mid,level');

        //获取质检备注
        $mqcReport = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $goodsPid], 'plat' => 21], 'bconc', ['atime' => -1]);

        //获取商品品牌，机型，级别
        $bid = ArrayHelper::map($productDict, 'bid');
        $mid = ArrayHelper::map($productDict, 'mid');
        $level = ArrayHelper::map($productDict, 'level');

        $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bid]], 'bname');
        $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mid]], 'mname');
        $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $level]], 'lname');

        //获取供应商数据
        $offerIds = ArrayHelper::map($odrGoods, 'offer');
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offerIds], 'tid' => 2], 'oid,exts');
        $time = time();
        $plat = 23;
        $updateData = [];
        $supprof = 0;
        foreach ($odrGoods as $key => $value)
        {
            $salecost = $value['scost1'];
            $saleamt = number_format($value['scost1'] * (1 + $rate), 2, '.', '');
            $odrGoods[$key]['saleamt'] = $saleamt;

            //获取供应商信息
            $offer = $odrGoods[$value['pid']]['offer'];

            /*
             * 销售毛利说明
             * 供应商商品时：毛利 = 佣金 - 成本
             * 自有商品时：毛利 = 销售价 - 成本
             */
            if ($odrGoods[$value['pid']]['issup'] == 1)
            {
                $supprof = $this->calculateOfferCommission($plat, $saleamt, $offerDict[$offer]);
                $profit = $supprof - $salecost;
            }
            else
            {
                $profit = $saleamt - $salecost;
            }

            //组装订单商品数据
            $updateData[] = [
                'gid' => $value['gid'],
                'bprc' => ceil($saleamt),
                'supprof' => $supprof,
                'profit1' => $profit,
                'profit2' => $profit,
                'mtime' => $time,
            ];
        }
        if ($updateData == false)
        {
            return;
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //将商品数据插入订单商品表
            OdrGoodsModel::M()->inserts($updateData, true);

            //获取返回的订单总成本和总金额
            $totalAmount = $this->updateOrderAmount($odrOrder['okey']);

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

        //补充说明
        $orderGoods = [];
        foreach ($odrGoods as $key => $value)
        {
            $orderGoods[] = [
                'pid' => $value['pid'],
                'bcode' => $value['bcode'] ?? '-',
                'plat' => SaleDictData::SOURCE_PLAT[$value['plat']] ?? '-',
                'bname' => $qtoBrand[$productDict[$value['pid']]['bid']]['bname'] ?? '-',
                'mname' => $qtoModel[$productDict[$value['pid']]['mid']]['mname'] ?? '-',
                'lname' => $qtoLevel[$productDict[$value['pid']]['level']]['lname'] ?? '-',
                'report' => $mqcReport[$productDict[$value['pid']]['pid']]['bconc'] ?? '',
                'scost1' => floatval($value['scost1']) ?: $value['supcost'],
                'bprc' => number_format($value['saleamt'], 2, '.', '') ?? '-',
            ];
        }
        $odrOrder['oamt'] = number_format($totalAmount['oamt'], 2, '.', '') ?? '-';
        $odrOrder['data'] = $orderGoods;

        //返回
        return $odrOrder;
    }

    /**
     * 用户出价保存
     * @param string $oid 订单主键
     * @param string $pid 商品主键
     * @param float $bprc 用户出价
     * @return array
     * @throws
     */
    public function save(string $oid, string $pid, float $bprc)
    {
        //查找该订单下的商品数据
        $order = OdrOrderModel::M()->getRow(['oid' => $oid, 'ostat' => 0, 'tid' => 22], 'okey');
        $odrGoods = OdrGoodsModel::M()->getRow(['pid' => $pid, 'okey' => $order['okey'], 'ostat' => 0], 'gid,issup');
        if ($odrGoods == false)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }

        //查找商品数据
        $prdProduct = PrdProductModel::M()->getRow(['pid' => $pid, 'stcwhs' => 105], 'salecost,offer');

        //输入价向上取整
        $saleamt = ceil($bprc);
        $offerId = $prdProduct['offer'];
        $salecost = $prdProduct['salecost'];
        $time = time();
        $supprof = 0;
        $plat = 23;

        //获取供应商数据
        $offerInfo = CrmOfferModel::M()->getRow(['oid' => $offerId, 'tid' => 2], 'oid,exts');

        /*
        * 销售毛利说明
        * 供应商商品时：毛利 = 佣金 - 成本
        * 自有商品时：毛利 = 销售价 - 成本
        */
        if ($odrGoods['issup'] == 1)
        {
            $supprof = $this->calculateOfferCommission($plat, $saleamt, $offerInfo);
            $profit = $supprof - $salecost;
        }
        else
        {
            $profit = $saleamt - $salecost;
        }

        //组装订单商品数据
        $GoodsData = [
            'bprc' => $saleamt,
            'supprof' => $supprof,
            'profit1' => $profit,
            'profit2' => $profit,
            'mtime' => $time,
        ];

        try
        {
            //开启事务
            Db::beginTransaction();

            //将商品数据插入订单商品表
            OdrGoodsModel::M()->update(['gid' => $odrGoods['gid']], $GoodsData);

            //更新订单金额
            $totalAmount = $this->updateOrderAmount($order['okey']);

            //事务提交
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }

        //获取返回的订单总金额
        $order['oamt'] = number_format($totalAmount['oamt'], 2, '.', '') ?? '-';

        //返回
        return $order;
    }

    /**
     * 删除订单商品
     * @param string $oid 订单主键
     * @param string $pid 商品主键
     * @return array
     * @throws
     */
    public function delete(string $oid, string $pid)
    {
        //查找该订单下的商品数据
        $order = OdrOrderModel::M()->getRow(['oid' => $oid, 'ostat' => 0], 'okey,tid');
        if ($order['tid'] != 22)
        {
            throw new AppException('订单类型不正确', AppException::WRONG_ARG);
        }
        $odrGoodsInfo = OdrGoodsModel::M()->getRow(['pid' => $pid, 'okey' => $order['okey']], 'gid');
        if ($odrGoodsInfo == false)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }
        $gid = $odrGoodsInfo['gid'];

        //查询该订单下的商品是否有过出价记录
        $odrQuote = OdrQuoteModel::M()->getRow(['okey' => $order['okey'], 'gid' => $gid], 'qid');

        try
        {
            //开启事务
            Db::beginTransaction();

            //恢复商品待销售状态
            PrdProductModel::M()->update(['pid' => $pid], ['stcstat' => 11, 'stctime' => time()]);

            //恢复仓库商品状态
            StcStorageModel::M()->update(['pid' => $pid, 'twhs' => 105], ['prdstat' => 11]);

            //删除商品同时删除出价记录
            if ($odrQuote)
            {
                OdrQuoteModel::M()->delete(['gid' => $gid]);
            }

            //删除订单商品
            OdrGoodsModel::M()->delete(['gid' => $gid]);

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

        //返回处理过的订单数据
        $totalAmount = $this->updateOrderAmount($order['okey']);

        //获取返回的订单总金额
        $order['ocost1'] = number_format($totalAmount['ocost1'], 2, '.', '') ?? '-';
        $order['oamt'] = number_format($totalAmount['oamt'], 2, '.', '') ?? '-';

        //返回
        return $order;
    }

    /**
     * 提交订单
     * @param string $oid
     * @param int $change_price
     * @param int $internal_price
     * @return string
     * @throws
     */
    public function submit(string $oid, int $change_price, int $internal_price)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid, 'ostat' => 0], 'okey,tid');
        if ($odrOrder['tid'] != 22)
        {
            throw new AppException('订单类型不正确', AppException::WRONG_ARG);
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'gid');
        if ($odrGoods == false)
        {
            throw new AppException('订单不能为空', AppException::NO_DATA);
        }

        //获取订单商品信息
        $odrGoodsGid = ArrayHelper::map($odrGoods, 'gid');
        $text = [
            'change_price' => $change_price,
            'internal_price' => $internal_price
        ];
        $extraFields = json_encode($text);

        try
        {
            //开启事务
            Db::beginTransaction();

            //更新扩展字段
            OdrOrderModel::M()->update(['oid' => $oid], ['exts' => $extraFields, 'ostat' => 10]);

            //更新订单商品状态
            OdrGoodsModel::M()->update(['gid' => ['in' => $odrGoodsGid]], ['ostat' => 10]);

            //事务提交
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
     * 外单待成交
     * @param string $oid
     * @return array
     * @throws
     */
    public function detail(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,tid,buyer,profit11,oamt1,ostat,qty,atime,ocost1,oamt,whs');
        if ($odrOrder['tid'] != 22)
        {
            throw new AppException('订单类型不正确', AppException::WRONG_ARG);
        }
        if ($odrOrder['buyer'])
        {
            throw new AppException('订单已成交', AppException::DATA_EXIST);
        }
        $info['atime'] = DateHelper::toString($odrOrder['atime']);
        $info['tidName'] = SaleDictData::ORDER_TYPE[$odrOrder['tid']] ?? '-';
        $info['statName'] = SaleDictData::ORDER_OSTAT[$odrOrder['ostat']] ?? '-';
        $info['okey'] = $odrOrder['okey'];
        $info['ostat'] = $odrOrder['ostat'];
        $info['qty'] = $odrOrder['qty'];
        $info['ocost1'] = $odrOrder['ocost1'];
        $info['wname'] = SysWarehouseData::D()->getName($odrOrder['whs']) ?? '-';

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'gid,bcode,pid,scost1,bprc,supcost', ['atime' => -1]);
        if ($odrGoods == false)
        {
            throw new AppException('商品数据不存在，如有疑问请联系开发部', AppException::NO_DATA);
        }

        //获取商品信息
        $goodPids = ArrayHelper::map($odrGoods, 'pid');
        $productInfo = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $goodPids]], 'bid,mid,level');

        $bid = ArrayHelper::map($productInfo, 'bid', '-1');
        $mid = ArrayHelper::map($productInfo, 'mid', '-1');
        $level = ArrayHelper::map($productInfo, 'level', '-1');

        //获取商品品牌，机型，级别
        $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bid]], 'bname');
        $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mid]], 'mname');
        $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $level]], 'lname');

        //补充说明
        $orderGoods = [];
        foreach ($odrGoods as $key => $value)
        {
            $productBid = $productInfo[$value['pid']]['bid'] ?? '';
            $productMid = $productInfo[$value['pid']]['mid'] ?? '';
            $productLevel = $productInfo[$value['pid']]['level'] ?? '';
            $orderGoods[] = [
                'gid' => $value['gid'],
                'pid' => $value['pid'],
                'bcode' => $value['bcode'],
                'mid' => $productInfo[$value['pid']]['mid'] ?? '-',
                'bname' => $qtoBrand[$productBid]['bname'] ?? '-',
                'mname' => $qtoModel[$productMid]['mname'] ?? '-',
                'lname' => $qtoLevel[$productLevel]['lname'] ?? '-',
                'scost1' => floatval($value['scost1']) ?: $value['supcost'],
                'bprc' => number_format($value['bprc'], 2, '.', '') ?? number_format($value['scost1'], 2, '.', ''),
            ];
        }

        //按机型倒序排序
        usort($orderGoods, function ($a, $b) {
            $mid1 = $a['mid'];
            $mid2 = $b['mid'];
            if ($mid1 == $mid2)
            {
                return 0;
            }
            if ($mid1 < $mid2)
            {
                return 1;
            }
            return -1;
        });

        $info['product'] = $orderGoods;

        //提取所需数据
        $gids = [];
        $ocosts = [];
        $bprcs = 0;
        foreach ($orderGoods as $value)
        {
            $gids[] = $value['gid'];
            $ocosts[] = $value['scost1'];
            $bprcs += $value['bprc'];
        }

        //计算内部出价利润率
        $ocost = array_sum($ocosts);
        $info['oid'] = $oid;
        $info['ocost'] = number_format($ocost, 2, '.', '');
        $info['oamt'] = number_format($bprcs, 2, '.', '');
        if ($odrOrder['oamt1'] != 0)
        {
            $profit = $odrOrder['profit11'] / $odrOrder['oamt1'];
            $info['profit'] = round($profit * 100, 2);
        }
        else
        {
            $info['profit'] = 0;
        }

        $quoteInfo = OdrQuoteModel::M()->getRow(['okey' => $odrOrder['okey'], 'gid' => ['in' => $gids], 'stat' => 2], 'gid');
        if ($quoteInfo)
        {
            //获取批发商报价数据
            $info['data'] = $this->getQuotes($gids, $ocosts);
            $info['dstat'] = 1;
        }
        else
        {
            $info['dstat'] = 0;
        }

        //补充二维码链接
        $apiHost = Configer::get('common:apihost', '');
        $webHost = Configer::get('common:webhost', '');
        $codeUrl = urlencode("$webHost/sale/h5/store/outer/share/index.html?oid=$oid");
        $info['qrcode'] = "$apiHost/pub/qrcode/create?content=$codeUrl";

        //返回
        return $info;
    }

    /**
     * 外单详情修改订单
     * @param string $oid
     * @throws
     */
    public function modify(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,ostat,tid');
        if ($odrOrder['ostat'] != 10)
        {
            throw new AppException('当前订单状态不允许修改', AppException::WRONG_ARG);
        }
        if ($odrOrder['tid'] != 22)
        {
            throw new AppException('当前订单类型不允许修改', AppException::WRONG_ARG);
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey'], 'ostat' => 10], 'gid,pid');
        if ($odrGoods)
        {
            $goodsPid = ArrayHelper::map($odrGoods, 'pid');

            try
            {
                //开启事务
                Db::beginTransaction();

                //修改订单商品状态
                OdrGoodsModel::M()->update(['okey' => $odrOrder['okey'], 'pid' => ['in' => $goodsPid]], ['ostat' => 0]);

                //修改订单状态
                OdrOrderModel::M()->update(['oid' => $oid], ['ostat' => 0]);

                //事务提交
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
    }

    /**
     * 外单详情删除订单
     * @param string $oid
     * @throws
     */
    public function orderDelete(string $oid)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,ostat,tid');
        if (!in_array($odrOrder['ostat'], [0, 10]))
        {
            throw new AppException('订单状态不允许删除', AppException::WRONG_ARG);
        }
        if ($odrOrder['tid'] != 22)
        {
            throw new AppException('当前订单类型不允许删除', AppException::WRONG_ARG);
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'gid,pid');
        if ($odrGoods)
        {
            $goodsPid = ArrayHelper::map($odrGoods, 'pid');
            try
            {
                //开启事务
                Db::beginTransaction();

                //恢复商品待销售状态
                PrdProductModel::M()->update(['pid' => ['in' => $goodsPid]], ['stcstat' => 11, 'stctime' => time()]);

                //恢复仓库商品状态
                StcStorageModel::M()->update(['pid' => ['in' => $goodsPid], 'twhs' => 105], ['prdstat' => 11]);

                //删除订单下的所有订单商品记录
                OdrGoodsModel::M()->delete(['okey' => $odrOrder['okey']]);

                //删除订单
                OdrOrderModel::M()->delete(['oid' => $oid]);

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
            //删除订单
            OdrOrderModel::M()->delete(['oid' => $oid]);
        }
    }

    /**
     * 外单待成交 - 修改价格
     * @param string $qid
     * @param int $bprc
     * @return string
     * @throws
     */
    public function change(string $qid, int $bprc)
    {
        //获取报价数据
        $quoteInfo = OdrQuoteModel::M()->getRowById($qid, 'bprc');
        if ($quoteInfo == false)
        {
            throw new AppException('报价数据未找到', AppException::NO_DATA);
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //修改当前报价
            OdrQuoteModel::M()->updateById($qid, ['bprc' => $bprc]);

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
     * 出价完成 成交
     * @param string $oid
     * @param string $userId
     * @param string $recver
     * @param int $rectel
     * @return string
     * @throws
     */
    public function complete(string $oid, string $userId, string $recver, int $rectel)
    {
        //获取订单数据
        $orderInfo = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,ostat,tid');
        if ($orderInfo['ostat'] != 10)
        {
            throw new AppException('订单状态不正确', AppException::WRONG_ARG);
        }
        if ($orderInfo['tid'] != 22)
        {
            throw new AppException('订单类型不正确', AppException::WRONG_ARG);
        }
        //获取报价信息
        $quoteInfo = OdrQuoteModel::M()->getList(['okey' => $orderInfo['okey'], 'buyer' => $userId, 'stat' => 2], 'qid,gid,bprc,buyer');
        if ($quoteInfo == false)
        {
            throw new AppException('该订单报价信息不存在', AppException::NO_DATA);
        }

        //获取订单商品信息
        $orderGoods = OdrGoodsModel::M()->getDict('gid', ['okey' => $orderInfo['okey']], 'gid,offer,scost1,issup');

        //获取供应商数据
        $offerIds = ArrayHelper::map($orderGoods, 'offer');
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offerIds], 'tid' => 2], 'oid,exts');

        //查询用户是否有商品未出价
        $orderGoodsGid = ArrayHelper::map($orderGoods, 'gid');
        $quoteInfoGid = ArrayHelper::map($quoteInfo, 'gid');
        $diff = array_diff($orderGoodsGid, $quoteInfoGid);
        if (!empty($diff))
        {
            throw new AppException('用户有机器未出价', AppException::NO_DATA);
        }
        $qids = ArrayHelper::map($quoteInfo, 'qid');
        $time = time();
        $plat = 23;
        $supprof = 0;
        $updateData = [];

        //更新订单的基本信息
        $orderData = [
            'buyer' => $userId,
            'recver' => $recver,
            'rectel' => $rectel,
            'ostat' => 11,
            'paystat' => 1,
            'mtime' => $time,
            'otime' => $time,
        ];

        //循环更新订单商品的信息
        foreach ($quoteInfo as $key => $value)
        {
            if (isset($orderGoods[$value['gid']]))
            {
                //获取商品成本
                $salecost = $orderGoods[$value['gid']]['scost1'];

                //获取商品销售价
                $saleamt = $value['bprc'];

                //获取供应商信息
                $offer = $orderGoods[$value['gid']]['offer'];

                /*
                 * 销售毛利说明
                 * 供应商商品时：毛利 = 佣金 - 成本
                 * 自有商品时：毛利 = 销售价 - 成本
                 */
                if ($orderGoods[$value['gid']]['issup'] == 1)
                {
                    $supprof = $this->calculateOfferCommission($plat, $saleamt, $offerDict[$offer]);
                    $profit = $supprof - $salecost;
                }
                else
                {
                    $profit = $saleamt - $salecost;
                }

                //组装更新数据
                $updateData[] = [
                    'gid' => $value['gid'],
                    'bprc' => $value['bprc'],
                    'ostat' => 11,
                    'supprof' => $supprof,
                    'profit1' => $profit,
                    'profit2' => $profit,
                    'mtime' => $time,
                    'otime' => $time,
                ];
            }
        }
        if ($updateData == false)
        {
            return;
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //更新地址表的相关信息
            $this->crmAddressLogic->save($plat, $userId, [
                'way' => 2,
                'lnker' => $recver,
                'lnktel' => $rectel,
                'rgnid' => '440304',
                'rgndtl' => '华强北自提'
            ]);

            //更新订单商品信息
            OdrGoodsModel::M()->inserts($updateData, true);

            //更新外发订单客户的报价状态
            OdrQuoteModel::M()->update(['qid' => ['in' => $qids]], ['stat' => 3]);

            //更新订单金额的相关数据
            $this->updateOrderAmount($orderInfo['okey']);

            //更新订单基本信息
            OdrOrderModel::M()->update(['oid' => $oid], $orderData);

            //事务提交
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
     * 将商品加入订单商品表，同时修改订单状态，商品库存状态
     * @param array $prdProduct
     * @param array $order
     * @param array $supplyInfo
     * @throws
     */
    public function AddorderGoodData(array $prdProduct, array $order, array $supplyInfo)
    {
        $time = time();
        //组装商品数据
        $gid = IdHelper::generate();
        $GoodsData = [
            'gid' => $gid,
            'okey' => $order['okey'],
            'plat' => $prdProduct['plat'],
            'tid' => 22,
            'src' => 23,
            'yid' => $supplyInfo['sid'],
            'ostat' => 0,
            'offer' => $prdProduct['offer'],
            'pid' => $prdProduct['pid'],
            'bcode' => $prdProduct['bcode'],
            'scost1' => $prdProduct['salecost'],
            'scost2' => $prdProduct['salecost'],
            'bprc' => ceil($prdProduct['saleamt']),
            'supprof' => $prdProduct['supprof'],
            'profit1' => $prdProduct['profit'],
            'profit2' => $prdProduct['profit'],
            'issup' => $prdProduct['issup'],
            'supcost' => $prdProduct['supcost'],
            'atime' => $time,
            'mtime' => $time,
            '_id' => $gid,
        ];

        try
        {
            //开启事务
            Db::beginTransaction();

            //新增订单商品数据
            $res = OdrGoodsModel::M()->insert($GoodsData, true);
            if ($res == false)
            {
                throw new AppException('新增订单商品失败', AppException::FAILED_INSERT);
            }

            //修改商品状态
            PrdProductModel::M()->update(['pid' => $GoodsData['pid']], ['stcstat' => 15, 'stctime' => $time]);

            //修改仓储商品状态
            StcStorageModel::M()->update(['pid' => $GoodsData['pid'], 'twhs' => 105], ['prdstat' => 15]);

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
     * 订单金额处理
     * @param string $okey
     * @return array
     * @throws
     */
    public function updateOrderAmount(string $okey)
    {
        //计算订单相关金额
        $oamt = 0; //合计订单总金额
        $oamt1 = 0; //自营商品金额
        $oamt2 = 0; //供应商商品金额
        $ocost = 0; //合计订单总成本
        $scost11 = 0; //自营商品成本
        $scost21 = 0; //供应商商品成本
        $profit11 = 0; //自有商品毛利
        $profit21 = 0; //供应商商品毛利
        $supprof = 0; //供应商商品佣金
        $time = time();

        //查找该订单下的商品数据
        $goodsList = OdrGoodsModel::M()->getList(['okey' => $okey], 'gid,bprc,scost1,profit1,supprof,issup');
        if ($goodsList)
        {
            //循环商品数据处理数据
            foreach ($goodsList as $value)
            {
                $bprc = $value['bprc'];
                $scost1 = $value['scost1'];
                $profit1 = $value['profit1'];
                $supprof += $value['supprof'];
                if ($value['issup'] == 0)
                {
                    $oamt1 += $bprc;
                    $scost11 += $scost1;
                    $profit11 += $profit1;
                }
                else
                {
                    $oamt2 += $bprc;
                    $scost21 += $scost1;
                    $profit21 += $profit1;
                }

                //合计订单总金额和订单总成本
                $oamt = $oamt1 + $oamt2;
                $ocost = $scost11 + $scost21;
            }

            //组装订单更新数据
            $OrderData = [
                'oamt' => $oamt,
                'oamt1' => $oamt1,
                'oamt2' => $oamt2,
                'qty' => count($goodsList),
                'payamt' => $oamt,
                'ocost1' => $ocost,
                'ocost2' => $ocost,
                'scost11' => $scost11,
                'scost12' => $scost11,
                'scost21' => $scost21,
                'scost22' => $scost21,
                'supprof' => $supprof,
                'profit11' => $profit11,
                'profit12' => $profit11,
                'profit21' => $profit21,
                'profit22' => $profit21,
                'mtime' => $time
            ];
        }
        else
        {
            $OrderData = [
                'oamt' => 0.00,
                'oamt1' => 0.00,
                'ocost1' => 0.00,
                'qty' => 0,
                'payamt' => 0,
            ];
        }

        //更新订单总成本，总金额，总数量
        OdrOrderModel::M()->update(['okey' => $okey], $OrderData);

        //返回
        return $OrderData;
    }

    /**
     * 获取报价数据
     * @param array $gids 订单商品id
     * @param array $ocosts 各个商品的成本价
     * @param int $stat 出价状态 2：表示出价待成交，3：表示和其他客户已成交
     * @return array|mixed
     */
    public function getQuotes($gids, $ocosts, $stat = 2)
    {
        //获取报价数据
        $quotes = OdrQuoteModel::M()->getList(['gid' => ['in' => $gids], 'stat' => $stat], 'qid,gid,bprc,buyer');
        if ($quotes == false)
        {
            return [];
        }

        //提取buyer
        $accs = ArrayHelper::map($quotes, 'buyer');

        //查找该订单下的商品信息
        $goodsDict = OdrGoodsModel::M()->getDict('gid', ['gid' => ['in' => $gids]], 'issup');

        //获取批发商名称
        $buyers = AccUserModel::M()->getList(['aid' => ['in' => $accs]], 'aid,rname,uname,mobile');

        //从以往记录中提取联系人信息
        $crmAddressInfo = CrmAddressModel::M()->getDict('uid', ['uid' => ['in' => $accs]], 'uid,lnker,lnktel,atime', ['def' => 1, 'atime' => 1]);
        foreach ($buyers as $key1 => $value1)
        {
            $acc = $value1['aid'];
            if ($crmAddressInfo != false)
            {
                $buyers[$key1]['rname'] = $crmAddressInfo[$acc]['lnker'];
                $buyers[$key1]['mobile'] = $crmAddressInfo[$acc]['lnktel'];
            }

            //计算订单金额、成本、利润率等
            $total = 0;
            $cost = 0;
            $qty = 0;
            $selftotal = 0;
            $selfcost = 0;
            $temp = [];
            foreach ($gids as $key2 => $value2)
            {
                $not = true;
                foreach ($quotes as $key3 => $value3)
                {
                    if ($acc == $value3['buyer'] && $value2 == $value3['gid'])
                    {
                        $temp[] = [
                            'qid' => $value3['qid'],
                            'bprc' => $value3['bprc']
                        ];
                        $total += $value3['bprc'];
                        $cost += $ocosts[$key2];
                        $qty++;
                        $not = false;
                        unset($quotes[$key3]);
                        if ($goodsDict[$value3['gid']]['issup'] == 0)
                        {
                            $selftotal += $value3['bprc'];
                            $selfcost += $ocosts[$key2];
                        }
                        break;
                    }
                }
                if ($not)
                {
                    $temp[] = [
                        'qid' => 0,
                        'bprc' => '-'
                    ];
                }
            }

            //补充数据
            $total = sprintf("%.2f", $total);
            $buyers[$key1]['bprcs'] = $temp;
            $buyers[$key1]['total'] = $total;
            $buyers[$key1]['qty'] = $qty;
            $buyers[$key1]['profit'] = 0;
            if ($selftotal != 0)
            {
                $porfit = ($selftotal - $selfcost) / $selftotal;
                $buyers[$key1]['profit'] = round($porfit * 100, 2);
            }
        }

        //按利润率高的排序
        usort($buyers, function ($a, $b) {
            $total1 = $a['total'];
            $total2 = $b['total'];
            if ($total1 == $total2)
            {
                return 0;
            }
            if ($total1 < $total2)
            {
                return 1;
            }
            return -1;
        });

        //返回
        return $buyers;
    }

    /**
     * 计算供应商佣金
     * @param int $plat 平台ID
     * @param int $saleamt 销售金额
     * @param array $offerInfo 供应商信息
     * @return mixed
     */
    private function calculateOfferCommission($plat, $saleamt, $offerInfo)
    {
        //提取佣金比例和封顶值
        $extData = ArrayHelper::toArray($offerInfo['exts']);
        if (!isset($extData[$plat]))
        {
            //没有设置指定平台的佣金则使用销售端的佣金
            $plat = 21;
        }
        $cmmrate = $extData[$plat]['rate'] ?? 0;
        $cmmmaxamt = $extData[$plat]['max'] ?? 0;
        $cmmminamt = $extData[$plat]['min'] ?? 0;

        //计算佣金
        $supprof = round(($saleamt * $cmmrate / 100), 2);
        if ($cmmmaxamt > 0 && $supprof > $cmmmaxamt)
        {
            $supprof = $cmmmaxamt;
        }
        if ($cmmminamt > 0 && $cmmminamt > $supprof)
        {
            $supprof = $cmmminamt;
        }

        //返回
        return $supprof;
    }

    /**
     * 计算利润率：利润率＝利润÷收入×100%
     * @param double $amt 金额
     * @param double $cost 成本
     * @return string
     */
    public static function profit($amt, $cost)
    {
        if ($amt == 0)
        {
            return '-';
        }
        return round(($amt - $cost) / $amt * 100, 2) . '%';
    }

    /**
     * 刷新页面
     * @param string $oid 金额
     * @return string
     */
    public static function refresh($oid)
    {
        $ostat = OdrOrderModel::M()->getOneById($oid, 'ostat');

        //返回
        return $ostat;
    }
}