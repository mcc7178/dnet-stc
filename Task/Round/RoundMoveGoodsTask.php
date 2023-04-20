<?php
namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Exception\AppException;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdWaterModel;
use App\Model\Stc\StcStorageModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\IdHelper;
use Swork\Service;

/**
 * 把流标的商品转至一口价
 * @package App\Module\Sale\Task\Round
 */
class RoundMoveGoodsTask extends BeanCollector implements ActInterface
{
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
            Service::$logger->info(__CLASS__ . '-[执行任务]', $data);

            $sid = $data['sid'];
            $away = $data['away'];

            //获取竞拍商品数据
            $info = PrdBidSalesModel::M()->getRowById($sid, 'plat,pid,yid,bid,mid,level,sprc,kprc,stat,inway,infield');
            if ($info == false)
            {
                throw new AppException("竞拍商品不存在-[$sid]");
            }

            $pid = $info['pid'];
            $plat = $info['plat'];
            $stat = $info['stat'];
            $sprc = $info['sprc'];
            $kprc = $info['kprc'];
            $inway = $info['inway'];
            $infield = $info['infield'];
            $time = time();

            /*
             * 新新流标转场一口价规则
             * 1：新新竞拍场次
             * 2：属于公司自有的商品，根据inway判断
             * 3：非内部场次
             */
            if ($plat != 21 || !in_array($inway, [1, 3, 4, 5, 72, 73, 92, 93, 1613]) || $infield != 0)
            {
                throw new AppException("商品不符合上架到一口价条件-[$sid]");
            }
            if ($stat != 22)
            {
                throw new AppException("竞拍商品非流标状态不能上一口价-[$sid]");
            }
            if ($sprc <= 0 && $kprc <= 0)
            {
                throw new AppException("竞拍商品价格异常不能上一口价-[$sid]");
            }

            Service::$logger->info(__CLASS__ . '-[符合执行条件]', $data);

            /*
             * 流标转一口价销售价说明
             * 有秒杀价时：销售价=(秒杀价+起拍价)/2
             * 无秒杀价时：销售价=起拍价
             * 无起拍价时：销售价=秒杀价
             */
            if ($sprc > 0 && $kprc > 0)
            {
                $bprc = ceil((($sprc + $kprc) / 2));
            }
            else
            {
                $bprc = $sprc ? $sprc : $kprc;
            }

            Service::$logger->info(__CLASS__ . '-[新增一口价商品]', $data);

            //新增一口价商品
            $sid = IdHelper::generate();
            $res = PrdShopSalesModel::M()->insert([
                'sid' => $sid,
                'pid' => $pid,
                'yid' => $info['yid'],
                'bid' => $info['bid'],
                'mid' => $info['mid'],
                'level' => $info['level'],
                'bprc' => $bprc,
                'stat' => 31,
                'inway' => $info['inway'],
                'isatv' => 1,
                'away' => $away,
                'atime' => $time,
                'mtime' => $time,
                'ptime' => $time,
                '_id' => $sid
            ]);
            if ($res == false)
            {
                throw new AppException("竞拍流标转一口价失败-[$sid]");
            }

            //更新商品为竞拍中状态
            PrdProductModel::M()->updateById($pid, [
                'stcstat' => 31,
                'stctime' => $time,
            ]);
            StcStorageModel::M()->update(['pid' => $pid, 'stat' => 1], ['prdstat' => 31]);

            //新增上架一口价流水
            $wid = IdHelper::generate();
            PrdWaterModel::M()->insert([
                'wid' => $wid,
                'tid' => 914,
                'pid' => $pid,
                'rmk' => $res ? '竞拍流标转一口价成功' : '竞拍流标转一口价失败',
                'atime' => $time,
                '_id' => $wid
            ]);

            Service::$logger->info(__CLASS__ . '-[同步新增老系统上架一口价流水]', $data);

            //Todo 新老系统过滤同步新增到老系统，过渡完删除这里
            $oldPid = PrdProductModel::M()->getOneById($pid, '_id');
            if (is_numeric($oldPid))
            {
                \App\Model\Dnet\PrdWaterModel::M()->insert([
                    'pid' => $oldPid,
                    'tid' => 914,
                    'rmk' => $res ? '竞拍流标转一口价成功' : '竞拍流标转一口价失败',
                    'wtime' => $time,
                    '_id' => $wid,
                ]);
            }
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage());
        }

        //返回
        return true;
    }
}