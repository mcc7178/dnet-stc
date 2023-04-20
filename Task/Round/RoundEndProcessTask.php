<?php

namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Crm\CrmDepositDeductionModel;
use App\Model\Prd\PrdBidPriceModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdGoodsModel;
use App\Model\Prd\PrdOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Smb\Data\SmbNodeKeyData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Service;

/**
 * 竞拍场次结束后处理任务
 * 1：流标商品上架一口价
 * 2：恢复未中标用户保证金
 * 3：更新相关商品状态
 * 4：其他
 * @package App\Module\Sale\Task\Round
 */
class RoundEndProcessTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject("amqp_realtime_task")
     * @var Amqp
     */
    private $amqp_realtime;

    /**
     * @Inject("amqp_message_task")
     * @var Amqp
     */
    private $amqp_message;

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

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

            //加锁，锁定一个小时，处理完也不解锁
            $lockKey = "round_end_process_$rid";
            if ($this->redis->setnx($lockKey, $time, 3600) == false)
            {
                Service::$logger->error("场次结束处理业务时加锁失败-[$rid]");

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
                Service::$logger->error("场次状态不允许操作-[$rid-{$roundInfo['stat']}]");

                return true;
            }
            if ((time() - $roundInfo['etime']) > 1800)
            {
                Service::$logger->error("场次超出可操作时间范围-[$rid]");

                return true;
            }

            Service::$logger->info(__CLASS__ . '-[符合执行条件]', $data);

            //获取场次商品数据（防止场次结束时没有更新成功，这里增加竞拍状态条件）
            $salesList = PrdBidSalesModel::M()->getList(['rid' => $rid, 'stat' => ['in' => [21, 22]]]);
            if ($salesList == false)
            {
                return true;
            }

            //恢复买家冻结的保证金
            $this->restoreBuyerDeposit($rid, $salesList);

            //计算买家出价排名
            $this->calculateBidRank($rid);

            $bidGoods = []; //中标商品ID
            $noBidGoods = []; //流标商品ID
            $noBidMoveGoods = []; //流标转场商品ID
            $smbOrderGoods = []; //拍卖订单商品ID
            $xianyuSmbOrderGoods = []; //闲鱼寄卖拍卖订单商品ID
            $xianyuSmbNoBidGoods = []; //闲鱼寄卖流标商品ID

            //循环提取各个业务需要的数据
            foreach ($salesList as $item)
            {
                $sid = $item['sid'];
                $pid = $item['pid'];
                $plat = $item['plat'];
                $stat = $item['stat'];
                $inway = $item['inway'];
                $infield = $item['infield'];

                //如果中标则按竞拍平台创建订单
                if ($stat == 21)
                {
                    $bidGoods[] = $pid;
                    if ($inway == 91)
                    {
                        $smbOrderGoods[] = $pid;
                    }
                    if ($inway == 1611)
                    {
                        $xianyuSmbOrderGoods[] = $pid;

                        //闲鱼寄卖-更新为中标（不付款）
                        AmqpQueue::deliver($this->amqp_realtime, 'smb_xianyu_goods_sold', [
                            'pid' => $pid,
                            'type' => 1,
                        ]);
                    }
                    continue;
                }

                //流标商品
                $noBidGoods[] = $pid;

                //闲鱼拍卖商品流标需兜底（不限上架平台）
                if ($inway == 91)
                {
                    AmqpQueue::deliver($this->amqp_realtime, 'smb_goods_sold', [
                        'pid' => $pid,
                        'type' => 2,
                    ]);
                    continue;
                }

                //闲鱼寄卖流标兜底
                if ($inway == 1611)
                {
                    $xianyuSmbNoBidGoods[] = $pid;

                    continue;
                }

                /*
                 * 新新流标转场一口价规则 - 2020-09-14 闲鱼寄卖流标不自动上架
                 * 1：新新竞拍场次
                 * 2：属于公司自有的商品，根据inway判断
                 * 3：非内部场次
                 */
                if ($plat == 21 && in_array($inway, [1, 3, 4, 5, 72, 73, 92, 93, 1613]) && $infield == 0)
                {
                    $noBidMoveGoods[] = $sid;
                    continue;
                }
            }

            //更新拍卖订单状态
            $this->updateSmbOrderStat($smbOrderGoods);

            //更新闲鱼寄卖数据拍卖订单状态
            $this->updateXianyuSmbOrderStat($xianyuSmbOrderGoods);

            //更新商品竞拍状态
            $this->updateProductStat($bidGoods, 32);
            $this->updateProductStat($noBidGoods, 33);

            //更新场次商品中标数、流标数
            PrdBidRoundModel::M()->updateById($rid, ['winbids' => count($bidGoods), 'nobids' => count($noBidGoods)]);

            //新新竞拍流标商品转场一口价
            foreach ($noBidMoveGoods as $value)
            {
                AmqpQueue::deliver($this->amqp_realtime, 'sale_round_move_goods', ['sid' => $value, 'away' => 3]);
            }

            //闲鱼寄卖流标兜底
            foreach ($xianyuSmbNoBidGoods as $pid)
            {
                AmqpQueue::deliver($this->amqp_realtime, 'smb_xianyu_goods_sold', [
                    'pid' => $pid,
                    'type' => 2,
                ]);
            }
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage(), [__CLASS__, $data]);
        }

        //返回
        return true;
    }

    /**
     * 同步更新拍卖订单
     * @param array $goods
     */
    private function updateSmbOrderStat(array $goods)
    {
        if ($goods == false)
        {
            return;
        }

        Service::$logger->info(__CLASS__ . '-[更新拍卖订单状态]');

        $time = time();
        $oids = [];
        $pids = [];

        //获取smb订单商品数据多一步检查，防止流拍兜底的商品被重复更新
        $products = PrdProductModel::M()->getList(['pid' => ['in' => $goods], 'plat' => 19, 'inway' => 91], 'pid,oid,recstat');
        foreach ($products as $value)
        {
            if ($value['recstat'] == 62)
            {
                $oids[] = $value['oid'];
                $pids[] = $value['pid'];
            }
        }

        //更新订单为等待买家付款状态
        if (count($oids) > 0)
        {
            PrdOrderModel::M()->update(['oid' => ['in' => $oids]], ['recstat' => 63, 'rectime63' => $time]);
            PrdGoodsModel::M()->update(['gid' => ['in' => $pids]], ['recstat' => 63, 'rectime63' => $time]);
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['recstat' => 63]);

            //投递业务节点
            foreach ($oids as $oid)
            {
                AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                    'node' => SmbNodeKeyData::PRD_RECSTAT_63,
                    'args' => ['oid' => $oid],
                ]);
            }
        }
    }

    /**
     * 同步更新拍卖订单
     * @param array $goods
     */
    private function updateXianyuSmbOrderStat(array $goods)
    {
        if ($goods == false)
        {
            return;
        }

        Service::$logger->info(__CLASS__ . '-[更新拍卖订单状态]');

        $time = time();
        $oids = [];
        $pids = [];

        //获取smb订单商品数据多一步检查，防止流拍兜底的商品被重复更新
        $products = PrdProductModel::M()->getList(['pid' => ['in' => $goods], 'plat' => 161, 'inway' => 1611], 'pid,oid,recstat');
        foreach ($products as $value)
        {
            if ($value['recstat'] == 62)
            {
                $oids[] = $value['oid'];
                $pids[] = $value['pid'];
            }
        }

        //更新订单为等待买家付款状态
        if (count($oids) > 0)
        {
            PrdOrderModel::M()->update(['oid' => ['in' => $oids]], ['recstat' => 63, 'rectime63' => $time]);
            PrdGoodsModel::M()->update(['gid' => ['in' => $pids]], ['recstat' => 63, 'rectime63' => $time]);
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['recstat' => 63]);
        }
    }

    /**
     * 更新商品竞拍状态
     * @param array $goods 商品ID
     * @param int $stat 商品状态 32：中标、33：流标
     */
    private function updateProductStat(array $goods, int $stat)
    {
        if ($goods == false)
        {
            return;
        }

        //组装更新数据
        $prdUpdateData = ['stcstat' => $stat, 'stctime' => time()];
        $prdDirectData = [];
        if ($stat == 33)
        {
            $prdDirectData['nobids'] = 'nobids+1';
        }
        $stcUpdateData = ['prdstat' => $stat];

        //切片分批更新数据
        $chunk = array_chunk($goods, 50);
        foreach ($chunk as $value)
        {
            PrdProductModel::M()->update(['pid' => ['in' => $value]], $prdUpdateData, $prdDirectData);
            StcStorageModel::M()->update(['pid' => ['in' => $value], 'stat' => 1], $stcUpdateData);
        }
    }

    /**
     * 恢复买家保证金
     * 恢复条件：必须是出价且未中标，如果中标了暂时不能恢复
     * @param string $rid 场次ID
     * @param array $salesList 场次商品
     * @throws
     */
    private function restoreBuyerDeposit(string $rid, array $salesList)
    {
        Service::$logger->info(__CLASS__ . '-[恢复买家保证金]', [$rid]);

        $depositDeduction = CrmDepositDeductionModel::M()->getList(['rid' => $rid, 'stat' => 1], 'did,uid,sid');
        if ($depositDeduction)
        {
            $tempDict = array_column($salesList, 'luckbuyer', 'sid');
            $tempData = [];
            foreach ($depositDeduction as $item)
            {
                $itemSid = $item['sid'];
                if (isset($tempDict[$itemSid]) && $tempDict[$itemSid] != $item['uid'])
                {
                    $tempData[$item['uid']][] = $item['did'];
                }
            }
            foreach ($tempData as $item)
            {
                AmqpQueue::deliver($this->amqp_realtime, 'crm_deposit_restore', ['did' => $item, 'src' => 10101]);
            }
            unset($depositDeduction, $tempDict);
        }
    }

    /**
     * 计算买家出价排名
     * 注意：这里临时使用原始SQL更新出价排名，后续需要优化高级SQL计算
     * @param string $rid
     */
    private function calculateBidRank(string $rid)
    {
        Service::$logger->info(__CLASS__ . '-[计算买家出价排名]', [$rid]);

        $updateRankSql = "update prd_bid_price as p1,(select cid,(select count(*)+1 from prd_bid_price where sid=p1.sid and stat in(1,2) and bprc>p1.bprc) as rank from  prd_bid_price as p1 where rid='$rid' and stat in(1,2)) as p2 set p1.rank=p2.rank where p1.cid=p2.cid";
        PrdBidPriceModel::M()->doQuery($updateRankSql);
    }
}