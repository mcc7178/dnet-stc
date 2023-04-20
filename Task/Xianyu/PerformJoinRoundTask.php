<?php

namespace App\Module\Sale\Task\Xianyu;

use App\Amqp\ActInterface;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdWaterModel;
use App\Module\Qto\Helper\XianyuHelper;
use Swork\Bean\BeanCollector;
use Swork\Helper\IdHelper;
use Swork\Service;

/**
 * 闲鱼寄卖商品加入场次 - 同步到闲鱼
 * @package App\Module\Sale\Task\Xianyu
 */
class PerformJoinRoundTask extends BeanCollector implements ActInterface
{
    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     */
    function execute(array $data)
    {
        $sid = $data['sid'] ?? '';
        if (empty($sid))
        {
            return true;
        }

        try
        {
            //加入场次 - 履约到闲鱼
            $this->performToXianyu($sid);
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage(), [__CLASS__]);
        }

        //返回
        return true;
    }

    /**
     * 履约到闲鱼【201:一次挂拍】
     * @param string $sid
     */
    private function performToXianyu(string $sid)
    {
        //获取场次商品数据
        $sale = PrdBidSalesModel::M()->getRowById($sid, 'rid,pid,stime,etime,ptime,tid');
        if (empty($sale))
        {
            return;
        }

        //获取回收订单信息 - rectime63>0二次上拍不处理
        $order = PrdProductModel::M()->join(PrdOrderModel::M(), ['oid' => 'oid'])
            ->getRow(['A.pid' => $sale['pid']], 'B.oid,B.thrsn,B.plat,B.recstat,B.rectime62,B.rectime63');
        if ($order['plat'] != 161 || $order['rectime63'] > 0)
        {
            return;
        }

        //交易已结束状态不能履约
        if (in_array($order['recstat'], [7, 8, 9, 10, 11]))
        {
            return;
        }

        //调用接口 - 检查订单状态
        $xianyuOrder = XianyuHelper::postEsc('/cons/consignment/order_get', ['biz_order_id' => $order['thrsn']]);
        $result = $xianyuOrder['result']['module'] ?? [];
        if (isset($result['order_status']) && $result['order_status'] == 20)
        {
            //已竞拍中，无须挂拍
            return;
        }

        //特卖场开场时间为公开时间
        if ($sale['tid'] == 2 && $sale['ptime'] > 0)
        {
            $sale['stime'] = $sale['ptime'];
        }

        //履约【一次挂拍】
        $args = [
            'biz_order_id' => $order['thrsn'],
            'order_status' => 3,//已质检
            'order_sub_status' => 201,//一次挂拍
            'attribute' => json_encode([
                'auction_id' => $sale['rid'],//拍卖订单号
                'end_time' => date('Y-m-d H:i:s', $sale['stime']),//竞拍开始时间
            ])
        ];
        $res = XianyuHelper::postEsc('/cons/consignment/order_perform', $args);
        if (empty($res['result']) || $res['result'] == 'false')
        {
            //记录流水
            $rmk = '【一次挂拍】履约失败：' . json_encode($res, JSON_UNESCAPED_UNICODE);
            $data = [
                'wid' => IdHelper::generate(),
                'tid' => 201,
                'oid' => $order['oid'],
                'rmk' => $rmk,
                'atime' => time(),
            ];
            PrdWaterModel::M()->insert($data);
        }
    }
}