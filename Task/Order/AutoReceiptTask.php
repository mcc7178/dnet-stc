<?php

namespace App\Module\Sale\Task\Order;

use App\Amqp\ActInterface;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Stc\StcLogisticsModel;
use Swork\Bean\BeanCollector;
use Swork\Db\Db;
use Swork\Service;

class AutoReceiptTask extends BeanCollector implements ActInterface
{
    /**
     * 处理自动确认收货（默认用户签收15天后）
     * @param array $data
     * @return bool
     * @throws
     */
    public function execute(array $data)
    {
        try
        {
            //处理逻辑
            $this->handle(5);
            $this->handle(3);
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage(), [__CLASS__]);
        }

        //返回
        return true;
    }

    /**
     * 处理逻辑
     * @param int $stat 发货单状态
     */
    private function handle(int $stat)
    {
        $todayTime = strtotime(date('Y-m-d 00:00:00'));
        $etime = strtotime('-16 days', $todayTime) + 86399;
        $stime = strtotime('-20 days', $todayTime);
        $time = time();

        //获取用户已经签收时间或发货时间超过15天的发货单
        $where = [
            'lstat' => $stat,
            'ltime' . $stat => ['between' => [$stime, $etime]],
        ];
        $logisticsList = StcLogisticsModel::M()->getList($where, 'lid,lkey,tid');
        if (count($logisticsList) == 0)
        {
            return;
        }

        //销售订单物流单号
        $saleOrderLogistics = [];

        //组装批量更新发货物流单数据
        $batchUpdateLogistics = [];
        foreach ($logisticsList as $value)
        {
            $batchUpdateLogistics[] = [
                'lid' => $value['lid'],
                'lstat' => 6,
                'ltime6' => $time,
            ];

            if ($value['tid'] == 3)
            {
                $saleOrderLogistics[] = $value['lkey'];
            }
        }

        //批量更新仓库发货单状态
        StcLogisticsModel::M()->inserts($batchUpdateLogistics, true);

        //如果不是没有销售发货单号数据则返回
        if ($saleOrderLogistics == false)
        {
            return;
        }

        //获取待发货的订单商品数据
        $where = [
            'ostat' => 22,
            'dlykey' => ['in' => $saleOrderLogistics],
        ];
        $goodsList = OdrGoodsModel::M()->getList($where, 'gid,okey');
        if (count($goodsList) == 0)
        {
            return;
        }

        $okeys = [];
        $batchUpdateGoods = [];

        //组装批量更新订单商品数据
        foreach ($goodsList as $value)
        {
            $okeys[] = $value['okey'];
            $batchUpdateGoods[] = [
                'gid' => $value['gid'],
                'ostat' => 23,
                'mtime' => $time,
            ];
        }

        //去除重复订单号
        $okeys = array_values(array_unique($okeys));

        try
        {
            //开启事务
            Db::beginTransaction();

            //更新订单状态
            OdrOrderModel::M()->update(['okey' => ['in' => $okeys], 'ostat' => 22], [
                'ostat' => 23,
                'otime23' => $time,
                'mtime' => $time,
            ]);
            OdrGoodsModel::M()->inserts($batchUpdateGoods, true);

            //提交事务
            Db::commit();
        }
        catch (\Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //输出异常日志
            Service::$logger->error($throwable->getMessage(), $okeys);
        }
    }
}