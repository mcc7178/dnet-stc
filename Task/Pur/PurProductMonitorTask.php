<?php
namespace App\Module\Sale\Task\Pur;

use App\Amqp\ActInterface;
use App\Amqp\AmqpQueue;
use App\Model\Prd\PrdProductModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Model\Pur\PurTaskModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Client\Redis;
use Swork\Service;

/**
 * 电商采购商品监控，用于处理采购点未读消息
 */
class PurProductMonitorTask extends BeanCollector implements ActInterface
{
    /**
     * 队列KEY
     * @var string
     */
    private $rkey = 'shop_pur_product_monit';

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     * @throws
     */
    function execute(array $data)
    {
        try
        {
            $i = 10;
            while ($i--)
            {
                //提取任务
                $data = $this->redis->rPop($this->rkey);
                if ($data == false)
                {
                    continue;
                }
                $data = unserialize($data);

                //提取where条件
                $where = $data[0];

                //查询处理商品数据
                $this->dealData($where);
            }
        }
        catch (\Throwable $throwable)
        {
            //输出错误日志
            Service::$logger->error($throwable->getMessage());
        }

        //返回
        return true;
    }

    /**
     * @param array $where
     * @throws
     */
    private function dealData(array $where)
    {
        $time = time();
        $where['plat'] = PurDictData::PUR_PLAT;
        $prds = PrdProductModel::M()->getList($where, 'bcode,recstat,prdstat,stcstat,chkstat');
        if (empty($prds))
        {
            return;
        }

        $bcodes = array_column($prds, 'bcode');
        $purGoodsDict = PurOdrGoodsModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]]);
        if (empty($purGoodsDict))
        {
            return;
        }
        $dids = array_column($purGoodsDict, 'did');
        $odrDemandDict = PurOdrDemandModel::M()->getDict('did', ['did' => ['in' => $dids]], 'aacc,okey,pkey,dkey,did,tid');

        foreach ($prds as $value)
        {
            $bcode = $value['bcode'];
            $prdstat = $value['prdstat'];
            $stcstat = $value['stcstat'];
            $chkstat = $value['chkstat'];

            $purGoods = $purGoodsDict[$bcode] ?? [];
            if (empty($purGoods))
            {
                continue;
            }

            $gData = [
                'prdstat' => $prdstat,
                'stcstat' => $stcstat,
            ];
            if ($prdstat == 1 && $stcstat == 11 && $purGoods['gstat'] == 1)
            {
                //在库
                $gData['gstat'] = 2;
                $gData['gtime2'] = $time;
            }
            if ($chkstat == 3 && $purGoods['gstat'] == 2)
            {
                $gData['gstat'] = 3;
                $gData['gtime3'] = $time;
            }

            //更新采购商品状态
            if ($gData)
            {
                PurOdrGoodsModel::M()->update(['bcode' => $bcode], $gData);
            }

            //更新小红点数据
            $odrDemand = $odrDemandDict[$purGoods['did']] ?? [];
            if (empty($odrDemand))
            {
                continue;
            }
            $toAcc = $odrDemand['aacc'];//通知人
            $okey = $odrDemand['okey'];//采购单号
            $did = $purGoods['did'];//采购需求单
            $tid = $purGoods['tid'];//采购分配任务

            //统计任务总数 - 在库数量 + 质检数量
            $tsnum = PurOdrGoodsModel::M()->getCount(['tid' => $tid, 'gtime2' => ['>' => 0]]);
            $tmnum = PurOdrGoodsModel::M()->getCount(['tid' => $tid, 'gtime3' => ['>' => 0]]);
            PurTaskModel::M()->updateById($tid, ['snum' => $tsnum, 'mnum' => $tmnum]);

            //统计采购需求总数 - 在库数量 + 质检数量
            $dsnum = PurOdrGoodsModel::M()->getCount(['did' => $did, 'gtime2' => ['>' => 0]]);
            $dmnum = PurOdrGoodsModel::M()->getCount(['did' => $did, 'gtime3' => ['>' => 0]]);
            PurOdrDemandModel::M()->updateById($did, ['snum' => $dsnum, 'mnum' => $dmnum, 'ltime' => $time]);
            PurOdrOrderModel::M()->updateById($okey, ['ltime' => $time]);

            //组装小红点数据（首页显示 - 采购单+需求单）
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1301, 'uid' => $toAcc]);
            AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1302, 'uid' => $toAcc]);

            //组装小红点数据（首页显示-已退货） - 条件：供应商确认退货 + 商品已退货
            if ($prdstat == 3 && $purGoods['gstat'] == 5)
            {
                AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1304, 'uid' => $toAcc]);
            }

            if (isset($gData['gstat']) && $gData['gstat'] == 3)
            {
                //组装小红点数据（采购列表显示 - 1404：采购单-质检待确认
                AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1404, 'uid' => $toAcc, 'bid' => $okey]);
            }
        }
    }
}
