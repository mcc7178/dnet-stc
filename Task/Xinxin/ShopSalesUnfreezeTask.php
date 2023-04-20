<?php

namespace App\Module\Sale\Task\Xinxin;

use App\Model\Prd\PrdShopSalesModel;
use App\Module\Api\Data\V2\FixedData;
use App\Traits\RedisQueueTrait;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Service;

/**
 * 解冻一口价商品定时任务
 * @package App\Task
 */
class ShopSalesUnfreezeTask extends BeanCollector
{
    public function execute(array $data)
    {
        try
        {
            $this->handleTask();
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage());
        }
        return true;
    }

    /**
     * 解冻超时未去支付的一口价商品
     */
    private function handleTask()
    {
        $time = time();
        $etime = $time - FixedData::SHOP_SALES_UNFREEZE_TIME;
        $stime = $etime - 86400;

        //解冻商品查询条件
        $where = [
            'stat' => 32,
            'luckodr' => '',
            '$group' => ['luckbuyer'],
            '$having' => [':maxtime' => ['between' => [$stime, $etime]]],
        ];

        //查询商品数据
        $list = PrdShopSalesModel::M()->getList($where, 'sid,luckbuyer,MAX(lucktime) maxtime', ['maxtime' => -1], 500);
        if (empty($list))
        {
            Service::$logger->info('当前无待解冻的一口价商品');
            return;
        }

        //商品id
        $sids = ArrayHelper::map($list, 'sid');

        //更新数据
        $updateData = [
            'stat' => 31,
            'luckbuyer' => '',
            'lucktime' => 0,
            'luckname' => '',
            'luckrgn' => 0,
            'luckodr' => '',
            'mtime' => $time,
        ];

        //分批解冻超时商品
        $chunkSids = array_chunk($sids, 50);
        foreach ($chunkSids as $item)
        {
            PrdShopSalesModel::M()->update(['sid' => $item], $updateData);
        }
    }
}