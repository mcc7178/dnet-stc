<?php
namespace App\Module\Sale\Logic\Store\Product;

use App\Exception\AppException;
use App\Model\Crm\CrmOfferModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsMirrorModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 商品展示
 * Class ProductLogic
 * @package App\Module\Sale\Logic\Store\Product
 */
class ProductLogic extends BeanCollector
{
    /**
     * 获取商品列表
     * @param array $query
     * @param int $idx
     * @param int $size
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //数据条件
        $where = $this->getWhere($query);

        //获取数据列表
        $where['A.twhs'] = 105;
        $cols = 'A.pid,A.stat,A.prdstat,A.ltime,A.otime,B.bcode,B.offer,B.plat,B.bid,B.mid,B.level,B.imei,B.rectime4,B.supcost,B.salecost,B.saletime,B.imei,B.pcost';
        $list = StcStorageModel::M()
            ->join(PrdProductModel::M(), ['A.pid' => 'B.pid'])
            ->getList($where, $cols, ['A.ltime' => -1], $size, $idx);
        if (empty($list))
        {
            return [];
        }

        //获取订单商品
        $bcodes = ArrayHelper::map($list, 'bcode');
        $odrGood = OdrGoodsModel::M()->getDict('pid', ['bcode' => ['in' => $bcodes]], 'okey,tid');
        $okey = ArrayHelper::map($odrGood, 'okey', '-1');
        $odrOrder = OdrOrderModel::M()->getDict('okey', ['okey' => ['in' => $okey]], 'oid,ostat');

        //组装数据
        foreach ($odrGood as $key => $value)
        {
            $odrGood[$key]['oid'] = $odrOrder[$value['okey']]['oid'] ?? '-';
            $odrGood[$key]['ostat'] = $odrOrder[$value['okey']]['ostat'] ?? '-';
        }

        //获取供应商名字
        $offers = ArrayHelper::map($list, 'offer');
        $crmOffer = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offers]], 'oname');

        //获取品牌字典
        $bids = ArrayHelper::map($list, 'bid');
        $brandDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');

        //获取机型字典
        $mids = ArrayHelper::map($list, 'mid');
        $modelDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

        //获取级别字典
        $levels = ArrayHelper::map($list, 'level');
        $levelDict = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lname');

        //获取质检备注字典
        $pids = ArrayHelper::map($list, 'pid');
        $rmk = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'bconc');

        foreach ($list as $key => $value)
        {
            $bid = $value['bid'];
            $mid = $value['mid'];
            $level = $value['level'];
            $pid = $value['pid'];
            $offer = $value['offer'];
            $list[$key]['bname'] = $brandDict[$bid]['bname'] ?? '-';
            $list[$key]['model'] = $modelDict[$mid]['mname'] ?? '-';
            $list[$key]['lname'] = $levelDict[$level]['lname'] ?? '-';
            $list[$key]['plat'] = $crmOffer[$offer]['oname'] ?: '-';
            $list[$key]['saletime'] = DateHelper::toString($list[$key]['saletime']);
            $list[$key]['rectime4'] = DateHelper::toString($list[$key]['rectime4']);
            $list[$key]['ltime'] = DateHelper::toString($list[$key]['ltime']);
            $list[$key]['otime'] = DateHelper::toString($list[$key]['otime']);
            $list[$key]['prdstatName'] = isset($list[$key]['prdstat']) ? SaleDictData::PRD_STORAGE_STAT[$list[$key]['prdstat']] : '-';
            $list[$key]['stat'] = isset($list[$key]['stat']) ? SaleDictData::PRD_PRODUCT_STAT[$list[$key]['stat']] : '-';
            $list[$key]['rmk'] = $rmk[$pid]['bconc'] ?? '-';
            $list[$key]['oid'] = $odrGood[$pid]['oid'] ?? '-';
            $list[$key]['tid'] = $odrGood[$pid]['tid'] ?? '-';
            $list[$key]['ostat'] = $odrGood[$pid]['ostat'] ?? '-';
            $list[$key]['salecost'] = (float)$value['salecost'] ?: $value['supcost'];
        }

        return $list;
    }

    /**
     * 获取查询字段
     * @param $query
     * @return array
     */
    public function getWhere(array $query)
    {
        $where = [];
        //供应编码和imei码查询
        if ($query['bcode'])
        {
            $where['$or'] = [
                ['B.bcode' => $query['bcode']],
                ['B.imei' => $query['bcode']],
            ];
        }

        //来源plat
        if ($query['plat'])
        {
            $where['B.plat'] = $query['plat'];
        }

        //销售状态prdstat
        if ($query['stat'])
        {
            $where['A.stat'] = $query['stat'];
        }

        //级别level
        if ($query['level'])
        {
            $where['B.level'] = $query['level'];
        }

        //品牌
        if ($query['bid'])
        {
            $where['B.bid'] = $query['bid'];
        }

        //机型mid
        if ($query['mid'])
        {
            $where['B.mid'] = $query['mid'];
        }

        //时间ltime
        if (count($query['ltime']) == 2)
        {
            $stime = strtotime($query['ltime'][0] . ' 00:00:00');
            $etime = strtotime($query['ltime'][1] . ' 23:59:59');
            $where['A.ltime'] = ['between' => [$stime, $etime,]];
        }

        //返回
        return $where;
    }

    /**
     * 商品总数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //获取数据条件
        $where = $this->getWhere($query);
        $where['A.twhs'] = 105;

        //查询数量
        $count = StcStorageModel::M()
            ->leftJoin(PrdProductModel::M(), ['A.pid' => 'B.pid'])
            ->getCount($where);

        //返回
        return $count;
    }

    /**
     * 商品详情
     * @param string $pid
     * @return array|int
     * @throws
     */
    public function getDetail(string $pid)
    {
        $col = 'pid,bid,mid,plat,level,palias,salecost,mdofsale,mdnet,mdcolor,mdram,mdwarr';
        $info = PrdProductModel::M()->getRowById($pid, $col);
        if ($info == false)
        {
            throw new AppException(AppException::NO_DATA);
        }

        //品牌名
        $info['bname'] = QtoBrandModel::M()->getOne(['bid' => $info['bid']], 'bname') ?? '-';

        //获取机型
        $info['model'] = QtoModelModel::M()->getOne(['mid' => $info['mid']], 'mname') ?? '-';

        //获取级别
        $info['lname'] = QtoLevelModel::M()->getOne(['lkey' => $info['level']], 'lname') ?? '-';

        //质检备注
        $qcReport = MqcReportModel::M()->getRow(['pid' => $pid, 'plat' => 21], 'bconc,bmkey', ['atime' => -1]);
        $info['data'] = [];
        if ($qcReport)
        {
            //获取质检详情
            $content = QtoOptionsMirrorModel::M()->getRow(['mkey' => $qcReport['bmkey']], 'content', ['atime' => -1]);
            if ($content)
            {
                $list = ArrayHelper::toArray($content['content']);

                //组装数据
                $newList = [];
                foreach ($list as $key => $value)
                {
                    foreach ($value['opts'] as $key1 => $item)
                    {
                        if ($item['normal'] == -1)
                        {
                            $value['opts'][$key1]['oname'] = '<span style="color: #ff0000">' . $item['oname'] . '</span>';
                        }
                    }
                    $newList[] = [
                        'desc' => implode(' ', array_column($value['opts'], 'oname')),
                        'cname' => $value['cname'],
                        'cid' => $value['cid'],
                    ];
                }
                $info['data'] = $newList;
            }
        }
        $info['qcReport'] = $qcReport['bconc'];

        return $info;
    }

    /**
     * 商品导出
     * @param array $query
     * @param int $idx
     * @param int $size
     * @return array
     * @throws
     */
    public function export(array $query, int $size, int $idx)
    {
        //如果导出数量超过5000则需要
        $count = $this->getCount($query);
        if ($count > 5000)
        {
            throw new AppException("当前导出数据为{$count}行，已超出系统限制的5000行，请指定日期分批导出", AppException::FAILED_OPERATE);
        }

        //列表数据
        $list = $this->getPager($query, $size, $idx);
        foreach ($list as $key => $value)
        {
            $list[$key]['idx'] = $key + 1;
        }

        $data = [];
        if ($query['prdstat'])
        {
            //拼装excel数据
            $data['list'] = $list;
            $data['header'] = [
                'idx' => '序号',
                'bcode' => '库存编号',
                'imei' => 'imei码',
                'bname' => '品牌',
                'model' => '机型',
                'lname' => '级别',
                'salecost' => '成本价',
                'ltime' => '入库时间',
            ];

            //返回数据
            return $data;
        }

        //拼装excel数据
        $data['list'] = $list;
        $data['header'] = [
            'idx' => '序号',
            'bcode' => '库存编号',
            'imei' => 'imei码',
            'bname' => '品牌',
            'model' => '机型',
            'lname' => '级别',
            'salecost' => '成本价',
            'pcost' => '成交价',
            'stat' => '库存状态',
            'prdstatName' => '商品状态',
            'ltime' => '入库时间',
            'otime' => '出库时间',
            'rmk' => '质检备注',
        ];

        //返回数据
        return $data;
    }

    /**
     * 品牌列表
     * @return array
     */
    public function getBrandList()
    {
        //查询条件
        $where['stcwhs'] = 105;
        $where['stcstat'] = 11;

        //以品牌分组
        $where['$group'] = ['bid'];
        $list = PrdProductModel::M()->getList($where, 'bid,count(bid) as count');

        //获取品牌字典
        $bids = ArrayHelper::map($list, 'bid');
        $qtoBrandDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');

        //组装数据
        $count = 0;
        foreach ($list as $key => $value)
        {
            $list[$key]['bname'] = $qtoBrandDict[$value['bid']]['bname'] ?? '其它';
            $count += $value['count'];
        }

        //组装数据
        $data[] = ['bid' => 'all', 'count' => $count, 'bname' => '全部'];

        //返回
        return array_merge($data, $list);
    }
}