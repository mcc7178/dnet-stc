<?php
namespace App\Module\Sale\Logic\Customer;

use App\Exception\AppException;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoOptionsMirrorModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;

class ProductLogic extends BeanCollector
{

    /**
     * 检查商品状态
     * @param string $bcode
     * @param string $acc
     * @return array|bool
     * @throws
     */
    public function check(string $bcode, string $acc)
    {
        //获取商品信息
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode], 'stcstat,stcwhs');
        if ($info == false)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }

        //检查仓库状态
        if ($info['stcwhs'] !== 105 || !in_array($info['stcstat'], [11, 15, 23]))
        {
            throw new AppException('商品库存状态不在库', AppException::OUT_OF_USING);
        }

        //组装数据
        $info = [
            'oid' => '',
            'ostat' => 0,
            'bprc' => '',
        ];

        //获取订单商品数据
        $goodsInfo = OdrGoodsModel::M()->getRow(['bcode' => $bcode], 'okey,bprc,ostat,rtntype,tid', ['atime' => -1]);
        if ($goodsInfo)
        {
            //获取订单数据
            $orderInfo = OdrOrderModel::M()->getRow(['okey' => $goodsInfo['okey']], 'oid,ostat,buyer');
            if ($orderInfo == false)
            {
                throw new AppException('商品关联订单数据异常', AppException::NO_DATA);
            }
            if ($orderInfo['buyer'] != $acc && in_array($goodsInfo['rtntype'], [0, 2]))
            {
                throw new AppException('商品已在其他订单中', AppException::NO_RIGHT);
            }

            //如果是线下订单就返回默认值
            if ($orderInfo['buyer'] == $acc && $goodsInfo['tid'] == 21)
            {
                $info = [
                    'oid' => $orderInfo['oid'],
                    'ostat' => $orderInfo['ostat'],
                    'bprc' => $goodsInfo['bprc'],
                ];
            }
        }

        //返回
        return $info;
    }

    /**
     * 获取商品质检报告
     * @param string $bcode
     * @return array|bool
     * @throws
     */
    public function getDetail(string $bcode)
    {
        //获取商品数据
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode], 'pid,bid,mid,level,pname,palias');
        if (!$info)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }

        //品牌名
        $info['brandName'] = QtoBrandModel::M()->getOne(['bid' => $info['bid']], 'bname') ?: '-';

        //获取级别
        $info['levelName'] = QtoLevelModel::M()->getOne(['lkey' => $info['level']], 'lname') ?: '-';

        $qcReportData = [];
        $qcReportDesc = '';

        //获取质检报告数据
        $qcReportInfo = MqcReportModel::M()->getRow(['pid' => $info['pid'], 'plat' => 21], 'bconc,bmkey', ['atime' => -1]);
        if ($qcReportInfo)
        {
            //获取质检镜像数据
            $content = QtoOptionsMirrorModel::M()->getRow(['mkey' => $qcReportInfo['bmkey']], 'content', ['atime' => -1]);
            if ($content)
            {
                $tmpData = ArrayHelper::toArray($content['content']);
                foreach ($tmpData as $value)
                {
                    foreach ($value['opts'] as $key2 => $item)
                    {
                        if ($item['normal'] == -1)
                        {
                            $value['opts'][$key2]['oname'] = '<span style="color: #ff0000">' . $item['oname'] . '</span>';
                        }
                    }
                    $qcReportData[] = [
                        'desc' => implode('、', array_column($value['opts'], 'oname')),
                        'cname' => $value['cname'],
                        'cid' => $value['cid'],
                    ];
                }
            }

            //替换分割符
            $qcReportDesc = str_replace(',', '、', $qcReportInfo['bconc']);
        }

        //补充质检数据
        $info['qcReport'] = $qcReportData;
        $info['qcDesc'] = $qcReportDesc;

        //返回
        return $info;
    }
}