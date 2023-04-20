<?php
namespace App\Module\Sale\Logic\Backend\Goods;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Prd\PrdProductModel;
use App\Model\Xye\XyeProductDistModel;
use App\Model\Xye\XyeProductModel;
use App\Model\Xye\XyeProductOperateModel;
use App\Model\Xye\XyeTaobaoShopModel;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Helper\IdHelper;

/**
 * 闲鱼优品导入
 * Class XyeGoodsLogic
 * @package App\Module\Sale\Logic\Backend\Order
 */
class XyeGoodsLogic extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 导入新增订单逻辑
     * @param array $file Excel文件
     * @throws
     */
    public function import(array $file)
    {
        if (empty($file))
        {
            throw new AppException('未获取到导入文件', AppException::DATA_MISS);
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
        $excelMapping = [
            'bcode' => 0,
            'elevel' => 1,
            'sprc' => 2,
            'tbshop' => 3,
        ];

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

        //保存
        $this->save($list);
    }

    /**
     * 保存订单
     * @param array $list 订单商品数据
     * @throws
     */
    private function save(array $list)
    {
        $errors = [];
        $time = time();
        $levelDict = [
            '全新' => 5010,
            '准新' => 5011,
            '99新' => 5012,
            '95新' => 5013,
            '9新' => 5014,
            '9成新' => 5014,
            '8新' => 5021,
            '8成新' => 5021,
            '8新以下' => 5022,
            '8成新以下' => 5022,
            '不符合标准' => 5099,
        ];
        $bcodes = array_column($list, 'bcode');
        if (empty($bcodes))
        {
            throw new AppException('找不到导入商品数据-01');
        }
        foreach ($bcodes as $key => $value)
        {
            $bcodes[$key] = trim($value);
        }
        $prdDict = PrdProductModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]], 'pid,bcode,offer,elevel');
        if (empty($prdDict))
        {
            throw new AppException('找不到导入商品数据-02');
        }
        $pids = array_column($prdDict, 'pid');
        $xyePrdDict = XyeProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pid,pstat');
        $xyeProductDistList = XyeProductDistModel::M()->getList(['pid' => ['in' => $pids]], 'pid,dstat,tbshop');
        $xyeOpePrdDict = XyeProductOperateModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'res' => 0, 'otype' => 10], 'pid');

        $xyeProductDistDict = [];
        $xyeProductSoldDict = [];
        foreach ($xyeProductDistList as $value)
        {
            $tmpKey = $value['pid'] . '.' . $value['tbshop'];
            if (isset($xyeProductDistDict[$tmpKey]))
            {
                $xyeProductDistDict[$tmpKey] += 1;
            }
            else
            {
                $xyeProductDistDict[$tmpKey] = 1;
            }
            if ($value['dstat'] == 22)
            {
                $xyeProductSoldDict[$value['pid']] = $value['dstat'];
            }
        }

        //获取淘宝店铺字典
        $shopDict = XyeTaobaoShopModel::M()->getDict('shopname', [], 'shop,shopname');

        $hasProducts = [];
        $xyeProducts = [];
        $xyeProductDist = [];
        $xyeOperates = [];
        $updatePrdData = [];
        foreach ($list as $value)
        {
            $bcode = trim($value['bcode']);
            $elevel = trim($value['elevel']);
            $sprc = trim($value['sprc']);
            $tbshop = trim($value['tbshop']);

            //检查数据
            if (!$prdDict[$bcode])
            {
                $errors[] = $bcode . '商品不存在';
            }
            $pid = $prdDict[$bcode]['pid'];

            if (!isset($levelDict[$elevel]))
            {
                $errors[] = $bcode . '成色数据不对';
            }
            else
            {
                $elevel = $levelDict[$elevel];
            }
            if (!is_numeric($sprc) || $sprc < 1)
            {
                $errors[] = $bcode . '价格数据不对';
            }
            if (!isset($shopDict[$tbshop]))
            {
                $errors[] = $bcode . '店铺数据不对';
            }
            $shop = $shopDict[$tbshop]['shop'];

            //指定供应商才能导入
            if (!in_array($prdDict[$bcode]['offer'], [
                '5af2b1a61f991d169200005c',
                '5e688c825d9b15021e56f667',
                '5e7457dd5d9b1570383248d5',
                '5e8c4dc45d9b1545be134bb2',
                '5e8c50095d9b15679432076d',
                '5f8558575d9b1508b40fecd7',
                '5faa597e5d9b1579fa20cbb4',
                '5faa59bd5d9b15436656fecd',
                '5cb55c485d9b1557db6102af',
            ]))
            {
//                continue;
            }

            //检查是否有重复
            if (isset($hasProducts[$pid]))
            {
                continue;
            }

            //检查同一个店铺是否发过两次
            $tmpKey = $pid . '.' . $shop;
            $pubQty = $xyeProductDistDict[$tmpKey] ?? 0;
            if ($pubQty >= 2)
            {
//                continue;
            }

            //商品已售则过滤
            if (isset($xyeProductSoldDict[$pid]))
            {
                continue;
            }

            $hasProducts[$pid] = 1;

            //符合条件才写入闲鱼优品
            if ($elevel < 5099)
            {
                if (!isset($xyePrdDict[$pid]))
                {
                    $xyeProducts[] = [
                        'pid' => $pid,
                        'sprc' => $sprc,
                        'pstat' => 12,
                        'tbshop' => $shop,
                        'otime' => $time,
                        'mtime' => $time,
                        'atime' => $time,
                    ];
                }

                $did = IdHelper::generate();
                $xyeProductDist[] = [
                    'did' => $did,
                    'pid' => $pid,
                    'sprc' => $sprc,
                    'dstat' => 12,
                    'dtime12' => $time,
                    'tbshop' => $shop,
                    'otime' => $time,
                    'mtime' => $time,
                    'atime' => $time,
                ];

                if (!isset($xyeOpePrdDict[$pid]))
                {
                    $xyeOperates[] = [
                        'oid' => IdHelper::generate(),
                        'did' => $did,
                        'pid' => $pid,
                        'otype' => 10,
                        'otime' => $time,
                        'atime' => $time
                    ];
                }
            }

            if ($prdDict[$bcode]['elevel'] == 0 && $elevel > 0)
            {
                $updatePrdData[] = [
                    'pid' => $pid,
                    'elevel' => $elevel,
                ];
            }
        }
        if (count($errors) > 0)
        {
            throw new AppException(implode(',', $errors));
        }

        if (count($xyeProducts) > 0)
        {
            XyeProductModel::M()->inserts($xyeProducts, true);
        }

        if (count($xyeProductDist) > 0)
        {
            XyeProductDistModel::M()->inserts($xyeProductDist);
        }

        if (count($xyeOperates) > 0)
        {
            XyeProductOperateModel::M()->inserts($xyeOperates, true);
        }

        if (count($updatePrdData) > 0)
        {
            PrdProductModel::M()->inserts($updatePrdData, true);
        }

        //写入操作队列
        foreach ($xyeOperates as $value)
        {
            AmqpQueue::deliver($this->amqp_common, 'xye_product_operate', ['oid' => $value['oid']]);
        }
    }
}