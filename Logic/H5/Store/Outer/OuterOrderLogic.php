<?php
namespace App\Module\Sale\Logic\H5\Store\Outer;

use App\Exception\AppException;
use App\Model\Crm\CrmStaffModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoOptionsMirrorModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;

class OuterOrderLogic extends BeanCollector
{
    /**
     * 获取外发订单详情
     * @param string $oid 订单ID
     * @param string $acc 登录帐号
     * @return array
     * @throws
     */
    public function getInfo(string $oid, string $acc)
    {
        //判断是否为内部员工
        $crmStaff = CrmStaffModel::M()->getRowById($acc, 'sname,stat');
        if (!$crmStaff || $crmStaff['stat'] != 1)
        {
            throw new AppException('抱歉，您无权查看此订单', AppException::NO_RIGHT);
        }

        //查询订单
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'oid,tid,buyer,okey,qty,ostat,exts');
        if (!$odrOrder)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }

        //是否显示内部出价和允许改价
        $exts = ArrayHelper::toArray($odrOrder['exts']);

        //是否外发订单
        if ($odrOrder['tid'] != 22)
        {
            throw new AppException('订单类型错误', AppException::NO_RIGHT);
        }

        //获取订单商品
        $okey = $odrOrder['okey'];
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $okey], 'gid,bcode,pid,bprc');
        if (!$odrGoods)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }

        //获取商品字典
        $pids = ArrayHelper::map($odrGoods, 'pid');
        $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => $pids], 'pname,level');

        //获取级别字典
        $levels = ArrayHelper::map($prdDict, 'level');
        $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lname');

        //组装数据
        foreach ($prdDict as $key => $value)
        {
            $prdDict[$key]['levelName'] = $qtoLevel[$value['level']]['lname'] ?? '-';
        }

        //质检备注
        $qcReport = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => 21], 'bconc,bmkey', ['atime' => 1]);
        if ($qcReport)
        {
            //获取质检详细信息
            $mkyes = ArrayHelper::map($qcReport, 'bmkey');
            $qtoOptionsMirror = QtoOptionsMirrorModel::M()->getDict('mkey', ['mkey' => ['in' => $mkyes]], 'content');

            //组装数据
            foreach ($qcReport as $key => $value)
            {
                $tempList = [];
                $mkey = $value['bmkey'];
                $content = isset($qtoOptionsMirror[$mkey]['content']) ? ArrayHelper::toArray($qtoOptionsMirror[$mkey]['content']) : [];

                //判断质检是否正常
                foreach ($content as $key1 => $item)
                {
                    foreach ($item['opts'] as $key2 => $item2)
                    {
                        if ($item2['normal'] == -1)
                        {
                            $item['opts'][$key2]['oname'] = '<span style="color:#EE0022">' . $item2['oname'] . '</span>';
                        }
                    }

                    //组装数据
                    $tempList[] = [
                        'desc' => implode('、', array_column($item['opts'], 'oname')),
                        'cname' => $item['cname'],
                    ];
                }
                $qcReport[$key]['content'] = $tempList;
            }
        }

        //组装数据
        $oamt = 0;
        foreach ($odrGoods as $key => $value)
        {
            $pid = $value['pid'];
            $odrGoods[$key]['qcReport'] = $qcReport[$pid] ?? [];
            $odrGoods[$key]['pname'] = $prdDict[$pid]['pname'] ?? '-';
            $odrGoods[$key]['levelName'] = $prdDict[$pid]['levelName'] ?? '';
            $odrGoods[$key]['level'] = $prdDict[$pid]['level'] ?? '';
            $oamt += $value['bprc'];
        }
        $oamt = number_format($oamt, '2');

        //返回
        return [
            'order' => [
                'oid' => $odrOrder['oid'],
                'okey' => $odrOrder['okey'],
                'exts' => $exts,
                'oamt' => $oamt,
                'count' => $odrOrder['qty'],
                'ostat' => $odrOrder['ostat']
            ],
            'goods' => $odrGoods,
        ];
    }
}