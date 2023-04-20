<?php
namespace App\Module\Sale\Logic\Backend\Shop;

use App\Exception\AppException;
use App\Model\Crm\CrmOfferModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Prd\PrdWaterModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcStorageModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;

/**
 * 上架商品
 * Class ShopProductLogic
 * @package App\Module\Sale\Logic\Backend\Shop
 */
class ShopProductLogic extends BeanCollector
{
    /**
     * 待上架商品翻页数据
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
        $cols = 'pid,inway,plat,offer,bid,mid,level,pname,palias,bcode,stcstat,upshelfs,nobids,cost31';
        $list = PrdProductModel::M()->getList($where, $cols, ['stctime' => -1], $size, $idx);
        if ($list)
        {
            //提取Id
            $pids = ArrayHelper::map($list, 'pid');
            $mids = ArrayHelper::map($list, 'mid');
            $bids = ArrayHelper::map($list, 'bid');
            $offers = ArrayHelper::map($list, 'offer');

            //获取品牌机型级别数据
            $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');
            $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');
            $lkeyDict = QtoLevelModel::M()->getDict('lkey', [], 'lname');

            //获取供应商数据
            $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offers]], 'oname');

            //获取良转优记录
            $stcDict = StcStorageModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'twhs' => 104], 'sid');

            //补充数据
            foreach ($list as $key => $item)
            {
                $list[$key]['bname'] = $bidDict[$item['bid']]['bname'] ?? '-';
                $list[$key]['mname'] = $midDict[$item['mid']]['mname'] ?? '-';
                $list[$key]['lname'] = $lkeyDict[$item['level']]['lname'] ?? '-';
                $list[$key]['oname'] = $offerDict[$item['offer']]['oname'] ?? '-';
                $list[$key]['upshelfs'] = $item['upshelfs'] > 0 ? '是' : '否';

                //标签
                //1-流标，3-供应商，5-不良品
                $list[$key]['flag'] = [];
                if ($item['nobids'] > 0 || in_array($item['stcstat'], [33, 34]))
                {
                    $list[$key]['flag'][] = 1;
                }
                if (in_array($item['inway'], [2, 21]) && $item['plat'] != 17)
                {
                    $list[$key]['flag'][] = 3;
                }
                /*if ($item['cost31'] > 0)
                {
                    $list[$key]['flag'][] = 5;
                }*/
                if (isset($stcDict[$item['pid']]))
                {
                    $list[$key]['flag'][] = 5;
                }
            }
        }
        ArrayHelper::fillDefaultValue($list, [0, '0.00', '']);

        //返回
        return $list;
    }

    /**
     * 待上架商品总数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //获取数据条件
        $where = $this->getPagerWhere($query);

        //返回
        return PrdProductModel::M()->getCount($where);

    }

    /**
     * 数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //默认条件
        $where = [
            'inway' => ['not in' => [91, 1611]],
            'stcwhs' => $query['whsPermission'],
            'stcstat' => ['in' => [11, 33, 34, 35]],
            'recstat' => ['in' => [61, 7]],
            'imgstat' => 2,
        ];

        //库存编码
        if ($query['bcode'])
        {
            $where['bcode'] = $query['bcode'];
        }

        //来源
        if ($query['plat'])
        {
            if ($query['plat'] == 18)
            {
                $where['inway'] = ['in' => [2, 21]];
            }
            else
            {
                $where['plat'] = $query['plat'];
            }
        }

        //供应商名称
        $oname = $query['oname'];
        if ($oname)
        {
            $offerList = CrmOfferModel::M()->getList(['oname' => ['like' => "%$oname%"]], 'oid');
            if ($offerList)
            {
                $oids = ArrayHelper::map($offerList, 'oid');
                $where['offer'] = ['in' => $oids];
            }
            else
            {
                $where['pid'] = -1;
            }
        }

        //品牌
        if ($query['bid'])
        {
            $where['bid'] = $query['bid'];
        }

        //机型
        if ($query['mid'])
        {
            $where['mid'] = $query['mid'];
        }

        //级别
        if ($query['level'])
        {
            $where['level'] = $query['level'];
        }

        //是否流标
        if ($query['nobids'])
        {
            $where['nobids'] = ['>' => 0];
        }

        //是否上架过
        if ($query['upshelfs'])
        {
            $where['upshelfs'] = ['>' => 0];
        }

        //返回
        return $where;
    }

    /**
     * 上架商品
     * @param int $type 上架类型：1-选择上架，2-搜索上架
     * @param string $pids 商品id
     * @param array $query 查询参数
     * @param string $acc 登录用户id
     * @throws
     */
    public function save(int $type, string $pids, array $query, string $acc)
    {
        $time = time();

        //上架方式
        $cols = 'pid,oid,bid,mid,level,bcode,plat,inway,offer';
        if ($type == 1)
        {
            //获取数据
            $pids = explode(',', $pids);
            $list = PrdProductModel::M()->getList(['pid' => ['in' => $pids]], $cols);
        }
        else
        {
            //数据条件
            $where = $this->getPagerWhere($query);

            //获取数据
            $list = PrdProductModel::M()->getList($where, $cols);
        }


        //提取id
        $pids = ArrayHelper::map($list, 'pid');

        //获取销售中的供应数据
        $supplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'salestat' => 1], 'sid');

        //获取商品数据
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'oid');

        //生成竞拍商品数据
        $salesData = [];
        $waterData = [];
        foreach ($list as $key => $item)
        {
            //寄卖来源商品，未交易完成不允许上一口价
            if (in_array($item['inway'], [91, 1611]))
            {
                throw new AppException("当前商品「{$item['bcode']}」不可上架一口价", AppException::OUT_OF_OPERATE);
            }

            //组装竞拍商品
            $salesData[] = [
                'sid' => IdHelper::generate(),
                'pid' => $item['pid'],
                'yid' => $supplyDict[$item['pid']]['sid'] ?? '',
                'bid' => $item['bid'],
                'mid' => $item['mid'],
                'level' => $item['level'],
                'stat' => 11,
                'offer' => $item['offer'],
                'inway' => $item['inway'],
                'isatv' => 0,
                'away' => 1,
                'atime' => $time,
                'mtime' => $time,
            ];

            //生成流水
            $waterData[] = [
                'wid' => IdHelper::generate(),
                'tid' => 912,
                'oid' => $prdDict[$item['pid']]['oid'] ?? '',
                'pid' => $item['pid'],
                'rmk' => "一口价上架",
                'acc' => $acc,
                'atime' => time()
            ];
        }

        //记录流水
        PrdWaterModel::M()->inserts($waterData);

        //上架商品
        PrdShopSalesModel::M()->inserts($salesData);

        //更新商品数据
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 14, 'stctime' => $time]);

        //更新库存数据
        StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 14]);
    }
}