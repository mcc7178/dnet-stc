<?php
namespace App\Module\Sale\Logic\Backend\Shop;

use App\Model\Prd\PrdBidRoundModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 竞拍场次数据
 * Class ShopRoundLogic
 * @package App\Module\Sale\Logic\Backend\Shop
 */
class ShopRoundLogic extends BeanCollector
{
    /**
     * 场次日期数据
     * @return array
     */
    public function dates()
    {
        //获取场次数据
        $where = ['stat' => 11, 'mode' => 1, '$group' => 'sdate'];
        $salesList = PrdBidRoundModel::M()->getList($where, "FROM_UNIXTIME(stime,'%Y-%m-%d') as sdate", ['sdate' => 1]);
        if ($salesList)
        {
            $dates = ArrayHelper::map($salesList, 'sdate');
        }

        //返回
        return array_values($dates);
    }

    /**
     * 场次数据
     * @param string $date
     * @return array
     */
    public function list(string $date)
    {
        //获取场次数据
        $stime = strtotime($date . ' 00:00:00');
        $etime = strtotime($date . ' 23:59:59');
        $where = ['stime' => ['between' => [$stime, $etime]], 'stat' => 11, 'mode' => 1];
        $list = PrdBidRoundModel::M()->getList($where, 'rid,rname,stat,stime,etime,upshelfs', ['stime' => 1]);
        if ($list)
        {
            //补充数据
            foreach ($list as $key => $item)
            {
                $list[$key]['ptime'] = DateHelper::toString($item['stime']) . ' ~ ' . DateHelper::toString($item['etime']);
            }
        }

        //返回
        return $list;
    }
}