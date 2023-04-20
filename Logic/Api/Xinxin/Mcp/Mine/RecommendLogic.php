<?php
namespace App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine;

use App\Exception\AppException;
use App\Model\Crm\CrmPrdRecommendModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopFavoriteModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Module\Sale\Data\XinxinDictData;
use App\Service\Acc\AccUserInterface;
use App\Service\Mqc\MqcBatchInterface;
use App\Service\Qto\QtoLevelInterface;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Configer;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class RecommendLogic extends BeanCollector
{
    /**
     * @Reference()
     * @var QtoLevelInterface
     */
    private $qtoLevelInterface;

    /**
     * @Reference()
     * @var MqcBatchInterface
     */
    private $mqcBatchInterface;

    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * 获取推荐的一口价商品数据
     * @param array $query 查询数据
     * @param int $idx 页码
     * @param int $size 每页条数
     * @return array
     * @throws
     */
    public function getShopSales(array $query, int $idx, int $size)
    {
        //参数判断
        $bid = $query['bid'];
        $mid = $query['mid'];
        $uid = $query['uid'];
        if (!$uid)
        {
            throw new AppException('缺少用户', AppException::NO_DATA);
        }

        //获取用u户
        $acc = $this->accUserInterface->getRow(['_id' => $uid], 'aid');
        if (!$acc)
        {
            throw new AppException('没有此用户', AppException::DATA_MISS);
        }
        $acc = $acc['aid'];

        //获取今天日期
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = $stime + 86399;
        $time = time();

        //获取一口价数据
        $cols = 'pid,bid,mid,bprc,_id as sid,tid,luckbuyer,luckodr,stat';
        $shopWhere = [
            '$or' => [
                ['$and' => [['stat' => ['in' => [31, 32]]], ['ptime' => ['<=' => $time]]]],
                ['$and' => [['stat' => 33], ['lucktime' => ['between' => [$stime, $etime]]]]]
            ]
        ];

        if ($bid && $mid)
        {
            $shopWhere['bid'] = $bid;
            $shopWhere['mid'] = $mid;
        }
        elseif ($bid && !$mid)
        {
            $mids = $this->getAllModels($acc);
            $shopWhere['bid'] = $bid;
            $shopWhere['mid'] = ['in' => $mids];
        }
        elseif (!$bid && !$mid)
        {
            $mids = $this->getAllModels($acc);
            $shopWhere['mid'] = ['in' => $mids];
        }
        $shops = PrdShopSalesModel::M()->getList($shopWhere, $cols, ['stat' => 1, 'bid' => 1, 'mid' => 1], $size, $idx);

        //图片路径
        $domain = Configer::get('common')['qiniu']['product'] ?? '';

        //如果有数据
        if ($shops)
        {
            //提取id
            $pids = ArrayHelper::map($shops, 'pid');

            //获取商品数据
            $prds = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'bcode,level,pname');

            //获取图片信息
            $picDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'imgsrc');

            //获取用户关注的机器数据
            $favoriteDict = PrdShopFavoriteModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'buyer' => $acc], 'isatv');

            //提取级别
            $levelDict = $this->qtoLevelInterface->getDict();
            foreach ($shops as $key => $value)
            {
                //获取质检报告
                $report = $this->mqcBatchInterface->getReport($prds[$value['pid']]['bcode']);

                $bconc = '';
                foreach ($report['catedesc'] as $k => $v)
                {
                    if ($v['cid'] == '0001')
                    {
                        $bconc = $v['desc'][0] ?? '';
                        break;
                    }
                }

                //质检报告
                $shops[$key]['bconc'] = explode(':', $bconc)[1] ?? '';

                //商品图片
                $shops[$key]['imgsrc'] = $domain . '/' . $picDict[$value['pid']]['imgsrc'];

                $shops[$key]['lname'] = $levelDict[$prds[$value['pid']]['level']]['lname'] . '货';
                $shops[$key]['pname'] = $prds[$value['pid']]['pname'];

                //是否已关注
                $fstat = isset($favoriteDict[$value['pid']]) && $favoriteDict[$value['pid']]['isatv'] == 1 ? 'on' : '';
                $shops[$key]['fstat'] = $fstat;

                //获取付款状态
                $bidStat = 0;
                if ($value['luckbuyer'])
                {
                    //冻结中
                    $bidStat = 1;
                    if ($value['luckbuyer'] == $acc)
                    {
                        //本人购买
                        $bidStat = 2;
                    }
                }
                if ($value['luckodr'])
                {
                    //已支付
                    $pstat = OdrOrderModel::M()->getOneById($value['luckodr'], 'paystat');
                    if ($pstat == 3)
                    {
                        $bidStat = 3;
                    }
                }
                $shops[$key]['bidStat'] = $bidStat;

                //一口价没有rid，给默认值3529
                $shops[$key]['rid'] = 3529;
                $shops[$key]['sid'] = (int)$value['sid'];
            }
        }

        //返回数据
        return $shops;
    }

    /**
     * 获取竞拍数据
     * @param array $query 查询参数
     * @param int $idx 页码
     * @param int $size 每页条数
     * @return array
     * @throws
     */
    public function getBidSales(array $query, int $idx, int $size)
    {
        //参数判断
        $uid = $query['uid'];
        $bid = $query['bid'];
        $mid = $query['mid'];
        if (!$uid)
        {
            throw new AppException('缺少用户', AppException::NO_DATA);
        }

        //获取用户
        $acc = $this->accUserInterface->getRow(['_id' => $uid], 'aid');
        if (!$acc)
        {
            throw new AppException('没有此用户', AppException::DATA_MISS);
        }
        $acc = $acc['aid'];

        //图片路径
        $domain = Configer::get('common')['qiniu']['product'] ?? '';

        //获取今天日期
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = $stime + 86399;

        //获取今天的竞拍场次数据
        $where = ['plat' => 21, 'stat' => ['>' => 11], 'stime' => ['>' => $stime], 'etime' => ['<=' => $etime]];
        $rounds = PrdBidRoundModel::M()->getList($where, 'rid');

        //如果今天没有已经公开的竞拍数据就取昨天的
        if ($rounds)
        {
            $rids = ArrayHelper::map($rounds, 'rid');
        }
        else
        {
            $tstime = $stime - 86400;
            $tetime = $stime - 1;
            $where = ['plat' => 21, 'stat' => ['>' => 11], 'stime' => ['>' => $tstime], 'etime' => ['<=' => $tetime]];
            $rounds = PrdBidRoundModel::M()->getList($where, 'rid');
            $rids = ArrayHelper::map($rounds, 'rid');
        }

        //数据条件
        $bidWhere = ['stat' => ['>' => 11], 'rid' => ['in' => $rids]];
        if ($bid && $mid)
        {
            $bidWhere['bid'] = $bid;
            $bidWhere['mid'] = $mid;
        }
        elseif ($bid && !$mid)
        {
            $mids = $this->getAllModels($acc);
            $bidWhere['bid'] = $bid;
            $bidWhere['mid'] = ['in' => $mids];
        }
        elseif (!$bid && !$mid)
        {
            $mids = $this->getAllModels($acc);
            $bidWhere['mid'] = ['in' => $mids];
        }

        //获取竞拍数据
        $cols = 'sprc,kprc,aprc,bprc,bps as bids,luckbuyer,luckrgn,level,pid,_id as sid,rid,tid,luckname,lucktime,bway,stat as pstat';
        $bids = PrdBidSalesModel::M()->getList($bidWhere, $cols, ['stat' => 1, 'bid' => 1, 'mid' => 1], $size, $idx);

        $time = time();
        $merge = [];
        if ($bids)
        {
            //提取数据
            $pids = ArrayHelper::map($bids, 'pid');
            $rids = ArrayHelper::map($bids, 'rid');
            $accs = ArrayHelper::map($bids, 'luckbuyer');

            //获取图片信息
            $picDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'imgsrc');

            //获取商品数据
            $prds = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'bcode,level,pname');

            //提取级别
            $levelDict = $this->qtoLevelInterface->getDict();

            //获取地区数据
            $rgnDict = $this->accUserInterface->getAccDict($accs, 'loginarea');

            //获取竞拍场次开始结束时间
            $ridDict = PrdBidRoundModel::M()->getDict('rid', ['rid' => ['in' => $rids]], 'stime,etime,_id');

            //获取用户关注的机器数据
            $favoriteDict = PrdBidFavoriteModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'buyer' => $acc], 'isatv');

            //补充数据
            $merge = [];
            foreach ($bids as $key => $value)
            {
                //获取质检报告
                $report = $this->mqcBatchInterface->getReport($prds[$value['pid']]['bcode']);

                $bconc = '';
                foreach ($report['catedesc'] as $k => $v)
                {
                    if ($v['cid'] == '0001')
                    {
                        $bconc = $v['desc'][0];
                    }
                }

                //质检报告
                $bids[$key]['bconc'] = explode(':', $bconc)[1] ?? '';

                $bids[$key]['lname'] = $levelDict[$prds[$value['pid']]['level']]['lname'] . '货';
                $bids[$key]['pname'] = $prds[$value['pid']]['pname'];
                $bids[$key]['imgsrc'] = $domain . '/' . $picDict[$value['pid']]['imgsrc'];
                $bids[$key]['ismy'] = $value['luckbuyer'] == $acc ? 1 : 0;
                $bids[$key]['favorite'] = isset($favoriteDict[$value['pid']]) && $favoriteDict[$value['pid']]['isatv'] == 1 ? 'on' : '';
                $bids[$key]['lprc'] = $value['bprc'] == 0 ? $value['sprc'] : $value['bprc'] + $value['aprc'];
                if ($bids[$key]['lprc'] > $value['kprc'] && $value['kprc'] != 0)
                {
                    $bids[$key]['lprc'] = $value['kprc'];
                }

                //补充出价
                $bids[$key]['myprc'] = $value['bprc'];

                //中标用户信息
                $bids[$key]['lucktime'] = DateHelper::toString($value['lucktime'], 'Y-m-d H:i:s');
                $bids[$key]['luckname'] = substr_replace($value['luckname'], '****', 3, 7);
                $bids[$key]['luckrgn'] = isset($rgnDict[$value['luckbuyer']]) ? $rgnDict[$value['luckbuyer']]['loginarea'] : '';

                //是否出局
                $bids[$key]['lead'] = $value['luckbuyer'] == $acc ? 2 : 1;

                //获取产品状态
                $bids[$key]['rdate'] = '';
                switch ($value['pstat'])
                {
                    case 12:
                        $odr_pstat[] = 2;
                        $rname = '距开场';
                        $rtime = $ridDict[$value['rid']]['stime'] - $time;
                        break;
                    case 13:
                        $odr_pstat[] = 3;
                        $rname = '距结束';
                        $rtime = $ridDict[$value['rid']]['etime'] - $time;
                        break;
                    default:
                        $odr_pstat[] = 1;
                        $rname = '揭标';
                        $rtime = 0;
                        $value['rdate'] = date('Y-m-d H:i:s', $value['lucktime']);
                        break;
                }
                $bids[$key]['rname'] = $rname;
                $bids[$key]['rtime'] = $rtime;
//            $bids[$key]['rdate'] = $this->format_countdown($rtime);
                $bids[$key]['rdate'] = in_array($value['pstat'], [12, 13]) ? $this->format_countdown($rtime) : $value['rdate'];

                $bids[$key]['pstat_name'] = XinxinDictData::BID_STAT[$value['pstat']];
                $bids[$key]['rid'] = isset($ridDict[$value['rid']]) ? $ridDict[$value['rid']]['_id'] : -1;
            }

            //数组重新排序
            foreach ($bids as $key => $value)
            {
                if ($value['pstat'] == 13)
                {
                    array_unshift($merge, $value);
                }
                else
                {
                    array_push($merge, $value);
                }
            }
        }

        //返回数据
        return $merge;
    }

    function format_countdown($deadline)
    {
        $times = $this->strpad(floor($deadline / 3600)) . ':';
        $times .= $this->strpad(floor($deadline / 60) % 60) . ':';
        $times .= $this->strpad($deadline % 60);

        return $times;
    }

    /**
     * 倒计时补0
     * @param int $num 时间
     * @return string
     */
    function strpad($num)
    {
        return strval(str_pad($num, 2, '0', STR_PAD_LEFT));
    }

    /**
     * 获取用户关注的所有机型
     * @param string $acc 用户id
     * @param int $bid 品牌id
     * @return array
     */
    private function getAllModels(string $acc, int $bid = 0)
    {
        $where = ['plat' => 21, 'buyer' => $acc, 'stat' => 1];
        if ($bid)
        {
            $where['bid'] = $bid;
        }
        $list = CrmPrdRecommendModel::M()->getList($where, 'mid');

        if (!$list)
        {
            return [];
        }

        return ArrayHelper::map($list, 'mid');
    }
}