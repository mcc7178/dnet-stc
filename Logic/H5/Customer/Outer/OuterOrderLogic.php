<?php
namespace App\Module\Sale\Logic\H5\Customer\Outer;

use App\Exception\AppException;
use App\Model\Mqc\MqcReportModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Odr\OdrQuoteModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoOptionsMirrorModel;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\IdHelper;

class OuterOrderLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 获取外单
     * @param string $oid 查询参数
     * @param string $acc
     * @return array
     * @throws
     */
    public function getInfo(string $oid, string $acc)
    {
        //查询订单
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'oid,tid,buyer,okey,qty,ostat,exts');
        if (!$odrOrder)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }

        //是否外发订单
        if ($odrOrder['tid'] != 22)
        {
            throw new AppException('订单类型错误', AppException::NO_RIGHT);
        }

        //订单是否成交
        if ($odrOrder['ostat'] != 10 && $odrOrder['buyer'] != $acc)
        {
            if ($odrOrder['ostat'] == 0)
            {
                throw new AppException('订单数据已关闭', AppException::NO_RIGHT);
            }
            throw new AppException('订单数据已成交，您无权查看', AppException::NO_RIGHT);
        }

        //获取订单商品
        $odrGoods = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'gid,bcode,pid,bprc');
        if (!$odrGoods)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }

        //获取出价字典
        $gids = ArrayHelper::map($odrGoods, 'gid');
        $odrQuote = OdrQuoteModel::M()->getDict('gid', ['gid' => ['in' => $gids], 'buyer' => $acc], 'bprc');
        $exts = ArrayHelper::toArray($odrOrder['exts']);
        if (!$odrQuote && $exts['internal_price'] == 0)
        {
            foreach ($odrGoods as $key => $value)
            {
                $odrGoods[$key]['bprc'] = 0;
            }
        }
        if ($odrQuote)
        {
            if ($exts['internal_price'] == 0)
            {
                foreach ($odrGoods as $key => $value)
                {
                    $odrGoods[$key]['bprc'] = $odrQuote[$value['gid']]['bprc'] ?? 0;
                }
            }
            else
            {
                foreach ($odrGoods as $key => $value)
                {
                    $odrGoods[$key]['bprc'] = $odrQuote[$value['gid']]['bprc'] ?? $value['bprc'];
                }
            }
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
            if($value['bprc'] === 0)
            {
                $odrGoods[$key]['bprc'] = '';
            }
        }
        if ($oamt == 0)
        {
            $oamt = '';
        }
        else
        {
            $oamt = number_format($oamt, '2');
        }

        //返回
        return [
            'order' => [
                'oid' => $odrOrder['oid'],
                'okey' => $odrOrder['okey'],
                'exts' => $exts['change_price'],
                'oamt' => $oamt,
                'count' => $odrOrder['qty'],
                'ostat' => $odrOrder['ostat']
            ],
            'goods' => $odrGoods,
        ];
    }

    /**
     * 保存出价记录
     * @param array $data
     * @param string $buyer
     * @throws
     */
    public function bid(array $data, string $buyer)
    {
        //解析参数
        $gid = $data['gid'];
        $bprc = $data['bprc'];

        //判断出价是否大于0
        if ($bprc <= 0)
        {
            throw new AppException('出价必须大于0', AppException::MISS_ARG);
        }

        //加锁，防止重复执行
        $lockKey = "sale_customer_outer_$buyer";
        if ($this->redis->setnx($lockKey, $gid, 30) == false)
        {
            throw new AppException('出价过于频繁，请稍后重试', AppException::FAILED_LOCK);
        }

        try
        {
            //获取订单商品
            $odrGoods = OdrGoodsModel::M()->getRowById($gid, 'gid,okey,ostat,bprc');
            if ($odrGoods == false)
            {
                throw new AppException('订单商品数据不存在', AppException::NO_DATA);
            }

            //检查商品是否成交
            if ($odrGoods['ostat'] != 10)
            {
                throw new AppException('订单商品数据不允许出价', AppException::OUT_OF_USING);
            }

            //如果未出价则新增，有出价则更新
            $qid = OdrQuoteModel::M()->getOne(['gid' => $odrGoods['gid'], 'buyer' => $buyer], 'qid');
            if ($qid)
            {
                OdrQuoteModel::M()->updateById($qid, ['bprc' => $bprc]);
            }
            else
            {
                $qid = IdHelper::generate();
                $quoteData = [
                    'qid' => $qid,
                    'plat' => 23,
                    'buyer' => $buyer,
                    'okey' => $odrGoods['okey'],
                    'gid' => $odrGoods['gid'],
                    'bprc' => $bprc,
                    'stat' => 1,
                    '_id' => $qid
                ];
                OdrQuoteModel::M()->insert($quoteData);
            }

            //解锁
            $this->redis->del($lockKey);
        }
        catch (\Throwable $throwable)
        {
            //解锁
            $this->redis->del($lockKey);

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 确认出价
     * @param string $oid
     * @param string $acc
     * @throws
     */
    public function confirm(string $oid, string $acc)
    {
        //查询订单
        $odrOrder = OdrOrderModel::M()->getRow(['oid' => $oid], 'oid,tid,buyer,okey,qty,ostat');
        if (!$odrOrder)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }

        //是否外发订单
        if ($odrOrder['tid'] != 22)
        {
            throw new AppException('订单类型错误');
        }

        //订单是否成交
        if ($odrOrder['ostat'] != 10 && $odrOrder['buyer'] != $acc)
        {
            throw new AppException('订单数据已成交，您无权查看');
        }

        //获取订单商品
        $goodsList = OdrGoodsModel::M()->getList(['okey' => $odrOrder['okey']], 'gid,bprc,okey');

        //获取出价字典
        $quoteDict = OdrQuoteModel::M()->getDict('gid', ['okey' => $odrOrder['okey'], 'buyer' => $acc]);

        $quoteData = [];
        foreach ($goodsList as $key => $value)
        {
            //判断出价是否存在
            if (!isset($quoteDict[$value['gid']]))
            {
                if ($value['bprc'] == 0)
                {
                    throw new AppException('存在未出价的商品', AppException::DATA_MISS);
                }
                //组装插入数据
                $qid = IdHelper::generate();
                $quoteData[] = [
                    'qid' => $qid,
                    'plat' => 23,
                    'buyer' => $acc,
                    'okey' => $value['okey'],
                    'gid' => $value['gid'],
                    'bprc' => $value['bprc'],
                    'stat' => 2,
                    '_id' => $qid
                ];
            }
        }

        //加锁，防止重复执行
        $lockKey = "sale_customer_outer_$acc";
        if ($this->redis->setnx($lockKey, $oid, 30) == false)
        {
            throw new AppException('确认出价过于频繁，请稍后重试', AppException::FAILED_LOCK);
        }

        try
        {
            //开启事务
            Db::beginTransaction();

            //新增默认出价数据
            if (count($quoteData) > 0)
            {
                OdrQuoteModel::M()->inserts($quoteData);
            }

            //更新已出价状态
            OdrQuoteModel::M()->update(['okey' => $odrOrder['okey'], 'buyer' => $acc], ['stat' => 2]);

            //解锁
            $this->redis->del($lockKey);

            //提交事务
            Db::commit();
        }
        catch (\Throwable $throwable)
        {
            //解锁
            $this->redis->del($lockKey);

            //回滚事务
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }
}