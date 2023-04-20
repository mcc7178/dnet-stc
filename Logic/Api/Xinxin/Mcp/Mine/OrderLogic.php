<?php
namespace App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Sale\SaleGroupBuyModel;
use App\Model\Stc\StcLogisticsModel;
use App\Module\Sale\Data\XinxinDictData;
use App\Module\Sale\Logic\Api\Xinxin\Mcp\CommonLogic;
use App\Service\Acc\AccUserInterface;
use App\Service\Pub\SysRegionInterface;
use App\Service\Qto\QtoInquiryInterface;
use App\Service\Qto\QtoLevelInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class OrderLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var CommonLogic
     */
    private $commonLogic;

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
     * @Reference("qto")
     * @var QtoLevelInterface
     */
    private $qtoLevelInterface;

    /**
     * @Reference()
     * @var SysRegionInterface
     */
    private $sysRegionInterface;

    /**
     * 获取用户订单数据
     * @param array $query
     * @return array
     * @throws
     */
    public function getPager(array $query)
    {
        //外部参数
        $acc = $query['acc'];
        $type = $query['type'];
        $idx = $query['idx'];
        $size = $query['size'];
        $data = [];
        $count = [];

        //数据检测
        if ($acc == '')
        {
            throw new AppException('缺少用户', AppException::NO_DATA);
        }

        //获取内部系统uid
        $accInfo = $this->accUserInterface->getRow(['_id' => $acc], 'aid');
        if ($accInfo == false)
        {
            throw new AppException('用户不存在', AppException::NO_DATA);
        }
        $acc = $accInfo['aid'];

        //数据条件
        $where = [
            'plat' => 21,
            'buyer' => $acc,
            'tid' => ['in' => [11, 12, 13]],
        ];
        if ($type == 1)
        {
            //待支付
            $where['ostat'] = ['in' => [11, 12]];
        }
        elseif ($type == 2)
        {
            //待收货
            $where['ostat'] = 22;
        }
        elseif ($type == 3)
        {
            //已完成
            $where['ostat'] = 23;
        }
        elseif ($type == 4)
        {
            //已取消
            $where['ostat'] = ['in' => [51, 53]];
        }
        elseif ($type == 5)
        {
            //待发货
            $where['ostat'] = ['in' => [13, 21]];
        }

        //获取用户订单数据
        $cols = 'oid,tid,src,okey,qty,oamt,ostat,paystat,payamt,paytype,groupbuy,_id';
        $list = OdrOrderModel::M()->getList($where, $cols, ['otime' => -1], $size, $idx);

        //如果有订单数据
        if ($list)
        {
            $tempData1 = []; //普通订单
            $tempData2 = []; //拼团订单
            foreach ($list as $key => $value)
            {
                $okey = $value['okey'];
                if ($value['tid'] == 13)
                {
                    $tempData2[$okey] = $value;
                }
                else
                {
                    $tempData1[$okey] = $value;
                }
            }

            //如果有普通订单
            if (count($tempData1) > 0)
            {
                $odrGoodsDict = $this->getOrderGoodsDict($tempData1);
            }

            //如果有拼团订单
            if (count($tempData2) > 0)
            {
                $groupBuyGoodsDict = $this->getGroupBuyOrderDict($tempData2);
            }

            $data = [];
            $tempData = [
                'order' => [],
                'goods' => [],
                'groupbuy' => [],
            ];
            foreach ($list as $key => $value)
            {
                $okey = $value['okey'];
                $tid = $value['tid'];

                $order = [
                    'oid' => $value['oid'],
                    'tid' => $value['tid'],
                    'okey' => $value['okey'],
                    'otime' => empty($value['otime']) ? 0 : DateHelper::toString($value['otime']),
                    'ostat' => $value['ostat'],
                    'paystat' => $value['paystat'],
                    'paytype' => $value['paytype'],
                    'qty' => $value['qty'],
                    'oamt' => $value['oamt'],
                    'groupbuy' => $value['groupbuy'],
                    'canceled' => 0,
                    '_id' => $value['_id'],
                ];
                $tempData['order'] = $order;

                //根据订单类型组装数据
                switch ($tid)
                {
                    case 13: //拼团订单
                        $tempData = array_merge($tempData, $groupBuyGoodsDict[$okey] ?? []);
                        break;
                    default: //普通订单
                        $tempData = array_merge($tempData, $odrGoodsDict[$okey] ?? []);
                        break;
                }
                $data[] = $tempData;
            }
        }

        //获取订单类型数量
        $countwhere = [
            'plat' => 21,
            'buyer' => $acc,
            'tid' => ['in' => [11, 12, 13]],
            '$group' => 'ostat',
        ];
        $odrcount = OdrOrderModel::M()->getDict('ostat', $countwhere, 'count(1) as count');

        //组装各个订单状态数量
        $count = [
//            'waitpay' => ($odrcount[11]['count'] ?? 0),    //待支付
//            'waitpays' => ($odrcount[11]['count'] ?? 0) + ($odrcount[12]['count'] ?? 0),    //待支付+待审核
            'waitpay' => ($odrcount[11]['count'] ?? 0) + ($odrcount[12]['count'] ?? 0),    //待支付+待审核
            'deliver' => ($odrcount[21]['count'] ?? 0) + ($odrcount[13]['count'] ?? 0),    //待发货
            'receiving' => $odrcount[22]['count'] ?? 0,   //已发货
            'finish' => $odrcount[23]['count'] ?? 0,       //交易完成
            'cancel' => $odrcount[51]['count'] ?? 0        //取消交易
        ];

        //返回
        return [
            'list' => $data,
            'count' => $count ?? []
        ];
    }

    /**
     * 获取订单详情
     * @param string $oid 订单ID
     * @param string $acc 用户ID
     * @return array
     * @throws
     */
    public function detail(string $oid, string $acc)
    {
        //参数判断
        if ($acc == '')
        {
            throw new AppException('缺少用户', AppException::NO_DATA);
        }

        //获取内部系统uid
        $accInfo = $this->accUserInterface->getRow(['_id' => $acc], 'aid');
        if ($accInfo == false)
        {
            throw new AppException('用户不存在', AppException::NO_DATA);
        }
        $acc = $accInfo['aid'];

        if (!$oid)
        {
            throw new AppException('缺少参数', AppException::WRONG_ARG);
        }

        //获取订单数据
        $cols = 'plat,tid,src,okey,qty,oamt,ostat,otime,rtnstat,paystat,paytype,recver,rectel,recreg,recdtl,rmk1,groupbuy,payamt,dlyway,dlykey,disamt,_id';
        if (is_numeric($oid))
        {
            $where = [
                '_id' => $oid,
                'buyer' => $acc,
            ];
        }
        else
        {
            $where = [
                'oid' => $oid,
                'buyer' => $acc,
            ];
        }
        $orderInfo = OdrOrderModel::M()->getRow($where, $cols);
        if ($orderInfo == false)
        {
            throw new AppException('订单数据不存在,如有疑问请联系客服', AppException::NO_DATA);
        }

        //提取基础参数
        $okey = $orderInfo['okey'];
        $ostat = $orderInfo['ostat'];
        $paystat = $orderInfo['paystat'];
        $paytype = $orderInfo['paytype'];
        $groupBuyId = $orderInfo['groupbuy'];
        $otime = $orderInfo['otime'];
        $qty = $orderInfo['qty'];

        //获取用户保证金
        $deposit = XinxinDictData::DEPOSIT;

        //获取省市区
        if ($orderInfo['recreg'] != 0)
        {
            $recdtl = $this->sysRegionInterface->getfullname($orderInfo['recreg']) . $orderInfo['recdtl'];
        }

        //处理订单时间
        $otime = DateHelper::toString($otime);

        //组装订单数据
        $order = [
            'okey' => $okey,
            'groupbuy' => $groupBuyId,
            'qty' => $qty,
            'src' => $orderInfo['src'],
            'oamt' => $orderInfo['oamt'],
            'disamt' => $orderInfo['disamt'],
            'ostat' => $ostat,
            'paystat' => $paystat,
            'paytype' => $paytype,
            'otime' => $otime,
            'recver' => $orderInfo['recver'],
            'rectel' => $orderInfo['rectel'],
            'recdtl' => $recdtl ?? $orderInfo['recdtl'],
            'rtnstat' => $orderInfo['rtnstat'],
            'dlyway' => $orderInfo['dlyway'],
            'rmk1' => $orderInfo['rmk1'],
            'rtime' => $rtime ?? 0,
            '_id' => $orderInfo['_id'],
            'deposit' => $deposit,
        ];

        //组装返回数据
        $data = [
            'order' => $order,
            'goods' => [],
            'groupbuy' => [],
        ];

        if (in_array($ostat, [11, 13, 21, 51, 53]) && $orderInfo['tid'] == 13)
        {
            //获取拼团订单相关数据
            $groupBuyData = $this->getGroupBuyOrderDict([$okey => $orderInfo]);
            $data = array_merge($data, $groupBuyData[$okey] ?? []);
        }
        else
        {
            //获取普通订单相关数据
            $odrGoodsDict = $this->getOrderGoodsDict([$okey => $orderInfo]);
            $data = array_merge($data, $odrGoodsDict[$okey] ?? []);
        }

        //待收货状态的订单按物流包裹拆分商品
        if ($ostat == 22)
        {
            $data['goods'] = $this->getPackgerData($data['goods']);
        }

        //返回
        return $data;
    }

    /**
     * 获取普通订单商品列表
     * @param array $order 订单列表
     * @return array
     * @throws
     */
    private function getOrderGoodsDict(array $order)
    {
        //提取订单号
        $okeys = array_keys($order);

        //获取商品信息
        $odrGoods = OdrGoodsModel::M()->getDicts('okey', ['okey' => ['in' => $okeys]], 'src,pid as _pid,sid as _sid,bprc,rtntype,dlykey,_id');
        if ($odrGoods == false)
        {
            return [];
        }

        //提取商品ID
        $pids = [];
        foreach ($odrGoods as $value)
        {
            foreach ($value as $item)
            {
                $pids[] = $item['_pid'];
            }
        }

        //提取老系统订单ID
        $oids = ArrayHelper::map($order, '_id');

        //获取老系统商品信息
        $oldOdrGoods = \App\Model\Dnet\OdrGoodsModel::M()->getDict('gid', ['oid' => ['in' => $oids]], 'sid,rid');

        //提取拼团ID
        $gkeys = ArrayHelper::map($order, 'groupbuy');

        //获取拼团机器图片
        $saleGroupDict = SaleGroupBuyModel::M()->getDict('gkey', ['gkey' => ['in' => $gkeys]], 'groupimg');

        //获取商品数据
        $prdSupplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pname,level,imgsrc');
        $prdProductDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'inway,_id');

        //获取级别字典
        $levelDict = $this->qtoLevelInterface->getDict();

        //补充数据
        foreach ($order as $key => $value)
        {
            $goods = $odrGoods[$key] ?? [];
            foreach ($goods as $key2 => $value2)
            {
                $pid = $value2['_pid'];
                $gid = $value2['_id'];
                $level = $prdSupplyDict[$pid]['level'] ?? 0;
                $imgsrc = $prdSupplyDict[$pid]['imgsrc'] ?? '';
                $goods[$key2]['pname'] = $prdSupplyDict[$pid]['pname'] ?? '-';
                $goods[$key2]['level'] = $prdSupplyDict[$pid]['level'] ?? 0;
                $goods[$key2]['imgsrc'] = Utility::supplementProductImgsrc($imgsrc, 60);
                $goods[$key2]['levelName'] = $levelDict[$level]['lname'] ?? 0;
                $goods[$key2]['inway'] = $prdProductDict[$pid]['inway'] ?? 0;
                $goods[$key2]['pid'] = $prdProductDict[$pid]['_id'] ?? '-';
                $goods[$key2]['sid'] = $oldOdrGoods[$gid]['sid'] ?? 0;
                $goods[$key2]['rid'] = 0; //场次ID字段，实际用不到
                $goods[$key2]['qty'] = 1;
                if ($value2['rtntype'] == 3)
                {
                    $order[$key]['order']['cancelled'] = 1;
                }
                if ($value['tid'] == 13)
                {
                    //如果是拼团订单，则显示拼团图片 => 待收货及已完成订单
                    $goods[$key2]['imgsrc'] = Utility::supplementQiniuDomain($saleGroupDict[$value['groupbuy']]['groupimg']);
                }

                //替换tid，否则前端订单详情跳转页面会出错
                $tid = 2;
                if ($value['src'] == 1101)
                {
                    $tid = 1;
                }
                $goods[$key2]['tid'] = $tid;
            }
            $order[$key] = ['goods' => $goods];
        }

        //返回
        return $order;
    }

    /**
     * 获取拼团订单数据
     * @param array $order 团购订单号
     * @return array
     * @throws
     */
    private function getGroupBuyOrderDict(array $order)
    {
        $groupBuyOrder = [];

        //获取商品信息
        $gkeys = ArrayHelper::map($order, 'groupbuy');
        $cols = 'gkey,mid,gprice,level,groupqty,buyqty,etime,groupimg,shareimg,label,stat';
        $saleGroup = SaleGroupBuyModel::M()->getDict('gkey', ['gkey' => ['in' => $gkeys]], $cols);
        if ($saleGroup == false)
        {
            return [];
        }

        //获取机型名称
        $mids = ArrayHelper::map($saleGroup, 'mid');
        $midDict = $this->qtoInquiryInterface->getDictModels($mids, 21);

        //补充数据
        foreach ($order as $key => $value)
        {
            $value2 = $saleGroup[$value['groupbuy']] ?? SaleGroupBuyModel::M()->getDefault();
            $mname = $midDict[$value2['mid']]['mname'] ?? '';
            $label = ArrayHelper::toArray($value2['label']);
            $mdnet = $label['mdnet'];
            $mdram = $label['mdram'];
            $mdcolor = $label['mdcolor'];
            $mdofsale = $label['mdofsale'];
            $goods['levelName'] = $label['level'];
            $goods['imgsrc'] = Utility::supplementQiniuDomain($value2['groupimg']);
            $goods['pname'] = $mname . ' ' . $mdram . ' ' . $mdcolor . ' ' . $mdofsale . ' ' . $mdnet;
            $goods['bprc'] = $value2['gprice'];
            $goods['qty'] = $value['qty'];
            $goods['level'] = $value2['level'];

            //拼团信息
            $dqty = 0;
            $groupqty = $value2['groupqty'];
            $buyqty = $value2['buyqty'];
            if ($groupqty > $buyqty)
            {
                $dqty = $groupqty - $buyqty;    //剩余成团数量
            }

            $dtime = 0;
            if ($value2['etime'] > time())
            {
                $dtime = $value2['etime'] - time();  //距离结束时间时长
            }

            //组装拼团返回信息
            $groupbuy = [
                'buyqty' => $buyqty,
                'dqty' => $dqty,
                'dtime' => $dtime,
                'gkey' => $value2['gkey'],
                'gprice' => $value2['gprice'],
                'stat' => $value2['stat'],
                'shareimg' => Utility::supplementQiniuDomain($value2['shareimg']),
            ];
            $groupBuyOrder[$key]['goods'] = [$goods];
            $groupBuyOrder[$key]['groupbuy'] = $groupbuy;
        }

        //返回
        return $groupBuyOrder;
    }

    /**
     * @param array $goods 商品信息
     * @return array
     */
    private function getPackgerData(array $goods)
    {
        //转成字典数组
        $dictGoods = [];
        foreach ($goods as $value)
        {
            $dictGoods[$value['dlykey']][] = $value;
        }

        //组装数据
        $data = [];
        foreach ($dictGoods as $key => $value)
        {
            $data[] = [
                'express' => [
                    'dlykey' => $key,
                ],
                'goods' => $value
            ];
        }

        //返回
        return $data;
    }

    /**
     * 订单动态
     * @param int $uid
     * @param string $oids
     * @return array
     * @throws
     */
    public function dynamic(int $uid, string $oids)
    {
        //验证参数
        $acc = $this->commonLogic->getAcc($uid);
        if (!$oids)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }

        //获取订单数据
        $oids = explode(',', $oids);
        $cols = 'oid,_id,groupbuy,otime,ostat,paystat,tid';
        $orderDict = OdrOrderModel::M()->getDict('oid', ['oid' => ['in' => $oids], 'buyer' => $acc], $cols);
        if (!$orderDict)
        {
            throw new AppException('订单数据不存在', AppException::DATA_MISS);
        }

        //获取团购数据
        $gkeys = ArrayHelper::map($orderDict, 'groupbuy');
        $groupDict = SaleGroupBuyModel::M()->getDict('gkey', ['gkey' => ['in' => $gkeys]], 'groupqty,buyqty,stat,etime');

        //有数据
        foreach ($orderDict as $key => $value)
        {
            switch ($value['ostat'])
            {
                case 11:
                    switch ($value['tid'])
                    {
                        case 11:
                            $ctime = XinxinDictData::BID_CANCEL_TIME;
                            break;
                        case 12:
                            $ctime = XinxinDictData::SHOP_CANCEL_TIME['order'];
                            break;
                        case 13:
                            $ctime = XinxinDictData::GROUP_ORDER_CANCEL_TIME['order'];
                            break;
                    }

                    $countdown = $ctime - (time() - $value['otime']);
                    $orderDict[$key]['countdown'] = $countdown > 0 ? $countdown : 0;
                    break;
                case 13:
                    if ($value['tid'] == 13)
                    {
                        $group = $groupDict[$value['groupbuy']];
                        $orderDict[$key]['overage'] = $group['groupqty'] - $group['buyqty'];
                        $orderDict[$key]['countdown'] = $group['etime'] - time();
                    }
                    break;
            }
        }

        //返回数据
        return $orderDict;
    }

    /**
     * 用户确认收货
     * @param int $uid 用户id
     * @param string $oid 订单id
     * @return string
     * @throws
     */
    public function confirmReceipt(int $uid, string $oid)
    {
        //验证参数
        $acc = $this->commonLogic->getAcc($uid);
        if (!$oid)
        {
            throw new AppException('缺少参数', AppException::DATA_MISS);
        }

        //获取订单数据
        if (is_numeric($oid))
        {
            $where = [
                '_id' => $oid,
                'buyer' => $acc
            ];
        }
        else
        {
            $where = [
                'oid' => $oid,
                'buyer' => $acc
            ];
        }
        $odrOrder = OdrOrderModel::M()->getList($where, 'dlykey,okey');
        if ($odrOrder == false)
        {
            return 'ok';
        }

        //更新订单状态
        OdrOrderModel::M()->update($where, ['ostat' => 23]);

        //获取订单信息
        $dkeys = ArrayHelper::map($odrOrder, 'dlykey');
        $okeys = ArrayHelper::map($odrOrder, 'okey');

        //更新订单商品状态
        OdrGoodsModel::M()->update(['okey' => ['in' => $okeys]], ['ostat' => 23]);

        //更新物流单状态
        StcLogisticsModel::M()->update(['lkey' => ['in' => $dkeys], 'buyer' => $acc], [
            'lstat' => 6,
            'ltime6' => time()
        ]);

        //返回
        return 'ok';
    }
}