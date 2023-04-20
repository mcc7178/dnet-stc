<?php

namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Dnet\PrdFavoriteModel;
use App\Model\Dnet\PrdRoundModel;
use App\Model\Dnet\PrdSalesModel;
use App\Model\Prd\PrdBidFavoriteModel;
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
 * 竞拍场次开始后处理任务
 * @package App\Module\Sale\Task\Round
 */
class RoundStartAfterTask extends BeanCollector implements ActInterface
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
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     */
    function execute(array $data)
    {
        try
        {
            $time = time();

            //加锁，锁定1秒
            $lockKey = "round_start_after_$time";
            if ($this->redis->setnx($lockKey, $time, 1) == false)
            {
                return true;
            }

            //数据条件
            $where = [
                'stat' => 12,
                'stime' => ['between' => [$time - 3600, $time]]
            ];

            //获取需要开场的场次数据
            $rounds = PrdBidRoundModel::M()->getList($where, 'rid,plat,mode,_id');
            if ($rounds == false)
            {
                return true;
            }

            //提取场次ID
            $rids = array_column($rounds, 'rid');

            //更新场次相关数据为竞拍中
            $updateWhere = ['rid' => ['in' => $rids]];
            $updateData = ['stat' => 13];
            PrdBidRoundModel::M()->update($updateWhere, $updateData);
            PrdBidSalesModel::M()->update($updateWhere, $updateData);
            PrdBidFavoriteModel::M()->update($updateWhere, $updateData);

            //同步老系统开场（用于新老系统过渡）
            $this->syncOldRoundData($rounds);

            //更新闲鱼拍卖订单商品为销售中（如果有数据）
            $this->updateSmbSheet($rids);

            //更新闲鱼寄卖订单商品为销售中（如果有数据）
            $this->updateXianyuSheet($rids);

            //解锁
            $this->redis->del($lockKey);
        }
        catch (\Throwable $throwable)
        {
            //输出错误日志
            Service::$logger->error($throwable->getMessage());
        }

        //返回
        return true;
    }

    /**
     * 用于新老系统过渡
     * 迁移完老系统可删除这段代码
     * @param $rounds
     */
    private function syncOldRoundData($rounds)
    {
        //提取场次ID
        $rids = array_column($rounds, '_id');

        //更新场次相关数据为竞拍中
        $updateWhere = ['rid' => ['in' => $rids], 'stat' => 12];
        PrdRoundModel::M()->update($updateWhere, ['stat' => 13]);
        PrdSalesModel::M()->update($updateWhere, ['stat' => 13]);

        //更新商品关注数据为竞拍中
        $updateWhere = ['rid' => ['in' => $rids], 'stat' => 1];
        PrdFavoriteModel::M()->update($updateWhere, ['stat' => 2]);
    }

    /**
     * 开场后，更新闲鱼拍卖订单为销售中
     * @param array $rids
     */
    private function updateSmbSheet(array $rids)
    {
        //获取场次商品
        $sales = PrdBidSalesModel::M()->getList(['rid' => ['in' => $rids]], 'pid');
        if (empty($sales))
        {
            return;
        }

        //更新商品为销售中
        $pids = array_column($sales, 'pid');
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], [
            'stcstat' => 31,
            'stctime' => time()
        ], ['upshelfs' => 'upshelfs+1']);
        StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 31]);

        //获取闲鱼拍卖的商品 - 避免流拍商品重新更新状态 recstat=61
        $where = [
            'pid' => ['in' => $pids],
            'plat' => 19,
            'recstat' => 61
        ];
        $products = PrdProductModel::M()->getList($where, 'pid,oid');
        if (empty($products))
        {
            return;
        }

        //更新为销售中
        $time = time();
        $oids = array_column($products, 'oid');
        $pids = array_column($products, 'pid');
        PrdOrderModel::M()->update(['oid' => ['in' => $oids]], ['recstat' => 62, 'rectime62' => $time]);
        PrdGoodsModel::M()->update(['gid' => ['in' => $pids]], ['recstat' => 62, 'rectime62' => $time]);
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['recstat' => 62]);

        //投递业务节点
        foreach ($oids as $oid)
        {
            AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                'node' => SmbNodeKeyData::PRD_RECSTAT_62,
                'args' => ['oid' => $oid]
            ]);
        }
    }

    /**
     * 开场后，更新闲鱼寄卖订单为销售中
     * @param array $rids
     */
    private function updateXianyuSheet(array $rids)
    {
        //获取场次商品
        $saleDict = PrdBidSalesModel::M()->getDict('pid', ['rid' => ['in' => $rids]], 'sid,pid,stime,etime');
        if (empty($saleDict))
        {
            return;
        }
        $pids = array_column($saleDict, 'pid');

        //获取闲鱼拍卖的商品 - 避免流拍商品重新更新状态 recstat=61
        $where = [
            'pid' => ['in' => $pids],
            'plat' => 161,
            'recstat' => 61
        ];
        $products = PrdProductModel::M()->getList($where, 'pid,oid');
        if (empty($products))
        {
            return;
        }

        //更新为销售中
        $time = time();
        $oids = array_column($products, 'oid');
        $pids = array_column($products, 'pid');
        PrdOrderModel::M()->update(['oid' => ['in' => $oids]], ['recstat' => 62, 'rectime62' => $time]);
        PrdGoodsModel::M()->update(['gid' => ['in' => $pids]], ['recstat' => 62, 'rectime62' => $time]);
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['recstat' => 62]);

        //投递业务节点 - 场次开场，履约到闲鱼
        foreach ($pids as $pid)
        {
            AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_round_start', [
                'sid' => $saleDict[$pid]['sid'],
            ]);
        }
    }
}