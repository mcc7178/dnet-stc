<?php
namespace App\Module\Sale\Task\Order;

use App\Amqp\ActInterface;
use App\Lib\Utility;
use App\Model\Odr\OdrOrderModel;
use Swork\Bean\BeanCollector;
use Swork\Configer;
use Swork\Helper\ArrayHelper;
use Swork\Service;

class SyncTask extends BeanCollector implements ActInterface
{
    /**
     * 处理支付成功订单
     * @param array $data
     * @return bool|void
     * @throws
     */
    public function execute(array $data)
    {
        try
        {
            $this->handle(11);
            $this->handle(21);
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage(), [__CLASS__]);
        }

        //返回
        return true;
    }

    private function handle(int $ostat)
    {
        //时间条件
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = time();

        //数据条件
        $where = [
            'ostat' => $ostat,
            'otime' => ['between' => [$stime, $etime]],
            'tid' => ['in' => [11, 12]],
        ];

        //获取待支付、待发货订单
        $newOrders = OdrOrderModel::M()->getList($where, 'ostat,_id');
        if ($newOrders == false)
        {
            return;
        }

        //提取老系统订单ID
        $_ids = ArrayHelper::map($newOrders, '_id');

        //获取老系统数据
        $oldOrders = \App\Model\Dnet\OdrOrderModel::M()->getDict('oid', ['oid' => ['in' => $_ids]], 'oid,ostat');

        //提取状态不一样的订单
        $syncIds = [];
        foreach ($newOrders as $value)
        {
            $oldId = $value['_id'];
            if (isset($oldOrders[$oldId]) && $oldOrders[$oldId]['ostat'] != $value['ostat'])
            {
                $syncIds[] = $oldId;
            }
        }
        if ($syncIds == false)
        {
            return;
        }
        $syncIds = join(',', $syncIds);

        //请求老系统重新同步一次
        $dnet2 = Configer::get('common:dnethost');
        $res = Utility::curl("$dnet2/pub/sync.html?do=order", ['oids' => $syncIds, 'ostat' => $ostat]);

        //输出结果
        Service::$logger->info("修复订单结果：$res");
    }
}