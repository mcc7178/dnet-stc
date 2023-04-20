<?php

namespace App\Module\Sale\Logic\Backend\Xinxin\Src;

use App\Model\Dnet\CrmSearchModel;
use Swork\Bean\BeanCollector;

class SearchLogic extends BeanCollector
{
    public function getPager(array $query, int $idx, int $size)
    {
        //新新平台
        $where['platform'] = 1;

        //首页
        if ($query['source'] == 1)
        {
            $where['src'] = 1;
        }

        //商品页
        if ($query['source'] == 2)
        {
            $where['src'] = 2;
        }

        //搜索时间
        if (count($query['stime']) == 2)
        {
            $stime = strtotime($query['stime'][0]);
            $etime = strtotime($query['stime'][1]) + 86399;
            $where['stime'] = ['between' => [$stime, $etime]];
        }
        $total = CrmSearchModel::M()->getCount($where);
        $where['$group'] = 'word';
        $list = CrmSearchModel::M()->getList($where, 'count(*) as count,word', ['count' => -1, 'stime' => -1], $size, $idx);
        $count = CrmSearchModel::M()->getList($where);
        $count = count($count);

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => $count,
                'total' => $total
            ],
            'list' => $list,
        ];
    }
}
