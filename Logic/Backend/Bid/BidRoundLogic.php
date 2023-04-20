<?php
namespace App\Module\Sale\Logic\Backend\Bid;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Crm\CrmRemindModel;
use App\Model\Dnet\PrdRoundModel;
use App\Model\Dnet\PrdSalesModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Sale\Data\SaleDictData;
use App\Module\Sale\Data\XinxinDictData;
use App\Module\Smb\Data\SmbNodeKeyData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

/**
 * 竞拍场次相关接口逻辑
 * Class BidRoundLogic
 * @package App\Module\Sale\Logic\Backend\Operation\Bid
 */
class BidRoundLogic extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * @Inject("amqp_message_task")
     * @var Amqp
     */
    private $amqp_message;

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
        $where = ['plat' => XinxinDictData::PLAT];

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

        if ($query['infield'] == 1)
        {
            $where['infield'] = 0;
        }
        if ($query['infield'] == 2)
        {
            $where['infield'] = 1;
        }

        //返回
        return $where;
    }

    /**
     * 导出数据
     * @param array $query
     * @param string $acc
     * @return array
     * @throws
     */
    public function export(array $query, string $acc)
    {
        //查询条件
        $where = [
            'plat' => 21
        ];

        //库存编号
        $bcode = $query['bcode'];
        if ($bcode)
        {
            $pid = PrdProductModel::M()->getOne(['bcode' => $bcode], 'pid');
            if ($pid)
            {
                $salesList = PrdBidSalesModel::M()->getList(['pid' => $pid], 'rid');
                if ($salesList)
                {
                    $rids = ArrayHelper::map($salesList, 'rid');
                    $where['rid'] = ['in' => $rids];
                }
            }
        }

        //开始时间
        $rtime = $query['rtime'];
        if (count($rtime) == 2)
        {
            $stime = strtotime($rtime[0] . ' 00:00:00');
            $etime = strtotime($rtime[1] . ' 23:59:59');
            $where['stime'] = ['between' => [$stime, $etime]];
        }

        //场次名称
        $rname = $query['rname'];
        if ($rname)
        {
            $roundList = PrdBidRoundModel::M()->getList(['rname' => ['like' => "%$rname%"]], 'rid');
            if ($roundList)
            {
                $rids = ArrayHelper::map($roundList, 'rid');
                $where['rid'] = ['in' => $rids];
            }
        }

        //条件验证
        if (!$where)
        {
            throw new AppException('查询条件有误', AppException::OUT_OF_OPERATE);
        }

        //获取数据
        $cols = 'sid,rid,pid,bid,stat,mid,level,offer,sprc,kprc,bprc,pvs,uvs,bps,favs,bway,luckbuyer,lucktime,luckname,atime,ord';
        $list = PrdBidSalesModel::M()->getList($where, $cols);
        if ($list == false)
        {
            throw new AppException('无可导出的数据', AppException::NO_DATA);
        }

        //提取id
        $rids = ArrayHelper::map($list, 'rid');
        $pids = ArrayHelper::map($list, 'pid');
        $bids = ArrayHelper::map($list, 'bid');
        $mids = ArrayHelper::map($list, 'mid');
        $offers = ArrayHelper::map($list, 'offer');
        $buyers = ArrayHelper::map($list, 'luckbuyer');

        //获取场次字典
        $roundDict = PrdBidRoundModel::M()->getDict('rid', ['rid' => ['in' => $rids]], 'rname,stime,etime');

        //获取品牌、机型、级别字典
        $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');
        $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');
        $levelDict = QtoLevelModel::M()->getDict('lkey', [], 'lkey,lname');

        //获取商品信息
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'bcode,pname,palias,elevel,rectime4,nobids,cost31,salecost,supcost');

        //来源信息
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => $offers], 'oname');

        //获取用户信息
        $accDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $buyers]], 'uname');

        //获取质检信息
        $reportDict = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => XinxinDictData::PLAT], 'bconc', ['atime' => 1]);

        foreach ($list as $key => $item)
        {
            $pid = $item['pid'];
            $rid = $item['rid'];

            //商品信息
            $list[$key]['bcode'] = $prdDict[$pid]['bcode'] ?? '-';
            $list[$key]['pname'] = $prdDict[$pid]['pname'] . $prdDict[$pid]['palias'] ?: '-';
            $list[$key]['rectime4'] = DateHelper::toString($prdDict[$pid]['rectime4'] ?? 0);
            $list[$key]['nobids'] = $prdDict[$pid]['nobids'] ?? '-';
            $list[$key]['cost31'] = $prdDict[$pid]['cost31'] > 0 ? '是' : '否';
            $list[$key]['salecost'] = $prdDict[$pid]['salecost'] ?? 0;
            if ($list[$key]['salecost'] == 0)
            {
                $list[$key]['salecost'] = $prdDict[$pid]['supcost'] ?? '-';
            }
            $list[$key]['oname'] = $offerDict[$item['offer']]['oname'] ?? '-';
            $list[$key]['bname'] = $bidDict[$item['bid']]['bname'] ?? '-';
            $list[$key]['mname'] = $midDict[$item['mid']]['mname'] ?? '-';
            $list[$key]['lname'] = $levelDict[$item['level']]['lname'] ?? '-';
            $list[$key]['ename'] = $levelDict[$prdDict[$pid]['elevel']]['lname'] ?? '-';
            $list[$key]['bconc'] = $reportDict[$pid]['bconc'] ?? '-';
            $list[$key]['brmk'] = $batchDict[$pid]['brmk'] ?? '-';

            //场次信息
            $list[$key]['rname'] = $roundDict[$rid]['rname'] ?? '-';
            $list[$key]['stime'] = DateHelper::toString($roundDict[$rid]['stime']);
            $list[$key]['etime'] = DateHelper::toString($roundDict[$rid]['etime']);

            //竞拍商品信息
            $profit = $item['stat'] == 21 ? (($item['bprc'] - $prdDict[$pid]['salecost']) ?? 0) : 0;
            $rate = $prdDict[$pid]['salecost'] > 0 ? (number_format(($profit / $prdDict[$pid]['salecost']), 2) * 100) . '%' : 0;
            $list[$key]['lucktime'] = DateHelper::toString($item['lucktime']);
            $list[$key]['atime'] = DateHelper::toString($item['atime']);
            $list[$key]['bway'] = $item['bway'] == 1 ? '竞价' : '秒杀';
            $list[$key]['pvs'] = '-';
            $list[$key]['uvs'] = '-';
            $list[$key]['profit'] = $profit ?: '-';
            $list[$key]['prorate'] = $item['stat'] == 21 ? $rate : 0;
            $list[$key]['luckbuyer'] = $accDict[$item['luckbuyer']]['uname'] ?? '';

            if ($item['stat'] != 21)
            {
                //未中标不显示成交价
                $list[$key]['bprc'] = '-';
            }
        }
        ArrayHelper::fillDefaultValue($list, ['', 0, '0', '0.00', null]);

        //获取用户权限
        $permis = AccUserModel::M()->exist(['aid' => $acc, 'permis' => ['like' => "%sale00101%"]]);
        if ($permis == false)
        {
            $this->filterPrc($list, ['salecost']);
        }

        //拼装excel数据
        $data['list'] = $list;
        $data['header'] = [
            'bcode' => '库存编号',
            'oname' => '商品来源',
            'bname' => '品牌',
            'mname' => '机型',
            'pname' => '商品名称',
            'lname' => '级别',
            'ename' => '成色',
            'rname' => '场次名称',
            'stime' => '场次开始时间',
            'etime' => '场次结束时间',
            'sprc' => '起拍价',
            'kprc' => '秒杀价',
            'bprc' => '成交价',
            'salecost' => '成本价',
            'profit' => '利润',
            'prorate' => '利润率',
            'bway' => '竞价方式',
            'bps' => '被拍次数',
            'nobids' => '流标次数',
            'pvs' => 'PV',
            'uvs' => 'UV',
            'favs' => '关注数',
            'luckbuyer' => '买家',
            'lucktime' => '中标时间',
            'atime' => '上架时间',
            'rectime4' => '入库时间',
            'ord' => '排序',
            'bconc' => '质检备注',
            'brmk' => '内部备注',
            'cost31' => '是否良转优',
        ];

        //返回数据
        return $data;
    }

    /**
     * 获取编辑场次详情数据
     * @param string $rid 场次id
     * @return array
     * @throws
     */
    public function getEditInfo(string $rid)
    {
        //获取数据
        $info = PrdBidRoundModel::M()->getRowById($rid, 'rname,stat,stime,etime,tid,limited,infield');
        if ($info == false)
        {
            throw new AppException('场次数据不存在', AppException::NO_DATA);
        }
        if (!in_array($info['stat'], [11, 12]))
        {
            throw new AppException('场次数据不允许编辑', AppException::OUT_OF_EDIT);
        }

        //场次时长(分钟)
        $info['len'] = ceil(($info['etime'] - $info['stime']) / 60);
        $info['stime'] = DateHelper::toString($info['stime'], 'Y-m-d H:i');

        //类型
        if ($info['infield'] == 0)
        {
            if ($info['tid'] == 0)
            {
                //日常
                $info['tid'] = 0;
            }
            else
            {
                //活动
                $info['tid'] = 1;
            }
        }
        else
        {
            if ($info['tid'] == 0)
            {
                //内部日常
                $info['tid'] = 2;
            }
            else
            {
                //内部活动
                $info['tid'] = 3;
            }
        }

        //返回
        return $info;
    }

    /**
     * 保存新增或编辑场次数据
     * @param array $data 场次数据
     * @param string $uid 用户id
     * @param int $plat 平台id
     * @throws
     */
    public function save(array $data, string $uid, int $plat)
    {
        //解析参数
        $rid = $data['rid'];
        $rname = $data['rname'];
        $stime = $data['stime'];
        $len = $data['len'];
        $limited = $data['limited'];
        $tid = $data['tid'];

        //数据验证
        if (strtotime($stime) < time())
        {
            throw new AppException('场次开始时间不能早于当前时间', AppException::OUT_OF_OPERATE);
        }

        //扩展参数
        $extData = $this->transTid($tid);
        $stime = strtotime($stime);
        $etime = $stime + $len * 60;

        //编辑
        if ($rid)
        {
            //组装场次数据
            $roundData = [
                'rname' => $rname,
                'stime' => $stime,
                'etime' => $etime,
                'limited' => $limited
            ];

            //更新场次数据
            PrdBidRoundModel::M()->updateById($rid, array_merge($roundData, $extData));

            //组装商品数据
            $salesData = [
                'stime' => $stime,
                'etime' => $etime,
            ];
            PrdBidSalesModel::M()->update(['rid' => $rid], $salesData);
        }
        else
        {
            //组装数据
            $rid = IdHelper::generate();
            $roundData = [
                'rid' => $rid,
                'plat' => $plat ?: XinxinDictData::PLAT,
                'tid' => $extData['tid'],
                'mode' => 1,
                'rname' => $rname,
                'stime' => $stime,
                'etime' => $etime,
                'pvs' => 0,
                'uvs' => 0,
                'bps' => 0,
                'bus' => 0,
                'stat' => 11,
                'ord' => 1,
                'limited' => $limited,
                'infield' => $extData['infield'],
                'groupkey' => ''
            ];
            PrdBidRoundModel::M()->insert($roundData);
        }

        //履约闲鱼寄卖商品到闲鱼
        $xianyuSmbList = PrdBidSalesModel::M()->join(PrdProductModel::M(), ['pid' => 'pid'])
            ->getList(['A.rid' => $rid, 'B.plat' => 161], 'A.sid');
        foreach ($xianyuSmbList as $value)
        {
            AmqpQueue::deliver($this->amqp_common, 'sale_xianyu_join_round', [
                'sid' => $value['sid']
            ]);
        }
    }

    /**
     * 根据tid转换参数
     * @param int $tid
     * @return array
     */
    private function transTid(int $tid)
    {
        $data = [];
        switch ($tid)
        {
            //日常场次
            case 0:
                $data = [
                    'tid' => 0,
                    'infield' => 0,
                ];
                break;
            //活动场次
            case 1:
                $data = [
                    'tid' => 1,
                    'infield' => 0,
                ];
                break;
            //内部日常场次
            case 2:
                $data = [
                    'tid' => 0,
                    'infield' => 1,
                ];
                break;
            //内部活动场次
            case 3:
                $data = [
                    'tid' => 1,
                    'infield' => 1,
                ];
                break;
        }

        return $data;
    }

    /**
     * 删除场次
     * @param string $rid
     * @throws
     */
    public function delete(string $rid)
    {
        //数据验证
        $info = PrdBidRoundModel::M()->getRowById($rid, 'stat');
        if ($info == false)
        {
            throw new AppException('场次数据不存在', AppException::NO_DATA);
        }
        if ($info['stat'] != 11)
        {
            throw new AppException('该场次不允许删除', AppException::OUT_OF_DELETE);
        }

        //获取商品数据
        $salesList = PrdBidSalesModel::M()->getList(['rid' => $rid], 'sid,pid');
        if ($salesList)
        {
            $sids = ArrayHelper::map($salesList, 'sid');
            $pids = ArrayHelper::map($salesList, 'pid');

            //更新商品数据
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => 11]);
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 11]);
            PrdBidSalesModel::M()->delete(['sid' => ['in' => $sids]]);
        }

        //删除数据
        PrdBidRoundModel::M()->deleteById($rid);
        PrdBidFavoriteModel::M()->delete(['rid' => $rid]);
    }

    /**
     * 公开或取消公开场次
     * @param string $rid 场次id
     * @param string $date 场次日期
     * @param int $stat 是否直接公开
     * @param int $type 类型：0-取消公开，1-公开
     * @throws
     */
    public function changeStat(string $rid, string $date, int $stat, int $type)
    {
        $time = time();

        //数据验证
        if ($rid)
        {
            //获取场次数据
            $roundInfo = PrdBidRoundModel::M()->getRowById($rid, 'stat,stime,etime');
            if ($roundInfo == false)
            {
                throw new AppException('场次数据不存在', AppException::NO_DATA);
            }
            if ($type == 1)
            {
                if ($roundInfo['stat'] != 11)
                {
                    throw new AppException('场次非待公开状态', AppException::OUT_OF_OPERATE);
                }
                if ($time > $roundInfo['etime'])
                {
                    throw new AppException('已经超过场次结束时间，不能公开', AppException::OUT_OF_OPERATE);
                }
                if ($time - $roundInfo['stime'] > 600)
                {
                    throw new AppException('已经超过场次开始时间10分钟，不能公开', AppException::OUT_OF_OPERATE);
                }
            }
            else
            {
                if ($roundInfo['stat'] == 13)
                {
                    throw new AppException('该场次已在竞拍中，无法取消公开', AppException::OUT_OF_OPERATE);
                }
                if ($roundInfo['stat'] != 12)
                {
                    throw new AppException('场次非待开场状态，不能取消公开', AppException::OUT_OF_OPERATE);
                }
            }

            //获取竞拍商品信息
            $salesList = PrdBidSalesModel::M()->getList(['rid' => $rid], 'sid,bid,pid,yid,sprc,kprc,aprc');
            if ($salesList == false)
            {
                throw new AppException('该场次没有商品数据，无法公开', AppException::OUT_OF_OPERATE);
            }
            $pids = ArrayHelper::map($salesList, 'pid');

            if ($type == 1)
            {
                //获取商品信息
                $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pname,bcode,imgstat');
                $prdSupplyDict = PrdSupplyModel::M()->getDict('sid', ['pid' => ['in' => $pids], 'salestat' => 1], 'sid');

                //检查竞拍商品是否允许公开
                foreach ($salesList as $key => $item)
                {
                    if ($item['sprc'] == 0 || $item['kprc'] == 0 || $item['aprc'] == 0)
                    {
                        throw new AppException('该场次未完成填价，无法公开', AppException::OUT_OF_OPERATE);
                    }
                    if (isset($prdDict[$item['pid']]) && $prdDict[$item['pid']]['imgstat'] != 2)
                    {
                        $pname = $prdDict[$item['pid']]['pname'] ?? '';
                        $bcode = $prdDict[$item['pid']]['bcode'] ?? '';
                        throw new AppException("“{$bcode}-{$pname}”未上传图片，请上传后重试", AppException::OUT_OF_OPERATE);
                    }
                    if (empty($prdSupplyDict[$item['yid']]))
                    {
                        $bcode = $prdDict[$item['pid']]['bcode'] ?? $item['sid'];
                        throw new AppException("商品「{$bcode}」供应数据异常，请联系研发部处理", AppException::OUT_OF_OPERATE);
                    }
                }

                //生成分组groupkey
                $bids = ArrayHelper::map($salesList, 'bid');
                sort($bids);
                $bids = implode('', $bids);
                $roundIds = array_map(function ($item) {
                    return $item['id'];
                }, SaleDictData::ROUND_GROUPKEY);
                $groupkey = !in_array($bids, $roundIds) ? 99 : $bids;
                PrdBidRoundModel::M()->updateById($rid, ['groupkey' => $groupkey]);

                //新新二手机场次公开提醒
                $this->sendRemind($rid, $roundInfo['stime'], $roundInfo['etime']);
            }
            $rids = [$rid];
        }
        elseif ($date && $type == 1)
        {
            //获取竞拍场次数据
            $stime = strtotime($date . ' 00:00:00');
            $etime = strtotime($date . ' 23:59:59');
            $where = ['stime' => ['between' => [$stime, $etime]], 'stat' => 11, 'mode' => 1];
            $roundDict = PrdBidRoundModel::M()->getDict('rid', $where, 'rid,rname,stat,stime,etime,upshelfs', ['stime' => 1]);
            if ($roundDict == false)
            {
                throw new AppException('场次数据不存在', AppException::NO_DATA);
            }

            //异常判断
            foreach ($roundDict as $item)
            {
                if ($item['upshelfs'] <= 0)
                {
                    throw new AppException("{$date}日存在没有商品的场次，无法公开", AppException::OUT_OF_OPERATE);
                }
                if ($time > $item['etime'])
                {
                    throw new AppException("{$date}日场次「{$item['rname']}」已经超过场次结束时间，不能公开", AppException::OUT_OF_OPERATE);
                }
                if ($time - $item['stime'] > 600)
                {
                    throw new AppException("{$date}日场次「{$item['rname']}」已经超过场次开始时间10分钟，不能公开", AppException::OUT_OF_OPERATE);
                }
            }

            //获取竞拍商品信息
            $rids = array_keys($roundDict);
            $salesList = PrdBidSalesModel::M()->getList(['rid' => ['in' => $rids]], 'sid,rid,pid,yid,sprc,kprc,aprc,stime');
            if ($salesList == false)
            {
                throw new AppException("{$date}日存在没有商品的场次，无法公开", AppException::OUT_OF_OPERATE);
            }

            //获取商品信息
            $pids = ArrayHelper::map($salesList, 'pid');
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pname,bcode,imgstat');

            //获取供应商品数据
            $prdSupplyDict = PrdSupplyModel::M()->getDict('sid', ['pid' => ['in' => $pids], 'salestat' => 1], 'sid');

            //检查竞拍商品是否允许公开
            foreach ($salesList as $key => $item)
            {
                $rname = $roundDict[$item['rid']]['rname'] ?? '';
                $sdate = DateHelper::toString($item['stime'], 'Y-m-d');
                if ($item['sprc'] == 0 || $item['kprc'] == 0 || $item['aprc'] == 0)
                {
                    throw new AppException("{$sdate}日-【{$rname}】场次未完成填价，无法公开", AppException::OUT_OF_OPERATE);
                }
                if (isset($prdDict[$item['pid']]) && $prdDict[$item['pid']]['imgstat'] != 2)
                {
                    $pname = $prdDict[$item['pid']]['pname'] ?? '';
                    $bcode = $prdDict[$item['pid']]['bcode'] ?? '';
                    throw new AppException("“{$sdate}日-【{$rname}】场次，{$bcode}-{$pname}”未上传图片，请上传后重试", AppException::OUT_OF_OPERATE);
                }
                if (empty($prdSupplyDict[$item['yid']]))
                {
                    $bcode = $prdDict[$item['pid']]['bcode'] ?? $item['sid'];
                    throw new AppException("商品「{$bcode}」供应数据异常，请联系研发部处理", AppException::OUT_OF_OPERATE);
                }
            }

            //生成分组groupkey
            foreach ($roundDict as $item)
            {
                $salesList = PrdBidSalesModel::M()->getList(['rid' => $item['rid']], 'bid');
                $bids = ArrayHelper::map($salesList, 'bid');
                sort($bids);
                $bids = implode('', $bids);
                $roundIds = array_map(function ($item) {
                    return $item['id'];
                }, SaleDictData::ROUND_GROUPKEY);
                $groupkey = !in_array($bids, $roundIds) ? 99 : $bids;
                PrdBidRoundModel::M()->updateById($item['rid'], ['groupkey' => $groupkey]);

                //新新二手机场次公开提醒
                $this->sendRemind($item['rid'], $item['stime'], $item['etime']);
            }
        }
        else
        {
            throw new AppException('公开场次参数异常，请稍后重试', AppException::WRONG_ARG);
        }

        /*
         * Todo 完全停止老系统时，可以把这个检查去掉
         * 公开场次时，需要检查新老系统场次数据是否一致
         */
        if ($type == 1)
        {
            $this->checkNewOldRoundData($rids);
        }

        //变更状态
        $stcstat = $type == 1 ? 31 : 14;
        PrdBidRoundModel::M()->update(['rid' => ['in' => $rids]], ['stat' => $type == 1 ? 12 : 11]);
        PrdBidSalesModel::M()->update(['rid' => ['in' => $rids]], ['stat' => $type == 1 ? 12 : 11]);
        PrdProductModel::M()->update(['pid' => ['in' => $pids]], ['stcstat' => $stcstat, 'stctime' => $time]);
        StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => $stcstat]);
        if ($type == 0)
        {
            //清除关注信息
            PrdBidFavoriteModel::M()->delete(['rid' => $rids]);
            PrdBidSalesModel::M()->update(['rid' => $rids], ['pvs' => 0, 'uvs' => 0, 'bps' => 0, 'bus' => 0, 'favs' => 0]);
        }

        //Todo 同步有延迟，这里直接更新老系统状态，完全停止老系统时，可以把这个更新去掉
        PrdRoundModel::M()->update(['_id' => ['in' => $rids]], ['stat' => $type == 1 ? 12 : 11]);
        $newSalesList = PrdBidSalesModel::M()->getList(['rid' => ['in' => $rids]], '_id');
        if ($newSalesList)
        {
            $oldSalesSids = ArrayHelper::map($newSalesList, '_id');
            PrdSalesModel::M()->update(['sid' => ['in' => $oldSalesSids]], ['stat' => $type == 1 ? 12 : 11]);
        }
    }

    /**
     * 场次公开提醒
     * @param string $rid
     * @param int $stime
     * @param int $etime
     */
    private function sendRemind(string $rid, int $stime, int $etime)
    {
        //今日起止时间戳
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayEnd = $todayStart + 86399;

        //非今日场次不提醒
        if (($stime >= $todayStart && $etime < $todayEnd) == false)
        {
            return;
        }

        //获取设置了公开提醒的数据
        $remindList = CrmRemindModel::M()->getList(['rtype' => 103, 'isatv' => 1], 'rid,acc');

        //投递任务
        foreach ($remindList as $key => $value)
        {
            AmqpQueue::deliver($this->amqp_message, 'smb_business_node', [
                'node' => SmbNodeKeyData::BID_ROUND_PUBLIC_AFTER,
                'args' => [
                    'rid' => $rid,
                    'acc' => $value['acc'],
                    'remindId' => $value['rid'],
                ]
            ]);
        }
    }

    /**
     * 确认价格
     * @param string $rid 场次id
     * @param int $stat 状态，0-取消，1-确认
     * @throws
     */
    public function confirm(string $rid, int $stat)
    {
        $roundInfo = PrdBidRoundModel::M()->getRowById($rid, 'upshelfs');
        if ($roundInfo == false)
        {
            throw new AppException('场次数据不存在', AppException::NO_DATA);
        }
        if ($roundInfo['upshelfs'] <= 0)
        {
            throw new AppException('该场次尚未上架商品', AppException::NO_DATA);
        }

        //变更数据
        PrdBidSalesModel::M()->update(['rid' => $rid], ['supcmf' => $stat]);
    }

    /**
     * 复制场次数据
     * @param string $cdate 要复制的日期
     * @param string $pdate 要发布的日期
     * @throws
     */
    public function copy(string $cdate, string $pdate)
    {
        //获取原场次
        $cstime = strtotime($cdate . ' 00:00:00');
        $cetime = strtotime($cdate . ' 23:59:59');
        $copyList = PrdBidRoundModel::M()->getList(['stime' => ['between' => [$cstime, $cetime]], 'mode' => 1]);
        if ($copyList == false)
        {
            throw new AppException($cdate . '不存在场次，请重新选择', AppException::NO_DATA);
        }

        //不能在当天发布
        if (strtotime($pdate . ' 23:59:59') < time())
        {
            throw new AppException('发布日期不能小于当前时间', AppException::OUT_OF_USING);
        }

        //组装数据
        $data = [];
        foreach ($copyList as $key => $item)
        {
            //场次开始、结束时分
            $start = date('H:i:s', $item['stime']);
            $end = date('H:i:s', $item['etime']);

            $data[] = [
                'rid' => IdHelper::generate(),
                'plat' => $item['plat'],
                'tid' => $item['tid'],
                'mode' => $item['mode'],
                'rname' => $item['rname'],
                'stime' => strtotime("$pdate $start"),
                'etime' => strtotime("$pdate $end"),
                'stat' => 11,
                'limited' => $item['limited'],
                'infield' => $item['infield'],
                'groupkey' => $item['groupkey'],
            ];
        }

        //新增数据
        PrdBidRoundModel::M()->inserts($data);
    }

    /**
     * 场次数据统计
     * @param string $rid
     * @return array
     * @throws
     */
    public function statistics(string $rid)
    {
        //数据验证
        $exist = PrdBidRoundModel::M()->existById($rid);
        if ($exist == false)
        {
            throw new AppException('场次数据不存在', AppException::NO_DATA);
        }

        //获取场次商品数据
        $where = [
            'rid' => $rid,
            'stat' => ['in' => [21, 22]],
            '$group' => 'stat'
        ];
        $statDict = PrdBidSalesModel::M()->getDict('stat', $where, 'count(*) as count');

        //秒杀数
        $bway2 = PrdBidSalesModel::M()->getCount(['rid' => $rid, 'bway' => 2]);

        //商品总数
        $count = PrdBidSalesModel::M()->getCount(['rid' => $rid]);

        //组装数据
        $stat21 = $statDict[21]['count'];
        $stat22 = $statDict[22]['count'];
        $data = [
            'stat21' => $stat21,
            'stat22' => $stat22,
            'bway2' => $bway2,
            'rate' => $stat21 > 0 ? number_format(($stat21 / $count) * 100) : 0,
        ];

        //返回
        return $data;
    }

    /**
     * 批量公开场次日期数据
     * @param string $rid
     * @return array
     * @throws
     */
    public function batch(string $rid)
    {
        //获取所有待公开的场次数据
        $data = [];
        $where = ['stat' => 11, 'mode' => 1, 'rid' => ['!=' => $rid], '$group' => 'sdate'];
        $roundList = PrdBidRoundModel::M()->getList($where, "FROM_UNIXTIME(stime,'%Y-%m-%d') as sdate", ['sdate' => 1]);
        if ($roundList)
        {
            $data = ArrayHelper::map($roundList, 'sdate');
        }

        //返回
        return $data;
    }

    /**
     * 获取场次列表数据（即转场列表）
     * @param string $date
     * @param string $rid
     * @return array
     * @throws
     */
    public function getList(string $date, string $rid)
    {
        //获取所有待公开场次数据
        $stime = strtotime($date . ' 00:00:00');
        $etime = strtotime($date . ' 23:59:59');
        $where = ['stime' => ['between' => [$stime, $etime]], 'stat' => 11, 'mode' => 1, 'rid' => ['!=' => $rid]];
        $roundList = PrdBidRoundModel::M()->getList($where, 'rid,rname,upshelfs,stime,etime');
        if ($roundList == false)
        {
            throw new AppException($date . '无待公开场次', AppException::NO_DATA);
        }
        foreach ($roundList as $key => $item)
        {
            $roundList[$key]['ptime'] = DateHelper::toString($item['stime']) . ' ~ ' . DateHelper::toString($item['etime']);
        }

        //返回
        return $roundList;
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

    /**
     * 检查新老数据是否一致
     * @param array $rids 场次ID
     * @throws
     */
    private function checkNewOldRoundData(array $rids)
    {
        $abnormalRound = [];
        $abnormalSales = [];

        //获取场次数据
        $newRoundCols = 'rid,rname,tid,stime,etime,upshelfs,ord,limited,infield,_id';
        $newRoundList = PrdBidRoundModel::M()->getList(['rid' => $rids], $newRoundCols);
        $oldRoundCols = 'rname,stime,etime,ord,onlines as upshelfs,limited,activity as tid,infield,_id';
        $oldRoundDict = PrdRoundModel::M()->getDict('_id', ['_id' => $rids], $oldRoundCols);

        //检验场次数据
        foreach ($newRoundList as $value)
        {
            $rid = $value['rid'];
            $_id = $value['_id'];
            unset($value['rid'], $value['_id']);
            if (!isset($oldRoundDict[$rid]))
            {
                $abnormalRound[$_id] = ['not data'];
                PrdBidRoundModel::M()->updateById($rid, $value);
            }
            else
            {
                foreach (['rname', 'tid', 'stime', 'etime', 'ord', 'limited', 'infield'] as $field)
                {
                    if ($value[$field] != $oldRoundDict[$rid][$field])
                    {
                        $abnormalRound[$_id][] = $field;
                        PrdBidRoundModel::M()->updateById($rid, $value);
                    }
                }
            }
        }

        //获取商品数据
        $newSalesCols = 'sid,tid,sprc,kprc,aprc,stime,etime,spike,ord,infield,inway,_id';
        $newSalesList = PrdBidSalesModel::M()->getList(['rid' => $rids], $newSalesCols);
        if ($newSalesList == false)
        {
            throw new AppException('竞拍商品缺少，请联系技术部处理', AppException::NO_DATA);
        }
        $oldSalesSids = ArrayHelper::map($newSalesList, '_id');
        $oldSalesCols = 'sprc,kprc,aprc,stime,etime,spike,activity as tid,spike,ord,infield,inway,_id';
        $oldSalesDict = PrdSalesModel::M()->getDict('_id', ['sid' => $oldSalesSids], $oldSalesCols);

        //检验商品数据
        foreach ($newSalesList as $value)
        {
            $sid = $value['sid'];
            $_id = $value['_id'];
            unset($value['sid'], $value['_id']);
            if (!isset($oldSalesDict[$sid]))
            {
                $abnormalSales[$_id] = ['not data'];
                PrdBidSalesModel::M()->updateById($sid, $value);
            }
            else
            {
                foreach (['tid', 'sprc', 'kprc', 'aprc', 'stime', 'etime', 'spike', 'ord', 'infield', 'inway'] as $field)
                {
                    if ($value[$field] != $oldSalesDict[$sid][$field])
                    {
                        $abnormalSales[$_id][] = $field;
                        PrdBidSalesModel::M()->updateById($sid, $value);
                    }
                }
            }
        }

        //抛出异常
        if (count($abnormalRound) > 0 || count($abnormalSales) > 0)
        {
            $msg = "新老系统数据不一致，公开场次失败，系统正在自动修复，请稍后重试！";
            if (count($abnormalRound) > 0)
            {
                $msg .= "abnormalRound：" . json_encode($abnormalRound);
            }
            if (count($abnormalSales) > 0)
            {
                $msg .= "abnormalSales：" . json_encode($abnormalSales);
            }
            throw new AppException($msg, AppException::FAILED_OPERATE);
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
}