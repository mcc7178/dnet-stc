<?php
namespace App\Module\Sale\Logic\Customer\Home;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmAddressModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsMirrorModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;
use Throwable;

class HomeLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * 获取外单
     * @param string $bcode 查询参数
     * @param string $acc
     * @return array|string
     * @throws
     */
    public function getDetail(string $acc, string $bcode)
    {
        //获取商品
        $col = 'pid,bid,mid,plat,level,palias,prdcost,stcstat,prdstat';
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode, 'stcstat' => ['in' => [11, 15, 23]], 'prdstat' => ['in' => [1, 2]], 'stcwhs' => 105], $col);
        if (!$info)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }
        if (($info['stcstat'] == 11 || $info['stcstat'] == 15) && $info['prdstat'] == 2)
        {
            throw new AppException('数据不存在', AppException::NO_DATA);
        }

        //是否报价中
        if ($info['stcstat'] == 15 || $info['stcstat'] == 23)
        {
            //报价中则返回订单id
            $list = OdrGoodsModel::M()
                ->join(OdrOrderModel::M(), ['A.okey' => 'B.okey'])
                ->getRow(['A.bcode' => $bcode, 'B.buyer' => $acc, 'A.plat' => 23, 'A.tid' => 21, 'A.src' => 23], 'B.oid,B.ostat', ['A.atime' => -1]);
            if (!$list)
            {
                throw new AppException('商品已被录入', AppException::NO_RIGHT);
            }

            //返回
            return $list;
        }

        //品牌名
        $info['bname'] = QtoBrandModel::M()->getOne(['bid' => $info['bid']], 'bname') ?? '-';

        //获取机型
        $info['model'] = QtoModelModel::M()->getOne(['mid' => $info['mid']], 'mname') ?? '-';

        //获取级别
        $info['levelName'] = QtoLevelModel::M()->getOne(['lkey' => $info['level']], 'lname') ?? '-';

        //质检备注
        $qcReport = MqcReportModel::M()->getRow(['pid' => $info['pid'], 'plat' => 21], 'bconc,bmkey', ['atime' => -1]);
        $info['data'] = [];
        if ($qcReport)
        {
            //获取质检详情
            $content = QtoOptionsMirrorModel::M()->getRow(['mkey' => $qcReport['bmkey']], 'content', ['atime' => -1]);
            if ($content)
            {
                $list = ArrayHelper::toArray($content['content']);

                //组装数据
                $newList = [];
                foreach ($list as $key => $value)
                {
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
                $info['data'] = $newList;
            }
        }
        $info['qcReport'] = $qcReport['bconc'];

        //返回
        return $info;
    }

    /**
     * 保存出价记录
     * @param string $acc
     * @param string $bcode
     * @param int $bprc
     * @param string $oid
     * @throws
     */
    public function bid(string $acc, string $bcode, int $bprc, string $oid)
    {
        //获取商品
        $col = 'pid,bid,bcode,mid,plat,level,palias,prdcost,stcstat,salecost,supcost,stcwhs,prdstat,inway,offer';
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode, 'stcstat' => ['in'=>[11,15]], 'prdstat' => 1, 'stcwhs' => 105], $col);
        if (!$info)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }

        //获取收货地址
        $crmAddress = CrmAddressModel::M()->getRow(['uid' => $acc], 'lnker,lnktel,rgnid,rgndtl', ['def' => -1, 'mtime' => -1]);
        if (!$crmAddress)
        {
            //获取用户信息
            $userInfo = AccUserModel::M()->getRowById($acc, 'rname,mobile');
            if ($userInfo)
            {
                $crmAddress['lnker'] = $userInfo['rname'];
                $crmAddress['lnktel'] = $userInfo['mobile'];
                $crmAddress['rgnid'] = '440304';
                $crmAddress['rgndtl'] = '';
            }
        }

        //订单数据
        $plat = 23;
        $supprof = 0;
        $productPid = $info['pid'];
        $salecost = $info['salecost'];

        //计算订单相关金额
        $oamt1 = 0; //自营商品金额
        $oamt2 = 0; //供应商商品金额
        $scost11 = 0; //自营商品成本
        $scost21 = 0; //供应商商品成本
        $profit11 = 0; //自有商品毛利
        $profit21 = 0; //供应商商品毛利

        //提取供应商品数据
        $prdSupply = PrdSupplyModel::M()->getRow(['pid' => $productPid, 'salestat' => 1], 'sid,pid');
        if ($prdSupply == false)
        {
            throw new AppException('缺少供应商品数据', AppException::NO_DATA);
        }

        //获取供应商数据
        $offerId = $info['offer'];
        $offerDict = CrmOfferModel::M()->getRow(['oid' => $offerId, 'tid' => 2], 'oid,exts');

        /*
        * 销售毛利说明
        * 供应商商品时：毛利 = 佣金 - 成本
        * 自有商品时：毛利 = 销售价 - 成本
        */
        if ($info['inway'] == 21)
        {
            $issup = 1;
            $supprof = $this->calculateOfferCommission($plat, $bprc, $offerDict);
            $profit = $supprof - $salecost;
        }
        else
        {
            $issup = 0;
            $profit = $bprc - $salecost;
        }

        //查询条件
        $where['paystat'] = 0;
        $where['buyer'] = $acc;
        if ($oid != '')
        {
            $where['ostat'] = ['in' => [0, 10]];
            $where['oid'] = $oid;
        }
        else
        {
            //查询当天是否有未支付的订单的条件
            $where['atime'] = [
                'between' => [
                    strtotime(date('Y-m-d') . ' 00:00:00'),
                    strtotime(date('Y-m-d') . ' 23:59:59')
                ]
            ];
            $where['ostat'] = 0;
        }

        $odrOrder = OdrOrderModel::M()->getRow($where);

        //判断是否存在
        if ($odrOrder)
        {
            $oid = $odrOrder['oid'];

            //获取订单商品数据
            $odrGoods = OdrGoodsModel::M()->getRow(['bcode' => $bcode, 'okey' => $odrOrder['okey']], 'okey,gid', ['atime' => -1]);
            if ($odrGoods){
                if ($odrOrder['ostat'] !== 0)
                {
                    throw new AppException('数据不允许操作',AppException::OUT_OF_OPERATE);
                }
                //组装更新数据
                $goodsData = [
                    'bprc' => $bprc,
                    'supprof' => $supprof,
                    'issup' => $issup,
                    'profit1' => $profit,
                    'profit2' => $profit,
                    'mtime' => time(),
                ];

                //更新订单商品价格
                OdrGoodsModel::M()->updateById($odrGoods['gid'], $goodsData);

                //获取更新后的商品数据
                $ordGoods2 = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'bprc,profit1,supprof,issup');

                //处理数据
                foreach ($ordGoods2 as $value)
                {
                    $bprc = $value['bprc'];
                    $profit1 = $value['profit1'];
                    $supprof += $value['supprof'];
                    if ($value['issup'] == 0)
                    {
                        $oamt1 += $bprc;
                        $profit11 += $profit1;
                    }
                    else
                    {
                        $oamt2 += $bprc;
                        $profit21 += $profit1;
                    }
                }

                //合计订单总金额
                $oamt = $oamt1 + $oamt2;

                //组装数据
                $orderData = [
                    'oamt' => $oamt,
                    'oamt1' => $oamt1,
                    'oamt2' => $oamt2,
                    'payamt' => $oamt,
                    'supprof' => $supprof,
                    'profit11' => $profit11,
                    'profit12' => $profit11,
                    'profit21' => $profit21,
                    'profit22' => $profit21,
                    'mtime' => time(),
                ];
            }
            else
            {
                //插入订单商品表
                $gid = IdHelper::generate();
                $data = [
                    'gid' => $gid,
                    'plat' => $odrOrder['plat'],
                    'okey' => $odrOrder['okey'],
                    'yid' => $prdSupply['sid'],
                    'tid' => $odrOrder['tid'],
                    'src' => $odrOrder['src'],
                    'ostat' => $odrOrder['ostat'],
                    'offer' => $info['offer'],
                    'pid' => $info['pid'],
                    'bcode' => $info['bcode'],
                    'scost1' => $info['salecost'],
                    'scost2' => $info['salecost'],
                    'supcost' => $info['supcost'],
                    'bprc' => $bprc,
                    'supprof' => $supprof,
                    'profit1' => $profit,
                    'profit2' => $profit,
                    'issup' => $issup,
                    'atime' => time(),
                    '_id' => $gid
                ];

                //更新订单表的字段
                $qty = $odrOrder['qty'] + 1;
                $oamt = $odrOrder['oamt'] + $bprc;
                $oamt1 = $odrOrder['oamt1'];
                $oamt2 = $odrOrder['oamt2'];
                $scost11 = $odrOrder['scost11'];
                $profit11 = $odrOrder['profit11'];
                $scost21 = $odrOrder['scost21'];
                $profit21 = $odrOrder['profit21'];
                if ($issup == 0)
                {
                    $oamt1 = $odrOrder['oamt1'] + $bprc;
                    $scost11 = $odrOrder['scost11'] + $info['salecost'];
                    $profit11 = $odrOrder['profit11'] + $profit;
                }
                else
                {
                    $oamt2 = $odrOrder['oamt2'] + $bprc;
                    $scost21 = $odrOrder['scost21'] + $info['salecost'];
                    $profit21 = $odrOrder['profit21'] + $profit;
                }
                $ocost = $odrOrder['ocost1'] + $info['salecost'];
                $supprof = $odrOrder['supprof'] + $supprof;

                $orderData = [
                    'qty' => $qty,
                    'oamt' => $oamt,
                    'oamt1' => $oamt1,
                    'oamt2' => $oamt2,
                    'ocost1' => $ocost,
                    'ocost2' => $ocost,
                    'payamt' => $oamt,
                    'scost11' => $scost11,
                    'scost12' => $scost11,
                    'scost21' => $scost21,
                    'scost22' => $scost21,
                    'supprof' => $supprof,
                    'profit11' => $profit11,
                    'profit12' => $profit11,
                    'profit21' => $profit21,
                    'profit22' => $profit21,
                    'mtime' => time()
                ];
            }
        }
        else
        {
            if ($issup == 0)
            {
                $oamt1 = $bprc;
                $scost11 = $info['salecost'];
                $profit11 = $profit;
            }
            else
            {
                $oamt2 = $bprc;
                $scost21 = $info['salecost'];
                $profit21 = $profit;
            }

            //订单总表
            $oid = IdHelper::generate();
            $okey = $this->uniqueKeyLogic->getUniversal();
            $orderData = [
                'oid' => $oid,
                'plat' => 23,
                'buyer' => $acc,
                'tid' => 21,
                'src' => 23,
                'okey' => $okey,
                'qty' => 1,
                'oamt' => $bprc,
                'oamt1' => $oamt1,
                'oamt2' => $oamt2,
                'payamt' => $bprc,
                'ocost1' => $info['salecost'],
                'ocost2' => $info['salecost'],
                'scost11' => $scost11,
                'scost12' => $scost11,
                'scost21' => $scost21,
                'scost22' => $scost21,
                'supprof' => $supprof,
                'profit11' => $profit11,
                'profit12' => $profit11,
                'profit21' => $profit21,
                'profit22' => $profit21,
                'recver' => $crmAddress['lnker'],
                'rectel' => $crmAddress['lnktel'],
                'recreg' => $crmAddress['rgnid'],
                'recdtl' => $crmAddress['rgndtl'],
                'dlyway' => 2,
                'ostat' => 0,
                'paystat' => 0,
                'atime' => time(),
                '_id' => $oid
            ];

            //插入订单商品表
            $gid = IdHelper::generate();
            $data = [
                'gid' => $gid,
                'plat' => 23,
                'okey' => $okey,
                'tid' => 21,
                'src' => 23,
                'ostat' => 0,
                'yid' => $prdSupply['sid'],
                'offer' => $info['offer'],
                'pid' => $info['pid'],
                'bcode' => $info['bcode'],
                'scost1' => $info['salecost'],
                'scost2' => $info['salecost'],
                'supcost' => $info['supcost'],
                'bprc' => $bprc,
                'supprof' => $supprof,
                'profit1' => $profit,
                'profit2' => $profit,
                'issup' => $issup,
                'atime' => time(),
                '_id' => $gid
            ];
        }

        //加锁，防止重复执行
        $lockKey = "sale_h5_customer_outer_$acc";
        if ($this->redis->setnx($lockKey, $acc, 30) == false)
        {
            throw new AppException('出价过于频繁，请稍后重试', AppException::FAILED_LOCK);
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //判断是否存在
            if ($odrOrder)
            {
                if (!$odrGoods){
                    //插入新的订单商品
                    OdrGoodsModel::M()->insert($data);

                    //商品和仓库改为报价中
                    PrdProductModel::M()->update(['bcode' => $bcode], ['stcstat' => 15]);
                    StcStorageModel::M()->update(['pid' => $info['pid'], 'twhs' => 105], ['prdstat' => 15]);
                }

                //更新订单
                OdrOrderModel::M()->update($where, $orderData);
            }
            else
            {
                //插入新的订单商品
                OdrOrderModel::M()->insert($orderData);

                //插入新的订单
                OdrGoodsModel::M()->insert($data);

                //商品和仓库改为报价中
                PrdProductModel::M()->update(['bcode' => $bcode], ['stcstat' => 15]);
                StcStorageModel::M()->update(['pid' => $info['pid'], 'twhs' => 105], ['prdstat' => 15]);
            }

            //解锁
            $this->redis->del($lockKey);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //解锁
            $this->redis->del($lockKey);

            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }

        return $oid;
    }

    /**
     * 待提交明细界面
     * @param string $acc
     * @param string $oid 订单id
     * @return array
     * @throws
     */
    public function submitInfo(string $acc, string $oid)
    {
        //获取订单数据
        $orderInfo = OdrOrderModel::M()->getRow(['oid' => $oid], 'okey,oid,qty,oamt,buyer,ostat,plat,tid');
        if ($orderInfo == false)
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }
        if (!in_array($orderInfo['ostat'], [0, 10]) || $orderInfo['plat'] !== 23 || $orderInfo['tid'] !== 21)
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

        //商品订单明细
        $orderGoods = OdrGoodsModel::M()->getList(['okey' => $orderInfo['okey'], 'ostat' => ['in' => [0, 10]]], 'bcode,pid,bprc', ['gid' => -1]);

        $goods = [];
        if (count($orderGoods) > 0)
        {
            //pid字典
            $pids = ArrayHelper::map($orderGoods, 'pid');

            //获取商品数据
            $product = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pid,bcode,bid,mid,level,palias');

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
                        'pid' => $product[$pid]['pid'] ?? '-',
                        'bcode' => $value['bcode'] ?? '-',
                        'palias' => $product[$pid]['palias'] ?? '-',
                        'brand' => $qtoBrand[$product[$pid]['bid']]['bname'] ?? '-',
                        'pname' => $qtoModel[$product[$pid]['mid']]['mname'] . ' ' . $product[$pid]['palias'] ?? '-',
                        'level' => $qtoLevel[$product[$pid]['level']]['lname'] ?? '-',
                        'bprc' => $value['bprc'] ?? '-',
                    ];
                }
            }
        }
        $orderInfo['goods'] = $goods;

        //填充默认值
        ArrayHelper::fillDefaultValue($orderInfo);

        //返回
        return $orderInfo;
    }

    /**
     * 待提交-出价
     * @param string $oid 订单id
     * @param string $pid 商品id
     * @param int $bprc 出价
     * @param string $acc
     * @throws
     */
    public function offer(string $oid, string $pid, int $bprc, string $acc)
    {
        //出价不为0
        if ($bprc <= 0)
        {
            throw new AppException('出价不能小于1块钱', AppException::WRONG_ARG);
        }

        //查询条件
        $where = [
            'oid' => $oid,
            'ostat' => ['in' => [0, 10]],
            'src' => 23,
            'plat' => 23,
            'tid' => 21
        ];

        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow($where, 'okey,buyer');
        if ($odrOrder == false)
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }

        //验证用户
        if ($odrOrder['buyer'] !== $acc)
        {
            throw new AppException('你无权操作', AppException::NO_RIGHT);
        }

        //获取okey
        $okey = $odrOrder['okey'];

        //获取订单明细
        $ordGoods = OdrGoodsModel::M()->getRow(['pid' => $pid, 'okey' => $okey, 'ostat' => ['in' => [0, 10]]], 'gid,offer,scost1,bprc,issup');
        if ($ordGoods == false)
        {
            throw new AppException('订单没有此商品', AppException::NO_DATA);
        }

        //获取供应商数据
        $offerInfo = CrmOfferModel::M()->getRow(['oid' => $ordGoods['offer'], 'tid' => 2], 'oid,exts');

        //商品的成本
        $salecost = $ordGoods['scost1'];

        //初始数据
        $supprof = 0;
        $time = time();

        /*
        * 销售毛利说明
        * 供应商商品时：毛利 = 佣金 - 成本
        * 自有商品时：毛利 = 销售价 - 成本
        */
        if ($ordGoods['issup'] == 1)
        {
            $issup = 1;
            $supprof = $this->calculateOfferCommission(23, $bprc, $offerInfo);
            $profit = $supprof - $salecost;
        }
        else
        {
            $issup = 0;
            $profit = $bprc - $salecost;
        }

        //组装更新数据
        $goodsData = [
            'bprc' => $bprc,
            'supprof' => $supprof,
            'issup' => $issup,
            'profit1' => $profit,
            'profit2' => $profit,
            'mtime' => $time,
        ];

        try
        {
            //开始事务
            Db::beginTransaction();

            //更新订单商品价格
            OdrGoodsModel::M()->updateById($ordGoods['gid'], $goodsData);

            //获取更新后的商品数据
            $ordGoods2 = OdrGoodsModel::M()->getList(['okey' => $okey], 'bprc,profit1,supprof,issup');

            //计算订单相关金额
            $oamt1 = 0; //自营商品金额
            $oamt2 = 0; //供应商商品金额
            $profit11 = 0; //自有商品毛利
            $profit21 = 0; //供应商商品毛利
            $supprof = 0; //供应商商品佣金

            //处理数据
            foreach ($ordGoods2 as $value)
            {
                $bprc = $value['bprc'];
                $profit1 = $value['profit1'];
                $supprof += $value['supprof'];
                if ($value['issup'] == 0)
                {
                    $oamt1 += $bprc;
                    $profit11 += $profit1;
                }
                else
                {
                    $oamt2 += $bprc;
                    $profit21 += $profit1;
                }
            }

            //合计订单总金额
            $oamt = $oamt1 + $oamt2;

            //组装数据
            $orderData = [
                'oamt' => $oamt,
                'oamt1' => $oamt1,
                'oamt2' => $oamt2,
                'payamt' => $oamt,
                'supprof' => $supprof,
                'profit11' => $profit11,
                'profit12' => $profit11,
                'profit21' => $profit21,
                'profit22' => $profit21,
                'mtime' => $time,
            ];

            //更新订单的总金额
            OdrOrderModel::M()->update(['oid' => $oid, 'buyer' => $acc], $orderData);

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 待提交-商品删除
     * @param string $oid 订单id
     * @param string $pid 商品id
     * @param string $acc
     * @throws
     */
    public function prdDelete(string $oid, string $pid, string $acc)
    {
        //所需字段
        $cols = 'okey,buyer,oamt1,oamt2,scost11,scost21,profit11,profit21,supprof,ostat,plat,tid';

        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], $cols);
        if ($odrOrder == false)
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }
        if ($odrOrder['ostat'] !== 0 || $odrOrder['plat'] !== 23 || $odrOrder['tid'] !== 21)
        {
            throw new AppException('订单状态不允许操作', AppException::OUT_OF_OPERATE);
        }

        //验证用户
        if ($odrOrder['buyer'] !== $acc)
        {
            throw new AppException('你无权操作', AppException::NO_RIGHT);
        }

        $okey = $odrOrder['okey'];

        //获取订单明细
        $ordGoods = OdrGoodsModel::M()->getRow(['pid' => $pid, 'okey' => $okey, 'ostat' => 0], 'gid,bprc,profit1,supprof,issup,scost1');
        if ($ordGoods == false)
        {
            throw new AppException('订单没有此商品', AppException::NO_DATA);
        }

        Db::beginTransaction();
        try
        {
            //删除订单商品明细
            OdrGoodsModel::M()->deleteById($ordGoods['gid']);

            //更新商品状态
            PrdProductModel::M()->updateById($pid, ['stcstat' => 11, 'stctime' => time()]);

            //更新仓库商品状态
            StcStorageModel::M()->update(['pid' => $pid, 'twhs' => 105], ['prdstat' => 11]);

            //获取更新后的订单商品
            $ordGoodsCount = OdrGoodsModel::M()->getCount(['okey' => $okey]);

            if ($ordGoodsCount == 0)
            {
                //没有数据就删除订单
                OdrOrderModel::M()->deleteById($oid);
            }
            else
            {
                //计算订单相关金额
                $oamt1 = $odrOrder['oamt1']; //自营商品金额
                $oamt2 = $odrOrder['oamt2']; //供应商商品金额
                $scost11 = $odrOrder['scost11']; //自营商品成本
                $scost21 = $odrOrder['scost21']; //供应商商品成本
                $profit11 = $odrOrder['profit11']; //自有商品毛利
                $profit21 = $odrOrder['profit21']; //供应商商品毛利

                if ($ordGoods['issup'] == 0)
                {
                    $oamt1 = $odrOrder['oamt1'] - $ordGoods['bprc'];
                    $scost11 = $odrOrder['scost11'] - $ordGoods['scost1'];
                    $profit11 = $odrOrder['profit11'] - $ordGoods['profit1'];
                }
                else
                {
                    $oamt2 = $odrOrder['oamt2'] - $ordGoods['bprc'];
                    $scost21 = $odrOrder['scost21'] - $ordGoods['scost1'];
                    $profit21 = $odrOrder['profit21'] - $ordGoods['profit1'];
                }

                //合计订单总金额和订单总成本
                $oamt = $oamt1 + $oamt2;
                $ocost = $scost11 + $scost21;

                //供应商商品佣金
                $supprof = $odrOrder['supprof'] - $ordGoods['supprof'];

                //组装数据
                $orderData = [
                    'qty' => $ordGoodsCount,
                    'oamt' => $oamt,
                    'oamt1' => $oamt1,
                    'oamt2' => $oamt2,
                    'payamt' => $oamt,
                    'ocost1' => $ocost,
                    'ocost2' => $ocost,
                    'scost11' => $scost11,
                    'scost12' => $scost11,
                    'scost21' => $scost21,
                    'scost22' => $scost21,
                    'profit11' => $profit11,
                    'profit12' => $profit11,
                    'profit21' => $profit21,
                    'profit22' => $profit21,
                    'supprof' => $supprof,
                    'mtime' => time(),
                ];

                //更新订单
                OdrOrderModel::M()->update(['oid' => $oid, 'buyer' => $acc], $orderData);
            }

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();
            throw $throwable;
        }
    }

    /**
     * 确认提交接口
     * @param string $oid 订单id
     * @param string $acc
     * @throws
     */
    public function confirm(string $oid, string $acc)
    {
        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid,], 'okey,buyer,ostat,plat,tid');
        if ($odrOrder == false)
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }
        if ($odrOrder['ostat'] !== 0 || $odrOrder['plat'] !== 23 || $odrOrder['tid'] !== 21)
        {
            throw new AppException('订单状态不允许操作', AppException::OUT_OF_OPERATE);
        }

        //验证用户
        if ($odrOrder['buyer'] !== $acc)
        {
            throw new AppException('你无权操作', AppException::NO_RIGHT);
        }

        //获取订单商品数据
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey'], 'ostat' => 0], 'bprc');
        if (!$odrGoods)
        {
            throw new AppException('订单中没有商品', AppException::NO_DATA);
        }

        foreach ($odrGoods as $value)
        {
            //是否未出价
            if ($value['bprc'] == 0)
            {
                throw new AppException('存在未出价的商品', AppException::FAILED_OPERATE);
            }
        }

        //开始事务
        Db::beginTransaction();
        try
        {
            //更新订单状态
            OdrOrderModel::M()->update(['oid' => $oid, 'ostat' => 0], ['ostat' => 10]);

            //更新订单商品状态
            OdrGoodsModel::M()->update(['okey' => $odrOrder['okey'], 'ostat' => 0], ['ostat' => 10]);

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();
            throw $throwable;
        }
    }

    /**
     * 计算供应商佣金
     * @param int $plat
     * @param int $saleamt
     * @param array $crmOffer
     * @return float|int|mixed
     */
    private function calculateOfferCommission(int $plat, int $saleamt, array $crmOffer)
    {
        //提取佣金比例和封顶值
        $extData = ArrayHelper::toArray($crmOffer['exts']);
        if (!isset($extData[$plat]))
        {
            $plat = 21;
        }
        $cmmrate = $extData[$plat]['rate'] ?? 0;
        $cmmmaxamt = $extData[$plat]['max'] ?? 0;
        $cmmminamt = $extData[$plat]['min'] ?? 0;

        //计算佣金
        $supprof = round(($saleamt * $cmmrate / 100), 2);
        if ($cmmmaxamt > 0 && $supprof > $cmmmaxamt)
        {
            $supprof = $cmmmaxamt;
        }
        if ($cmmminamt > 0 && $cmmminamt > $supprof)
        {
            $supprof = $cmmminamt;
        }

        //返回
        return $supprof;
    }

}