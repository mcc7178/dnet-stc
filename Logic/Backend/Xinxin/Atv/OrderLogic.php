<?php

namespace App\Module\Sale\Logic\Backend\Xinxin\Atv;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Dnet\FncRefundModel;
use App\Model\Dnet\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Sale\SaleGroupBuyModel;
use App\Module\Pub\Service\SysRegionService;
use App\Params\Common;
use App\Service\Acc\AccUserInterface;
use App\Service\Pub\SysRegionInterface;
use App\Service\Qto\QtoInquiryInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class OrderLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * @Reference("qto")
     * @var QtoInquiryInterface
     */
    private $qtoInquiryInterface;

    /**
     * @Reference()
     * @var SysRegionInterface
     */
    private $sysRegionInterface;

    /**
     * 拼团活动列表
     * @param array $query 查询参数
     * @param int $idx 页码
     * @param int $size 每页数量
     * @return mixed
     * @throws
     */
    public function getPager(array $query, int $idx, int $size)
    {
        //查询条件
        $where['stat'] = ['>' => 1];

        //团购名称
        if (!empty($query['gname']))
        {
            $where['gname'] = ['like' => '%' . $query['gname'] . '%'];
        }

        //机型
        if (!empty($query['mname']))
        {
            $mids = $this->qtoInquiryInterface->getSearchModelNames($query['mname']);
            if (count($mids) == 0)
            {
                return Common::emptyPager($size, $idx);
            }
            $mids = ArrayHelper::map($mids, 'mid');
            $where['mid'] = ['in' => $mids];
        }

        //活动开始时间
        if (count($query['stime']) == 2)
        {
            $stime = strtotime($query['stime'][0]);
            $etime = strtotime($query['stime'][1]) + 86399;
            $where['stime'] = ['between' => [$stime, $etime]];
        }

        //状态
        if (!empty($query['stat']))
        {
            $where['stat'] = $query['stat'];
        }

        //获取翻页数据
        $list = SaleGroupBuyModel::M()->getList($where, '*', ['stime' => -1], $size, $idx);
        $count = SaleGroupBuyModel::M()->getCount($where);

        //如果有数据
        if (count($list) > 0)
        {
            //获取机型
            $mids = ArrayHelper::map($list, 'mid');
            $midDict = $this->qtoInquiryInterface->getDictModels($mids, 0);

            foreach ($list as $key => $value)
            {
                //补充信息
                $list[$key]['mname'] = $midDict[$value['mid']]['mname'] ?? '-';
                $waitqty = $value['groupqty'] - $value['buyqty'];
                $list[$key]['waitqty'] = $waitqty < 0 ? 0 : $waitqty;

                //处理时间
                $list[$key]['stime'] = DateHelper::toString($value['stime']);
                $list[$key]['etime'] = DateHelper::toString($value['etime']);
                $list[$key]['gtime'] = DateHelper::toString($value['gtime']);

                //判断状态
                switch ($value['stat'])
                {
                    case 2:
                        $list[$key]['stattext'] = '拼团中';
                        break;
                    case 3:
                        $list[$key]['stattext'] = '拼团成功';
                        break;
                    case 4:
                        $list[$key]['stattext'] = '拼团失败';
                        break;
                }
            }
        }

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => $count
            ],
            'list' => $list
        ];
    }

    /**
     * 拼团活动详情
     * @param string $gkey 团购编号
     * @return mixed
     * @throws
     */
    public function getInfo(string $gkey)
    {
        //检查参数
        if (empty($gkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取数据
        $info = SaleGroupBuyModel::M()->getRowById($gkey, '*');
        if (!$info)
        {
            throw new AppException('找不到该条团购信息', AppException::DATA_MISS);
        }

        $atvInfo = $this->getAtvInfo($info);
        $modelInfo = $this->getModelInfo($info);

        $info = [
            'atvInfo' => $atvInfo,
            'modelInfo' => $modelInfo,
        ];

        //返回
        return $info;
    }

    /**
     * 获取活动详情
     * @param array $info
     * @return array
     */
    private function getAtvInfo(array $info)
    {
        //判断状态
        switch ($info['stat'])
        {
            case 2:
                $stattext = '拼团中';
                break;
            case 3:
                $stattext = '拼团成功';
                break;
            case 4:
                $stattext = '拼团失败';
                break;
        }

        //获取拼团失败状态下可以申请退款的订单数量
        $count = 0;
        if ($info['stat'] == 4)
        {
            $where = [
                'tid' => 13,
                'paystat' => 3,
                'groupbuy' => $info['gkey'],
                'rtnstat' => 0,
                'ostat' => 53,
                'otime' => ['between' => [$info['stime'], $info['etime']]]
            ];
            $count = OdrOrderModel::M()->getCount($where);
        }

        $waitqty = $info['groupqty'] - $info['buyqty'];

        //组装数据
        $atvInfo = [
            'gkey' => $info['gkey'],
            'gname' => $info['gname'],
            'stime' => DateHelper::toString($info['stime']),
            'etime' => DateHelper::toString($info['etime']),
            'gtime' => DateHelper::toString($info['gtime']),
            'stat' => $info['stat'],
            'groupqty' => $info['groupqty'],
            'buyqty' => $info['buyqty'],
            'waitqty' => $waitqty < 0 ? 0 : $waitqty,
            'orderqty' => $info['orderqty'],         //参团总人数
            'payqty' => $info['payqty'],
            'stattext' => $stattext,
            'count' => $count
        ];

        //返回
        return $atvInfo;
    }

    /**
     * 获取机型信息
     * @param array $info
     * @return array
     */
    private function getModelInfo(array $info)
    {
        //获取机型名称
        $mname = $this->qtoInquiryInterface->getModelName($info['mid'], 21);

        //获取机型内存,网络制度,颜色,销售地
        $label = ArrayHelper::toArray($info['label']);

        //组装数据
        $modelInfo = [
            'mname' => $mname,
            'groupimg' => Utility::supplementQiniuDomain($info['groupimg']),
            'level' => $label['level'] ?: '-',
            'mdram' => $label['mdram'] ?: '-',
            'mdnet' => $label['mdnet'] ?: '-',
            'mdcolor' => $label['mdcolor'] ?: '-',
            'mdofsale' => $label['mdofsale'] ?: '-',
            'oprice' => $info['oprice'],
            'gprice' => $info['gprice'],
            'describe' => $info['describe'],
        ];

        //返回
        return $modelInfo;
    }

    /**
     * 获取订单列表
     * @param array $query 查询参数
     * @param int $idx 页码
     * @param int $size 每页数量
     * @return array
     */
    public function getOrderList(array $query, int $idx, int $size)
    {
        //订单编号
        if (!empty($query['okey']))
        {
            $where['okey'] = $query['okey'];
        }

        //微信昵称
        if (!empty($query['uname']))
        {
            $whereAcc['uname'] = ['like' => '%' . $query['uname'] . '%'];
            $data = $this->accUserInterface->getList(21, $whereAcc, 'aid');
            if (count($data) == 0)
            {
                return Common::emptyPager($size, $idx);
            }
            $accArr = array_column($data, 'aid');
            $where['buyer'] = ['in' => $accArr];
        }

        //收货人
        if (!empty($query['recver']))
        {
            $where['recver'] = ['like' => '%' . $query['recver'] . '%'];
        }

        //手机号码
        if (!empty($query['rectel']))
        {
            $where['rectel'] = $query['rectel'];
        }

        //订单状态
        if (!empty($query['stat']))
        {
            if ($query['stat'] == 61)
            {
                $where['rtnstat'] = ['!=' => 0];
            }
            else
            {
                $where['ostat'] = $query['stat'];
                if ($query['stat'] == 21 || $query['stat'] == 51)
                {
                    $where['rtnstat'] = 0;
                }
            }
        }

        //机型
        if (!empty($query['mname']))
        {
            $mids = $this->qtoInquiryInterface->getSearchModelNames($query['mname']);
            if (count($mids) == 0)
            {
                return Common::emptyPager($size, $idx);
            }
            $mids = ArrayHelper::map($mids, 'mid');
            $where['mid'] = ['in' => $mids];
        }

        $where['groupbuy'] = $query['gkey'];
        $where['tid'] = 13;
        $cols = 'oid,buyer,okey,otime,paytime,qty,oamt,recver,rectel,recreg,recdtl,ostat,rtnstat,dlykey';
        $odrList = OdrOrderModel::M()->getList($where, $cols, ['ostat' => 1, 'otime' => -1], $size, $idx);
        $count = OdrOrderModel::M()->getCount($where);
        if ($odrList)
        {
            //获取用户头像昵称
            $accs = ArrayHelper::map($odrList, 'buyer');
            $accDict = $this->accUserInterface->getAccDict($accs, 'avatar,uname');

            //组装数据
            foreach ($odrList as $key => $value)
            {
                //获取省市区
                $recreg = $this->sysRegionInterface->getFullName($value['recreg']) ?? '';

                $dlykey = $value['dlykey'];
                $odrList[$key]['avatar'] = Utility::supplementAvatarImgsrc($accDict[$value['buyer']]['avatar'] ?? '-');
                $odrList[$key]['uname'] = $accDict[$value['buyer']]['uname'] ?? '-';
                $odrList[$key]['otime'] = DateHelper::toString($value['otime']);
                $odrList[$key]['paytime'] = DateHelper::toString($value['paytime']);
                $odrList[$key]['recdtl'] = $recreg . $value['recdtl'];
                $odrList[$key]['isshow'] = false;

                //判断订单状态
                switch ($value['ostat'])
                {
                    case 11:
                        $odrList[$key]['stattext'] = '待支付';
                        break;
                    case 13:
                        $odrList[$key]['stattext'] = '已支付';
                        break;
                    case 21:
                        $odrList[$key]['stattext'] = '待发货';
                        $odrList[$key]['isshow'] = empty($dlykey);
                        break;
                    case 22:
                        $odrList[$key]['stattext'] = '已发货';
                        break;
                    case 23:
                        $odrList[$key]['stattext'] = '已完成';
                        break;
                    case 51:
                        $odrList[$key]['stattext'] = '已取消';
                        break;
                    case 53:
                        $odrList[$key]['stattext'] = '购买失败';
                        $odrList[$key]['isshow'] = empty($dlykey);
                        break;

                }
                if ($value['rtnstat'] != 0)
                {
                    $odrList[$key]['stattext'] = '已退款';
                }
            }
        }

        //返回
        return [
            'list' => $odrList,
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => $count
            ]
        ];
    }

    /**
     * 拼团失败全部退款
     * @param string $acc 操作人ID
     * @param string $gkey 团购编号
     * @throws
     * @return mixed
     */
    public function refundAll(string $acc, string $gkey)
    {
        //检查参数
        if (empty($gkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取相应的订单
        $where = [
            'tid' => 13,
            'groupbuy' => $gkey,
            'ostat' => 51,
            'paystat' => 3,
            'rtnstat' => 0
        ];
        $returndata = [];
        $odrList = OdrOrderModel::M()->getList($where, 'oid,okey,otime,buyer,qty,oamt,payamt,recver,rectel,ostat,paystat,_id');
        if (count($odrList) == 0)
        {
            throw new AppException('该拼团活动没有有效订单');
        }

        //获取老系统用户id
        $buyers = ArrayHelper::map($odrList, 'buyer');
        array_push($buyers, $acc);
        $buyerId = $this->accUserInterface->getAccDict($buyers, '_id');
        foreach ($odrList as $key => $value)
        {
            $returndata[$key]['rkey'] = $this->generateFncRefundKey();
            $returndata[$key]['buyer'] = $buyerId[$value['buyer']]['_id'];
            $returndata[$key]['oid'] = $value['_id'];
            $returndata[$key]['tid'] = 1;
            $returndata[$key]['qty'] = $value['qty'];
            $returndata[$key]['oamt'] = $value['oamt'];
            $returndata[$key]['ramts'] = $value['payamt'];
            $returndata[$key]['stat'] = 2;
            $returndata[$key]['recver'] = $value['recver'];
            $returndata[$key]['rectel'] = $value['rectel'];
            $returndata[$key]['rway'] = 0;
            $returndata[$key]['auser'] = $buyerId[$acc]['_id'];
            $returndata[$key]['atime'] = time();

            //增加退款单
            FncRefundModel::M()->insert($returndata[$key]);
        }

        $oids = ArrayHelper::map($returndata, 'oid');
        if (count($oids) == 0)
        {
            return 'ok';
        }
        $fncRefund = FncRefundModel::M()->getList(['oid' => ['in' => $oids]], 'oid,ramts,rid');
        if (count($fncRefund) > 0)
        {
            //批量更新订单状态
            foreach ($fncRefund as $value)
            {
                OdrOrderModel::M()->update(['_id' => $value['oid']], [
                    'rtnstat' => 44,
                    'rtnamt' => $value['ramts'],
                    'rtntime' => time(),
                ]);

                OdrGoodsModel::M()->update(['oid' => [$value['oid']]], [
                    'rid' => $value['rid'],
                ]);
            }
        }

        //返回
        return 'ok';
    }

    /**
     * 拼团订单单独退款
     * @param string $acc 操作人id
     * @param string $oid 订单ID
     * @throws
     * @return mixed
     */
    public function refundOne(string $acc, string $oid)
    {
        //检查参数
        if (empty($oid))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //查找订单数据
        $order = OdrOrderModel::M()->getRowById($oid, 'oid,okey,otime,buyer,qty,oamt,payamt,recver,rectel,ostat,paystat,rtnstat,_id');
        if ($order == false)
        {
            throw new AppException('找不到相关订单数据', true);
        }
        if (!in_array($order['ostat'], [21, 53]))
        {
            throw new AppException('该订单非拼团失败或非待发货订单，不允许操作', true);
        }
        if ($order['paystat'] != 3)
        {
            throw new AppException('非已付款订单不允许操作', true);
        }
        if ($order['rtnstat'] != 0)
        {
            throw new AppException('该订单已退款，请勿重复退款', true);
        }

        //订单数据
        $rkey = $this->generateFncRefundKey();
        $oid = $order['_id'];
        $orderqty = $order['qty'];
        $recver = $order['recver'];
        $rectel = $order['rectel'];
        $oamt = $order['oamt'];
        $ramt = $order['payamt'];
        $accs = [$order['buyer'], $acc];
        $accDict = $this->accUserInterface->getAccDict($accs, '_id');
        $buyer = $accDict[$order['buyer']]['_id'] ?? '-';
        $auser = $accDict[$acc]['_id'] ?? '-';

        //生成退款单
        $refund_data = [
            'rkey' => $rkey,
            'buyer' => $buyer,
            'tid' => 1,
            'oid' => $oid,
            'qty' => $orderqty,
            'oamt' => $oamt,
            'ramts' => $ramt,
            'stat' => 2,
            'recver' => $recver,
            'rectel' => $rectel,
            'rway' => 0,
            'auser' => $auser,
            'atime' => time(),
        ];
        $rid = FncRefundModel::M()->insert($refund_data);
        if ($rid == 0)
        {
            throw new AppException('生成退款单失败，请稍后重试', true);
        }

        //更改订单状态
        OdrOrderModel::M()->updateById($order['oid'], [
            'ostat' => 51,
            'rtnstat' => 44,
            'rtnamt' => $ramt,
            'rtntime' => time(),
        ]);

        //关联订单商品退款单
        OdrGoodsModel::M()->update(['oid' => $oid], [
            'rid' => $rid,
        ]);

        //返回
        return 'ok';
    }

    /**
     * 按当天日期生成退款编号
     * @return string
     */
    private function generateFncRefundKey()
    {
        $time = strtotime(date('Ymd 00:00:00', time()));
        $today = date('Ymd', $time);
        $rkey = 'generate_fnc_refund_rkey_' . $today;

        while (true)
        {
            //递增当天外借单数
            $num = $this->redis->incr($rkey);
            if ($num == 1)
            {
                //获取当天外借单数重置计数器（防止因清空缓存导致产生重复报单号）
                $count = FncRefundModel::M()->getCount(['atime' => ['>=' => $time]]);
                if ($count > 0)
                {
                    $num = $this->redis->incrBy($rkey, $count);
                }

                //设置缓存1天有效期
                $this->redis->expire($rkey, 86400);
            }

            /*
             * 加锁防止重复生成订单号
             * 1：加锁失败则重新生成（表示已存在）
             * 2：加锁成功则跳出循环（表示不存在）
             */
            $rkey = 'TK' . $today . str_pad($num, 6, '0', STR_PAD_LEFT);
            if (FncRefundModel::M()->exist(['rkey' => $rkey]))
            {
                continue;
            }

            //有数据结束循环
            break;
        }

        //返回
        return $rkey;
    }
}
