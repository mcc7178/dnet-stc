<?php
namespace App\Module\Sale\Logic\Backend\Bid;

use App\Exception\AppException;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Module\Sale\Data\SaleDictData;
use App\Module\Sale\Data\XchuiziDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 小槌子内部场次相关接口逻辑
 * Class InfieldRoundLogic
 * @package App\Module\Sale\Logic\Backend\Bid
 */
class InfieldRoundLogic extends BeanCollector
{
    /**
     * 竞拍场次翻页数据
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //数据条件
        $where = $this->getPagerWhere($query);

        //获取数据
        $cols = 'rid,plat,tid,mode,rname,stime,etime,stat,infield,bps,bus,upshelfs';
        $list = PrdBidRoundModel::M()->getList($where, $cols, ['stat' => 1, 'stime' => -1], $size, $idx);
        if ($size == 0)
        {
            $list = PrdBidRoundModel::M()->getList($where, $cols, ['stat' => 1, 'stime' => -1]);
        }

        //如果有数据
        if ($list)
        {
            //提取id
            $rids = ArrayHelper::map($list, 'rid');

            //获取出价数据
            $salesClos = 'sum(favs) as favs,count(if(supcmf=0,true,null)) as supcmf';
            $salesDict = PrdBidSalesModel::M()->getDict('rid', ['rid' => ['in' => $rids], '$group' => 'rid'], $salesClos);

            //中标流标数据
            $statDict = PrdBidSalesModel::M()->getDict('rid', [
                'rid' => ['in' => $rids],
                'stat' => ['in' => [21, 22]],
                '$group' => ['rid']
            ], 'count(if(stat=21,true,null)) as stat21,count(if(stat=22,true,null)) as stat22');

            //获取关注数据
            $favDict = PrdBidFavoriteModel::M()->getDict('rid', ['rid' => ['in' => $rids], '$group' => 'rid'], 'count(distinct(buyer)) as favs');

            //获取未填价数据
            $noPrcDict = PrdBidSalesModel::M()->getDict('rid', ['rid' => ['in' => $rids], 'kprc' => ['<=' => 0], 'isatv' => 1, '$group' => 'rid'], 'count(*) as count');

            //补充数据
            foreach ($list as $key => $item)
            {
                $rid = $item['rid'];

                //中标流标数据
                $stat21 = $statDict[$rid]['stat21'] ?? 0;
                $stat22 = $statDict[$rid]['stat22'] ?? 0;
                $rate = $item['upshelfs'] == 0 ? 0 : ($stat22 > 0 ? (($stat22 / $item['upshelfs']) * 100) : 0);

                $list[$key]['favs'] = $favDict[$rid]['favs'] ?? '-';
                $list[$key]['stat21'] = $stat21 ?: '-';
                $list[$key]['stat22'] = $stat22 ?: '-';
                $list[$key]['rate'] = $rate ? number_format($rate) . '%' : '-';
                $list[$key]['supcmf'] = $salesDict[$rid]['supcmf'] ?? 0;
                $list[$key]['statDesc'] = SaleDictData::BID_ROUND_STAT[$item['stat']] ?? '-';
                $list[$key]['stime'] = DateHelper::toString($item['stime']);
                $list[$key]['etime'] = DateHelper::toString($item['etime']);
                $list[$key]['rtype'] = SaleDictData::BID_ROUND_TID[$item['tid']] ? SaleDictData::BID_ROUND_TID[$item['tid']] . '场次' : '-';
                $list[$key]['noprc'] = $noPrcDict[$rid]['count'] ?? '-';
                if ($item['infield'] == 1)
                {
                    $list[$key]['rtype'] = '内部' . $list[$key]['rtype'];
                }
            }
        }

        //填充默认数据
        ArrayHelper::fillDefaultValue($list, ['', 0, '0']);

        //返回
        return $list;
    }

    /**
     * 竞拍场次总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //获取数据
        $count = PrdBidRoundModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 竞拍场次翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //查询条件
        $where = ['plat' => XchuiziDictData::PLAT];
        $where['rname'] = ['like' => '闲鱼寄卖%'];
        $where['infield'] = 1;
        if (in_array($query['stat'], [11, 12, 13, 14]))
        {
            $where['stat'] = $query['stat'];
        }

        //场次名称
        $rname = $query['rname'];
        if ($rname)
        {
            $where['rname'] = ['like' => "%$rname%"];
        }

        //库存编码
        if ($query['bcode'])
        {
            $pid = PrdProductModel::M()->getOne(['bcode' => $query['bcode']], 'pid');
            if ($pid)
            {
                $sales = PrdBidSalesModel::M()->getList(['pid' => $pid], 'rid');
                if ($sales)
                {
                    $rids = ArrayHelper::map($sales, 'rid');
                    $where['rid'] = ['in' => $rids];
                }
                else
                {
                    $where['rid'] = -1;
                }
            }
            else
            {
                $where['rid'] = -1;
            }
        }

        //场次时间
        $rtime = $query['rtime'];
        if (count($rtime) == 2)
        {
            $stime = strtotime($rtime[0] . ' 00:00:00');
            $etime = strtotime($rtime[1] . ' 23:59:59');
            $where['stime'] = ['between' => [$stime, $etime]];
        }

        //返回
        return $where;
    }

    /**
     * 获取场次信息
     * @param string $rid
     * @return bool|mixed
     * @throws
     */
    public function getInfo(string $rid)
    {
        //获取数据
        $info = PrdBidRoundModel::M()->getRowById($rid, 'stat,stime,etime');
        if ($info == false)
        {
            throw new AppException('场次信息不存在', AppException::NO_DATA);
        }

        //返回
        return $info;
    }
}