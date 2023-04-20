<?php
namespace App\Module\Sale\Logic\Backend\Shop;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopFavoriteModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Prd\PrdWaterModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Sale\Data\SaleDictData;
use App\Module\Sale\Data\XinxinDictData;
use App\Module\Sale\Logic\Backend\Bid\BidSalesLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

class ShopSalesLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject()
     * @var BidSalesLogic
     */
    private $bidSalesLogic;

    /**
     * 一口价商品翻页数据
     * @param string $tabtype
     * @param array $query
     * @param int $size
     * @param int $idx
     * @param string $acc
     * @return array
     * @throws
     */
    public function getPager(string $tabtype, array $query, int $size, int $idx, string $acc)
    {
        //数据条件
        $where = $this->getPagerWhere($tabtype, $query);

        //排序方式
        $order = ['A.atime' => -1];
        if ($tabtype == 'online' || $tabtype == 'open')
        {
            $order = ['A.ptime' => -1];
        }
        if ($tabtype == 'edit')
        {
            $order = [
                'A.atime' => -1,
                'A.mid' => 1,
                'B.level' => 1,
                'B.mdram' => 1,
                'B.mdnet' => 1,
                'B.mdcolor' => 1,
                'B.mdofsale' => 1,
                'B.mdwarr' => 1
            ];
        }
        $order['A.sid'] = -1;

        //获取数据
        $cols = 'A.sid,A.pid,A.bid,A.mid,A.tid,A.bprc,A.bps,A.bus,A.favs,A.pvs,A.uvs,A.stat,A.atime,A.ptime,A.away,A.luckbuyer,A.luckodr,A.inway,A.isatv,A.lucktime';
        $cols .= ",B.bcode,B.plat,B.pname,B.palias,B.level,B.offer,B.salecost,B.nobids,B.upshelfs,B.cost31,B.mid,B.mdram,B.mdnet,B.mdcolor,B.mdofsale,B.mdwarr";
        $list = PrdShopSalesModel::M()->leftJoin(PrdProductModel::M(), ['pid' => 'pid'])->getList($where, $cols, $order, $size, $idx);

        if ($list)
        {
            $time = time();

            //提取id
            $sids = ArrayHelper::map($list, 'sid');
            $pids = ArrayHelper::map($list, 'pid');
            $buyers = ArrayHelper::map($list, 'luckbuyer');
            $odrs = ArrayHelper::map($list, 'luckodr');
            $oids = ArrayHelper::map($list, 'offer');

            //获取品牌机型级别字典
            $bidDict = QtoBrandModel::M()->getDict('bid', [], 'bname');
            $midDict = QtoModelModel::M()->getDict('mid', [], 'mname');
            $levelDict = QtoLevelModel::M()->getDict('lkey', [], 'lname');

            //获取用户数据
            $accDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $buyers]], 'uname');

            //获取支付数据
            $odrDict = OdrOrderModel::M()->getDict('oid', ['oid' => $odrs], 'paytime');

            //获取供应商数据
            $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $oids]], 'oname');

            //获取供应数据
            $supplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'salestat' => 1], 'imgpack');

            //获取质检信息
            $reportDict = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => XinxinDictData::PLAT], 'bconc', ['atime' => 1]);

            //获取关注数据
            $favDict = PrdShopFavoriteModel::M()->getDict('sid', ['sid' => ['in' => $sids], 'isatv' => 1, '$group' => 'sid'], 'count(*) as count');

            //获取良转优记录
            $stcDict = StcStorageModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'twhs' => 104], 'sid');

            //补充数据
            $priceDict = [];
            foreach ($list as $key => $item)
            {
                $pid = $item['pid'];

                //产品图片
                $imgpack = $supplyDict[$pid]['imgpack'] ?? '[]';
                $imgs = json_decode($imgpack, true) ?: [];
                foreach ($imgs as $k => $v)
                {
                    $imgs[$k]['thumb'] = Utility::supplementProductImgsrc($v['src'], 100);
                    $imgs[$k]['src'] = Utility::supplementProductImgsrc($v['src']);
                }

                //状态
                if ($item['stat'] == 31 && $item['ptime'] > $time)
                {
                    $statDesc = '待公开';
                    $list[$key]['published'] = 0;
                }
                else
                {
                    $statDesc = SaleDictData::SHOP_SALES_STAT[$item['stat']] ?? '-';
                    $list[$key]['published'] = 1;
                }
                if ($item['stat'] == 32 && $item['luckodr'] != '')
                {
                    $statDesc = '待付款';
                }

                //中标用户
                $uname = '-';
                if (isset($accDict[$item['luckbuyer']]))
                {
                    if (($item['stat'] == 32 && $item['luckodr'] != '') || ($item['stat'] == 33))
                    {
                        $uname = $accDict[$item['luckbuyer']]['uname'] ?: '-';
                    }
                }
                $list[$key]['bcode'] = $item['bcode'] ?? '-';
                $list[$key]['pname'] = $item['pname'] ?? '-';
                $list[$key]['palias'] = $item['palias'] ?? '-';
                $list[$key]['bname'] = $bidDict[$item['bid']]['bname'] ?? '-';
                $list[$key]['mname'] = $midDict[$item['mid']]['mname'] ?? '-';
                $list[$key]['oname'] = $offerDict[$item['offer']]['oname'] ?: '-';
                $list[$key]['imgs'] = $imgs;
                $list[$key]['lname'] = $item['level'] ? ($levelDict[$item['level']]['lname'] ?? '-') : '-';
                $list[$key]['statDesc'] = $statDesc;
                $list[$key]['uname'] = $uname;
                $list[$key]['atime'] = DateHelper::toString($item['atime']);
                $list[$key]['ptime'] = DateHelper::toString($item['ptime']);
                $list[$key]['stime'] = DateHelper::toString(($odrDict[$item['luckodr']]['paytime'] ?? 0));
                $list[$key]['len'] = ceil(($time - $item['atime']) / 86400) ?: '-';
                $list[$key]['nobids'] = $item['nobids'] ?: '-';
                $list[$key]['upshelfs'] = $item['upshelfs'] > 0 ? '是' : '否';
                $list[$key]['salecost'] = $item['salecost'] ?: '-';
                $list[$key]['pvs'] = $item['pvs'] ?: '-';
                $list[$key]['uvs'] = $item['pvs'] ?: '-';
                $list[$key]['bps'] = $item['bps'] ?: '-';
                $list[$key]['bus'] = $item['bus'] ?: '-';
                $list[$key]['bprc'] = $item['bprc'] ?: ($tabtype == 'edit' && $query['bid'] != 0 ? '' : '-');
                $list[$key]['favs'] = $favDict[$item['sid']]['count'] ?? '-';
                $list[$key]['bconc'] = $reportDict[$pid]['bconc'] ?? '-';

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

                $mid = $item['mid'] ?? '';
                $level = $item['level'] ?? '';
                $mdram = $item['mdram'] ?? '';
                $mdnet = $item['mdnet'] ?? '';
                $mdcolor = $item['mdcolor'] ?? '';
                $mdofsale = $item['mdofsale'] ?? '';
                $mdwarr = $item['mdwarr'] ?? '';
                $priceKey = "$mid$level$mdram$mdnet$mdcolor$mdofsale$mdwarr";
                if ($priceKey != '' && isset($priceDict[$priceKey]))
                {
                    $list[$key]['saleamt1'] = $priceDict[$priceKey]['saleamt1'] ?? 0;
                    $list[$key]['saletime1'] = $priceDict[$priceKey]['saletime1'] ?? 0;
                    $list[$key]['bconc1'] = $priceDict[$priceKey]['bconc1'] ?? '';
                    $list[$key]['saleamt2'] = $priceDict[$priceKey]['saleamt2'] ?? 0;
                    $list[$key]['saletime2'] = $priceDict[$priceKey]['saletime2'] ?? 0;
                    $list[$key]['bconc2'] = $priceDict[$priceKey]['bconc2'] ?? '';
                    $list[$key]['saleamt3'] = $priceDict[$priceKey]['saleamt3'] ?? 0;
                    $list[$key]['saletime3'] = $priceDict[$priceKey]['saletime3'] ?? 0;
                    $list[$key]['bconc3'] = $priceDict[$priceKey]['bconc3'] ?? '';
                }
                else
                {
                    //size>=50为导出  不需要查询
                    if ($size < 50)
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
                }

                //标签
                //1-流标，2-特价，3-供应商，4-活动，5-不良品
                $list[$key]['flag'] = [];
                if (isset($item) && $item['nobids'] > 0)
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
                /*if (isset($item) && $item['cost31'] > 0)
                {
                    $list[$key]['flag'][] = 5;
                }*/
                if (isset($stcDict[$item['pid']]))
                {
                    $list[$key]['flag'][] = 5;
                }
            }
        }

        //补充默认参数
        ArrayHelper::fillDefaultValue($list, ['0.00', null]);

        //获取用户权限
        $permis = AccUserModel::M()->exist(['aid' => $acc, 'permis' => ['like' => "%sale00101%"]]);
        if ($permis == false)
        {
            $this->bidSalesLogic->filterPrc($list, ['salecost']);
        }

        //返回
        return $list;
    }

    /**
     * 一口价商品条数
     * @param string $type
     * @param array $query
     * @return int
     */
    public function getCount(string $type, array $query)
    {
        //数据条件
        $where = $this->getPagerWhere($type, $query);

        //返回
        return PrdShopSalesModel::M()->leftJoin(PrdProductModel::M(), ['pid' => 'pid'])->getCount($where);
    }

    /**
     * 查询条件
     * @param string $tabtype
     * @param array $query
     * @return array
     */
    private function getPagerWhere(string $tabtype, array $query)
    {
        $where = [];

        //tab页参数
        switch ($tabtype)
        {
            //所有商品
            case 'all':
                break;
            //在线商品
            //下架、转场的弹窗列表不显示冻结中商品
            case 'online':
                $where['A.isatv'] = 1;
                $where['A.stat'] = $query['from'] == 'online' ? 31 : ['in' => [31, 32]];
                $where['A.ptime'] = ['<=' => time()];
                break;
            //编辑中
            case 'edit':
                $where['A.stat'] = 11;
                $where['A.isatv'] = 0;
                break;
            //待公开
            case 'open':
                $where['A.stat'] = 31;
                $where['A.ptime'] = ['>' => time()];
                $where['A.isatv'] = 1;
                break;
        }

        //库存编码
        $bcode = $query['bcode'];
        if ($bcode)
        {
            $where['B.bcode'] = $bcode;
        }

        //来源
        $plat = $query['plat'];
        if ($plat)
        {
            if ($plat == 18)
            {
                $where['A.inway'] = ['in' => [2, 21]];
            }
            else
            {
                $where['B.plat'] = $plat;
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
                $where['B.offer'] = ['in' => $oids];
            }
            else
            {
                $where['A.pid'] = -1;
            }
        }

        //品牌
        $bid = $query['bid'];
        if ($bid)
        {
            $where['A.bid'] = $bid;
        }

        //机型
        $mid = $query['mid'];
        if ($mid)
        {
            $where['A.mid'] = $mid;
        }

        //级别
        $lkey = $query['lkey'];
        if ($lkey)
        {
            $where['B.level'] = $lkey;
        }

        //状态
        $stat = $query['stat'];
        if ($stat)
        {
            //销售中
            if ($stat == 31)
            {
                $where['A.ptime'] = ['<=' => time()];
                $where['A.stat'] = 31;
                $where['A.isatv'] = 1;
            }
            elseif ($stat == 99)
            {
                //待付款
                $where['A.stat'] = 32;
                $where['A.luckodr'] = ['!=' => ''];
            }
            elseif ($stat == 98)
            {
                $where['A.stat'] = 31;
                $where['A.ptime'] = ['>' => time()];
            }
            else
            {
                $where['A.stat'] = $stat;
            }
        }

        //是否填价
        $isprc = $query['isprc'];
        if ($isprc)
        {
            $where['A.bprc'] = $isprc == 1 ? ['>' => 0] : 0;
        }

        //时间类型
        $ttype = $query['ttype'];
        $time = $query['time'];
        if ($ttype && count($time) == 2)
        {
            $stime = strtotime($time[0] . ' 00:00:00');
            $etime = strtotime($time[1] . ' 23:59:59');
            switch ($ttype)
            {
                //上架时间
                case 1:
                    $where['A.atime'] = ['between' => [$stime, $etime]];
                    break;
                //销售时间
                case 2:
                    $odrList = OdrOrderModel::M()->getList(['paytime' => ['between' => [$stime, $etime]]], 'oid');
                    if ($odrList)
                    {
                        $oids = ArrayHelper::map($odrList, 'oid');
                        $where['A.luckodr'] = ['in' => $oids];
                    }
                    else
                    {
                        $where['A.sid'] = -1;
                    }
                    break;
                //公开时间
                case 3:
                    if ($tabtype == 'edit')
                    {
                        $tmp = [time(), $stime, $etime];
                        sort($tmp);
                        $where['A.ptime'] = ['between' => [$tmp[1], $tmp[2]]];
                    }
                    else
                    {
                        $where['A.ptime'] = ['between' => [$stime, $etime]];
                    }
                    break;
            }
        }

        //类型
        if ($query['tid'])
        {
            $where['A.tid'] = ['!=' => $query['tid']];
        }

        //返回
        return $where;
    }

    /**
     * 下架商品
     * @param int $type 类型：1-选择批量，2-查询批量
     * @param string $tabtype tab页类型(状态)
     * @param string $sids
     * @param array $query
     * @param string $acc
     * @throws
     */
    public function remove(int $type, string $tabtype, string $sids, array $query, string $acc)
    {
        $time = time();

        //解析参数
        if ($type == 1)
        {
            $sids = explode(',', $sids);
        }
        else
        {
            $where = $this->getPagerWhere($tabtype, $query);
            $list = PrdShopSalesModel::M()->leftJoin(PrdProductModel::M(), ['pid' => 'pid'])->getList($where, 'sid');
            if ($list == false)
            {
                throw new AppException('一口价商品数据不存在', AppException::NO_DATA);
            }
            $sids = ArrayHelper::map($list, 'sid');
        }

        //获取一口价商品数据
        $salesList = PrdShopSalesModel::M()->getList(['sid' => ['in' => $sids]], 'sid,pid,stat,isatv,pid,ptime,lucktime', ['atime' => -1]);
        if ($salesList == false)
        {
            throw new AppException('一口价商品数据不存在', AppException::NO_DATA);
        }

        //获取商品信息
        $pids = ArrayHelper::map($salesList, 'pid');
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'bcode,pname,oid');

        //更新数据
        $stat11 = $stat33 = [];
        $pids11 = [];
        $waterData = [];
        foreach ($salesList as $key => $item)
        {
            if ($item['stat'] == 32 && $item['lucktime'] > 0)
            {
                throw new AppException("{$prdDict[$item['pid']]['bcode']} -{$prdDict[$item['pid']]['pname']}待付款商品不能下架", AppException::NO_DATA);
            }
            if ($item['stat'] == 32 && $item['lucktime'] > 0)
            {
                throw new AppException("{$prdDict[$item['pid']]['bcode']} -{$prdDict[$item['pid']]['pname']}商品冻结中，不能下架", AppException::NO_DATA);
            }
            if ($item['stat'] == 33)
            {
                throw new AppException("{$prdDict[$item['pid']]['bcode']} -{$prdDict[$item['pid']]['pname']}商品已销售，不能下架", AppException::NO_DATA);
            }

            //编辑中
            if ($item['stat'] == 11)
            {
                $stat11[] = $item['sid'];
                $pids11[] = $item['pid'];
            }
            else
            {
                $pids33[] = $item['pid'];
            }

            //待公开
            if ($item['stat'] == 31 && $item['ptime'] > $time)
            {
                $stat33[] = $item['sid'];
            }

            //销售中
            if ($item['stat'] == 31 && $item['ptime'] <= $time)
            {
                $stat33[] = $item['sid'];
            }

            //生成流水
            $waterData[] = [
                'wid' => IdHelper::generate(),
                'tid' => 917,
                'oid' => $prdDict[$item['pid']]['oid'] ?? '',
                'pid' => $item['pid'],
                'rmk' => '一口价下架',
                'acc' => $acc,
                'atime' => $time
            ];
        }
        if (!$stat11 && !$stat33)
        {
            throw new AppException('商品状态有误', AppException::NO_DATA);
        }

        //编辑中的商品下架，更新为待上架状态
        if ($stat11)
        {
            PrdShopSalesModel::M()->delete(['sid' => ['in' => $stat11]]);
            PrdProductModel::M()->update(['pid' => ['in' => $pids11]], ['stcstat' => 11, 'stctime' => $time]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids11], 'stat' => 1], ['prdstat' => 11]);
        }
        //待公开、在线商品下架，更新为编辑中
        if ($stat33)
        {
            PrdShopSalesModel::M()->update(['sid' => ['in' => $stat33]], [
                'isatv' => 0,
                'stat' => 11,
                'ptime' => 0,
                'pvs' => 0,
                'uvs' => 0,
                'bps' => 0,
                'bus' => 0,
                'favs' => 0,
                'mtime' => $time
            ]);

            //更新商品库存状态为上架中
            PrdProductModel::M()->update(['pid' => ['in' => $pids33]], ['stcstat' => 14, 'stctime' => $time]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids33], 'stat' => 1], ['prdstat' => 14]);
        }

        //清除关注数据
        PrdShopFavoriteModel::M()->delete(['sid' => ['in' => $sids]]);

        //记录流水
        PrdWaterModel::M()->inserts($waterData);
    }

    /**
     * 公开/取消公开
     * @param string $sids 商品ids
     * @param int $type 类型：1-选择，2-查询
     * @param int $stat 状态：0-取消公开，1-公开
     * @param string $ptime 公开时间
     * @param array $query 查询条件
     * @throws
     */
    public function stat(string $sids, int $type, int $stat, string $ptime, array $query)
    {
        $time = time();

        if ($type == 1)
        {
            $sids = explode(',', $sids);
            $salesList = PrdShopSalesModel::M()->getList(['sid' => ['in' => $sids]], 'sid,pid');
        }
        else
        {
            $where = $this->getPagerWhere('open', $query);
            $salesList = PrdShopSalesModel::M()->leftJoin(PrdProductModel::M(), ['pid' => 'pid'])->getList($where, 'sid,pid');
            $sids = ArrayHelper::map($salesList, 'sid');
        }
        if ($salesList == false)
        {
            throw new AppException('一口价商品数据不存在', AppException::NO_DATA);
        }

        //更新数据
        $ptime = strtotime($ptime);
        if ($stat == 1)
        {
            $updateData['stat'] = 31;
            $updateData['isatv'] = 1;
            $updateData['ptime'] = $ptime;
        }
        else
        {
            $updateData['stat'] = 11;
            $updateData['isatv'] = 0;
            $updateData['ptime'] = 0;
        }
        $updateData['mtime'] = $time;
        PrdShopSalesModel::M()->update(['sid' => ['in' => $sids]], $updateData);

        //更新商品、库存状态
        $pids = ArrayHelper::map($salesList, 'pid');
        if ($stat == 1)
        {
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 31, 'stctime' => $time]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 31]);
        }
        else
        {
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 14, 'stctime' => $time]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 14]);
        }
    }

    /**
     * 商品转场
     * @param int $type 类型：1-选择批量，2-查询批量
     * @param string $tabtype tab页类型
     * @param string $rid 场次id
     * @param string $sids
     * @param array $query
     * @param string $acc
     * @throws
     */
    public function shift(int $type, string $tabtype, string $rid, string $sids, array $query, string $acc)
    {
        $time = time();

        //解析参数
        if ($type == 1)
        {
            $sids = explode(',', $sids);
            $list = PrdShopSalesModel::M()->getList(['sid' => ['in' => $sids]], 'sid,ptime,pid,yid,bid,mid,tid,bprc,inway');
        }
        else
        {
            $where = $this->getPagerWhere($tabtype, $query);
            $cols = 'A.sid,A.ptime,A.pid,A.yid,A.bid,A.mid,A.tid,A.bprc,A.inway';
            $list = PrdShopSalesModel::M()->leftJoin(PrdProductModel::M(), ['pid' => 'pid'])->getList($where, $cols);
            $sids = ArrayHelper::map($list, 'sid');
        }
        if ($list == false)
        {
            throw new AppException('一口价商品数据不存在', AppException::NO_DATA);
        }

        //获取场次数据
        $count = count($list);
        $roundInfo = PrdBidRoundModel::M()->getRowById($rid, 'stat,rid,mode,stime,etime,infield,plat,rname');
        if ($roundInfo == false)
        {
            throw new AppException('竞拍场次不存在', AppException::NO_DATA);
        }
        if ($roundInfo['stat'] != 11)
        {
            throw new AppException('竞拍场次非待公开状态，不能转入', AppException::NO_DATA);
        }

        //获取级别数据
        $pids = ArrayHelper::map($list, 'pid');
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'level,offer,oid,inway');

        //更新一口价数据
        $salesData = [];
        $waterDaat = [];
        foreach ($list as $key => $item)
        {
            $pid = $item['pid'];

            //生成竞拍商品数据
            $salesData[] = [
                'sid' => IdHelper::generate(),
                'plat' => $roundInfo['plat'],
                'rid' => $rid,
                'pid' => $item['pid'],
                'yid' => $item['yid'],
                'bid' => $item['bid'],
                'mid' => $item['mid'],
                'level' => $prdDict[$pid]['level'] ?? 0,
                'tid' => $item['tid'],
                'mode' => $roundInfo['mode'],
                'offer' => $prdDict[$pid]['offer'] ?? '',
                'stat' => 11,
                'inway' => $prdDict[$pid]['inway'] ?? 0,
                'isatv' => 1,
                'stime' => $roundInfo['stime'],
                'etime' => $roundInfo['etime'],
                'infield' => $roundInfo['infield'],
                'spike' => 1,
                'away' => 2,
                'atime' => $time,
                'kprc' => $item['bprc'] ?? 0,
            ];

            //生成流水
            $waterDaat[] = [
                'wid' => IdHelper::generate(),
                'tid' => 919,
                'oid' => $prdDict[$item['pid']]['oid'] ?? '',
                'pid' => $item['pid'],
                'rmk' => "一口价转到『{$roundInfo['rname']}』竞拍场",
                'acc' => $acc,
                'atime' => $time
            ];
        }

        //竞拍商品上架
        PrdBidSalesModel::M()->inserts($salesData);

        //更新场次上架商品数
        $count = PrdBidSalesModel::M()->getCount(['rid' => $rid]);
        PrdBidRoundModel::M()->updateById($rid, ['upshelfs' => $count]);

        //删除一口价商品
        PrdShopSalesModel::M()->delete(['sid' => ['in' => $sids]]);

        //更新商品、库存状态
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 14, 'stctime' => $time]);
        StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 14]);

        //记录流水
        PrdWaterModel::M()->inserts($waterDaat);
    }

    /**
     * 编辑中商品品牌列表
     * @return array
     * @throws
     */
    public function getBrands()
    {
        $data = [];
        $bids = PrdShopSalesModel::M()->getDistinct('bid', ['stat' => 11, 'isatv' => 0]);
        if ($bids)
        {
            //获取品牌字典
            asort($bids);
            $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');
            foreach ($bids as $key => $item)
            {
                $data[] = [
                    'bid' => $item,
                    'bname' => $bidDict[$item]['bname'] ?? '-'
                ];
            }
        }

        //返回
        return $data;
    }

    /**
     * 活动/特价标记
     * @param string $sids 商品id集合
     * @param int $tid 类型id，1-活动，2-特价
     * @param int $type 类型，1-选择，2-搜索
     * @param array $query 搜索条件
     * @throws
     */
    public function mark(string $sids, int $tid, int $type, array $query)
    {
        //获取商品数据
        if ($type == 1)
        {
            $sids = explode(',', $sids);
            $salesList = PrdShopSalesModel::M()->getList(['sid' => ['in' => $sids]], 'stat');
        }
        else
        {
            $where = $this->getPagerWhere('edit', $query);
            $salesList = PrdShopSalesModel::M()->leftJoin(PrdProductModel::M(), ['pid' => 'pid'])->getList($where, 'stat');
        }
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
        PrdShopSalesModel::M()->update(['sid' => ['in' => $sids]], ['tid' => $tid, 'mtime' => time()]);
    }

    /**
     * 填价
     * @param string $sid
     * @param int $price
     * @param string $acc
     * @throws
     */
    public function price(string $sid, int $price, string $acc)
    {
        //数据验证
        $info = PrdShopSalesModel::M()->getRowById($sid, 'stat,pid');
        if ($info == false)
        {
            throw new AppException('数据不存在', AppException::NO_DATA);
        }
        if ($info['stat'] != 11)
        {
            throw new AppException('当前状态不允许填价', AppException::NO_DATA);
        }

        //更新数据
        PrdShopSalesModel::M()->updateById($sid, ['bprc' => intval($price), 'mtime' => time()]);

        //记录流水
        $oid = PrdProductModel::M()->getOneById($info['pid'], 'oid', [], '');
        PrdWaterModel::M()->insert(['wid' => IdHelper::generate(),
            'tid' => 920,
            'oid' => $oid,
            'pid' => $info['pid'],
            'rmk' => "一口价商品编辑价格：$price",
            'acc' => $acc,
            'atime' => time()
        ]);
    }

    /**
     * 导出数据
     * @param string $tabType
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     * @throws
     */
    public function export(string $tabType, array $query, int $size, int $idx)
    {
        $head = [
            'bcode' => '库存编号',
            'bname' => '品牌',
            'mname' => '机型',
            'pname' => '商品名称',
            'level' => '级别',
            'oname' => '来源',
            'trans' => '是否良转优',
            'salecost' => '成本价',
            'bprc' => '一口价',
            'profit' => '利润',
            'profitRate' => '利润率',
            'nobids' => '流标次数',
            'stctime' => '入库时间',
            'ptime' => '上架时间',
            'lucktime' => '中标时间',
            'bconc' => '质检备注',
        ];

        //获取分页数据
        $list = $this->getPager($tabType, $query, $size, $idx, '');
        if (empty($list))
        {
            return [
                'head' => $head,
                'list' => [],
                'pages' => 0,
            ];
        }

        //获取数据总量
        $countKey = 'sale_shop_all_export_' . md5(json_encode($query));
        $count = $this->redis->get($countKey);
        if (!$count)
        {
            $count = $this->getCount($tabType, $query);
            if ($count > 50000)
            {
                throw new AppException('最多导出5w，请筛选条件缩小数据范围');
            }
            $this->redis->set($countKey, $count, 60);
        }

        $pids = array_column($list, 'pid');

        //获取入库时间字典
        $stcDict = StcStorageModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'twhs' => 101], 'pid,ftime', ['ftime' => 1]);

        //获取成本字典
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pid,salecost');

        //组装导出数据
        $newList = [];
        foreach ($list as $value)
        {
            $pid = $value['pid'];
            $trans = '否';
            if (in_array(5, $value['flag']))
            {
                $trans = '是';
            }

            $stctime = $stcDict[$pid]['ftime'] ?? 0;
            $stctime = DateHelper::toString($stctime);

            $lucktime = DateHelper::toString($value['lucktime']);

            //计算利润 利润率
            $salecost = $prdDict[$pid]['salecost'] ?? 0;
            $profit = '-';
            if ($value['bprc'] > 0)
            {
                $profit = $value['bprc'] - $salecost;
            }
            $profitRate = '-';
            if ($salecost > 0 && $profit > 0)
            {
                $profitRate = round($profit / $salecost * 100, 2);
            }
            $newList[] = [
                'bcode' => $value['bcode'],
                'bname' => $value['bname'],
                'mname' => $value['mname'],
                'pname' => $value['pname'] . ' ' . $value['palias'],
                'level' => $value['lname'],
                'oname' => $value['oname'],
                'trans' => $trans,
                'salecost' => $salecost,
                'bprc' => $value['bprc'],
                'profit' => $profit,
                'profitRate' => $profitRate,
                'nobids' => $value['nobids'],
                'stctime' => $stctime,
                'ptime' => $value['ptime'],
                'lucktime' => $lucktime,
                'bconc' => $value['bconc'],
            ];
        }

        return [
            'head' => $head,
            'list' => $newList,
            'pages' => ceil($count / $size),
        ];
    }
}