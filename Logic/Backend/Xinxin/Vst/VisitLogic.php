<?php

namespace App\Module\Sale\Logic\Backend\Xinxin\Vst;

use App\Model\Topd\Xinxin\TopdRptXvisitModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\DateHelper;

class VisitLogic extends BeanCollector
{
    /**
     * 获取页面
     * @param array $rtime 搜索时间
     * @param int $idx 页码
     * @param int $size 每页数量
     * @return array
     */
    public function getPager(array $rtime, int $idx, int $size)
    {
        //搜索时间
        if (count($rtime) == 2)
        {
            $stime = strtotime($rtime[0]);
            $etime = strtotime($rtime[1]) + 86399;
        }
        else
        {
            $etime = strtotime(date('Y-m-d')) + 86399;
            $stime = strtotime('-30 days', $etime) + 1;
        }
        $where['rtime'] = ['between' => [$stime, $etime]];
        $list = TopdRptXvisitModel::M()->getList($where, '*', ['rtime' => -1], $size, $idx);
        $count = TopdRptXvisitModel::M()->getCount($where);

        foreach ($list as $key => $value)
        {
            $list[$key]['rtime'] = DateHelper::toString($value['rtime'],'Y-m-d');
        }

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => $count,
            ],
            'list' => $list,
        ];
    }
}
