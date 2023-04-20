<?php

namespace App\Module\Sale\Task;

use App\Model\Crm\CrmBuyerModel;
use App\Model\Prd\PrdBidPriceModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdShopSalesModel;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\TimerTask;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Server\Tasker;

/**
 * 定期补充crm_buyer表数据 -- 提供访问统计使用
 * @package App\Task
 */
class SuppDataTask extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 补充crm_buyer 数据
     * @TimerTask(10000)
     */
    public function start_fixed()
    {
        //每天23:50:50执行
        $time = time();
        $dotime = strtotime(date('Y-m-d 23:50:00'));
        if ($time < $dotime)
        {
            return;
        }

        // 检查是否已经运行过
        $exist = $this->redis->get('repair_crm_buyer_data');
        if ($exist)
        {
            return;
        }
        $this->redis->set('repair_crm_buyer_data', 1, 3600);

        //投递任务
        Tasker::deliver(SuppDataTask::class, 'getFirstBid');
        Tasker::deliver(SuppDataTask::class, 'getFirstOdr');
        Tasker::deliver(SuppDataTask::class, 'getLastBid');
        Tasker::deliver(SuppDataTask::class, 'getLastOdr');
    }

    /**
     * 更新首拍时间
     */
    public function getFirstBid()
    {
        $where = [
            'plat' => 21,
            'firstbid' => 0,
            'bidbvs' => ['>' => 0],
        ];
        $crm_buyer = CrmBuyerModel::M()->getList($where, 'acc');
        $accs = ArrayHelper::map($crm_buyer, 'acc');
        if (count($accs) > 0)
        {
            foreach ($accs as $value)
            {
                $where = [
                    'buyer' => $value,
                    'bprc' => ['>' => 0],
                    'plat' => 21,
                ];
                $firstbid = PrdBidPriceModel::M()->getOne($where, 'btime', ['btime' => 1]);
                if ($firstbid)
                {
                    CrmBuyerModel::M()->update(['acc' => $value], ['firstbid' => $firstbid]);
                }
            }
        }
    }

    /**
     * 更新首单时间
     */
    public function getFirstOdr()
    {
        $where = [
            'plat' => 21,
            'firstodr' => 0,
            'payodrs' => ['>' => 0],//付款订单>0
        ];
        $crm_buyer = CrmBuyerModel::M()->getList($where, 'acc');
        $accs = ArrayHelper::map($crm_buyer, 'acc');
        if (count($accs) > 0)
        {
            foreach ($accs as $item)
            {
                //竞拍
                $where = [
                    'luckbuyer' => $item,
                    'stat' => 21,
                    'plat' => 21
                ];
                $firstbidodr = PrdBidSalesModel::M()->getOne($where, 'lucktime', ['lucktime' => 1]);

                //一口价 - 已销售
                $where = [
                    'luckbuyer' => $item,
                    'stat' => 33,
                ];
                $firstshopodr = PrdShopSalesModel::M()->getOne($where, 'lucktime', ['lucktime' => 1]);

                $firstodr = 0;
                if ($firstbidodr > 0 && $firstshopodr <= 0)
                {
                    $firstodr = $firstbidodr;
                }
                if ($firstbidodr <= 0 && $firstshopodr > 0)
                {
                    $firstodr = $firstshopodr;
                }
                if ($firstbidodr > 0 && $firstshopodr > 0)
                {
                    $firstodr = $firstbidodr >= $firstshopodr ? $firstshopodr : $firstbidodr;
                }
                if ($firstodr > 0)
                {
                    CrmBuyerModel::M()->update(['acc' => $item], ['firstodr' => $firstodr]);
                }
            }
        }
    }

    /**
     * 更新最后拍时间
     */
    public function getLastBId()
    {
        //获取今日竞拍的用户
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = $stime + 86399;
        $where = [
            'plat' => 21,
            'btime' => ['between' => [$stime, $etime]],
            '$group' => 'buyer',
        ];
        $prdPrice = PrdBidPriceModel::M()->getDict('buyer', $where, 'btime', ['btime' => -1]);
        if ($prdPrice)
        {
            foreach ($prdPrice as $k => $v)
            {
                $where = [
                    'acc' => $k,
                    'plat' => 21
                ];
                $data['lastbid'] = $v['btime'];
                CrmBuyerModel::M()->update($where, $data);
            }
        }
    }

    /**
     * 更新最后一单时间
     */
    public function getLastOdr()
    {
        //获取今日下单的用户
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = $stime + 86399;

        //竞拍
        $where = [
            'plat' => 21,
            'stat' => 21,
            'lucktime' => ['between' => [$stime, $etime]],
            '$group' => 'luckbuyer'
        ];
        $prdbidSales = PrdBidSalesModel::M()->getDict('luckbuyer', $where, 'lucktime', ['lucktime' => -1]);

        //一口价
        $where = [
            'stat' => 33,
            'lucktime' => ['between' => [$stime, $etime]],
            '$group' => 'luckbuyer'
        ];
        $pidshopsales = PrdShopSalesModel::M()->getDict('luckbuyer', $where, 'lucktime', ['lucktime' => -1]);
        if ($prdbidSales)
        {
            foreach ($prdbidSales as $i => $p)
            {
                $where = [
                    'acc' => $i,
                    'plat' => 21,
                ];
                $bidlucktime = $p['lucktime'];
                $shoplucktime = $pidshopsales[$i]['lucktime'] ?? 0;
                $data['lastodr'] = $bidlucktime > $shoplucktime ? $bidlucktime : $shoplucktime;
                CrmBuyerModel::M()->update($where, $data);
            }
        }
    }
}