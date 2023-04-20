<?php

namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Crm\CrmAddressModel;
use App\Model\Crm\CrmDepositDeductionModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Pos\PosGridModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Module\Api\Logic\V1\Bid\TmpSyncLogic;
use App\Module\Pub\Logic\UniqueKeyLogic;
use App\Module\Smb\Data\SmbNodeKeyData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;
use Swork\Service;

/**
 * 竞拍场次结束后创建订单任务
 * @package App\Module\Sale\Task\Round
 */
class RoundEndOrderTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject("amqp_message_task")
     * @var Amqp
     */
    private $amqp_message;

    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * @Inject()
     * @var TmpSyncLogic
     */
    private $tmpSyncLogic;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     * @throws
     */
    function execute(array $data)
    {
        $rid = $data['rid'];
        $time = time();

        try
        {
            Service::$logger->info(__CLASS__ . '-[执行任务]', $data);

            //加锁，锁定一个小时
            $lockKey = "round_end_order_$rid";
            if ($this->redis->setnx($lockKey, $time, 3600) == false)
            {
                Service::$logger->error("场次结束创建订单时加锁失败-[$rid]");
                return true;
            }

            //获取需要结束的场次数据
            $roundInfo = PrdBidRoundModel::M()->getRowById($rid, 'rid,plat,mode,etime,stat');
            if ($roundInfo == false)
            {
                return true;
            }

            //检查场次状态以及结束时间(超出半小时不处理)
            if ($roundInfo['stat'] != 14)
            {
                return true;
            }
            if ((time() - $roundInfo['etime']) > 1800)
            {
                Service::$logger->error("场次超出可操作时间范围-[$rid]");
                return true;
            }

            //获取场次商品数据（防止场次结束时没有更新成功，这里增加竞拍状态条件）
            $salesList = PrdBidSalesModel::M()->getList(['rid' => $rid, 'stat' => 21]);
            if ($salesList == false)
            {
                return true;
            }

            Service::$logger->info(__CLASS__ . '-[符合执行条件]', ['args' => $data, 'count' => count($salesList)]);

            $idsData = [
                'offer' => [], //大B供应商ID
                'buyer' => [], //中标买家ID
                'product' => [], //主商品ID
            ];
            $gridData = []; //分配格子墙数据
            $batchUpdateSales = [];
            $batchUpdateDeduction = [];

            //循环提取各个业务需要的数据
            foreach ($salesList as $item)
            {
                $mode = $item['mode'];
                $buyer = $item['luckbuyer'];
                $idsData['buyer'][] = $buyer;
                $idsData['offer'][] = $item['offer'];
                $idsData['product'][] = $item['pid'];
                $gridData["$mode:$buyer"] = true;
            }

            //创建竞拍订单
            $res = $this->createOrder($salesList, $idsData, $batchUpdateSales, $batchUpdateDeduction);
            if ($res == true)
            {
                //批量更新竞拍相关数据
                $this->batchUpdateAuctionData($rid, $batchUpdateSales, $batchUpdateDeduction);

                //分配发货格子墙
                $this->assignShelfGrid($gridData);
            }
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage());
        }

        //返回
        return true;
    }

    /**
     * 创建竞拍订单
     * @param array $bidGoods 中标商品数据
     * @param array $idsData 业务ID集合
     * @param array $batchUpdateSales 批量更新竞拍商品数据
     * @param array $batchUpdateDeduction 批量更新竞拍扣款记录数据
     * @return bool
     * @throws
     */
    private function createOrder(array $bidGoods, array $idsData, &$batchUpdateSales, &$batchUpdateDeduction)
    {
        Service::$logger->info(__CLASS__ . '-[创建竞拍订单]');

        //获取主商品数据
        $productDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $idsData['product']]], 'pid,bcode,salecost,supcost,stcwhs,inway,_id');

        //获取供应商数据
        $offerIds = array_values(array_unique($idsData['offer']));
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offerIds], 'tid' => 2], 'oid,exts,_id');

        //获取买家收货地址数据
        $crmAddressDict = [];
        $buyerIds = array_values(array_unique($idsData['buyer']));
        $crmAddressList = CrmAddressModel::M()->getList(['uid' => ['in' => $buyerIds], 'def' => 1]);
        foreach ($crmAddressList as $value)
        {
            $plat = $value['plat'];
            $uid = $value['uid'];
            $crmAddressDict["$plat:$uid"] = $value;
        }

        //销毁临时数据
        unset($idsData, $buyerIds, $crmAddressList);

        $time = time();
        $tempGoodsData = [];

        Service::$logger->info(__CLASS__ . '-[组装中标商品数据]');

        //组装中标商品数据
        foreach ($bidGoods as $key => $value)
        {
            $pid = $value['pid'];
            $plat = $value['plat'];
            $offer = $value['offer'];
            $luckplat = $value['luckplat'];
            $luckbuyer = $value['luckbuyer'];
            $luckname = $value['luckname'];
            $saleamt = $value['bprc'];
            $salecost = $productDict[$pid]['salecost'] ?? 0;
            $stcwhs = $productDict[$pid]['stcwhs'] ?? 0;
            $inway = $productDict[$pid]['inway'] ?? 0;
            $supprof = 0;
            $issup = 0;

            /*
             * 销售毛利说明
             * 供应商商品时：毛利 = 佣金 - 成本
             * 自有商品时：毛利 = 销售价 - 成本
             */
            if (isset($offerDict[$offer]) && $inway == 21)
            {
                $issup = 1;
                $supprof = $this->calculateOfferCommission($plat, $saleamt, $offerDict[$offer]);
                $profit = $supprof - $salecost;
            }
            else
            {
                $profit = $saleamt - $salecost;
            }

            //订单来源 明拍：1101、暗拍：1102
            $src = $value['mode'] == 1 ? 1101 : 1102;

            //订单商品数据
            $gid = IdHelper::generate();
            $tempGoodsData["$luckplat:$luckbuyer:$stcwhs"][] = [
                'gid' => $gid,
                'plat' => $luckplat,
                'tid' => 11,
                'src' => $src,
                'ostat' => 11,
                'otime' => $time,
                'offer' => $offer,
                'pid' => $pid,
                'bcode' => $productDict[$pid]['bcode'] ?? '',
                'yid' => $value['yid'],
                'sid' => $value['sid'],
                'bprc' => $saleamt,
                'scost1' => $salecost,
                'scost2' => $salecost,
                'supcost' => $productDict[$pid]['supcost'] ?? 0,
                'supprof' => $supprof,
                'profit1' => $profit,
                'profit2' => $profit,
                'bway' => $value['bway'],
                'issup' => $issup,
                'whs' => $stcwhs,
                'mtime' => $time,
                'atime' => $time,
                '_id' => $gid,
            ];

            //如果买家没有默认地址，则补充手机号码
            if (!isset($crmAddressDict["$luckplat:$luckbuyer"]) && Utility::isMobile($luckname))
            {
                $crmAddressDict["$luckplat:$luckbuyer"] = ['lnktel' => $luckname];
            }
        }

        //提取默认地址信息
        $getAddress = function ($plat, $buyer, $field) use ($crmAddressDict) {
            return $crmAddressDict["$plat:$buyer"][$field] ?? '';
        };

        //组装订单数据
        $assemblyOrderData = function ($plat, $buyer, $whs, &$goods) use ($getAddress, $time, &$batchUpdateSales, &$batchUpdateDeduction) {

            //订单号
            $oid = IdHelper::generate();
            $okey = $this->uniqueKeyLogic->getUniversal();

            //计算订单相关金额
            $oamt1 = 0; //自营商品金额
            $oamt2 = 0; //供应商商品金额
            $scost11 = 0; //自营商品成本
            $scost21 = 0; //供应商商品成本
            $profit11 = 0; //自有商品毛利
            $profit21 = 0; //供应商商品毛利
            $supprof = 0; //供应商商品佣金

            //循环商品数据处理数据
            foreach ($goods as $key => $value)
            {
                $sid = $value['sid'];
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
                $goods[$key]['okey'] = $okey;

                //组装批量更新竞拍商品数据
                $batchUpdateSales[] = ['sid' => $sid, 'luckodr' => $oid];

                //组装批量更新扣款记录数据
                $batchUpdateDeduction[] = ['sid' => $sid, 'uid' => $buyer, 'okey' => $okey];
            }

            //合计订单总金额和订单总成本
            $oamt = $oamt1 + $oamt2;
            $ocost = $scost11 + $scost21;

            //组装订单数据
            return [
                'oid' => $oid,
                'plat' => $plat,
                'buyer' => $buyer,
                'tid' => 11,
                'src' => $goods[0]['src'],
                'okey' => $okey,
                'qty' => count($goods),
                'oamt' => $oamt,
                'oamt1' => $oamt1,
                'oamt2' => $oamt2,
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
                'otime' => $time,
                'ostat' => 11,
                'paystat' => 1,
                'dlyway' => $getAddress($plat, $buyer, 'way') ?: 0,
                'recver' => $getAddress($plat, $buyer, 'lnker'),
                'rectel' => $getAddress($plat, $buyer, 'lnktel'),
                'recreg' => $getAddress($plat, $buyer, 'rgnid') ?: 0,
                'recdtl' => $getAddress($plat, $buyer, 'rgndtl'),
                'whs' => $whs,
                'mtime' => $time,
                'atime' => $time,
                '_id' => $oid,
            ];
        };

        Service::$logger->info(__CLASS__ . '-[组装竞拍订单数据]');

        //组装批量订单数据
        $batchOrderData = [];
        $batchGoodsData = [];
        foreach ($tempGoodsData as $key => $goods)
        {
            //中标平台、中标人、商品所在仓库
            list($plat, $buyer, $whs) = explode(':', $key);

            //19平台为一单一机，其他平台为一单多机
            if ($plat == 19)
            {
                foreach ($goods as $item)
                {
                    $item = [$item]; //以二维数组格式传参
                    $batchOrderData[] = $assemblyOrderData($plat, $buyer, $whs, $item);
                    $batchGoodsData = array_merge($batchGoodsData, $item);
                }
            }
            else
            {
                $batchOrderData[] = $assemblyOrderData($plat, $buyer, $whs, $goods);
                $batchGoodsData = array_merge($batchGoodsData, $goods);
            }
        }

        //销毁临时数据
        unset($bidGoods, $crmAddressDict, $tempGoodsData, $tempBuyerData);

        try
        {
            //开启事务
            Db::beginTransaction();

            //批量新增订单
            $res = OdrOrderModel::M()->inserts($batchOrderData);
            if ($res == false)
            {
                throw new AppException('批量新增订单失败', AppException::FAILED_INSERT);
            }
            $res = OdrGoodsModel::M()->inserts($batchGoodsData);
            if ($res == false)
            {
                throw new AppException('批量新增订单商品失败', AppException::FAILED_INSERT);
            }

            //提交事务
            Db::commit();
        }
        catch (\Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //输出日志
            Service::$logger->error($throwable->getMessage());

            //返回
            return false;
        }

        //todo 同步到旧系统，旧系统废弃时，不用此处理
        $this->tmpSyncLogic->syncInsertOrders($batchOrderData, $batchGoodsData, $productDict);

        //投递对应的业务节点
        foreach ($batchOrderData as $value)
        {
            switch ($value['plat'])
            {
                case 19: //小槌子手机拍卖
                case 21: //新新二手机
                    //投递对应业务节点
                    AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                        'node' => SmbNodeKeyData::ODR_OSTAT_11,
                        'args' => ['okey' => $value['okey']]
                    ]);
                    break;
            }
        }

        //返回
        return true;
    }

    /**
     * 计算供应商佣金
     * @param int $plat 平台ID
     * @param float $saleamt 销售金额
     * @param array $offerInfo 供应商信息
     * @return mixed
     */
    private function calculateOfferCommission(int $plat, float $saleamt, array $offerInfo)
    {
        //提取佣金比例和封顶值
        $extData = ArrayHelper::toArray($offerInfo['exts']);
        if (!isset($extData[$plat]))
        {
            //没有设置指定平台的佣金则默认使用新新的
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
     * 批量更新竞拍相关数据
     * @param string $rid 场次ID
     * @param array $batchUpdateSales 竞拍商品数据
     * @param array $batchUpdateDeduction 竞拍保证金扣款数据
     */
    private function batchUpdateAuctionData(string $rid, array $batchUpdateSales, array $batchUpdateDeduction)
    {
        Service::$logger->info(__CLASS__ . '-[批量更新竞拍相关数据]', [$rid]);

        //批量补充竞拍商品中标订单ID
        PrdBidSalesModel::M()->inserts($batchUpdateSales, true);

        //组装批量更新when条件
        $updateDeductionWhen = [];
        foreach ($batchUpdateDeduction as $value)
        {
            $updateDeductionWhen[] = "when sid='{$value['sid']}' and uid='{$value['uid']}' then '{$value['okey']}'";
        }
        $updateDeductionWhen = join(' ', $updateDeductionWhen);

        //批量补充保证金扣款中标订单号
        CrmDepositDeductionModel::M()->update(['rid' => $rid, 'stat' => 1], [], [
            'okey' => "(case $updateDeductionWhen else okey end)"
        ]);
    }

    /**
     * 分配货架格子（即发货格子墙）
     * @param array $data
     */
    private function assignShelfGrid(array $data)
    {
        //获取所有格子墙（格子数量很少，不用考虑性能）
        $gridList = PosGridModel::M()->getList([], '*', ['twhs' => 1, 'sort' => 1]);
        if ($gridList == false)
        {
            return;
        }

        Service::$logger->info(__CLASS__ . '-[分配仓位码]');

        //组装格子数据
        $idleGrid = [];
        $lockGrid = [];
        foreach ($gridList as $value)
        {
            $whs = $value['twhs'];
            $gkey = $value['gkey'];
            $stat = $value['stat'];
            $buyer = $value['buyer'];
            if ($stat == 1 && $buyer != '')
            {
                $lockGrid["$whs:$buyer"] = true;
                continue;
            }
            $idleGrid[$whs][] = $gkey;
        }

        $time = time();
        $batchUpdateGrid = [];
        $whsDict = [
            1 => 102, //明拍在优品发货
            2 => 103, //暗拍在非良品发货
        ];

        //处理数据
        foreach ($data as $key => $value)
        {
            //提取参数
            list($mode, $buyer) = explode(':', $key);

            //分配发货仓库
            $whs = $whsDict[$mode] ?? 0;

            //检查当前买家是否已经分配格子
            if (isset($lockGrid["$whs:$buyer"]))
            {
                continue;
            }

            //检查是否没有空闲的仓位
            if (empty($idleGrid[$whs]))
            {
                continue;
            }

            //分配空闲的格子给买家
            foreach ($idleGrid[$whs] as $idx => $grid)
            {
                //锁定格子，防止被重复使用
                if ($this->redis->setnx("assign_shelf_grid_$grid", $time, 60))
                {
                    //组装批量更新数据
                    $batchUpdateGrid[] = [
                        'gkey' => $grid,
                        'buyer' => $buyer,
                        'stat' => 1,
                        'mtime' => $time,
                    ];

                    //记录当前买家已分配格子
                    $lockGrid["$whs:$buyer"] = true;

                    //注销当前格子
                    unset($idleGrid[$whs][$idx]);
                    break;
                }
            }
        }

        //批量更新格子墙数据
        if ($batchUpdateGrid)
        {
            PosGridModel::M()->inserts($batchUpdateGrid, true);
        }
    }
}