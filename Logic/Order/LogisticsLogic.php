<?php
namespace App\Module\Sale\Logic\Order;

use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Stc\StcLogisticsModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Helper\IdHelper;

/**
 * 销售单付款成功后创建发货单
 * @package App\Module\Sale\Logic\Order
 */
class LogisticsLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * 新增发货物流订单
     * @param array $orderList 订单列表
     * @throws
     */
    public function create(array $orderList)
    {
        //截止6点前付款当天发货
        $deadline = strtotime(date('Ymd 18:00:00'));

        $updateOrders = [];
        $newLogistics = [];
        foreach ($orderList as $key => $order)
        {
            $tid = $order['tid'];
            $plat = $order['plat'];
            $buyer = $order['buyer'];
            $payTime = $order['paytime'];
            $whs = $order['whs'];
            $lgKey = '';

            //非指定订单类型，无需生成发货单
            if (!in_array($tid, [11, 12, 31, 33, 35]))
            {
                continue;
            }

            //检查是否需要合并发货单（仅限新新、小槌子的竞拍订单和一口价订单）
            if (in_array($plat, [21, 22]) && in_array($tid, [11, 12]))
            {
                $lgWhere = ['whs' => $whs, 'buyer' => $buyer, 'lstat' => 1];
                $lgCols = 'lkey,recver,rectel,recreg,recdtl,lway as dlyway';
                $logisticsList = StcLogisticsModel::M()->getList($lgWhere, $lgCols);
                foreach ($logisticsList as $value)
                {
                    $merge = true;
                    foreach (['recver', 'rectel', 'recreg', 'recdtl', 'dlyway'] as $field)
                    {
                        if (trim($order[$field]) != trim($value[$field]))
                        {
                            $merge = false;
                            break;
                        }
                    }
                    if ($merge && $payTime <= $deadline)
                    {
                        $lgKey = $value['lkey'];
                        break;
                    }
                }
            }

            //创建新发货单
            if ($lgKey == '')
            {
                //发货单号
                $lgKey = $this->uniqueKeyLogic->getStcLG();

                //组装发货单数据
                $newLogistics[] = [
                    'lid' => IdHelper::generate(),
                    'lkey' => $lgKey,
                    'plat' => $plat,
                    'whs' => $whs,
                    'tid' => 3,
                    'recver' => $order['recver'],
                    'rectel' => $order['rectel'],
                    'recreg' => $order['recreg'],
                    'recdtl' => $order['recdtl'],
                    'lway' => $order['dlyway'],
                    'lstat' => 1,
                    'ltime1' => time(),
                    'buyer' => $buyer,
                ];
            }

            //组装需要更新的订单
            $updateOrders[] = [
                'okey' => $order['okey'],
                'lgKey' => $lgKey,
            ];
        }

        //新增发货单
        if (count($newLogistics) > 0)
        {
            StcLogisticsModel::M()->inserts($newLogistics);
        }

        //关联订单发货单号
        foreach ($updateOrders as $item)
        {
            $okey = $item['okey'];
            $lgKey = $item['lgKey'];
            OdrOrderModel::M()->update(['okey' => $okey], ['dlykey' => $lgKey]);
            OdrGoodsModel::M()->update(['okey' => $okey], ['dlykey' => $lgKey]);
        }
    }
}