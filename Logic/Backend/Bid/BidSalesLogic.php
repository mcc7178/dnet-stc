<?php
namespace App\Module\Sale\Logic\Backend\Bid;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Prd\PrdWaterModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Sale\Data\SaleDictData;
use App\Module\Sale\Data\XinxinDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

/**
 * 场次商品
 * Class BidSalesLogic
 * @package App\Module\Sale\Logic\Backend\Bid
 */
class BidSalesLogic extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 场次商品列表
     * @param string $acc 用户id
     * @param int $size
     * @param int $idx
     * @param array $query
     * @return array
     * @throws
     */
    public function getPager(string $acc, int $size, int $idx, array $query)
    {
        //查询条件
        $lose = $query['lose'];
        $where = $this->getPagerWhere($query);

        //旧系统的排序 stat asc,ord desc,mid desc,level asc,sid desc
        $order = ['stat' => 1, 'ord' => -1, 'mid' => -1, 'level' => 1, 'sid' => -1];

        //获取商品数据
        $cols = 'sid,rid,plat,pid,bid,mid,level,tid,sprc,kprc,aprc,bprc,stat,offer,favs,pvs,uvs,bus,bps,bway,inway';
        $list = PrdBidSalesModel::M()->getList($where, $cols, $order, $size, $idx);
        if ($list)
        {
            //提取id
            $pids = ArrayHelper::map($list, 'pid');
            $offers = ArrayHelper::map($list, 'offer');

            //获取商品数据
            $prdCols = 'bcode,pname,palias,supcost,salecost,nobids,upshelfs,cost31,mid,level,mdram,mdnet,mdcolor,mdofsale,mdwarr,stcstat';
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], $prdCols);

            //获取供应数据
            $supplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'salestat' => 1], 'imgpack');

            //获取品牌、机型、级别字典
            $bidDict = QtoBrandModel::M()->getDict('bid', [], 'bname');
            $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => array_column($prdDict, 'mid')]], 'mname');
            $levelDict = QtoLevelModel::M()->getDict('lkey', [], 'lname');

            //获取供应商数据
            $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offers]], 'oname');

            //获取质检信息
            $reportDict = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => XinxinDictData::PLAT], 'bconc', ['atime' => 1]);

            //获取良转优记录
            $stcDict = StcStorageModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'twhs' => 104], 'sid');

            //补充数据
            $priceDict = [];
            foreach ($list as $key => $item)
            {
                $pid = $item['pid'];
                if ($lose == 1 && !in_array($prdDict[$pid]['stcstat'], [11, 33, 34, 35]))
                {
                    unset($list[$key]);
                    continue;
                }

                //产品图片
                $imgpack = $supplyDict[$pid]['imgpack'] ?? '[]';
                $imgs = json_decode($imgpack, true) ?: [];
                foreach ($imgs as $k => $v)
                {
                    $imgs[$k]['thumb'] = Utility::supplementProductImgsrc($v['src'], 100);
                    $imgs[$k]['src'] = Utility::supplementProductImgsrc($v['src']);
                }

                $list[$key]['bcode'] = $prdDict[$pid]['bcode'] ?? '-';
                $list[$key]['pname'] = $prdDict[$pid]['pname'] ?? '-';
                $list[$key]['palias'] = $prdDict[$pid]['palias'] ?? '-';
                $list[$key]['bname'] = $bidDict[$item['bid']]['bname'] ?? '-';
                $list[$key]['mname'] = $midDict[$item['mid']]['mname'] ?? '-';
                $list[$key]['salecost'] = $prdDict[$pid]['salecost'] ?? 0;
                if ($list[$key]['salecost'] == 0)
                {
                    $list[$key]['salecost'] = $prdDict[$pid]['supcost'] ?? '-';
                }
                $list[$key]['pcost'] = $prdDict[$pid]['pcost'] ?? '-';
                $list[$key]['lname'] = $levelDict[$item['level']]['lname'] ?? '-';
                $list[$key]['imgs'] = $imgs;
                $list[$key]['oname'] = $offerDict[$item['offer']]['oname'] ?: '-';
                $list[$key]['statDesc'] = SaleDictData::BID_SALES_STAT[$item['stat']];
                $list[$key]['bconc'] = $reportDict[$pid]['bconc'] ?? '-';

                $tag = $item['stat'] == 11 ? '' : '-';
                $list[$key]['sprc'] = $item['sprc'] ?: $tag;
                $list[$key]['kprc'] = $item['kprc'] ?: $tag;
                $list[$key]['aprc'] = $item['aprc'] ?: $tag;
                $list[$key]['bprc'] = $item['bprc'] ?: $tag;
                $list[$key]['favs'] = $item['favs'] ?: $tag;
                $list[$key]['pvs'] = $item['pvs'] ?: $tag;
                $list[$key]['uvs'] = $item['uvs'] ?: $tag;
                $list[$key]['bus'] = $item['bus'] ?: $tag;
                $list[$key]['bps'] = $item['bps'] ?: $tag;

                //成交价数据
                $list[$key]['saleamt1'] = '-';
                $list[$key]['saletime1'] = '-';
                $list[$key]['bconc1'] = '-';
                $list[$key]['saleamt2'] = '-';
                $list[$key]['saletime2'] = '-';
                $list[$key]['bconc2'] = '-';
                $list[$key]['saleamt3'] = '-';
                $list[$key]['saletime3'] = '-';
                $list[$key]['bconc3'] = '-';

                $mid = $prdDict[$pid]['mid'] ?? '';
                $level = $prdDict[$pid]['level'] ?? '';
                $mdram = $prdDict[$pid]['mdram'] ?? '';
                $mdnet = $prdDict[$pid]['mdnet'] ?? '';
                $mdcolor = $prdDict[$pid]['mdcolor'] ?? '';
                $mdofsale = $prdDict[$pid]['mdofsale'] ?? '';
                $mdwarr = $prdDict[$pid]['mdwarr'] ?? '';
                $priceKey = "$mid$level$mdram$mdnet$mdcolor$mdofsale$mdwarr";
                if ($priceKey != '' && isset($priceDict[$priceKey]))
                {
                    $list[$key]['saleamt1'] = $priceDict[$priceKey]['saleamt1'];
                    $list[$key]['saletime1'] = $priceDict[$priceKey]['saletime1'];
                    $list[$key]['bconc1'] = $priceDict[$priceKey]['bconc1'];
                    $list[$key]['saleamt2'] = $priceDict[$priceKey]['saleamt2'] ?? '-';
                    $list[$key]['saletime2'] = $priceDict[$priceKey]['saletime2'] ?? '-';
                    $list[$key]['bconc2'] = $priceDict[$priceKey]['bconc2'] ?? '-';
                    $list[$key]['saleamt3'] = $priceDict[$priceKey]['saleamt3'] ?? '-';
                    $list[$key]['saletime3'] = $priceDict[$priceKey]['saletime3'] ?? '-';
                    $list[$key]['bconc3'] = $priceDict[$priceKey]['bconc3'] ?? '-';
                }
                else
                {
                    $subWhere = [
                        'mid' => $mid,
                        'level' => $level,
                        'mdram' => $mdram,
                        'mdnet' => $mdnet,
                        'mdcolor' => $mdcolor,
                        'mdofsale' => $mdofsale,
                        'mdwarr' => $mdwarr,
                        'salestat' => 2
                    ];
                    $saleList = PrdSupplyModel::M()->getList($subWhere, 'pid,saleamt,saletime', ['saletime' => -1], 3);
                    foreach ($saleList as $k => $value)
                    {
                        if ($value['saleamt'] > 0)
                        {
                            //质检备注
                            $bconc = MqcReportModel::M()->getOne(['pid' => $value['pid'], 'plat' => XinxinDictData::PLAT], 'bconc', ['atime' => -1]);

                            $t = $k + 1;
                            $list[$key]['saleamt' . $t] = number_format($value['saleamt'], 2);
                            $list[$key]['saletime' . $t] = DateHelper::toString($value['saletime'], 'm-d');
                            $list[$key]['bconc' . $t] = $bconc ?: '-';

                            //保存价格数据
                            $priceDict[$priceKey]['saleamt' . $t] = number_format($value['saleamt'], 2);
                            $priceDict[$priceKey]['saletime' . $t] = DateHelper::toString($value['saletime'], 'm-d');
                            $priceDict[$priceKey]['bconc' . $t] = $bconc ?: '-';
                        }
                    }
                }

                //是否流标
                $list[$key]['nobids'] = (isset($prdDict[$pid]) && $prdDict[$pid]['nobids'] > 0) ? 1 : '-';

                //是否上架
                $list[$key]['upshelfs'] = (isset($prdDict[$pid]) && $prdDict[$pid]['upshelfs'] > 0) ? 1 : '-';

                //标签
                //1-流标，2-特价，3-供应商，4-活动，5-不良品
                $list[$key]['flag'] = [];
                if (isset($prdDict[$pid]) && ($prdDict[$pid]['nobids'] > 0 || in_array($prdDict[$pid]['stcstat'], [33, 34])))
                {
                    $list[$key]['flag'][] = 1;
                }
                if ($item['tid'] == 2)
                {
                    $list[$key]['flag'][] = 2;
                }
                if (in_array($item['inway'], [2, 21]) && $item['plat'] != 17)
                {
                    $list[$key]['flag'][] = 3;
                }
                if ($item['tid'] == 1)
                {
                    $list[$key]['flag'][] = 4;
                }
                /*if (isset($prdDict[$pid]) && $prdDict[$pid]['cost31'] > 0)
                {
                    $list[$key]['flag'][] = 5;
                }*/
                if (isset($stcDict[$item['pid']]))
                {
                    $list[$key]['flag'][] = 5;
                }
            }
        }
        $list = array_values($list);
        ArrayHelper::fillDefaultValue($list, ['0.00']);

        //获取用户权限
        $permis = AccUserModel::M()->exist(['aid' => $acc, 'permis' => ['like' => "%sale00101%"]]);
        if ($permis == false)
        {
            $this->filterPrc($list, ['salecost']);
        }

        //返回
        return $list;
    }

    /**
     * 场次商品列表
     * @param array $query
     * @return int
     * @throws
     */
    public function getCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //返回
        return PrdBidSalesModel::M()->getCount($where);
    }

    /**
     * 竞拍商品填价
     * @param string $sid 商品id
     * @param int $sprc 起拍价
     * @param int $kprc 秒杀价
     * @param int $aprc 每拍加价
     * @param string $acc 登录用户ID
     * @throws
     */
    public function savePrice(string $sid, int $sprc, int $kprc, int $aprc, string $acc)
    {
        //数据验证
        $sprc = intval($sprc);
        $kprc = intval($kprc);
        $aprc = intval($aprc);
        $info = PrdBidSalesModel::M()->getRowById($sid, 'plat,pid,stat,sprc,kprc,aprc,inway');
        if ($info == false)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }
        if ($info['stat'] != 11)
        {
            throw new AppException('非待公开状态，不能修改价格', AppException::NO_DATA);
        }
        if ($info['inway'] == 91 && $sprc > 0 && $info['sprc'] != $sprc)
        {
            throw new AppException('闲鱼拍卖商品不可修改起拍价', AppException::NO_DATA);
        }
        if ($sprc == $info['sprc'] && $kprc == $info['kprc'] && $aprc == $info['aprc'])
        {
            return;
        }

        //拼装数据
        $data = [];
        if ($sprc > 0 && $info['inway'] != 91)
        {
            $data['sprc'] = $sprc;
        }
        if ($kprc > 0)
        {
            $data['kprc'] = $kprc;
        }
        if ($aprc > 0)
        {
            $data['aprc'] = $aprc;
        }

        if ($sprc < 1)
        {
            throw new AppException('起拍价必填', AppException::OUT_OF_OPERATE);
        }
        if ($kprc && $sprc && ($sprc > $kprc))
        {
            throw new AppException('秒杀价不能小于起拍价', AppException::OUT_OF_OPERATE);
        }
        if ($kprc && $sprc && $aprc && ($kprc - $sprc) < $aprc * 3)
        {
            throw new AppException('秒杀价-起拍价不能小于3个加拍价', AppException::OUT_OF_OPERATE);
        }

        //获取商品数据
        $prdInfo = PrdProductModel::M()->getRowById($info['pid'], 'bcost');
        if ($prdInfo == false)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }
        if ($info['inway'] == 1611 && $sprc && ($sprc < $prdInfo['bcost']))
        {
            throw new AppException('闲鱼寄卖的商品起拍价不能小于保底价', AppException::OUT_OF_OPERATE);
        }
        if ($info['inway'] == 1611)
        {
            //服务费 10~150  最低167 最高2500
            $baseAmt = $prdInfo['bcost'];//保底价
            $minSprc = ceil($baseAmt / 0.94);
            if ($baseAmt <= 156)
            {
                $minSprc = $baseAmt + 10;
            }
            if ($baseAmt >= 2350)
            {
                $minSprc = $baseAmt + 150;
            }
            if ($sprc < $minSprc)
            {
                $msg = "当前保底价: $baseAmt , 起拍价不能低于: $minSprc";
                throw new AppException($msg, AppException::OUT_OF_OPERATE);
            }
        }

        //记录流水
        if ($kprc && $sprc && $aprc)
        {
            $oid = PrdProductModel::M()->getOneById($info['pid'], 'oid', [], '');
            PrdWaterModel::M()->insert([
                'wid' => IdHelper::generate(),
                'tid' => 920,
                'oid' => $oid,
                'pid' => $info['pid'],
                'rmk' => "起拍价:$sprc,秒杀价:$kprc,加拍价:$aprc",
                'acc' => $acc,
                'atime' => time()
            ]);
        }

        //保存价格
        PrdBidSalesModel::M()->updateById($sid, $data);
    }

    /**
     * 下架商品
     * @param string $sids 商品ids
     * @throws
     */
    public function remove(string $sids, string $acc)
    {
        $time = time();

        //解析参数
        $sids = explode(',', $sids);

        //获取商品数据
        $sales = PrdBidSalesModel::M()->getList(['sid' => ['in' => $sids]], 'sid,tid,rid,pid,stat,plat');
        if ($sales == false)
        {
            throw new AppException('竞拍商品数据不存在', AppException::NO_DATA);
        }

        //提取数据
        $pids = ArrayHelper::map($sales, 'pid');
        $sids = ArrayHelper::map($sales, 'sid');

        //获取商品数据
        $waterData = [];
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'oid');
        foreach ($sales as $key => $item)
        {
            if ($item['stat'] > 12)
            {
                throw new AppException('商品在竞拍中，不能操作下架', AppException::OUT_OF_OPERATE);
            }

            //操作流水
            $waterData[] = [
                'wid' => IdHelper::generate(),
                'tid' => 917,
                'oid' => $prdDict[$item['pid']]['oid'] ?? '',
                'pid' => $item['pid'],
                'rmk' => '竞拍下架',
                'acc' => $acc,
                'atime' => $time
            ];
        }

        //删除数据
        PrdBidSalesModel::M()->delete(['sid' => ['in' => $sids]]);
        PrdBidFavoriteModel::M()->delete(['sid' => ['in' => $sids]]);

        //更新场次上架商品数
        $rid = $sales[0]['rid'];
        $count = PrdBidSalesModel::M()->getCount(['rid' => $rid]);
        PrdBidRoundModel::M()->updateById($rid, ['upshelfs' => $count]);

        //更新商品、库存数据
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 11, 'stctime' => time()]);
        StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 11]);

        //添加流水
        PrdWaterModel::M()->inserts($waterData);
    }

    /**
     * 活动/特价标记
     * @param string $sids 商品id集合
     * @param int $tid 类型id，1-活动，2-特价
     * @throws
     */
    public function mark(string $sids, int $tid)
    {
        //获取商品数据
        $sids = explode(',', $sids);
        $salesList = PrdBidSalesModel::M()->getList(['sid' => ['in' => $sids]], 'stat');
        if ($salesList == false)
        {
            throw new AppException('竞拍商品数据不存在', AppException::NO_DATA);
        }
        $stats = ArrayHelper::map($salesList, 'stat');
        if ($stats != [11])
        {
            throw new AppException('非待公开状态，不允许操作', AppException::NO_DATA);
        }

        //更新数据
        PrdBidSalesModel::M()->update(['sid' => ['in' => $sids]], ['tid' => $tid]);
    }

    /**
     * 修改商品信息-保存
     * @param string $pid
     * @param string $alias
     * @throws
     */
    public function save(string $pid, string $alias)
    {
        //数据验证
        $exist = PrdProductModel::M()->existById($pid);
        if ($exist == false)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }

        //更新数据
        PrdProductModel::M()->updateById($pid, ['alias' => $alias]);
    }

    /**
     * 商品转场
     * @param string $sids
     * @param string $frid
     * @param string $trid
     * @param int $shop 是否转入到一口价
     * @param int $lose 是否流标上架
     * @param string $acc 登录用户ID
     * @throws
     */
    public function trans(string $sids, string $frid, string $trid, int $shop, int $lose, string $acc)
    {
        //解析参数
        $time = time();
        $sids = explode(',', $sids);

        //原场次信息
        $fromRound = PrdBidRoundModel::M()->getRowById($frid, 'rname');
        if ($fromRound == false)
        {
            throw new AppException('原竞拍场次数据不存在', AppException::NO_DATA);
        }

        //获取场次数据
        if (!$shop)
        {
            $roundInfo = PrdBidRoundModel::M()->getRowById($trid, 'stat,stime,etime,rname');
            if ($roundInfo == false)
            {
                throw new AppException('场次数据不存在', AppException::NO_DATA);
            }
            if ($roundInfo['stat'] != 11)
            {
                throw new AppException('场次非待公开状态,不可转入', AppException::OUT_OF_OPERATE);
            }

            //获取竞拍商品数据
            $salesList = PrdBidSalesModel::M()->getList(['sid' => ['in' => $sids], 'stat' => ['in' => [11, 12, 22]]]);
            if ($salesList == false)
            {
                throw new AppException('竞拍商品数据不存在', AppException::NO_DATA);
            }
            $sids = ArrayHelper::map($salesList, 'sid');
            $pids = ArrayHelper::map($salesList, 'pid');

            //获取商品信息
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'stcstat,bcode,oid,inway,plat');
            if ($lose == 1)
            {
                foreach ($prdDict as $item)
                {
                    if (!in_array($item['stcstat'], [11, 33, 34, 35]))
                    {
                        throw new AppException("{$item['bcode']}不在库,不能操作转场", AppException::OUT_OF_OPERATE);
                    }
                }
            }

            //获取销售商品信息
            $prdSupplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'salestat' => 1], 'sid,pid');

            //记录流水
            $waterData = [];
            foreach ($salesList as $item)
            {
                $waterData[] = [
                    'wid' => IdHelper::generate(),
                    'tid' => 919,
                    'oid' => $prdDict[$item['pid']]['oid'] ?? '',
                    'pid' => $item['pid'],
                    'rmk' => "『{$fromRound['rname']}』竞拍场转到『{$roundInfo['rname']}』竞拍场",
                    'acc' => $acc,
                    'atime' => $time
                ];
            }

            //更新商品数据
            //待公开 （如果有闲鱼寄卖商品需要履约到闲鱼，在下面）
            PrdBidSalesModel::M()->update(['sid' => ['in' => $sids], 'stat' => 11], [
                'rid' => $trid,
                'stime' => $roundInfo['stime'],
                'etime' => $roundInfo['etime'],
                'away' => 2,
                'atime' => $time,
            ]);

            //已公开
            $stat12List = PrdBidSalesModel::M()->getList(['sid' => ['in' => $sids], 'stat' => ['>' => 11]], 'sid');
            if ($stat12List)
            {
                //新增竞拍商品
                foreach ($salesList as $key => $item)
                {
                    $salesList[$key]['sid'] = IdHelper::generate();
                    $salesList[$key]['rid'] = $trid;
                    $salesList[$key]['yid'] = $prdSupplyDict[$item['pid']]['sid'] ?? '';
                    $salesList[$key]['inway'] = $prdDict[$item['pid']]['inway'] ?? 0;
                    $salesList[$key]['stime'] = $roundInfo['stime'];
                    $salesList[$key]['etime'] = $roundInfo['etime'];
                    $salesList[$key]['stat'] = 11;
                    $salesList[$key]['away'] = 2;
                    $salesList[$key]['atime'] = $time;
                    $salesList[$key]['isatv'] = 1;
                }
                PrdBidSalesModel::M()->inserts($salesList);

                //下架待开场商品
                $sids = ArrayHelper::map($stat12List, 'sid');
                PrdBidSalesModel::M()->update(['sid' => ['in' => $sids], 'stat' => ['>' => 11]], ['isatv' => 0]);
            }

            //更新竞拍场次商品数量
            $upShelfStatDict = PrdBidSalesModel::M()->getDict('rid', ['rid' => [$frid, $trid], '$group' => 'rid'], 'rid,count(*) as count');
            foreach ([$frid, $trid] as $rid)
            {
                $count = $upShelfStatDict[$rid]['count'] ?? 0;
                PrdBidRoundModel::M()->updateById($rid, ['upshelfs' => $count]);
            }

            //更新商品、库存数据
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 14, 'stctime' => $time]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 14]);

            //添加流水
            PrdWaterModel::M()->inserts($waterData);

            //待公开的闲鱼寄卖商品 - 履约到闲鱼
            $xianyuSmbList = PrdBidSalesModel::M()->join(PrdProductModel::M(), ['pid' => 'pid'])
                ->getList(['A.sid' => ['in' => $sids], 'A.stat' => 11, 'B.plat' => 161], 'A.sid');
            foreach ($xianyuSmbList as $value)
            {
                AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_join_round', [
                    'sid' => $value['sid']
                ]);
            }
        }
        else
        {
            //转入到一口价

            //获取竞拍商品数据
            $cols = 'sid,pid,yid,bid,mid,level,tid,inway,kprc,offer';
            $salesList = PrdBidSalesModel::M()->getList(['sid' => ['in' => $sids], 'stat' => ['in' => [11, 12, 22]]], $cols);
            if ($salesList == false)
            {
                throw new AppException('竞拍商品数据不存在', AppException::NO_DATA);
            }
            $pids = ArrayHelper::map($salesList, 'pid');

            //获取商品数据
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'stcstat,bcode,oid,inway');

            //获取销售商品信息
            $prdSupplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'salestat' => 1], 'sid,pid');

            //检查商品是否允许上架
            foreach ($salesList as $key => $item)
            {
                $pid = $item['pid'];
                $bcode = $prdDict[$pid]['bcode'];
                $stcstat = $prdDict[$pid]['stcstat'];

                //检查是否允许转场
                if ($lose == 1 && !in_array($stcstat, [11, 33, 34, 35]))
                {
                    throw new AppException("商品「{$bcode}」不在库，不可转入一口价", AppException::OUT_OF_OPERATE);
                }
                if ($item['inway'] == 91)
                {
                    throw new AppException("闲鱼拍卖商品「{$bcode}」不可转入一口价，请重新选择", AppException::OUT_OF_OPERATE);
                }
                if ($prdDict[$pid]['inway'] == 1611)
                {
                    throw new AppException("闲鱼寄卖商品「{$bcode}」不可转入一口价，请重新选择", AppException::OUT_OF_OPERATE);
                }
                if (empty($prdSupplyDict[$pid]))
                {
                    throw new AppException("商品「{$bcode}」供应数据异常，不可转入一口价，请联系研发部处理", AppException::OUT_OF_OPERATE);
                }
            }

            //下架竞拍商品
            PrdBidSalesModel::M()->delete(['sid' => ['in' => $sids], 'stat' => 11]);
            PrdBidSalesModel::M()->update(['sid' => ['in' => $sids], 'stat' => ['>' => 11]], ['isatv' => 0]);

            //上架一口价商品
            $shopData = [];
            $waterData = [];
            foreach ($salesList as $key => $item)
            {
                $shopData[] = [
                    'sid' => IdHelper::generate(),
                    'pid' => $item['pid'],
                    'yid' => $prdSupplyDict[$item['pid']]['sid'],
                    'bid' => $item['bid'],
                    'mid' => $item['mid'],
                    'level' => $item['level'],
                    'tid' => $item['tid'],
                    'bprc' => $item['kprc'],
                    'stat' => 11,
                    'offer' => $item['offer'],
                    'inway' => $prdDict[$item['pid']]['inway'] ?? 0,
                    'isatv' => 0,
                    'away' => 2,
                    'atime' => $time,
                    'mtime' => $time,
                ];

                //记录流水
                $waterData[] = [
                    'wid' => IdHelper::generate(),
                    'tid' => 919,
                    'oid' => $prdDict[$item['pid']]['oid'] ?? '',
                    'pid' => $item['pid'],
                    'rmk' => "『{$fromRound['rname']}』竞拍场转到一口价",
                    'acc' => $acc,
                    'atime' => $time
                ];
            }
            PrdShopSalesModel::M()->inserts($shopData);

            //如果不是流拍转场，则更新原场次上架商品数
            if (!$lose)
            {
                $count = PrdBidSalesModel::M()->getCount(['rid' => $frid]);
                PrdBidRoundModel::M()->updateById($frid, ['upshelfs' => $count]);
            }

            //更新商品、库存数据
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 14, 'stctime' => $time]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 14]);

            //添加流水
            PrdWaterModel::M()->inserts($waterData);
        }
    }

    /**
     * 竞拍商品排序
     * @param string $rid
     * @param array $sids
     * @throws
     */
    public function sort(string $rid, array $sids)
    {
        //数据验证
        $exist = PrdBidRoundModel::M()->existById($rid);
        if ($exist == false)
        {
            throw new AppException('场次数据不存在', AppException::NO_DATA);
        }

        //反转数组
        $sids = array_reverse($sids);
        foreach ($sids as $key => $sid)
        {
            PrdBidSalesModel::M()->updateById($sid, ['ord' => $key]);
        }
    }

    /**
     * 替换价格
     * @param array $list
     * @param array $from 要被替换的值
     * @param string $to 要替换成的字符
     */
    public function filterPrc(array &$list, array $from = [''], string $to = '-')
    {
        foreach ($list as $key => $item)
        {
            foreach ($item as $idx => $val)
            {
                if (in_array($idx, $from))
                {
                    $list[$key][$idx] = $to;
                }
            }
        }
    }

    /**
     * 查询条件
     * $rid 场次id，
     * $tid 活动类别，1-活动，2-特价，
     * $lose 是否流标上架
     * @param array $query
     * @return array
     * @throws
     */
    private function getPagerWhere(array $query)
    {
        //获取场次数据，场次id
        $rid = $query['rid'];
        $roundInfo = PrdBidRoundModel::M()->getRowById($rid, 'stat');
        if ($roundInfo == false)
        {
            throw new AppException('场次数据不存在', AppException::NO_DATA);
        }

        //固定条件
        $where = ['rid' => $rid];

        if ($roundInfo['stat'] != 14)
        {
            $where['isatv'] = 1;
        }

        //活动类别，1-活动，2-特价
        if (in_array($query['tid'], [1, 2]))
        {
            $where['tid'] = ['!=' => $query['tid']];
        }

        //是否流标上架
        if ($query['lose'])
        {
            $where['stat'] = 22;
        }

        //库存编号
        $bcodes = $query['bcodes'];
        if ($bcodes)
        {
            $bcode = explode(',', $bcodes);
            $prdWhere['bcode'] = ['in' => $bcode];
        }

        //来源渠道
        $plat = $query['plat'];
        if ($plat > 0)
        {
            if ($plat == 18)
            {
                $where['inway'] = ['in' => [2, 21]];
            }
            else
            {
                $where['plat'] = $plat;
            }
        }

        //供应商名称
        $oname = $query['oname'];
        if (!empty($oname))
        {
            $oWhere['oname'] = ['like' => '%' . $oname . '%'];
            $offer = CrmOfferModel::M()->getDistinct('oid', $oWhere);
            $where['offer'] = count($offer) > 0 ? ['in' => $offer] : -1;
        }

        //品牌
        if ($query['bid'] > 0)
        {
            $where['bid'] = $query['bid'];
        }

        //级别
        $level = $query['level'];
        $where['level'] = ['<' => 40];
        if (!empty($level) && $level < 40)
        {
            $where['level'] = $level;
        }

        //是否上架
        $onshelf = $query['onshelf'];
        if ($onshelf)
        {
            $prdWhere['upshelfs'] = $onshelf == 1 ? ['>' => 0] : 0;
        }

        //是否流标
        $nobids = $query['nobids'];
        if ($nobids)
        {
            $prdWhere['nobids'] = $nobids == 1 ? ['>' => 0] : 0;
        }

        if (isset($prdWhere))
        {
            $pids = PrdProductModel::M()->getDistinct('pid', $prdWhere);
            $where['pid'] = count($pids) > 0 ? ['in' => $pids] : -1;
        }

        return $where;
    }
}