<?php
namespace App\Module\Sale\Logic\Backend\Order;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Stc\StcLogisticsModel;
use App\Model\Stc\StcStorageModel;
use App\Model\Sys\SysExpressCompanyModel;
use App\Model\Xye\XyeTaobaoShopModel;
use App\Module\Pub\Logic\UniqueKeyLogic;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;
use Swork\Helper\StringHelper;

/**
 * 创建销售订单逻辑
 * @package App\Module\Sale\Logic\Backend\Order
 */
class OrderCreateLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * 扫码新增订单逻辑
     * @param array $data 录单数据
     * @throws
     */
    public function scan(array $data)
    {
        //提取参数
        $tid = $data['tid'];
        $src = $data['src'];
        $recver = $data['recver'];
        $rectel = $data['rectel'];
        $recdtl = $data['recdtl'];
        $expid = $data['expid'];
        $expno = $data['expno'];
        $exptime = $data['exptime'];
        $payno = $data['payno'];
        $goods = $data['goods'];
        $acc = $data['acc'];
        if ($goods == false)
        {
            throw new AppException('缺少商品数据，请核实后重试', AppException::DATA_MISS);
        }

        //重新组装数据（与导入订单格式一样）
        $list = [];
        foreach ($goods as $value)
        {
            $list[] = [
                'bcode' => $value['bcode'],
                'bprc' => $value['bprc'],
                'recver' => $recver,
                'rectel' => $rectel,
                'recdtl' => $recdtl,
                'expid' => $expid,
                'expno' => $expno,
                'exptime' => empty($exptime) ? 0 : strtotime($exptime),
                'payno' => $payno,
            ];
        }

        //保存订单
        $this->save($tid, $src, $list, 1, $acc);
    }

    /**
     * 导入新增订单逻辑
     * @param string $acc 导入人ID
     * @param int $tid 导入类型  31、内购订单 32、第三方订单 33、自建订单  34、B2C淘宝（导入后可直接发货） 35、B2C微信（审核后才能发货）36、闲鱼优品导入
     * @param int $src 导入来源
     * @param array $file Excel文件
     * @throws
     */
    public function import(string $acc, int $tid, int $src, array $file)
    {
        if (empty($file))
        {
            throw new AppException('未获取到导入文件', AppException::DATA_MISS);
        }
        if (!in_array($tid, [31, 32, 33, 34, 35, 36]))
        {
            throw new AppException('导入类型有误', AppException::DATA_MISS);
        }

        try
        {
            //临时文件路径
            $tmpName = $file['tmp_name'];

            //获取表格内容
            $list = Utility::getExcelValues($tmpName);
            if ($list == false)
            {
                throw new AppException('缺少导入数据，请核实后重试', AppException::DATA_MISS);
            }

            //删除文件
            @unlink($tmpName);
        }
        catch (\Throwable $throwable)
        {
            //删除文件
            @unlink($tmpName);

            //抛出异常
            throw $throwable;
        }

        //获取表格映射关系
        $excelMapping = $this->getExcelFieldMapping($tid);

        //组装表格列表数据
        foreach ($list as $key => $item)
        {
            $tempData = [];
            foreach ($excelMapping as $field => $column)
            {
                $value = trim(($item[$column] ?? ''));
                if ($value)
                {
                    $value = str_replace('=', '', $value);
                    $value = str_replace('"', '', $value);
                    $value = str_replace("'", '', $value);
                    $value = str_replace("`", '', $value);
                    $value = str_replace(",", '', $value);
                }
                if ($field == 'exptime')
                {
                    $value = str_replace('/', '-', $value);
                    $value = Utility::excelDateToTimestamp($value);
                }
                $tempData[$field] = $value;
            }
            $list[$key] = $tempData;
        }

        //保存订单
        $this->save($tid, $src, $list, 0, $acc);
    }

    /**
     * 保存订单
     * @param int $tid 订单类型
     * @param int $src 订单来源
     * @param array $list 订单商品数据
     * @param int $type 1:新增  0:导入
     * @param string $acc 录单人ID
     * @throws
     */
    private function save(int $tid, int $src, array $list, int $type, string $acc = '')
    {
        //提取库存编号
        $bcodes = array_column($list, 'bcode');

        //获取主商品数据
        $cols = 'pid,inway,offer,bcode,salecost,prdstat,stcwhs,stcstat';
        $productDict = PrdProductModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]], $cols, ['rectime4' => -1]);
        if ($productDict == false)
        {
            throw new AppException('缺少商品主数据', AppException::NO_DATA);
        }

        //提取商品ID
        $pids = [];
        $offers = [];
        foreach ($productDict as $value)
        {
            $pids[] = $value['pid'];
            $offers[$value['offer']] = true;
        }
        $pids = array_column($productDict, 'pid');

        //提取供应商品数据
        $supplyDict = PrdSupplyModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'salestat' => 1], 'sid,pid');
        if ($supplyDict == false)
        {
            throw new AppException('缺少供应商品数据', AppException::NO_DATA);
        }

        //获取供应商数据
        $offerDict = [];
        if (count($offers) > 0)
        {
            $offerIds = array_keys($offers);
            $offerDict = CrmOfferModel::M()->getDict('oid', ['oid' => ['in' => $offerIds], 'tid' => 2], 'oid,exts');
        }

        //获取快递公司数据
        $expressCompany = SysExpressCompanyModel::M()->getList();
        $expressCompanyDict = array_column($expressCompany, 'eid', 'ename');

        //获取录单人数据
        $accUser = AccUserModel::M()->getRowById($acc, 'rname');
        $recorder = $accUser['rname'] ?? '-';

        $plat = $tid == 31 ? 0 : 21; //除了内购订单外，其他订单归到新新平台

        //B2C导入订单 20200928
        if (in_array($tid, [34, 35]))
        {
            $plat = 24;
        }

        //闲鱼优品导入订单 20201118
        $shopDict = [];
        if (in_array($tid, [36]))
        {
            $plat = 162;
            $shopDict = XyeTaobaoShopModel::M()->getDict('shopname', [], 'shop,shopname');
        }

        $tempGoodsData = [];
        $stcLogisticsDict = []; //买家收货地址字典
        $batchSupplyData = []; //供应商品数据（用于批量更新）
        $checkRepeatData = []; //检查是否重复有编码
        $time = time();

        //组装订单商品数据
        foreach ($list as $key => $value)
        {
            $idx = $key + 1;
            $bcode = $value['bcode'];
            $rectel = $value['rectel'];
            $saleamt = $value['bprc'];
            $expid = $value['expid'] ?? 0;
            $expno = $value['expno'] ?? '';
            $expname = $value['expname'] ?? '';
            $exptime = $value['exptime'] ?? 0;
            if ($expid == 0)
            {
                $expid = $expressCompanyDict[$expname] ?? 0;
            }
            $payno = $value['payno'] ?? '';
            $shopName = $value['shopName'] ?? '';

            //发货方式
            $dlyway = 1;
            if (isset($value['dlyway']) && trim($value['dlyway']) == '自提')
            {
                $dlyway = 2;
            }

            //付费方式(只有B2C淘宝+微信才有)
            $expway = 1;
            if (isset($value['expway']) && $value['expway'] == '到付')
            {
                $expway = 2;
            }

            //B2C微信 填写快递单号无效处理（财务审核通过后才能发货，才有快递单号）
            if ($tid == 35)
            {
                $expno = '';
            }

            $pid = $productDict[$bcode]['pid'] ?? '';
            $yid = $supplyDict[$pid]['sid'] ?? '';
            $inway = $productDict[$bcode]['inway'] ?? '';
            $offer = $productDict[$bcode]['offer'] ?? '';
            $salecost = $productDict[$bcode]['salecost'] ?? 0;
            $prdstat = $productDict[$bcode]['prdstat'] ?? 0;
            $stcwhs = $productDict[$bcode]['stcwhs'] ?? 0;
            $stcstat = $productDict[$bcode]['stcstat'] ?? 0;
            $supprof = 0;
            $issup = 0;

            //检查商品是否存在
            if ($pid == '')
            {
                throw new AppException("第{$idx}行 {$bcode}，缺少商品主数据", AppException::NO_DATA);
            }
            if ($yid == '')
            {
                throw new AppException("第{$idx}行 {$bcode}，缺少待销售商品数据", AppException::NO_DATA);
            }

            //检查商品是否重复了
            if (isset($checkRepeatData[$bcode]))
            {
                throw new AppException("第{$idx}行 {$bcode}，存在重复商品", AppException::DATA_EXIST);
            }

            //检查商品库存是否在公司仓库并且是在库状态
            if ($prdstat != 1 || $stcwhs != 101 || !in_array($stcstat, [11, 33, 34, 35]))
            {
                throw new AppException("第{$idx}行 {$bcode}，库存状态不在库-[$stcstat]", AppException::NO_DATA);
            }

            //检查商品来源是否允许录单销售
            if (in_array($inway, [71, 91]))
            {
                throw new AppException("第{$idx}行 {$bcode}，帮卖来源的商品不允许录单-[$inway]", AppException::NO_RIGHT);
            }

            //检查价格是否正确
            if ($saleamt <= 0)
            {
                throw new AppException("第{$idx}行 {$bcode}，销售价格不正确-[$saleamt]", AppException::WRONG_ARG);
            }

            //检查手机号码是否正确
            if (Utility::isMobile($rectel) == false)
            {
                throw new AppException("第{$idx}行 {$bcode}，手机号码不正确-[$rectel]", AppException::WRONG_ARG);
            }

            //如果有快递单号，则检查格式
            if ($expno && !StringHelper::isLetterNumber($expno))
            {
                throw new AppException("第{$idx}行 {$bcode}，物流单号不正确-[$expno]", AppException::WRONG_ARG);
            }

            //如果是第三方订单并且不是指定的来源渠道，必须有第三方单号
            if (in_array($tid, [32, 36]) && !in_array($src, [41]) && !StringHelper::isTextKey($payno))
            {
                throw new AppException("第{$idx}行 {$bcode}，第三方订单号不正确-[$payno]", AppException::WRONG_ARG);
            }

            //如果是闲鱼优品订单则检查店铺是否正确
            if ($tid == 36)
            {
                if (!isset($shopDict[$shopName]))
                {
                    throw new AppException("第{$idx}行 {$bcode}，订单来源不正确-[$shopName]", AppException::WRONG_ARG);
                }
                $src = SaleDictData::XYE_SHOP_MAPPING_ODR_SRC[$shopDict[$shopName]['shop']] ?? 0;
                if ($src == 0)
                {
                    throw new AppException("第{$idx}行 {$bcode}，订单来源未映射-[$shopName]", AppException::WRONG_ARG);
                }
            }

            //记录库存编码
            $checkRepeatData[$bcode] = true;

            /*
             * 销售毛利说明
             * 供应商商品时：毛利 = 佣金 - 成本
             * 自有商品时：毛利 = 销售价 - 成本
             */
            if (isset($offerDict[$offer]))
            {
                $issup = 1;
                $supprof = $this->calculateOfferCommission($plat, $saleamt, $offerDict[$offer]);
                $profit = $supprof - $salecost;
            }
            else
            {
                $profit = $saleamt - $salecost;
            }

            //优先使用第三方单号分组，如果没有则用手机号码分组
            $groupKey = $payno ?: $rectel;

            /*
             * 订单状态
             * 1：第三方+B2C淘宝订单并且有快递单号时为：交易完成状态
             * 2：第三方+B2C淘宝订单并且没有有快递单号时为：待发货状态
             * 3：自建+B2C微信订单为：待支付状态
             */
            if (in_array($tid, [32, 34, 36]) && $expno == '')
            {
                $ostat = 21;
            }
            else
            {
                $ostat = 11;
                if (in_array($tid, [32, 34, 36]))
                {
                    //第三方订单 + B2C淘宝订单处理
                    $ostat = 23;
                }
            }

            $paytime = 0;
            if (in_array($tid, [32, 34, 36]))
            {
                $paytime = $time;
            }

            //订单商品数据
            $gid = IdHelper::generate();
            $tempGoodsData[$groupKey][] = [
                'gid' => $gid,
                'plat' => $plat,
                'tid' => $tid,
                'src' => $src,
                'ostat' => $ostat,
                'otime' => $time,
                'paytime' => $paytime,
                'offer' => $offer,
                'pid' => $pid,
                'bcode' => $bcode,
                'yid' => $yid,
                'bprc' => $saleamt,
                'scost1' => $salecost,
                'scost2' => $salecost,
                'supprof' => $supprof,
                'profit1' => $profit,
                'profit2' => $profit,
                'issup' => $issup,
                'whs' => 101,
                'mtime' => $time,
                'atime' => $time,
                '_id' => $gid,
            ];

            //供应商品数据
            $batchSupplyData[] = [
                'sid' => $yid,
                'salestat' => 2,
                'salechn' => $plat,
                'saleway' => $tid,
                'saleamt' => $saleamt,
                'salecmm' => $supprof,
                'saletime' => $time,
                'profit' => $profit,
            ];

            //记录发货地址信息
            if (!isset($stcLogisticsDict[$groupKey]))
            {
                $stcLogisticsDict[$groupKey] = [
                    'rectel' => $rectel,
                    'recver' => $value['recver'],
                    'recdtl' => $value['recdtl'],
                    'expid' => $expid,
                    'exptime' => $exptime,
                    'expno' => $expno,
                    'expway' => $expway,
                    'lway' => $dlyway,
                ];
            }
        }

        //提取发货地址信息
        $getLogisticsInfo = function ($groupKey, $field) use ($stcLogisticsDict) {
            return $stcLogisticsDict[$groupKey][$field] ?? '';
        };

        //组装订单数据
        $assemblyBatchData = function ($plat, $groupKey, $goods, $recorder, $type, &$batchOrderData, &$batchGoodsData, &$batchLogisticsData) use ($getLogisticsInfo, $time) {

            //提取参数
            $lgKey = $this->uniqueKeyLogic->getStcLG();
            $recver = $getLogisticsInfo($groupKey, 'recver');
            $rectel = $getLogisticsInfo($groupKey, 'rectel');
            $recdtl = $getLogisticsInfo($groupKey, 'recdtl');
            $expid = $getLogisticsInfo($groupKey, 'expid');
            $expno = $getLogisticsInfo($groupKey, 'expno');
            $exptime = $getLogisticsInfo($groupKey, 'exptime');
            $lway = $getLogisticsInfo($groupKey, 'lway');
            $expway = $getLogisticsInfo($groupKey, 'expway');
            $exptime = $exptime ?? 0;

            //录单人
            $exts = ['recorder' => $recorder];

            //订单号
            $oid = IdHelper::generate();
            $okey = $this->uniqueKeyLogic->getUniversal();

            //计算订单相关金额
            $oamt1 = 0; //自营商品金额
            $oamt2 = 0; //供应商商品金额
            $scost11 = 0; //自营商品成本
            $scost21 = 0; //供应商商品成本
            $profit11 = 0; //自有商品毛利
            $profit21 = 0; //供应商商品毛利
            $supprof = 0; //供应商商品佣金

            //循环商品数据处理数据
            foreach ($goods as $value)
            {
                $tid = $value['tid'];
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

                //补充订单商品数据
                $value['okey'] = $okey;
                $dlykey = '';
                if (in_array($tid, [32, 34, 35, 36]))
                {
                    $dlykey = $lgKey;
                }
                $value['dlykey'] = $dlykey;

                //组装订单商品数据
                $batchGoodsData[] = $value;
            }

            //合计订单总金额和订单总成本
            $oamt = $oamt1 + $oamt2;
            $ocost = $scost11 + $scost21;

            //订单类型
            $tid = $goods[0]['tid'];

            $dlykey = '';
            $paystat = 1;
            $paytime = 0;
            $dlytime3 = 0;
            if (in_array($tid, [32, 34, 36]))
            {
                //第三方订单+B2C淘宝设置为付款成功
                $dlykey = $lgKey;
                $paystat = 3;
                $paytime = $time;
                $dlytime3 = $exptime;
            }

            //组装订单数据
            $batchOrderData[] = [
                'oid' => $oid,
                'plat' => $plat,
                'tid' => $tid,
                'src' => $goods[0]['src'],
                'okey' => $okey,
                'qty' => count($goods),
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
                'supprof' => $supprof,
                'profit11' => $profit11,
                'profit12' => $profit11,
                'profit21' => $profit21,
                'profit22' => $profit21,
                'otime' => $time,
                'ostat' => $goods[0]['ostat'],
                'paystat' => $paystat,
                'paytime' => $paytime,
                'payno' => Utility::isMobile($groupKey) ? '' : $groupKey,
                'third' => $groupKey,
                'dlyway' => $lway,
                'dlykey' => $dlykey,
                'dlytime3' => $dlytime3,
                'recver' => $recver,
                'rectel' => $rectel,
                'recdtl' => $recdtl,
                'exts' => json_encode($exts, JSON_UNESCAPED_UNICODE),
                'rmk2' => $type ? '手动新增' : '批量导入',
                'whs' => 101,
                'mtime' => $time,
                'atime' => $time,
                '_id' => $oid,
            ];

            //第三方/B2C订单  组装发货单数据
            if (in_array($tid, [32, 34, 35, 36]))
            {
                $batchLogisticsData[] = [
                    'lid' => IdHelper::generate(),
                    'lkey' => $lgKey,
                    'plat' => $plat,
                    'whs' => 101,
                    'tid' => 3,
                    'expid' => $expid,
                    'expno' => $expno,
                    'expway' => $expway,
                    'recver' => $recver,
                    'rectel' => $rectel,
                    'recdtl' => $recdtl,
                    'lway' => $lway,
                    'lstat' => $expno ? 3 : 1,
                    'ltime1' => $time,
                    'ltime3' => $exptime,
                ];
            }
        };

        //组装批量订单数据
        $batchOrderData = [];
        $batchGoodsData = [];
        $batchLogisticsData = [];
        foreach ($tempGoodsData as $key => $goods)
        {
            $assemblyBatchData($plat, $key, $goods, $recorder, $type, $batchOrderData, $batchGoodsData, $batchLogisticsData);
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //先生成物流单数据，防止同步到旧系统的时候没有旧的物流单数据导致物流单id缺失
            if (count($batchLogisticsData) > 0)
            {
                $res = StcLogisticsModel::M()->inserts($batchLogisticsData);
                if ($res == false)
                {
                    throw new AppException('新增订单发货单失败', AppException::FAILED_INSERT);
                }
            }

            //批量新增订单
            $res = OdrOrderModel::M()->inserts($batchOrderData);
            if ($res == false)
            {
                throw new AppException('新增订单失败', AppException::FAILED_INSERT);
            }

            //批量新增商品数据
            $res = OdrGoodsModel::M()->inserts($batchGoodsData);
            if ($res == false)
            {
                throw new AppException('新增订单商品失败', AppException::FAILED_INSERT);
            }

            /*
             * 如果是第三方订单，则更新商品状态为已售
             * 如果是自建订单，则更新商品状态为挂单中
             */
            if (in_array($tid, [32, 34, 36]))
            {
                PrdProductModel::M()->update(['pid' => ['in' => $pids]], [
                    'prdstat' => 2,
                    'stcstat' => 23,
                    'stctime' => $time,
                    'saletime' => $time,
                ]);
                PrdSupplyModel::M()->inserts($batchSupplyData, true);
                StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['stat' => 2, 'prdstat' => 23]);
            }
            else
            {
                PrdProductModel::M()->update(['pid' => ['in' => $pids]], [
                    'stcstat' => 15,
                    'stctime' => $time,
                ]);
                StcStorageModel::M()->update(['pid' => ['in' => $pids], 'stat' => 1], ['prdstat' => 15]);
            }

            //提交事务
            Db::commit();
        }
        catch (\Throwable $throwable)
        {
            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
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
            //没有设置指定平台的佣金则默认使用新新的
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

    /**
     * 获取excel模板字段映射关系
     * @param int $tid 订单类型
     * @return array
     */
    private function getExcelFieldMapping(int $tid)
    {
        //自建订单模板字段映射
        if ($tid == 33)
        {
            return [
                'bcode' => 0,
                'bprc' => 1,
                'recver' => 2,
                'rectel' => 3,
                'recdtl' => 4,
                'expname' => 5,
                'expno' => 6,
                'exptime' => 7,
            ];
        }

        //B2C订单
        if (in_array($tid, [34, 35]))
        {
            return [
                'bcode' => 0,
                'bprc' => 1,
                'recver' => 2,
                'rectel' => 3,
                'recdtl' => 4,
                'expname' => 5,
                'expno' => 6,
                'exptime' => 7,
                'dlyway' => 8,//发货方式
                'expway' => 9,//付费方式
                'payno' => 10,//第三方订单（淘宝用到）
            ];
        }

        //闲鱼优品订单
        if (in_array($tid, [36]))
        {
            return [
                'bcode' => 0,
                'bprc' => 1,
                'recver' => 2,
                'rectel' => 3,
                'recdtl' => 4,
                'expname' => 5,
                'expno' => 6,
                'exptime' => 7,
                'payno' => 8,
                'shopName' => 9,
            ];
        }

        //第三方订单模板字段映射
        return [
            'bcode' => 0,
            'bprc' => 1,
            'recver' => 2,
            'rectel' => 3,
            'recdtl' => 4,
            'expname' => 5,
            'expno' => 6,
            'exptime' => 7,
            'payno' => 8,
        ];
    }
}