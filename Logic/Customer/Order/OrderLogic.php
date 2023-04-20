<?php
namespace App\Module\Sale\Logic\Customer\Order;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsMirrorModel;
use App\Module\Sale\Data\PayData;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Throwable;

class OrderLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderPayLogic
     */
    private $orderPayLogic;

    /**
     * 获取商品列表
     * @param array $query
     * @param string $acc
     * @param int $idx
     * @param int $size
     * @return array
     */
    public function getPager(array $query, string $acc, int $size, int $idx)
    {

        //数据条件
        $where = $this->getWhere($query, $acc);
        $where['src'] = 23;
        $where['tid'] = 21;
        $where['plat'] = 23;

        //获取订单数据
        $cols = 'oid,okey,qty,oamt,ostat,atime,paytime';
        $odrOrder = OdrOrderModel::M()->getList($where, $cols, ['ostat' => 1, 'atime' => -1, 'paytime' => -1], $size, $idx);

        //组装数据
        foreach ($odrOrder as $key => $value)
        {
            $odrOrder[$key]['ostatName'] = SaleDictData::ODR_ORDER_STAT[$value['ostat']] ?? '-';
            $odrOrder[$key]['atime'] = DateHelper::toString($value['atime']);
            $odrOrder[$key]['paytime'] = DateHelper::toString($value['paytime']);
        }

        //返回
        return $odrOrder;
    }

    /**
     * 获取列表总数
     * @param array $query
     * @param string $acc
     * @return int
     */
    public function getCount(array $query, string $acc)
    {
        //获取查询条件
        $where = $this->getWhere($query, $acc);
        $where['src'] = 23;
        $where['tid'] = 21;
        $where['plat'] = 23;

        //返回数据
        return OdrOrderModel::M()->getCount($where);
    }

    /**
     * 获取订单详情
     * @param string $acc
     * @param string $oid
     * @return array
     * @throws
     */
    public function getInfo(string $acc, string $oid)
    {
        //查询字段
        $cols = 'oid,okey,paystat,ostat,qty,oamt,atime,paytime,buyer,plat,tid';

        //获取订单数据
        $orderInfo = OdrOrderModel::M()->getRow(['oid' => $oid], $cols);
        if ($orderInfo == false)
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }
        if ($orderInfo['plat'] !==23 || $orderInfo['tid'] !== 21)
        {
            throw new AppException('订单状态不允许操作', AppException::OUT_OF_OPERATE);
        }

        //验证用户
        if ($orderInfo['buyer'] !== $acc)
        {
            throw new AppException('你无权查看', AppException::NO_RIGHT);
        }

        //补充姓名
        $orderInfo['buyer'] = AccUserModel::M()->getOneById($acc, 'rname');

        //支付状态
        $orderInfo['ostatname'] = SaleDictData::ODR_ORDER_STAT[$orderInfo['ostat']] ?? '-';

        //创建时间
        $orderInfo['atime'] = DateHelper::toString($orderInfo['atime']);

        //支付时间
        $orderInfo['paytime'] = DateHelper::toString($orderInfo['paytime']);

        //商品订单明细
        $orderGoods = OdrGoodsModel::M()->getList(['okey' => $orderInfo['okey']], 'bcode,pid,bprc');

        $goods = [];
        if (count($orderGoods) > 0)
        {
            //pid字典
            $pids = ArrayHelper::map($orderGoods, 'pid');

            //获取商品数据
            $product = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pid,bcode,bid,mid,level');
            if ($product)
            {
                //品牌字典
                $bids = ArrayHelper::map($product, 'bid');
                $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');

                //机型字典
                $mids = ArrayHelper::map($product, 'mid');
                $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

                //级别字典
                $levels = ArrayHelper::map($product, 'level');
                $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lname');

                //组装数据
                foreach ($orderGoods as $key => $value)
                {
                    $pid = $value['pid'];
                    $goods[] = [
                        'bcode' => $value['bcode'] ?? '-',
                        'brand' => $qtoBrand[$product[$pid]['bid']]['bname'] ?? '-',
                        'pname' => $qtoModel[$product[$pid]['mid']]['mname'] ?? '-',
                        'level' => $qtoLevel[$product[$pid]['level']]['lname'] ?? '-',
                        'bprc' => $value['bprc'],
                    ];
                }
            }
        }

        $orderInfo['goods'] = $goods;

        //获取预下单支付数据
        if (in_array($orderInfo['ostat'], [11, 12]))
        {
            $orderInfo['payInfo'] = $this->getPayInfo($oid);
        }

        //填充默认值
        ArrayHelper::fillDefaultValue($orderInfo);

        //返回
        return $orderInfo;
    }

    /**
     * 获取商品详情
     * @param string $bcode
     * @return array
     * @throws
     */
    public function getPrdInfo(string $bcode, string $oid)
    {
        //查询字段
        $cols = 'pid,bid,mid,plat,level,palias,prdcost';

        //获取商品数据
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode], $cols);
        if ($info == false)
        {
            throw new AppException('商品不存在', AppException::NO_DATA);
        }

        //获取订单数据
        $okey = OdrOrderModel::M()->getOne(['oid' => $oid], 'okey');

        //获取订单商品数据
        $bcodes = OdrGoodsModel::M()->getDistinct('bcode', ['okey' => $okey]);
        //订单中是否有此商品
        if (!in_array($bcode, $bcodes))
        {
            throw new AppException('订单中的没有此商品', AppException::NO_DATA);
        }

        //品牌名
        $info['bname'] = QtoBrandModel::M()->getOne(['bid' => $info['bid']], 'bname') ?? '-';

        //获取机型
        $info['model'] = QtoModelModel::M()->getOne(['mid' => $info['mid']], 'mname') ?? '-';

        //获取级别
        $info['level'] = QtoLevelModel::M()->getOne(['lkey' => $info['level']], 'lname') ?? '-';

        //质检备注
        $qcReport = MqcReportModel::M()->getRow(['pid' => $info['pid'], 'plat' => 21], 'bconc,bmkey', ['atime' => -1]);

        //获取质检详情
        $content = QtoOptionsMirrorModel::M()->getRow(['mkey' => $qcReport['bmkey']], 'content', ['atime' => -1]);

        $newList = [];
        if ($content)
        {
            $list = ArrayHelper::toArray($content['content']);

            //组装数据
            foreach ($list as $key => $value)
            {
                //异常标红
                foreach ($value['opts'] as $key1 => $item)
                {
                    if ($item['normal'] == -1)
                    {
                        $value['opts'][$key1]['oname'] = '<span style="color: #ff0000">' . $item['oname'] . '</span>';
                    }
                }

                $newList[] = [
                    'desc' => implode(' ', array_column($value['opts'], 'oname')),
                    'cname' => $value['cname'],
                    'cid' => $value['cid'],
                ];
            }
        }

        $info['data'] = $newList;
        $info['qcReport'] = $qcReport['bconc'];

        //返回
        return $info;
    }

    /**
     * 获取查询字段
     * @param $query
     * @return array
     */
    public function getWhere(array $query, string $acc)
    {
        $where = [];

        //用户
        if ($acc)
        {
            $where['buyer'] = $acc;
        }

        //供应编码
        if ($query['bcode'])
        {
            $where['okey'] = OdrGoodsModel::M()->getOne(['bcode' => $query['bcode'], 'src' => 23, 'tid' => 21, 'plat' => 23], 'okey');
        }

        //订单编号
        if ($query['okey'])
        {
            $where['okey'] = $query['okey'];
        }

        //支付状态
        if ($query['ostat'] != '')
        {
            $where['ostat'] = $query['ostat'];
        }

        //时间类型
        if ($query['ttype'] && count($query['time']) == 2)
        {
            $where[$query['ttype']] = [
                'between' => [
                    strtotime($query['time'][0] . ' 00:00:00'),
                    strtotime($query['time'][1] . ' 23:59:59')
                ]
            ];
        }

        //返回
        return $where;
    }

    /**
     * 导出功能
     * @param array $query
     * @param string $acc
     * @return array
     * @throws Throwable
     */
    public function export(array $query, string $acc)
    {
        //导出需要选取时间
        if ($query['time'] == [])
        {
            throw new AppException('防止数据量过大请选择指定下单日期导出', AppException::OUT_OF_TIME);
        }

        //时间不得超过7天
        if ($query['time'])
        {
            $date = $query['time'];
            if (count($date) == 2)
            {
                //一天中开始时间和结束时间
                $stime = strtotime($date[0] . ' 00:00:00');
                $etime = strtotime($date[1] . ' 23:59:59');

                if ($etime - $stime > 604800)
                {
                    throw new AppException('所选时间不得超过7天', AppException::OUT_OF_TIME);
                }
            }
        }


        //数据条件
        $where = $this->getWhere($query, $acc);
        $where['buyer'] = $acc;

        //获取自己的所有订单
        $odrGoods = [];
        $orders = OdrOrderModel::M()->getDict('okey', $where, 'oid,okey');
        if ($orders)
        {
            //获取商品数据
            $okeys = ArrayHelper::map($orders, 'okey');
            $odrGoods = OdrGoodsModel::M()->getList(['okey' => ['in' => $okeys]], 'pid,okey,bcode,bprc');
            if (!$odrGoods)
            {
                throw new AppException('商品不存在', AppException::NO_DATA);
            }

            //品牌商品
            $pids = ArrayHelper::map($odrGoods, 'pid');
            $product = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pid,bcode,bid,mid,level');

            //品牌字典
            $bids = ArrayHelper::map($product, 'bid');
            $qtoBrand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');

            //机型字典
            $mids = ArrayHelper::map($product, 'mid');
            $qtoModel = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

            //级别字典
            $levels = ArrayHelper::map($product, 'level');
            $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lname');

            //组装数据
            foreach ($odrGoods as $key => $value)
            {
                $pid = $value['pid'];
                $odrGoods[$key]['oid'] = $orders[$value['okey']]['oid'] ?? '-';
                $odrGoods[$key]['bname'] = $qtoBrand[$product[$pid]['bid']]['bname'] ?? '-';
                $odrGoods[$key]['pname'] = $qtoModel[$product[$pid]['mid']]['mname'] ?? '-';
                $odrGoods[$key]['level'] = $qtoLevel[$product[$pid]['level']]['lname'] ?? '-';

            }
        }

        //拼装excel数据
        $data['list'] = $odrGoods;
        $data['header'] = [
            'okey' => '订单编号',
            'bcode' => '库存编号',
            'bname' => '品牌',
            'pname' => '机型',
            'level' => '级别',
            'bprc' => '出价',
        ];

        //返回数据
        return $data;
    }

    /**
     * 获取待支付订单 预支付数据
     * @param string $oid
     * @return array|void
     * @throws
     */
    private function getPayInfo(string $oid)
    {
        if (empty($oid))
        {
            return;
        }

        //获取微信二维码支付预下单数据
        $wxPayData = $this->orderPayLogic->create($oid, 11);

        //获取支付宝二维码支付预下单数据
        $aliPayData = $this->orderPayLogic->create($oid, 12);

        //线下支付对公账户
        $offPayData = PayData::ACCOUNT;

        //返回
        return [
            'wxPayData' => $wxPayData,
            'aliPayData' => $aliPayData,
            'offPayData' => $offPayData,
        ];
    }

    /**
     * 取消订单
     * @param string $oid
     * @param string $acc
     * @throws
     */
    public function cancel(string $oid, string $acc)
    {
        //获取订单数据
        $orderInfo = OdrOrderModel::M()->getRow(['oid' => $oid], 'tid,okey,ostat,plat,buyer');
        if ($orderInfo == false)
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }
        if ($orderInfo['buyer'] !== $acc)
        {
            throw new AppException('你无权操作', AppException::NO_RIGHT);
        }
        if ($orderInfo['ostat'] !== 10 || $orderInfo['tid'] !== 21)
        {
            throw new AppException('当前订单状态不支持取消订单', AppException::WRONG_ARG);
        }
        if ($orderInfo['plat'] !== 23)
        {
            throw new AppException('当前订单销售平台不支持取消订单', AppException::WRONG_ARG);
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $orderInfo['okey']], 'pid');
        if (!$odrGoods)
        {
            throw new AppException('商品关联订单数据异常', AppException::NO_DATA);
        }

        $time = time();

        try
        {
            //开启事务
            Db::beginTransaction();

            //恢复订单下的所有订单商品状态
            OdrGoodsModel::M()->update(['okey' => $orderInfo['okey']], ['ostat' => 0, 'mtime' => $time]);

            //将订单恢复到待成交状态
            OdrOrderModel::M()->updateById($oid, ['ostat' => 0, 'mtime' => $time]);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //事务回滚
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

}