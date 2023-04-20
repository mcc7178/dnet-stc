<?php

namespace App\Module\Sale\Logic\Api\Xinxin;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Cms\CmsAdvertModel;
use App\Model\Crm\CrmRemindModel;
use App\Model\Dnet\SysAccModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdSupplyModel;
use App\Module\Pub\Data\SysConfData;
use App\Module\Sale\Data\XinxinDictData;
use App\Module\Sale\Logic\Api\Xinxin\Mcp\CommonLogic;
use App\Service\Acc\AccUserInterface;
use App\Service\Qto\QtoLevelInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Configer;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

class SaleLogic extends BeanCollector
{
    /**
     * @Reference("qto")
     * @var QtoLevelInterface
     */
    private $qtoLevelInterface;


    /**
     * @Inject()
     * @var CommonLogic
     */
    private $commonLogic;

    /**
     * 首页-今日竞拍
     * @param string $uid
     * @return mixed
     * @throws
     */
    public function getList(string $uid)
    {
        //当前时间
        $time = time();

        //默认值
        $res = [];

        //获取公开配置项
        $config = SysConfData::D()->get('xinxin_public_time');
        if (!$config)
        {
            throw new AppException('获取配置项失败');
        }

        //时间
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = strtotime(date('Y-m-d 23:59:59'));

        //获取今日竞拍统计数据
        $sales = $this->getStatistics($stime, $etime);

        //是否存在竞拍场次数据
        if (!$sales)
        {
            //指定日期未公开
            if (isset($config['ptime']) && $time >= $config['stime'] && $time <= $config['etime'] && $time < $config['ptime'])
            {
                $ctdown = strtotime($config['ptime']) - $time;
                $res = ['rstat' => 15, 'text' => DateHelper::toString($config['ptime'], 'm月d日 H:i'), 'ctdown' => $ctdown];
            }
            elseif (isset($config['day']) && $time < strtotime($config['day']))
            {
                //默认日期未公开
                $ctdown = strtotime($config['day']) - $time;
                $res = ['rstat' => 11, 'text' => $config['day'], 'ctdown' => $ctdown];
            }

            //是否有设置提醒
            $remind = false;
            if ($uid)
            {
                $remind = CrmRemindModel::M()->exist(['acc' => $uid, 'rway' => 1, 'rtype' => 103, 'isatv' => 1]);
            }

            //竞拍尚未公开
            if ($res)
            {
                $res['remind'] = intval($remind);

                return $res;
            }
        }

        //补充数据
        foreach ($sales as $key => $value)
        {
            $sales[$key]['iconurl'] = XinxinDictData::ICONS[$value['groupkey']]['icon1'];
            $sales[$key]['stime'] = DateHelper::toString($value['stime'], 'H:i');
        }

        //获取竞拍起止时间
        $twhere = ['stime' => ['>=' => $stime], 'etime' => ['<=' => $etime], 'plat' => 21, 'stat' => ['>' => 11], 'infield' => 0];
        $list = PrdBidRoundModel::M()->getList($twhere, 'stime,etime,rname', ['stime' => 1]);

        //无竞拍场次数据
        if (!$list)
        {
            return true;
        }

        //全部未开始
        if ($time < $list[0]['stime'] && ($list[0]['stime'] - $time) > 300)
        {
            $res['rstat'] = 12;
            $res['sales'] = $sales;
            $res['ctdown'] = $list[0]['stime'] - $time;

            //是否设置提醒
            $remind = false;
            if ($uid)
            {
                $remind = CrmRemindModel::M()->exist(['acc' => $uid, 'rway' => 1, 'rtype' => 101, 'isatv' => 1]);
            }
            $res['remind'] = intval($remind);
        }

        //全部已结束
        if ($time >= $list[count($list) - 1]['etime'])
        {
            $res['rstat'] = 14;
            $res['sales'] = $sales;
            if (isset($config['ptime']) && $time < $config['ptime'])
            {
                $res['text'] = DateHelper::toString($config['ptime'], 'm月d日 H:i');
            }
            else
            {
                $res['text'] = '明天 ' . $config['day'];
            }

            //是否设置提醒
            $remind = false;
            if ($uid)
            {
                $remind = CrmRemindModel::M()->exist(['acc' => $uid, 'rway' => 1, 'rtype' => 103, 'isatv' => 1]);
            }
            $res['remind'] = intval($remind);
        }

        if ($res)
        {
            //补充数据
            $res['stime'] = $list ? DateHelper::toString($list[0]['stime'], 'H:i') : 0;
            $res['etime'] = $list ? DateHelper::toString($list[count($list) - 1]['etime'], 'H:i') : 0;
            $res['count'] = $list ? count($list) : 0;

            return $res;
        }

        //获取当前竞拍数据
        $current = $this->getCurrentSales();
        if (!$current)
        {
            throw new AppException('暂无竞拍商品');
        }

        //竞拍状态判断
        if ($current['stime'] <= $time && $time < $current['etime'])
        {
            //单场进行中
            $res['rstat'] = 13;
            $res['ctdown'] = $current['etime'] - $time;
        }
        elseif ($time <= $current['stime'])
        {
            //单场倒计时
            $res['rstat'] = 16;
            $res['ctdown'] = $current['stime'] - $time;
        }

        //补充数据
        $res['sales'] = $current;
        $res['sales']['stime'] = DateHelper::toString($current['stime'], 'H:i');
        $res['sales']['etime'] = DateHelper::toString($current['etime'], 'H:i');
        $res['stime'] = DateHelper::toString($list[0]['stime'], 'H:i');
        $res['etime'] = DateHelper::toString($list[count($list) - 1]['etime'], 'H:i');
        $res['count'] = count($list);

        //返回数据
        return $res;
    }

    /**
     * 获取今天的竞拍场次统计数据
     * @param int $stime
     * @param int $etime
     * @return array
     */
    private function getStatistics(int $stime, int $etime)
    {
        //数据条件
        $where = [
            'plat' => 21,
            'stat' => ['>' => 11],
            'stime' => ['>=' => $stime],
            'etime' => ['<=' => $etime],
            'infield' => 0,
        ];
        $prdBidRount = PrdBidRoundModel::M()->getDict('groupkey', $where, 'rid,groupkey,stime,tid,_id', ['tid' => -1]);
        $where['$group'] = 'groupkey';
        $roundsList = PrdBidRoundModel::M()->getList($where, 'rid,groupkey,tid,count(groupkey) as num,stime,_id', ['stime' => 1]);

        foreach ($roundsList as $key => $value)
        {
            $roundsList[$key]['tid'] = $prdBidRount[$value['groupkey']]['tid'];
        }

        //返回数据
        return $roundsList;
    }

    /**
     * 获取当前场次数据
     * @return array|bool
     */
    private function getCurrentSales()
    {
        //获取待开场或进行中的场次数据
        $where = [
            'plat' => 21,
            'stat' => ['in' => [12, 13]],
            'infield' => 0,
            '$or' => [
                ['stime' => ['>=' => time()]],
                [
                    '$and' => [
                        ['stime' => ['<' => time()]],
                        ['etime' => ['>' => time()]]
                    ]
                ]
            ]
        ];

        $round = PrdBidRoundModel::M()->getRow($where, 'stime,etime,rname,rid,stat,_id,groupkey,tid', ['stime' => 1]);

        //获取竞拍商品数据
        if ($round)
        {
            $sales = PrdBidSalesModel::M()->getList(['rid' => $round['rid']], 'sprc,tid,bid', ['sprc' => 1]);

            $round['sprc'] = $sales[0]['sprc'];
            $round['iconurl'] = XinxinDictData::ICONS[$round['groupkey']]['icon1'];
        }

        //返回数据
        return $round;
    }

    /**
     * 特价竞拍商品
     * @return array
     */
    public function auction()
    {
        //外部参数
        $stime = strtotime(date('Y-m-d 00:00:00'), time());
        $etime = strtotime(date('Y-m-d 23:59:59'), time());
        $stop = false;
        $list = [];

        //获取当日竞拍场次
        $where = [
            'plat' => 21,
            'stat' => ['>' => 11],
            'stime' => ['between' => [$stime, $etime]],
            'infield' => 0
        ];
        $prdbidround = PrdBidRoundModel::M()->getDict('rid', $where, 'rid,stime,etime');

        //获取今日所有竞拍场次开始与结束时间
        $newPrdbidround = array_values($prdbidround);
        $times = ArrayHelper::maps([$newPrdbidround, $newPrdbidround], ['etime', 'stime']);
        $todayetime = $times ? max($times) : 0;
        $todaystime = $times ? min($times) : 0;
        if (time() > $todayetime)
        {
            //今日竞拍全部结束
            $stop = true;
        }
        $rids = ArrayHelper::map($prdbidround, 'rid');
        if (count($rids) == 0)
        {
            return [
                'stop' => $stop,
                'list' => $list,
            ];
        }
        $col = '_id,rid,pid,mid,level,sprc,stat';

        //竞拍中的商品
        $where = [
            'plat' => 21,
            'rid' => ['in' => $rids],
            'tid' => 2,
            'stat' => 13
        ];
        $bidding = PrdBidSalesModel::M()->getList($where, $col, [], 6);

        //待开场的商品
        $where = [
            'plat' => 21,
            'rid' => ['in' => $rids],
            'tid' => 2,
            'stat' => 12
        ];
        $open = PrdBidSalesModel::M()->getList($where, $col, [], 6);
        foreach ($open as $key1 => $item)
        {
            $open[$key1]['stime'] = $prdbidround[$item['rid']]['stime'];
        }
        $stime = array_column($open, 'stime');
        array_multisort($stime, SORT_ASC, $open);

        //竞拍结束商品
        $where = [
            'plat' => 21,
            'rid' => ['in' => $rids],
            'tid' => 2,
            'stat' => ['in' => [21, 22, 31]]
        ];
        $finish = PrdBidSalesModel::M()->getList($where, $col, ['stat' => 1], 6);

        //拼接数组
        $prdbidsales = array_merge($bidding, $open, $finish);
        $prdbidsales = array_slice($prdbidsales, 0, 6);
        if ($prdbidsales)
        {
            //补充商品信息
            $list = $this->addPrdInfo($prdbidsales);

            //补充竞拍开场时间
            foreach ($list as $key => $value)
            {
                $list[$key]['stime'] = date('H:i', $prdbidround[$value['rid']]['stime']);
                switch ($value['stat'])
                {
                    case 12:
                        $list[$key]['statdesc'] = $list[$key]['stime'] . ' 开抢';
                        break;
                    case 13:
                        $list[$key]['statdesc'] = '竞拍中';
                        break;
                    case 21:
                        $list[$key]['statdesc'] = '已售';
                        break;
                    case 22:
                        $list[$key]['statdesc'] = '竞拍结束';
                        break;
                    case 31:
                        $list[$key]['statdesc'] = '已售';
                        break;
                }
            }
        }

        //返回
        return [
            'stime' => $todaystime,
            'stop' => $stop,
            'list' => $list,
        ];
    }

    /**
     * 一口价热卖商品
     * @return array
     */
    public function todaySale()
    {
        //时间范围
        $etime = time();
        $stime = $etime - 86400 * 7;

        //获取一口价商品
        $list = [];
        $where = [
            'stat' => ['in' => [31, 32]],
            'tid' => 2,
            'ptime' => ['between' => [$stime, $etime]],
        ];

        $order = ['tid' => -1, 'stat' => 1, 'atime' => -1, 'sid' => 1];
        $cols = 'sid,_id,pid,mid,bprc,stat,tid';
        $prdshopsales = PrdShopSalesModel::M()->getList($where, $cols, $order, 6);

        //优先取特价机器
        if ($prdshopsales)
        {
            $count = count($prdshopsales);

            //特价机器不够6个用苹果、OPPO、VIVO、华为凑齐
            if ($count < 6)
            {
                $where = [
                    'stat' => ['in' => [31, 32]],
                    'tid' => ['in' => [0, 1]],
                    'bid' => ['in' => [10000, 20000, 80000, 40000]],
                    'ptime' => ['between' => [$stime, $etime]],
                ];
                $fourBrand = PrdShopSalesModel::M()->getList($where, $cols, $order, 6 - $count);

                //合并特价和四大品牌数据
                $prdshopsales = array_merge($prdshopsales, $fourBrand);
                $fourcount = count($prdshopsales);

                //特价+四大品牌不够6个，用其他品牌凑齐
                if ($fourcount < 6)
                {
                    $where['bid'] = ['not in' => [10000, 20000, 80000, 40000]];
                    $others = PrdShopSalesModel::M()->getList($where, $cols, $order, 6 - $count);

                    //合并数据
                    $prdshopsales = array_merge($prdshopsales, $others);
                }
            }
        }
        else
        {
            //没有特价机器的话，取苹果、OPPO、VIVO、华为
            $where = [
                'stat' => ['in' => [31, 32]],
                'tid' => ['in' => [0, 1]],
                'bid' => ['in' => [10000, 20000, 80000, 40000]],
                'ptime' => ['between' => [$stime, $etime]],
            ];
            $prdshopsales = PrdShopSalesModel::M()->getList($where, $cols, $order, 6);
            $count = count($prdshopsales);
            $where['bid'] = ['not in' => [10000, 20000, 80000, 40000]];

            //四个品牌没有的话，就取其他机器
            if (!$prdshopsales)
            {
                $prdshopsales = PrdShopSalesModel::M()->getList($where, $cols, $order, 6);
            }

            //四大品牌机器数量不够的话，用其他的来凑
            if ($count < 6)
            {
                $others = PrdShopSalesModel::M()->getList($where, $cols, $order, 6 - $count);

                //合并数据
                $prdshopsales = array_merge($prdshopsales, $others);
            }
        }

        //补充商品信息
        if ($prdshopsales)
        {
            $list = $this->addPrdInfo($prdshopsales);
            foreach ($list as $key => $value)
            {
                if ($value['stat'] == 32)
                {
                    $list[$key]['statdesc'] = '冻结中';
                }
            }
        }

        //返回
        return $list;
    }

    /**
     * 获取热门已售商品
     * @return array
     */
    public function hotsale()
    {
        $etime = time();
        $stime = $etime - 86400 * 7;

        //获取最后中标商品列表
        $where = [
            'plat' => 21,
            'stat' => 21,
            'lucktime' => ['between' => [$stime, $etime]],
            'infield' => 0
        ];
        $list = PrdBidSalesModel::M()->getList($where, 'sid,_id,tid,pid,mid,bprc,bps', ['lucktime' => -1, 'bps' => -1, 'atime' => -1], 4);

        //返回
        return $this->addPrdInfo($list);
    }

    /**
     * 补充商品数据
     * @param array $data
     * @return array
     */
    private function addPrdInfo(array $data)
    {
        if (!$data)
        {
            return [];
        }

        //获取机型内存ID，版本ID, 颜色
        $pids = ArrayHelper::map($data, 'pid');
        $pidDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'level,imgsrc,pname');

        //获取级别信息
        $levelDict = $this->qtoLevelInterface->getDict();

        //获取商品级别字典
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'level,slevel');

        //组装数据
        foreach ($data as $key => $value)
        {
            $pid = $value['pid'];

            $imgsrc = isset($pidDict[$pid]) ? Utility::supplementProductImgsrc($pidDict[$pid]['imgsrc'], 170) : '';
            $data[$key]['imgsrc'] = $imgsrc;
            $data[$key]['pname'] = $pidDict[$pid]['pname'] ?? '';

            //级别兼容处理
            $level = $value['level'];
            if ($level == 0)
            {
                $level = $prdDict[$pid]['slevel'] > 0 ? $prdDict[$pid]['slevel'] : $prdDict[$pid]['level'];
            }
            $data[$key]['level'] = $levelDict[$level]['lname'] ?? '-';
        }

        //返回
        return $data;
    }

    /**
     * 设置提醒
     * @param string $uid 用户id
     * @param int $rtype 提醒类型
     * @throws
     */
    public function remind(string $uid, int $rtype)
    {
        //检查用户数据
        $userInfo = AccUserModel::M()->getRowById($uid);
        if ($userInfo == false)
        {
            throw new AppException('用户不存在', AppException::NO_LOGIN);
        }

        //获取今天是否有已结束的场次
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = $stime + 86399;
        $where = ['plat' => 21, 'stime' => ['>=' => $stime], 'etime' => ['<=' => $etime], 'stat' => 14, 'infield' => 0];
        $num = PrdBidRoundModel::M()->getCount($where);

        //获取公开配置项
        $config = SysConfData::D()->get('xinxin_public_time');

        //今天的场次全部结束之后，公开时间是明天的默认时间
        $ptime = time() < $config['ptime'] ? $config['ptime'] : ($num == 0 ? strtotime($config['day']) : strtotime($config['day']) + 86400);

        //查询条件
        $where = [
            'acc' => $uid,
            'plat' => 21,
            'rway' => 1,
            'rtype' => $rtype
        ];

        //设置提醒
        if (CrmRemindModel::M()->exist($where))
        {
            CrmRemindModel::M()->update($where, ['isatv' => 1]);
        }
        else
        {
            $rid = IdHelper::generate();
            CrmRemindModel::M()->insert(['rid' => $rid, 'acc' => $uid, 'plat' => 21, 'rway' => 1, 'rtype' => $rtype, 'minute' => 300, 'isatv' => 1]);
        }
    }

    private function fmtTime($time, $format = 'H:i')
    {
        return !$time ? 0 : DateHelper::toString($time, $format);
    }

    /**
     * 获取竞拍排行
     */
    public function bidrank()
    {
        //获取截止当天最后一场次
        $lastRound = PrdBidRoundModel::M()->getRow(['plat' => 21, 'stat' => ['between' => [12, 14]], 'infield' => 0], 'etime', ['etime' => -1]);
        if ($lastRound == false)
        {
            $etime = time();
        }
        else
        {
            $etime = $lastRound['etime'];
        }
        $stime = strtotime(date('Y-m-d 00:00:00', $etime - (86400 * 7)));
        $etime = strtotime(date('Y-m-d 23:59:59', $etime));
        $where = [
            'plat' => 21,
            'stat' => 21,
            'lucktime' => ['between' => [$stime, $etime]],
            'infield' => 0,
            '$group' => 'luckbuyer',
        ];
        $col = 'sum(bprc) as bprc, count(*) as count,luckbuyer,luckrgn,luckname,luckarea';
        $list = PrdBidSalesModel::M()->getList($where, $col, ['bprc' => -1, 'count' => -1], 9);

        //补充数据
        foreach ($list as $key => $value)
        {
            $list[$key]['mobile'] = Utility::replaceMobile($value['luckname'], 7) ?: '-';
            $list[$key]['city'] = $value['luckarea'] ?: '-';
            $list[$key]['money'] = $value['bprc'] = (round($value['bprc'] / 10000, 1)) . '万';
        }

        //返回
        return $list;
    }

    /**
     * 级别列表
     */
    public function levelList()
    {
        $list = XinxinDictData::PRD_LEVEL;

        //返回
        return $list;
    }

    /**
     * 获取首页banner轮播图
     * @return array
     */
    public function banner()
    {
        //数据条件
        $where = [
            'distpos' => 1000,
            'stat' => 1,
            'stime' => ['<=' => time()],
            'etime' => ['>=' => time()]
        ];

        //所需字段
        $cols = 'aid,content,imgsrc,jumplink,distpos,distchn';

        //获取轮播图数据
        $banners = CmsAdvertModel::M()->getList($where, $cols, ['stime' => -1]);

        //投放渠道筛选
        if ($banners)
        {
            foreach ($banners as $key => $value)
            {
                $banners[$key]['imgsrc'] = Configer::get('common')['qiniu']['product'] . '/' . $value['imgsrc'];
                $channels = json_decode($value['distchn'], true);
                if (!in_array(3, $channels))
                {
                    unset($banners[$key]);
                }
            }
        }

        //返回数据
        return array_values($banners);
    }

    /**
     * 是否已经关注公众号
     * @param int $uid 用户id
     * @return mixed
     * @throws
     */
    public function isSub(int $uid)
    {
        //获取用户id
        $acc = $this->commonLogic->getAcc($uid);
        if ($acc == false)
        {
            return 1;
        }

        //获取数据
        $issub = SysAccModel::M()->getOne(['_id' => $acc], 'issubwx');

        //返回数据
//        return $issub ? $issub : 0;
        return 1;
    }
}