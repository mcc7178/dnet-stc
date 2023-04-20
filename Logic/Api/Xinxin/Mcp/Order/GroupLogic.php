<?php
namespace App\Module\Sale\Logic\Api\Xinxin\Mcp\Order;

use App\Exception\AppException;
use App\Model\Odr\OdrOrderModel;
use App\Model\Sale\SaleGroupBuyModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use App\Module\Sale\Data\XinxinDictData;
use App\Module\Sale\Logic\Api\Xinxin\Mcp\CommonLogic;
use App\Service\Acc\AccUserInterface;
use App\Service\Crm\CrmBuyerInterface;
use App\Service\Pub\SysRegionInterface;
use App\Service\Qto\QtoInquiryInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Configer;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

class GroupLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var CommonLogic
     */
    private $commonLogic;

    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

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
     * @var SysRegionInterface
     */
    private $sysRegionInterface;

    /**
     * @Reference("qto")
     * @var QtoInquiryInterface
     */
    private $qtoInquiryInterface;

    /**
     * 拼团活动数据
     * @return array
     * @throws
     */
    public function group()
    {
        //获取拼团活动数据
        $stime = strtotime(date('Y-m-d 00:00:00'));
        $etime = $stime + 86399;
        $where = [
            '$or' => [
                [
                    '$and' => [
                        ['stat' => 2],
                        ['isatv' => 1]
                    ]
                ],
                [
                    '$and' => [
                        ['stat' => 3],
                        ['gtime' => ['>=' => $stime]],
                        ['gtime' => ['<=' => $etime]]
                    ]
                ],
                [
                    '$and' => [
                        ['stat' => 4],
                        ['mtime' => ['>=' => $stime]],
                        ['mtime' => ['<=' => $etime]]
                    ]
                ]
            ]
        ];
        $cols = 'gkey,pname,label,groupimg,oprice,gprice,groupqty,payqty,etime,buyqty,stat';
        $list = SaleGroupBuyModel::M()->getList($where, $cols, ['stat' => 1, 'etime' => 1]);

        //如果有数据
        if ($list)
        {
            foreach ($list as $key => $value)
            {
                $label = json_decode($value['label'], true);
                $list[$key]['imgsrc'] = Configer::get('common')['qiniu']['product'] . '/' . $value['groupimg'];
                $list[$key]['overage'] = $value['groupqty'] - $value['buyqty'];
                $list[$key]['countdown'] = ($value['etime'] - time() > 0) ? $value['etime'] - time() : 0;
                $list[$key]['lname'] = $label['level'];

                //解析团购商品名称
                unset($label['level']);
                $pname = implode(' ', array_filter(array_values($label)));
                $list[$key]['pname'] = $value['pname'] . ' ' . str_replace('无', '', $pname);
                unset($list[$key]['label']);
            }
        }

        //返回数据
        return $list;
    }

    /**
     * 拼团详情
     * @param int $uid
     * @param string $gkey
     * @return array
     * @throws
     */
    public function groupInfo(int $uid, string $gkey)
    {
        //参数判断
        $acc = $this->commonLogic->getAcc($uid);
        if (!$gkey)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }

        //获取团购基本信息
        $cols = 'gkey,pname,label,groupimg,oprice,gprice,groupqty,payqty,stime,etime,buyqty,limitqty,describe as desc';
        $info = SaleGroupBuyModel::M()->getRowById($gkey, '*');

        //补充基本信息
        $label = json_decode($info['label'], true);
        $info['countdown'] = ($info['etime'] - time() > 0) ? $info['etime'] - time() : 0;
        $info['overage'] = $info['groupqty'] - $info['buyqty'];
        $info['lname'] = $label['level'];

        //解析团购商品名称
        unset($label['level']);
        $pname = implode(' ', array_filter(array_values($label)));
        $info['pname'] = $info['pname'] . ' ' . str_replace('无', '', $pname);
        $info['imgsrc'] = Configer::get('common')['qiniu']['product'] . '/' . $info['groupimg'];

        //自己是否已经参加团购
        $info['isjoin'] = 0;

        $stime = $info['stime'];
        $etime = $info['etime'];

        //获取购买者信息
        $where = [
            'ostat' => ['in' => [13, 21, 22, 23, 51]],
            'otime' => ['between' => [$stime, $etime]],
            'paystat' => 3,
            'groupbuy' => $gkey,
            '$group' => 'buyer',
        ];
        $buyerList = OdrOrderModel::M()->getList($where, 'buyer,sum(qty) as qty', ['paytime' => 1]);

        if ($buyerList)
        {
            //提取购买者id
            $buyers = ArrayHelper::map($buyerList, 'buyer');

            //获取用户头像信息
            $avaters = $this->accUserInterface->getAccDict($buyers, 'avatar');

            //补充数据
            foreach ($buyerList as $key => $value)
            {
                $avatar = isset($avaters[$value['buyer']]) ? $avaters[$value['buyer']]['avatar'] : '';
                $buyerList[$key]['avatar'] = Configer::get('common')['qiniu']['avatar'] . '/' . $avatar;
            }
            $info['staffs'] = $buyerList;

            //自己是否购买
            if (in_array($acc, $buyers))
            {
                $info['isjoin'] = 1;
            }
        }

        //获取团购规则
        $info['rules'] = XinxinDictData::GROUPBUY_RULES;

        //返回数据
        return $info;
    }

    /**
     * 创建订单
     * @param int $uid 用户id
     * @param string $gkey 团购订单号
     * @param int $num 购买数量
     * @return array
     * @throws
     */
    public function createOrder(int $uid, string $gkey, int $num)
    {
        //参数处理
        $acc = $this->commonLogic->getAcc($uid);
        if (!$gkey)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }
        if (!$num)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }

        //验证权限
        $permis = $this->checkGroupBuyPermis($uid, $gkey, $num);
        if ($permis['stat'] != 1)
        {
            return $permis;
        }

        //获取团购单价
        $gprice = SaleGroupBuyModel::M()->getOneById($gkey, 'gprice');

        //组装订单表数据
        $time = time();
        $okey = $this->uniqueKeyLogic->getUniversal();
        $oamt = $gprice * $num;
        $oid = IdHelper::generate();
        $data = [
            'oid' => $oid,
            'plat' => 21,
            'buyer' => $acc,
            'tid' => 13,
            'okey' => $okey,
            'qty' => $num,
            'oamt' => $oamt,
            'otime' => $time,
            'ostat' => 11,
            'paystat' => 1,
            'payamt' => $oamt,
            'groupbuy' => $gkey,
            'atime' => $time,
        ];

        //写入数据
        OdrOrderModel::M()->insert($data);

        //更新团购参团人数
        $orders = OdrOrderModel::M()->getList(['groupbuy' => $gkey], 'buyer');
        $buyers = ArrayHelper::map($orders, 'buyer');
        SaleGroupBuyModel::M()->updateById($gkey, ['orderqty' => count($buyers)]);

        //返回数据
        return ['stat' => 1, 'oid' => $oid];
    }

    /**
     * 检测团购订单购买权限
     * @param int $uid
     * @param string $gkey
     * @param int $num
     * @return mixed
     * @throws
     */
    public function checkGroupBuyPermis(int $uid, string $gkey, int $num)
    {
        //参数处理
        $acc = $this->commonLogic->getAcc($uid);
        if (!$gkey)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }
        if (!$num)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }

        //获取用户信息
        $mobile = $this->accUserInterface->getAccInfo($acc, 'mobile');
        $buyer = $this->crmBuyerInterface->getBuyerInfo($acc, 21, 'deposit');

        //检查用户数据
        $deposit = XinxinDictData::DEPOSIT;
        if ($buyer == false)
        {
            throw new AppException('买家信息不存在', AppException::DATA_MISS);
        }
        if ($mobile['mobile'] == '')
        {
            return ['stat' => 5, 'msg' => '尚未绑定手机号'];
        }
        if ($buyer['deposit'] < $deposit)
        {
            return ['stat' => 6, 'msg' => '尚未缴纳保证金', 'num' => $deposit];
        }

        //获取团购订单数据
        $info = SaleGroupBuyModel::M()->getRowById($gkey, 'groupqty,limitqty,buyqty,stat,gprice');
        if (!$info)
        {
            throw new AppException('团购信息不存在', AppException::DATA_MISS);
        }

        //团购状态
        if ($info['stat'] != 2)
        {
            return ['stat' => 2, 'msg' => '您来晚了一步,团购活动已经已结束啦'];
        }

        //验证是否存在待支付的团购订单
        $where = ['buyer' => $acc, 'plat' => 21, 'tid' => 13, 'ostat' => 11];
        $exist = OdrOrderModel::M()->exist($where);
        if ($exist)
        {
            return ['stat' => 7, 'msg' => '您还有一个未支付的拼团订单，请先完成支付后继续下单'];
        }

        //限购数量
        if ($info['limitqty'] > 0 && $num > $info['limitqty'])
        {
            return ['stat' => 3, 'num' => $info['limitqty']];
        }

        //获取当前用户在当前拼团中总共的购买数量
        $boughts = OdrOrderModel::M()->getList(['groupbuy' => $gkey, 'buyer' => $acc, 'ostat' => ['in' => [11, 13, 21, 22, 23]]], 'qty');
        if ($boughts)
        {
            $qtys = array_column($boughts, 'qty');
            $bought = array_sum(array_values($qtys));
            if ($info['limitqty'] > 0 && $bought + $num > $info['limitqty'])
            {
                return ['stat' => 3, 'num' => $info['limitqty']];
            }
        }

        //可购买数量
        $overage = $info['groupqty'] - $info['buyqty'];
        if ($num > $overage)
        {
            return ['stat' => 4, 'num' => $overage];
        }

        //返回数据
        return ['stat' => 1];
    }

    /**
     * 支付详情页
     * @param int $uid
     * @param string $oid
     * @return array
     * @throws
     */
    public function payInfo(int $uid, string $oid)
    {
        //外部参数
        $acc = $this->commonLogic->getAcc($uid);
        $time = time();

        //验证数据
        if (!$oid)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }

        //获取订单数据
        $cols = 'oid,tid,okey,qty,otime,oamt,ostat,paystat as pstat,recver,recreg,recdtl,rectel,dlyway as lway,groupbuy,_id';
        $order = OdrOrderModel::M()->getRow(['oid' => $oid, 'buyer' => $acc], $cols);
        if ($order == false)
        {
            throw new AppException('订单不存在', AppException::DATA_MISS);
        }

        //获取拼团数据
        $cols = 'pname,label,groupimg,oprice,gprice,groupqty,limitqty,buyqty,stat';
        $group = SaleGroupBuyModel::M()->getRowById($order['groupbuy'], $cols);

        //补充数据
        //取消时间
        switch ($order['tid'])
        {
            //竞拍
            case 11:
                $ctime = XinxinDictData::BID_CANCEL_TIME;
                break;
            //一口价
            case 12:
                $ctime = $order['pstat'] == 2 ? XinxinDictData::SHOP_CANCEL_TIME['offpay'] : XinxinDictData::SHOP_CANCEL_TIME['order'];
                break;
            //团购订单
            case 13:
                $ctime = XinxinDictData::GROUP_ORDER_CANCEL_TIME['order'];
                break;
        }

        //计算取消时间
        $etime = $ctime - ($time - $order['otime']);
        if ($order['ostat'] == 51 || $etime <= 0)
        {
            return ['go' => 'cancel', 'oid' => $order['_id']];
        }

        $order['odate'] = DateHelper::toString($order['otime']);
        $label = json_decode($group['label'], true);

        //如果收货地址为空
        if (empty($order['recver']) && empty($order['rectel']))
        {
            //自动获取最近一个已支付订单的地址
            $newAddress = OdrOrderModel::M()->getRow(['buyer' => $acc, 'paystat' => 3], 'recver,rectel,recreg,recdtl,dlyway', ['paytime' => -1]);

            //重新组装订单地址
            if ($newAddress)
            {
                $order['recreg'] = $newAddress['recreg'];
                $order['recver'] = $newAddress['recver'];
                $order['rectel'] = $newAddress['rectel'];
                $order['recdtl'] = $newAddress['recdtl'];
                $order['lway'] = $newAddress['dlyway'];
                $order['areaId'] = $newAddress['recreg'];
            }
        }

        //解析省市区
        $recreg = 0;
        if ($order['recreg'])
        {
            $recreg = $this->sysRegionInterface->getGroupNames($order['recreg']);
        }

        //如果只有2个长度，默认补充一个
        if (is_array($recreg) && count($recreg) == 2)
        {
            array_splice($recreg, 1, 0, [' ']);
        }

        //组装返回数据
        $info = $order;
        $info['imgsrc'] = Configer::get('common')['qiniu']['product'] . '/' . $group['groupimg'];
        $info['lname'] = $label['level'] . '货';
        unset($label['level']);
        $pname = implode(' ', array_filter(array_values($label)));
        $info['pname'] = $group['pname'] . ' ' . str_replace('无', '', $pname);
        $info['plias'] = $group['pname'];
        $info['limitqty'] = $group['limitqty'];
        $info['overage'] = $group['groupqty'] - $group['buyqty'];
        $info['recreg'] = $recreg;
        $info['rtime'] = $etime;
        $info['acc'] = $uid;
        $info['gprice'] = $group['gprice'];
        $info['addr'] = [
            'recver' => $order['recver'],
            'rectel' => $order['rectel'],
            'recdtl' => $order['recdtl'],
            'recreg' => $recreg,
            'lway' => $order['lway'],
            'areaId' => $order['recreg']
        ];

        //返回
        return $info;
    }

    /**
     * 支付结果查询
     * @param array $args
     * @return mixed
     * @throws
     */
    public function payResult(array $args)
    {
        //参数判断
        $acc = $args['uid'];
        $oid = $args['oid'];
        $acc = $this->commonLogic->getAcc($acc);
        if (!$oid)
        {
            throw new AppException('缺少订单编号', AppException::DATA_MISS);
        }
        $oid = explode(',', $oid);

        //验证订单
        $cols = 'oid,_id,okey,qty,oamt,ostat,paystat,paytime,groupbuy,paytype as ptype,disamt,payamt';
        $order = OdrOrderModel::M()->getRow(['oid' => $oid, 'buyer' => $acc], $cols);
        if (!$order)
        {
            throw new AppException('订单不存在', AppException::DATA_MISS);
        }

        //查询团购订单状态
        $cols = 'gkey,groupqty,limitqty,buyqty,stat,etime,mid,shareimg,label,gprice';
        $group = SaleGroupBuyModel::M()->getRowById($order['groupbuy'], $cols);
        if ($group)
        {
            $stat = $group['stat'];

            //组装结果数组
            $label = json_decode($group['label'], true);
            $mname = $this->qtoInquiryInterface->getModelName($group['mid'], 21);
            $group['countdown'] = $group['etime'] - time() > 0 ? $group['etime'] - time() : 0;
            $group['overage'] = $group['groupqty'] - $group['buyqty'];
            $group['okey'] = $order['okey'];
            $group['oamt'] = $order['oamt'];
            $group['pamt'] = $order['oamt'];
            $group['qty'] = $order['qty'];
            $group['mname'] = $mname;
            $group['mdram'] = $label['mdram'];
            $group['payamt'] = $order['oamt'] - $order['disamt'];
            $group['shareimg'] = Configer::get('common')['qiniu']['product'] . '/' . $group['shareimg'];
            $group['gstat'] = $group['stat'];
            unset($group['label']);

            //付款成功、团购进行中
            if ($order['ostat'] == 13 && $stat == 2)
            {
                $group['stat'] = 1;
            }

            //付款成功、拼团成功
            if ($order['ostat'] == 13 && $stat == 3)
            {
                $group['stat'] = 3;
            }

            //购买失败
            if (in_array($order['ostat'], [51, 53]) && in_array($stat, [2, 3, 4]))
            {
                $group['stat'] = 53;
            }

            //支付超时
            if ($order['ostat'] == 11 && in_array($stat, [2, 3, 4]))
            {
                $group['stat'] = 4;
            }
        }

        //账户数据
        $account = [
            '开户行：中国农业银行',
            '户　名：深圳市收收科技有限公司',
            '4100 7300 0400 20817'
        ];

        //结果数据
        $data = [
            'mind_order_account' => $account,
            'mind_order_order' => $order,
            'group' => $group
        ];

        //返回数据
        return $data;
    }
}