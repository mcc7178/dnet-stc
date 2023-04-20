<?php
namespace App\Module\Sale\Logic\Backend\Xinxin\User;

use App\Exception\AppException;
use App\Model\Crm\CrmBuyerModel;
use App\Model\Crm\CrmStaffModel;
use App\Model\Cts\CtsServiceSheetModel;
use App\Model\Dnet\CrmSearchModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidPriceModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Service\Acc\AccUserInterface;
use App\Service\Crm\CrmBuyerInterface;
use App\Service\Crm\CrmStaffInterface;
use App\Service\Pub\SysRegionInterface;
use App\Service\Qto\QtoLevelInterface;
use App\Service\Topd\XinxinInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Configer;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class UserLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Reference()
     * @var QtoLevelInterface
     */
    private $qtoLevelInterface;

    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * @Reference()
     * @var CrmBuyerInterface
     */
    private $crmBuyerInterface;

    /**
     * @Reference()
     * @var CrmStaffInterface
     */
    private $crmStaffInterface;

    /**
     * @Reference()
     * @var XinxinInterface
     */
    private $xinxinInterface;

    /**
     * @Inject()
     * @var CrmSearchModel
     */
    private $oldCrmSearchModel;

    /**
     * @Reference()
     * @var SysRegionInterface
     */
    private $sysRegionInterface;

    /**
     * 获取用户列表数据
     * @param array $query
     * @param int $idx
     * @param int $size
     * @return array
     */
    public function getPager(array $query, int $idx = 1, int $size = 25)
    {
        //检查参数，组装where条件
        $permis = false;
        $where = 'where b.plat=21 ';
        if ($query['uname'] != '')
        {
            $where .= " and u.uname like '%" . $query['uname'] . "%'";
        }
        if ($query['loginid'] != '')
        {
            $where .= " and u.loginid='{$query['loginid']}'";
        }
        if (is_numeric($query['mobile']))
        {
            $where .= " and u.mobile='{$query['mobile']}'";
        }
        if (isset($query['regtime']) && count($query['regtime']) == 2)
        {
            $stime = strtotime($query['regtime'][0]);
            $etime = strtotime($query['regtime'][1]) + 86399;
            $where .= " and u.atime between $stime and $etime";
        }
        if ($query['unlogin'] > 0)
        {
            $endtime = time() - $query['unlogin'] * 86400;
            $where .= " and u.logintime<$endtime";
        }
        if ($query['fromstaff'] != '')
        {
            $where .= " and b.fmacc='{$query['fromstaff']}'";
        }

        //获取分页数据
        if ($idx < 1)
        {
            $idx = 1;
        }
        $start = ($idx - 1) * $size;
        $cols = 'u.aid, u.avatar, u.uname, u.loginid, u.mobile, u.loginarea, u.logintime, u.atime, u.logins, 
        b.bid, b.payamts, b.fmacc, b.fmarea';
        $sql = "select $cols from acc_user u join crm_buyer b on u.aid=b.acc $where order by logintime desc limit $start, $size";
        $list = CrmBuyerModel::M()->query($sql);

        //获取总数量
        $sql = "select count(1) as t_num from acc_user u join crm_buyer b on u.aid=b.acc $where";
        $count = CrmBuyerModel::M()->query($sql);
        $count = $count[0]['t_num'];

        //提取字典
        $fmacc = ArrayHelper::map($list, 'fmacc');
        $fmacc = array_filter($fmacc);
        if (count($fmacc) > 0)
        {
            $staffDict = CrmStaffModel::M()->getDict('acc', ['acc' => ['in' => $fmacc]], 'sname');
        }
        $fmareaDict = $this->getFmareaDict();

        //购机数量 / 售后次数字典
        $buyers = ArrayHelper::map($list, 'aid');
        $ctsDict = $orderDict = [];
        if (count($buyers) > 0)
        {
            $orderList = OdrOrderModel::M()->getList(['plat' => 21, 'ostat' => ['>' => 12], 'buyer' => ['in' => $buyers], '$group' => 'buyer'], 'buyer, sum(qty) as t_num');
            foreach ($orderList as $value)
            {
                $orderDict[$value['buyer']] = $value['t_num'];
            }

            $ctsSheet = CtsServiceSheetModel::M()->getList(['uid' => ['in' => $buyers], '$group' => 'uid'], 'uid, count(1) as t_num');
            foreach ($ctsSheet as $value)
            {
                $ctsDict[$value['uid']] = $value['t_num'];
            }
        }

        //组装数据
        $qiniuHost = Configer::get('qiniu:domain:default', '');
        foreach ($list as $key => $value)
        {
            //归属销售人员
            $list[$key]['fmacc'] = $staffDict[$value['fmacc']]['sname'] ?? '-';

            //图片地址拼接
            $list[$key]['avatar'] = $qiniuHost . '/' . $value['avatar'];

            //时间处理 - 最后登录时间
            $logintime = '-';
            if ($value['logintime'] > 0)
            {
                $logintime = date('Y-m-d H:i', $value['logintime']);
            }
            $list[$key]['logintime'] = $logintime;

            //时间处理 - 注册时间
            $list[$key]['regtime'] = date('Y-m-d H:i', $value['atime']);

            //归属区域
            $list[$key]['fmarea'] = $fmareaDict[$value['fmarea']] ?? '-';

            //购机数量
            $list[$key]['buyprds'] = $orderDict[$value['aid']] ?? '-';

            //售后次数
            $list[$key]['ctsnums'] = $ctsDict[$value['aid']] ?? '-';

            //手机号处理 - 无权限*混淆处理
            $mobile = $value['mobile'];
            if (is_numeric($mobile) && $permis == false)
            {
                $mobile = substr_replace($value['mobile'], '****', 3, 4);
            }
            $list[$key]['mobile'] = $mobile;
        }

        //填充默认数据
        ArrayHelper::fillDefaultValue($list);

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => intval($count),
            ],
            'list' => $list
        ];
    }

    /**
     * 获取用户详情
     * @param string $acc
     * @return mixed
     * @throws
     */
    public function getUserInfo(string $acc)
    {
        //检查参数，组装where条件
        $permis = false;
        if ($acc == '')
        {
            throw new AppException('缺少用户ID', AppException::WRONG_ARG);
        }

        //获取账号信息 - 主账号 & 新新
        $user = $this->accUserInterface->getAccInfo($acc);
        $xinxinBuyer = $this->crmBuyerInterface->getBuyerInfo($acc, 21);
        $xczBuyer = $this->crmBuyerInterface->getBuyerInfo($acc, 22);

        //竞拍场次数
        $joinRounds = PrdBidPriceModel::M()->getList(['plat' => 21, 'buyer' => $acc, '$group' => 'rid']);
        $joinRounds = count($joinRounds);

        //中标场次数
        $bidRounds = PrdBidSalesModel::M()->getList(['plat' => 21, 'luckbuyer' => $acc, '$group' => 'rid']);
        $bidRounds = count($bidRounds);

        //获取销售人员姓名
        $fmacc = '-';
        if ($xczBuyer['fmacc'] != '')
        {
            $fmacc = $xczBuyer['fmacc'];
        }
        if ($xinxinBuyer['fmacc'] != '')
        {
            $fmacc = $xinxinBuyer['fmacc'];
        }
        $staff = $this->crmStaffInterface->getRowById($fmacc);
        $fromSale = '-';
        if (isset($staff['sname']))
        {
            $fromSale = $staff['sname'];
        }

        $bidprds = PrdBidSalesModel::M()->getCount(['plat' => 21, 'luckbuyer' => $acc]);
        $payamts = PrdBidSalesModel::M()->getRow(['plat' => 21, 'luckbuyer' => $acc], 'sum(bprc) as amts');
        $payamts = $payamts['amts'];

        $shopBidprds = PrdShopSalesModel::M()->getCount(['luckbuyer' => $acc]);
        $shopPayamts = PrdShopSalesModel::M()->getRow(['luckbuyer' => $acc], 'sum(bprc) as amts');
        $shopPayamts = $shopPayamts['amts'];

        //手机号处理 - 无权限*混淆处理
        $mobile = $user['mobile'];
        if (is_numeric($mobile) && $permis == false)
        {
            $mobile = substr_replace($mobile, '****', 3, 4);
        }

        //组装数据
        $info[] = ['label' => '昵称', 'lname' => $user['uname'] ?? '-'];
        $info[] = ['label' => '登录账号', 'lname' => $user['loginid'] ?? '-'];
        $info[] = ['label' => '手机号码', 'lname' => $mobile ?? '-'];
        $info[] = ['label' => '注册时间', 'lname' => date('Y-m-d H:i', $user['atime'])];
        $info[] = ['label' => '最后登录时间', 'lname' => date('Y-m-d H:i', $user['logintime'])];
        $info[] = ['label' => '登录次数', 'lname' => $user['logins']];
        $info[] = ['label' => '竞拍场次数', 'lname' => $joinRounds];
        $info[] = ['label' => '中标场次数', 'lname' => $bidRounds];
        $info[] = ['label' => '中标台数', 'lname' => $bidprds];
        $info[] = ['label' => '中标金额', 'lname' => $payamts];
        $info[] = ['label' => '一口价台数', 'lname' => $shopBidprds];
        $info[] = ['label' => '一口价金额', 'lname' => $shopPayamts];
        $info[] = ['label' => '新新保证金', 'lname' => $xinxinBuyer['deposit']];
        $info[] = ['label' => '小槌子保证金', 'lname' => $xczBuyer['deposit']];
        $info[] = ['label' => '归属销售', 'lname' => $fromSale];
        $info[] = ['label' => '登录地', 'lname' => $user['loginarea']];
        $info[] = ['label' => '归属区', 'lname' => $fmareaDict[$xinxinBuyer['fmarea']] ?? '-'];

        //获取各标签总数
        //获取关注总数
        $sql = "select count(1) as t_num from (
        (select atime,pid,level,sid,1 as tid from prd_bid_favorite where buyer='$acc') UNION
        (select atime,pid,level,sid,2 as tid from prd_shop_favorite where buyer='$acc')
        ) as new_table";
        $count = PrdBidFavoriteModel::M()->query($sql);
        $favNum = $count[0]['t_num'];
        $this->redis->set('sale_xinxin_fav_' . $acc, $favNum, 3600);

        //获取搜索记录总数
        $searchNum = $this->oldCrmSearchModel->getCount(['buyer' => $user['_id']]);
        $this->redis->set('sale_xinxin_search_' . $acc, $searchNum, 3600);

        //获取未中标记录总数
        $count = PrdBidPriceModel::M()->query("select count(1) as t_num from prd_bid_price where plat=21 and buyer='$acc' and sid not in(
        select sid from prd_bid_price where plat=21 and buyer='$acc' and stat=1)");
        $unbidNum = $count[0]['t_num'];
        $this->redis->set('sale_xinxin_unbid_' . $acc, $unbidNum, 3600);

        //获取浏览商品总数
        $resp = $this->xinxinInterface->getPrdVisit(['acc' => $user['_id']], '*', ['stime' => -1], 1);
        $visitNum = $resp['count'] ?? '';
        $this->redis->set('sale_xinxin_visit_' . $acc, $visitNum, 3600);

        //获取订单总数
        //获取数据
        $where['plat'] = 21;
        $where['buyer'] = $acc;
        $where['tid'] = ['in' => [11, 12]];
        $odrNum = OdrOrderModel::M()->getCount($where);
        $this->redis->set('sale_xinxin_odr_' . $acc, $odrNum, 3600);

        return [
            'info' => $info,
            'count' => [
                'fav' => $favNum,
                'search' => $searchNum,
                'unbid' => $unbidNum,
                'visit' => $visitNum,
                'odr' => $odrNum,
            ]
        ];
    }

    /**
     * 获取关注数据 - 来源 prd_bid_favorite prd_shop_favorite
     * @param string $acc
     * @param int $idx
     * @param int $size
     * @return array
     * @throws
     */
    public function getFavList(string $acc, int $idx = 1, int $size = 25)
    {
        //检查参数，组装where条件
        if ($acc == '')
        {
            throw new AppException('缺少用户ID', AppException::WRONG_ARG);
        }
        $where = "where buyer='$acc'";

        //获取分页数据
        if ($idx < 1)
        {
            $idx = 1;
        }
        $start = ($idx - 1) * $size;
        $sql = "select * from (
        (select atime,pid,level,sid,1 as tid from prd_bid_favorite $where) UNION
        (select atime,pid,level,sid,2 as tid from prd_shop_favorite $where)
        ) as new_table  order by atime desc limit $start,$size";
        $list = PrdBidFavoriteModel::M()->query($sql);
        $count = $this->redis->get('sale_xinxin_fav_' . $acc);

        //提取对应的销售表id
        $bidSids = $shopSids = [];
        foreach ($list as $value)
        {
            if ($value['tid'] == 1)
            {
                $bidSids[] = $value['sid'];
            }
            else
            {
                $shopSids[] = $value['sid'];
            }
        }

        //提取字典
        $bidDict = $shopDict = $prdDict = [];
        if (count($bidSids) > 0)
        {
            $bidDict = PrdBidSalesModel::M()->getDict('sid', ['sid' => ['in' => $bidSids]], '*');
        }
        if (count($shopSids) > 0)
        {
            $shopDict = PrdShopSalesModel::M()->getDict('sid', ['sid' => ['in' => $shopSids]], '*');
        }
        $pids = ArrayHelper::map($list, 'pid');
        if (count($pids) > 0)
        {
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], '*');
        }
        $qtoLevelDict = $this->qtoLevelInterface->getDict();

        //组装数据
        foreach ($list as $key => $value)
        {
            //格式化时间
            $list[$key]['showtime'] = date('Y-m-d H:i', $value['atime']);

            //库存编码
            $bcode = $prdDict[$value['pid']]['bcode'] ?? '-';
            $list[$key]['bcode'] = $bcode;

            //等级
            $list[$key]['level'] = $qtoLevelDict[$value['level']]['lname'] ?? '-';

            //商品名称
            $list[$key]['pname'] = $prdDict[$value['pid']]['pname'] ?? '-';

            $sprc = 0;//起拍价
            $kprc = 0;//秒杀价
            if ($value['tid'] == 1)
            {
                $sprc = $bidDict[$value['sid']]['sprc'] ?? 0;
                $kprc = $bidDict[$value['sid']]['kprc'] ?? 0;
            }
            else
            {
                $kprc = $shopDict[$value['sid']]['bprc'] ?? 0;
            }
            $list[$key]['sprc'] = $sprc;
            $list[$key]['kprc'] = $kprc;

            //来源
            $list[$key]['source'] = $value['tid'] == 1 ? '竞拍' : '一口价';

            $result = '未出价';//购买结果 - 已购得 未出价 未中标
            if ($value['tid'] == 1)
            {
                //检查是否出价过
                $hasPrice = PrdBidPriceModel::M()->getRow(['buyer' => $acc, 'sid' => $value['sid'], 'pid' => $value['pid']], 'pid');
                if (isset($hasPrice['pid']))
                {
                    $result = '未中标';
                }
                $luckbuyer = $bidDict[$value['sid']]['luckbuyer'] ?? '-';
                $odrTid = 11;
            }
            else
            {
                $luckbuyer = $shopDict[$value['sid']]['luckbuyer'] ?? '-';
                $odrTid = 12;
            }
            if ($luckbuyer == $acc)
            {
                $result = '已购得';
            }

            //检查订单状态
            $subSql = "select o.ostat from odr_order o join odr_goods g on o.okey=g.okey 
            where o.plat=21 and o.tid=$odrTid and o.buyer='$acc' and g.bcode='$bcode' order by o.otime desc limit 1";
            $odrGoods = OdrGoodsModel::M()->query($subSql);
            $ostat = $odrGoods[0]['ostat'] ?? 0;
            $statDesc = $this->getOdrStatDesc($ostat);
            if ($statDesc != '')
            {
                $result = $statDesc;
            }

            $list[$key]['result'] = $result;
        }

        //填充默认数据
        ArrayHelper::fillDefaultValue($list);

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => intval($count),
            ],
            'list' => $list
        ];
    }

    /**
     * 获取搜索数据 - 来源 - 旧表 crm_search
     * @param string $acc
     * @param int $idx
     * @param int $size
     * @return array
     * @throws
     */
    public function getSearchList(string $acc, int $idx = 1, int $size = 25)
    {
        //检查参数
        if ($acc == '')
        {
            throw new AppException('缺少用户ID', AppException::WRONG_ARG);
        }
        //获取旧表ID
        $user = $this->accUserInterface->getRow(['aid' => $acc], '_id');
        if (is_numeric($user['_id']) == false)
        {
            return $this->emptyData();
        }

        //获取数据
        $where = ['buyer' => $user['_id']];
        $list = $this->oldCrmSearchModel->getList($where, '*', ['stime' => -1], $size, $idx);
        $count = $this->redis->get('sale_xinxin_search_' . $acc);

        //组装数据
        foreach ($list as $key => $value)
        {
            //格式化时间
            $list[$key]['showtime'] = date('Y-m-d H:i', $value['stime']);

            //搜索来源
            $list[$key]['source'] = $value['src'] == 1 ? '首页' : '中标页';
        }

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => intval($count),
            ],
            'list' => $list
        ];
    }

    /**
     * 获取浏览数据 - 浏览记录 topd_trace_xvisit 清洗数据得到  要新建表
     * @param string $acc
     * @param int $idx
     * @param int $size
     * @return array
     * @throws
     */
    public function getVisitList(string $acc, int $idx = 1, int $size = 25)
    {
        //检查参数
        if ($acc == '')
        {
            throw new AppException('缺少用户ID', AppException::WRONG_ARG);
        }
        //获取旧表ID
        $acc = $this->accUserInterface->getRow(['aid' => $acc], '_id');
        if (is_numeric($acc['_id']) == false)
        {
            return $this->emptyData();
        }

        //获取数据
        $resp = $this->xinxinInterface->getPrdVisit(['acc' => $acc['_id']], '*', ['stime' => -1], $size, $idx);

        //提取字典
        $list = $resp['list'];
        $pids = ArrayHelper::map($list, 'pid');
        if (count($pids) > 0)
        {
            $prdDict = PrdProductModel::M()->getDict('_id', ['_id' => ['in' => $pids]], 'bcode,level,pname');
        }
        $sids = ArrayHelper::map($list, 'sid');
        if (count($sids) > 0)
        {
            $bidSaleDict = PrdBidSalesModel::M()->getDict('_id', ['_id' => ['in' => $sids]]);
            $shopSaleDict = PrdShopSalesModel::M()->getDict('_id', ['_id' => ['in' => $sids]]);
        }
        $qtoLevelDict = $this->qtoLevelInterface->getDict();

        //组装数据
        foreach ($list as $key => $value)
        {
            //时间展示
            $list[$key]['showtime'] = date('Y-m-d H:i', $value['stime']);

            //商品名称
            $list[$key]['pname'] = $prdDict[$value['pid']]['pname'] ?? '-';

            //库存编码
            $list[$key]['bcode'] = $prdDict[$value['pid']]['bcode'] ?? '-';

            //级别
            $level = $prdDict[$value['pid']]['level'] ?? 0;
            $list[$key]['level'] = $qtoLevelDict[$level]['lname'] ?? '-';

            //起拍价、秒杀价、销售方式
            if ($value['rid'] == 3529)
            {
                $list[$key]['sprc'] = 0;
                $list[$key]['kprc'] = $shopSaleDict[$value['sid']]['bprc'] ?? 0;
                $list[$key]['way'] = '一口价';
            }
            else
            {
                $list[$key]['sprc'] = $bidSaleDict[$value['sid']]['sprc'] ?? 0;
                $list[$key]['kprc'] = $bidSaleDict[$value['sid']]['kprc'] ?? 0;
                $list[$key]['way'] = '竞拍';
            }
        }

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => $resp['count']
            ],
            'list' => $list
        ];
    }

    /**
     * 获取未中标数据 - 只有新新竞拍有数据
     * @param string $acc
     * @param int $idx
     * @param int $size
     * @return array
     * @throws
     */
    public function getUnBidList(string $acc, int $idx = 1, int $size = 25)
    {
        //检查参数
        if ($acc == '')
        {
            throw new AppException('缺少用户ID', AppException::WRONG_ARG);
        }

        //出价记录排除中标的数据
        $start = ($idx - 1) * $size;
        $sql = "select * from prd_bid_price where plat=21 and buyer='$acc' and sid not in(
        select sid from prd_bid_price where plat=21 and buyer='$acc' and stat=1) order by btime desc limit  $start,$size";
        $list = PrdBidPriceModel::M()->query($sql);
        $count = $this->redis->get('sale_xinxin_unbid_' . $acc);

        //提取字典
        $pids = ArrayHelper::map($list, 'pid');
        if (count($pids) > 0)
        {
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], '*');
        }
        $qtoLevelDict = $this->qtoLevelInterface->getDict();

        $sids = ArrayHelper::map($list, 'sid');
        if (count($sids) > 0)
        {
            $saleDict = PrdBidSalesModel::M()->getDict('sid', ['sid' => ['in' => $sids]], '*');
        }

        //组装数据
        foreach ($list as $key => $value)
        {
            //格式化时间
            $list[$key]['showtime'] = date('Y-m-d H:i', $value['btime']);

            //库存编码
            $list[$key]['bcode'] = $prdDict[$value['pid']]['bcode'] ?? '-';

            //等级
            $level = $prdDict[$value['pid']]['level'] ?? 0;
            $list[$key]['level'] = $qtoLevelDict[$level]['lname'] ?? '-';

            //商品名称
            $list[$key]['pname'] = $prdDict[$value['pid']]['pname'] ?? '-';

            //起拍价 秒杀价
            $list[$key]['sprc'] = $saleDict[$value['sid']]['sprc'] ?? 0;
            $list[$key]['kprc'] = $saleDict[$value['sid']]['kprc'] ?? 0;
        }

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => intval($count),
            ],
            'list' => $list
        ];
    }

    /**
     * 获取中标订单数据   prd_bid_sales prd_shop_sales
     * @param string $acc
     * @param int $idx
     * @param int $size
     * @return array
     * @throws
     */
    public function getOrderList(string $acc, int $idx = 1, int $size = 25)
    {
        //检查参数，组装where条件
        if ($acc == '')
        {
            throw new AppException('缺少用户ID', AppException::WRONG_ARG);
        }

        //获取数据
        $where['plat'] = 21;
        $where['buyer'] = $acc;
        $where['tid'] = ['in' => [11, 12]];
        $cols = 'okey, qty, oamt, otime, ostat, tid';
        $list = OdrOrderModel::M()->getList($where, $cols, ['otime' => -1], $size, $idx);
        $count = $this->redis->get('sale_xinxin_odr_' . $acc);

        //组装数据
        foreach ($list as $key => $value)
        {
            //展示时间
            $list[$key]['showtime'] = date('Y-m-d H:i', $value['otime']);

            //订单状态
            $list[$key]['stat'] = $this->getOdrStatDesc($value['ostat']) ?? '-';

            //订单类型
            $list[$key]['type'] = $value['tid'] == 11 ? '竞拍订单' : '一口价订单';
        }

        //填充默认数据
        ArrayHelper::fillDefaultValue($list);

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => intval($count),
            ],
            'list' => $list
        ];
    }

    /**
     * @param string $okey
     * @param int $idx
     * @param int $size
     * @return mixed
     * @throws
     */
    public function getGoodsList(string $okey, int $idx = 1, int $size = 25)
    {
        //检查参数，组装where条件
        if ($okey == '')
        {
            throw new AppException('缺少订单号', AppException::WRONG_ARG);
        }

        //获取数据
        $orderInfo = OdrOrderModel::M()->getRow(['okey' => $okey], 'okey, otime, qty, recver, recreg, rectel, recdtl');
        if (empty($orderInfo))
        {
            throw new AppException('缺少订单订单数据', AppException::NO_DATA);
        }
        $orderInfo['otime'] = date('Y-m-d H:i', $orderInfo['otime']);
        $fullDtl = $this->sysRegionInterface->getFullName($orderInfo['recreg']);
        $orderInfo['recdtl'] = $fullDtl . ' ' . $orderInfo['recdtl'];

        //获取商品列表
        $where['okey'] = $okey;
        $cols = 'bcode, bprc';
        $list = OdrGoodsModel::M()->getList($where, $cols, ['otime' => -1], $size, $idx);
        $count = OdrGoodsModel::M()->getCount($where);

        //获取商品字典
        $bcodes = ArrayHelper::map($list, 'bcode');
        if (count($bcodes) > 0)
        {
            $prdDict = PrdProductModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]], 'pname,level');
        }
        //获取级别字典
        $levelDict = $this->qtoLevelInterface->getDict();

        //组装数据
        foreach ($list as $key => $value)
        {
            //商品名称
            $list[$key]['pname'] = $prdDict[$value['bcode']]['pname'] ?? '-';

            //级别
            $level = $prdDict[$value['bcode']]['level'] ?? 0;
            $list[$key]['level'] = $levelDict[$level]['lname'] ?? '-';
        }

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => intval($count),
            ],
            'list' => $list,
            'info' => $orderInfo
        ];
    }

    /**
     * 返回空列表
     * @return array
     */
    private function emptyData()
    {
        //返回
        return [
            'pager' => [
                'idx' => 1,
                'size' => 25,
                'count' => 0,
            ],
            'list' => []
        ];
    }

    /**
     * 归属区域字典
     * @return array
     */
    private function getFmareaDict()
    {
        return [
            1 => '华中华北区',
            2 => '华南区',
            3 => '华北区',
            4 => '华西区',
            5 => '华东区'
        ];
    }

    /**
     * 获取状态对应的文本
     * @param int $ostat
     * @return mixed|string
     */
    private function getOdrStatDesc(int $ostat)
    {
        //订单状态字典
        $statDict = [
            10 => '待成交',
            11 => '待支付',
            12 => '待审核',
            13 => '已支付',
            21 => '待发货',
            22 => '已发货',
            23 => '交易完成',
            51 => '取消交易',
            52 => '扣除押金',
        ];

        //返回
        return $statDict[$ostat] ?? '';
    }
}