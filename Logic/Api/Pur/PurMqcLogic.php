<?php
namespace App\Module\Sale\Logic\Api\Pur;

use App\Exception\AppException;
use App\Model\Crm\CrmMessageDotModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Model\Qto\QtoModelModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class PurMqcLogic extends BeanCollector
{

    /**
     * 获取采购单基本信息
     * @param string $okey
     * @param string $acc
     * @return array
     * @throws
     */
    public function getPurInfo(string $okey, string $acc)
    {
        //获取采购单数据
        $purOdrOrderInfo = PurOdrOrderModel::M()->getRowById($okey, 'aacc,merchant');
        if ($purOdrOrderInfo == false)
        {
            throw new AppException('采购单号不存在', AppException::NO_DATA);
        }
        if ($purOdrOrderInfo['aacc'] !== $acc)
        {
            throw new AppException('对不起，你无权限操作', AppException::NO_RIGHT);
        }

        //获取供货商数据
        $purMerchantInfo = PurMerchantModel::M()->getRowById($purOdrOrderInfo['merchant'], 'mname,mobile');

        //默认值
        $inum = $cnum = $dnum = $rnum = 0;

        //获取需求商品数据
        $purOdrGoodsList = PurOdrGoodsModel::M()->getList(['okey' => $okey, 'aacc' => $acc], 'gstat,prdstat,gtime3');
        if ($purOdrGoodsList)
        {
            foreach ($purOdrGoodsList as $key => $value)
            {
                //已质检
                if ($value['gtime3'] > 0)
                {
                    $inum += 1;
                }

                //待确认
                if ($value['gstat'] == 3)
                {
                    $cnum += 1;
                }

                //退货
                if ($value['gstat'] == 5)
                {
                    //待退货
                    if ($value['prdstat'] == 1)
                    {
                        $dnum += 1;
                    }

                    //已退货
                    if ($value['prdstat'] == 3)
                    {
                        $rnum += 1;
                    }
                }
            }
        }

        //组装返回数据
        return [
            'mname' => $purMerchantInfo['mname'] ?? '',
            'mobile' => $purMerchantInfo['mobile'] ?? '',
            'okey' => $okey,
            'inum' => $inum,  //已质检
            'cnum' => $cnum,  //待确认
            'dnum' => $dnum,  //待退货
            'rnum' => $rnum,  //已退货
        ];
    }

    /**
     * 已质检商品列表(分页获取数据)
     * @param string $okey
     * @param int $stat
     * @param string $acc
     * @param int $idx
     * @param int $size
     * @return array
     * @throws
     */
    public function getPager(string $okey, int $stat, string $acc, int $idx, int $size)
    {
        //获取采购单数据
        $purOdrOrderInfo = PurOdrOrderModel::M()->getRowById($okey, 'aacc,merchant');
        if ($purOdrOrderInfo == false)
        {
            throw new AppException('采购单号不存在', AppException::NO_DATA);
        }
        if ($purOdrOrderInfo['aacc'] !== $acc)
        {
            throw new AppException('对不起，你无权限操作', AppException::NO_RIGHT);
        }

        //固定数据
        $where = [
            'okey' => $okey,
            'aacc' => $acc,
        ];

        //固定小红点条件
        $hotsWhere = [
            'uid' => $acc,
            'plat' => 24,
            'dtype' => 14,
            'bid' => $okey,
        ];

        //获取已质检商品数据
        if ($stat == 1)
        {
            $where['gtime3'] = ['>' => 0];
            $hotsWhere['src'] = 1402;
        }

        //获取质检待确定商品数据
        if ($stat == 2)
        {
            $where['gstat'] = 3;
            $hotsWhere['src'] = 1404;
        }

        //获取已质检、质检待确定商品数据
        $purOdrGoodsList = PurOdrGoodsModel::M()->getList($where, 'bcode,dkey,gtime3', [], $size, $idx);

        if ($purOdrGoodsList)
        {
            //dkey字典
            $dkeys = ArrayHelper::map($purOdrGoodsList, 'dkey');

            //获取计划-需求
            $purDemandDict = PurDemandModel::M()->getDict('dkey', ['dkey' => ['in' => $dkeys]], 'mid');

            //mid字典
            $mids = ArrayHelper::map($purDemandDict, 'mid');

            //获取型号
            $qtoModelDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

            //补充数据
            foreach ($purOdrGoodsList as $key => $value)
            {
                $mid = $purDemandDict[$value['dkey']]['mid'] ?? '';
                $purOdrGoodsList[$key]['midName'] = $qtoModelDict[$mid]['mname'] ?? '-';
                $purOdrGoodsList[$key]['gtime3'] = DateHelper::toString($value['gtime3']);
            }
        }

        //填充默认值
        ArrayHelper::fillDefaultValue($purOdrGoodsList);

        //删除小红点数据
        CrmMessageDotModel::M()->delete($hotsWhere);

        //返回
        return $purOdrGoodsList;
    }
}