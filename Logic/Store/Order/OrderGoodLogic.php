<?php


namespace App\Module\Sale\Logic\Store\Order;

use App\Exception\AppException;
use App\Model\Crm\CrmOfferModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Stc\StcStorageModel;
use Swork\Bean\BeanCollector;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Throwable;

/**
 * 订单商品处理
 * Class OrderGoodLogic
 * @package App\Module\Sale\Logic\Store\Order
 */
class OrderGoodLogic extends BeanCollector
{
    /**
     *订单详情-待成交 删除订单商品
     * @param $oid
     * @param $pid
     * @throws
     */
    public function delete(string $oid, string $pid)
    {

        //获取订单数据
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid, 'plat' => 23], 'okey');
        $odrGoodsInfo = OdrGoodsModel::M()->getRow(['pid' => $pid, 'okey' => $odrOrder['okey']], 'gid');
        if (!$odrGoodsInfo)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }
        $gid = $odrGoodsInfo['gid'];

        try
        {
            //开启事务
            Db::beginTransaction();

            //恢复商品待销售状态
            PrdProductModel::M()->update(['pid' => $pid], ['stcstat' => 11, 'stctime' => time()]);

            //恢复仓库商品状态
            StcStorageModel::M()->update(['pid' => $pid, 'twhs' => 105], ['prdstat' => 11]);

            //删除订单商品
            OdrGoodsModel::M()->delete(['gid' => $gid]);
            $odrGoodsInfo = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'gid');

            //如果订单下没有订单商品数据则删除订单
            if (!$odrGoodsInfo)
            {
                OdrOrderModel::M()->delete(['oid' => $oid]);
            }
            else
            {
                //更新订单金额的相关数据
                $this->updateOrderAmount($odrOrder['okey']);
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
     * 订单详情-待成交 保存商品议价金额
     * @param string $oid
     * @param array $data
     * @return string
     * @throws
     */
    public function save(string $oid, array $data)
    {
        if (empty($data))
        {
            throw new AppException('价格未修改，请修改后保存', AppException::WRONG_ARG);
        }

        //获取订单数据
        $orderInfo = OdrOrderModel::M()->getRowById($oid, 'okey,tid,ostat');
        if ($orderInfo == false)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }
        if ($orderInfo['tid'] != 21 || $orderInfo['ostat'] != 10)
        {
            throw new AppException('订单数据不允许操作', AppException::NO_RIGHT);
        }

        //查找该订单下的商品信息
        $goodsDict = OdrGoodsModel::M()->getDict('pid', ['okey' => $orderInfo['okey']], 'gid,scost1,issup,offer');
        if ($goodsDict == false)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }
        $updateData = [];
        $time = time();
        $plat = 23;
        $supprof = 0;

        //获取供应商数据
        $offerIds = ArrayHelper::map($goodsDict,'offer');
        $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offerIds], 'tid' => 2], 'oid,exts');

        foreach ($data as $key => $value)
        {
            if (isset($goodsDict[$value['pid']]))
            {
                //获取商品成本
                $salecost = $goodsDict[$value['pid']]['scost1'];

                //获取商品销售价
                $saleamt = $value['bprc'];

                //获取供应商信息
                $offer = $goodsDict[$value['pid']]['offer'];

                /*
                 * 销售毛利说明
                 * 供应商商品时：毛利 = 佣金 - 成本
                 * 自有商品时：毛利 = 销售价 - 成本
                 */
                if ($goodsDict[$value['pid']]['issup'] == 1)
                {
                    $supprof = $this->calculateOfferCommission($plat, $saleamt, $offerDict[$offer]);
                    $profit = $supprof - $salecost;
                }
                else
                {
                    $profit = $saleamt - $salecost;
                }

                //获取商品gid
                $gid = $goodsDict[$value['pid']]['gid'];

                //组装更新数据
                $updateData[] = [
                    'gid' => $gid,
                    'bprc' => $value['bprc'],
                    'supprof' => $supprof,
                    'profit1' => $profit,
                    'profit2' => $profit,
                    'mtime' => $time,
                ];
            }
        }
        if ($updateData == false)
        {
            return;
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //批量更新订单数据
            OdrGoodsModel::M()->inserts($updateData, true);

            //更新订单金额的相关数据
            $this->updateOrderAmount($orderInfo['okey']);

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
     * 修改价格后订单金额处理
     * @param string $okey
     * @return array
     * @throws
     */
    public function updateOrderAmount(string $okey)
    {

        //计算订单相关金额
        $oamt = 0; //合计订单总金额
        $oamt1 = 0; //自营商品金额
        $oamt2 = 0; //供应商商品金额
        $ocost = 0; //合计订单总成本
        $scost11 = 0; //自营商品成本
        $scost21 = 0; //供应商商品成本
        $profit11 = 0; //自有商品毛利
        $profit21 = 0; //供应商商品毛利
        $supprof = 0; //供应商商品佣金
        $time = time();

        //查找该订单下的商品数据
        $goodsList = OdrGoodsModel::M()->getList(['okey' => $okey], 'gid,bprc,scost1,profit1,supprof,issup');

        //循环商品数据处理数据
        foreach ($goodsList as $value)
        {
            $bprc = $value['bprc'];
            $scost1 = $value['scost1'];
            $profit1 = $value['profit1'];
            $supprof += $value['supprof'];
            if ($value['issup'] == 0)
            {
                $oamt1 += $bprc;
                $scost11 += $scost1;
                $profit11 += $profit1;
            }
            else
            {
                $oamt2 += $bprc;
                $scost21 += $scost1;
                $profit21 += $profit1;
            }

            //合计订单总金额和订单总成本
            $oamt = $oamt1 + $oamt2;
            $ocost = $scost11 + $scost21;
        }

        //订单数据
        $OrderData = [
            'oamt' => $oamt,
            'oamt1' => $oamt1,
            'oamt2' => $oamt2,
            'qty' => count($goodsList),
            'payamt' => $oamt,
            'ocost1' => $ocost,
            'ocost2' => $ocost,
            'scost11' => $scost11,
            'scost12' => $scost11,
            'scost21' => $scost21,
            'scost22' => $scost21,
            'supprof' => $supprof,
            'profit11' => $profit11,
            'profit12' => $profit11,
            'profit21' => $profit21,
            'profit22' => $profit21,
            'mtime' => $time
        ];

        //更新订单总成本，总金额，总数量
        OdrOrderModel::M()->update(['okey' => $okey], $OrderData);
        return $OrderData;
    }

    /**
     * 计算供应商佣金
     * @param int $plat 平台ID
     * @param int $saleamt 销售金额
     * @param array $offerInfo 供应商信息
     * @return mixed
     */
    private function calculateOfferCommission($plat, $saleamt, $offerInfo)
    {
        //提取佣金比例和封顶值
        $extData = ArrayHelper::toArray($offerInfo['exts']);
        if (!isset($extData[$plat]))
        {
            //没有设置指定平台的佣金则使用销售端的佣金
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