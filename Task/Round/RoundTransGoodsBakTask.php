<?php
namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Exception\AppException;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdWaterModel;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\IdHelper;
use Swork\Service;

/**
 * 每天08:00把指定场次场次的商品上架到一口价
 * @package App\Module\Sale\Task\Round
 */
class RoundTransGoodsBakTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 指定来源的场次ID
     * @var string
     */
    private $fromRoundId = '5e5dbc505d9b1501a57f6823'; //一口价待转场ID

    /**
     * 每天指定时间点公开
     * @var string
     */
    private $publicTime = '09:30:00';

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     * @throws
     */
    function execute(array $data)
    {
        try
        {
            $this->timingTransGoods();
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error(__CLASS__, [$throwable->getCode(), $throwable->getMessage()]);
        }
        finally
        {
            return true;
        }
    }

    private function timingTransGoods()
    {
        //获取指定场次下的商品数据
        $fromSales = PrdBidSalesModel::M()
            ->join(PrdProductModel::M(), ['pid' => 'pid'])
            ->getList(['A.rid' => $this->fromRoundId], 'A.yid,A.bid,A.mid,A.mid,A.level,kprc,A.inway,B.pid,B._id', ['A.mid' => 1]);

        //打印日志
        Service::$logger->info('RoundTransGoodsTask [FromSalesCount: ' . count($fromSales) . ']');

        //无商品则不处理
        if ($fromSales == false)
        {
            return;
        }

        //获取符合转场条件的商品
        $transGoods = [];
        foreach ($fromSales as $item)
        {
            foreach ($item as $value)
            {
                if (empty($value))
                {
                    Service::$logger->error('RoundTransGoodsTask [商品不符合转场条件]', $item);
                    continue 2;
                }
            }
            $transGoods[] = $item;
        }

        $atime = time();
        $ptime = strtotime(date("Y-m-d " . $this->publicTime));
        if ($ptime < $atime)
        {
            $ptime = $atime;
        }

        //组装批量新增数据
        $batchShopData = [];
        $batchWaterData = [];
        $batchOldWaterData = [];
        foreach ($transGoods as $goods)
        {
            //加锁，防止重复转场
            if ($this->redis->setnx('round_trans_goods_' . $goods['pid'], $atime, 60) == false)
            {
                continue;
            }

            //新增一口价商品
            $sid = IdHelper::generate();
            $batchShopData[] = [
                'sid' => $sid,
                'pid' => $goods['pid'],
                'yid' => $goods['yid'],
                'bid' => $goods['bid'],
                'mid' => $goods['mid'],
                'level' => $goods['level'],
                'bprc' => $goods['kprc'],
                'stat' => 31,
                'inway' => $goods['inway'],
                'isatv' => 1,
                'away' => 4,
                'atime' => $atime,
                'ptime' => $ptime,
                '_id' => $sid
            ];

            //新增上架一口价流水
            $wid = IdHelper::generate();
            $batchWaterData[] = [
                'wid' => $wid,
                'tid' => 914,
                'pid' => $goods['pid'],
                'rmk' => '系统定时转场至一口价',
                'atime' => $atime,
                '_id' => $wid
            ];

            //新增老系统上架一口价流水
            $batchOldWaterData[] = [
                'pid' => $goods['_id'],
                'tid' => 914,
                'rmk' => '系统定时转场至一口价',
                'wtime' => $atime,
                '_id' => $wid,
            ];
        }
        if ($batchShopData == false)
        {
            return;
        }

        //打印日志
        Service::$logger->error('RoundTransGoodsTask [BatchShopDataCount: ' . count($batchShopData) . ']');

        //防止数据过多，采用分批插入方式
        $batchShopChunkData = array_chunk($batchShopData, 20);
        $batchWaterChunkData = array_chunk($batchWaterData, 20);
        $batchOldWaterChunkData = array_chunk($batchOldWaterData, 20);
        foreach ($batchShopChunkData as $idx => $batchData)
        {
            try
            {
                //开启事务
                Db::beginTransaction();

                //批量插入相关数据
                $res = PrdShopSalesModel::M()->inserts($batchData);
                if ($res == false)
                {
                    throw new AppException('RoundTransGoodsTask [批量转场至一口价失败]', AppException::FAILED_INSERT);
                }
                PrdWaterModel::M()->inserts($batchWaterChunkData[$idx]);

                //删除原场次商品
                $res = PrdBidSalesModel::M()->delete([
                    'rid' => $this->fromRoundId,
                    'pid' => array_column($batchData, 'pid'),
                ]);
                if ($res == false)
                {
                    throw new AppException('RoundTransGoodsTask [删除原场次商品失败]', AppException::FAILED_DELETE);
                }

                //提交事务
                Db::commit();
            }
            catch (\Throwable $throwable)
            {
                //回滚事务
                Db::rollback();

                //打印日志
                Service::$logger->error($throwable->getMessage(), $batchData);

                //不往下执行
                continue;
            }

            //Todo 新老系统过滤同步新增到老系统，过渡完删除这里
            \App\Model\Dnet\PrdWaterModel::M()->inserts($batchOldWaterChunkData[$idx]);
        }
    }
}