<?php
namespace App\Module\Sale\Logic\Customer;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmAddressModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Stc\StcStorageModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;
use Throwable;

class GoodsLogic extends BeanCollector
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
     * 保存出价记录
     * @param string $acc
     * @param string $bcode
     * @param int $bprc
     * @param string $oid
     * @return mixed|string
     * @throws
     */
    public function bid(string $acc, string $bcode, int $bprc, string $oid)
    {
        //所需字段
        $col = 'pid,bid,bcode,mid,plat,level,palias,prdcost,stcstat,salecost,supcost,stcwhs,prdstat,inway,offer';

        //获取商品数据
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode, 'stcstat' => ['in' => [11, 15]], 'prdstat' => 1, 'stcwhs' => 105], $col);
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

        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow($where);

        //判断是否存在
        if ($odrOrder)
        {
            $oid = $odrOrder['oid'];

            //订单状态不是待提交不允许操作
            if ($odrOrder['ostat'] !== 0)
            {
                throw new AppException('订单状态不允许操作', AppException::OUT_OF_OPERATE);
            }

            //获取订单商品数据
            $odrGoods = OdrGoodsModel::M()->getRow(['bcode' => $bcode, 'okey' => $odrOrder['okey']], 'okey,gid', ['atime' => -1]);
            if ($odrGoods)
            {
                //组装更新订单商品数据
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

                //组装更新订单数据
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
                'whs' => 105,
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
        $lockKey = "sale_customer_goods_$acc";
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
                if (!$odrGoods)
                {
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
     * 待提交-商品删除
     * @param string $oid 订单id
     * @param string $pid 商品id
     * @param string $acc
     * @throws
     */
    public function delete(string $oid, string $pid, string $acc)
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