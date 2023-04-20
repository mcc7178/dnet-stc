<?php
namespace App\Module\Sale\Task\Xianyu;

use App\Amqp\ActInterface;
use App\Model\Prd\PrdBidRoundModel;
use App\Module\Sale\Data\XchuiziDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\IdHelper;
use Swork\Service;

/**
 * 闲鱼寄卖，提前创建空场次
 * @package App\Module\Sale\Task\Xianyu
 */
class CreateRoundTask extends BeanCollector implements ActInterface
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     */
    function execute(array $data)
    {
        try
        {
            //创建优品场次（常规场次 + 内部场）
            $this->createRound1();

            if (XchuiziDictData::XYU_ROUND_RULE_TYPE == 1)
            {
                //创建不良品场次（内部场）
                $this->createRound2();
            }
            else
            {
                //创建不良品场次（特卖场）
                $this->createRound3();
            }
        }
        catch (\Throwable $throwable)
        {
            Service::$logger->error($throwable->getMessage(), [__CLASS__]);
        }

        return true;
    }

    /**
     * 创建竞拍场次逻辑
     */
    private function createRound1()
    {
        //每天21点创建
        if (time() < strtotime(date('Y-m-d 20:00:00')))
        {
            return;
        }

        //加锁，防止重复创建
        if ($this->redis->setnx('sale_xianyu_create_round1_lock', time(), 3600) == false)
        {
            return;
        }

        //获取场次规则（名称 起止时间）
        $rules = $this->roundRule();

        //场次数据
        $rdata = [];
        foreach ($rules as $rule)
        {
            $rname = $rule['rname'];
            $stime = $rule['stime'];
            $etime = $rule['etime'];

            //检查场次是否已存在
            $where = [
                'rname' => $rname,
                'stime' => ['between' => [$stime, $etime]],
            ];
            $exist = PrdBidRoundModel::M()->exist($where);
            if ($exist)
            {
                continue;
            }
            $where = [
                'rname' => $rname,
                'etime' => ['between' => [$stime, $etime]],
            ];
            $exist = PrdBidRoundModel::M()->exist($where);
            if ($exist)
            {
                continue;
            }

            //组装场次数据（常规场次）
            $rdata[] = [
                'rid' => IdHelper::generate(),
                'plat' => 21,
                'tid' => 0,
                'mode' => 1,
                'rname' => $rname,
                'stime' => $stime,
                'etime' => $etime,
                'pvs' => 0,
                'uvs' => 0,
                'bps' => 0,
                'bus' => 0,
                'stat' => 11,
                'ord' => 1,
                'limited' => 0,
                'infield' => 0,//非内部场
                'groupkey' => ''
            ];

            //组装场次数据（内部场-2020-09-18）
            $rname = str_replace('场', '内部场', $rname);
            $rdata[] = [
                'rid' => IdHelper::generate(),
                'plat' => 21,
                'tid' => 0,
                'mode' => 1,
                'rname' => $rname,
                'stime' => $stime,
                'etime' => $etime,
                'pvs' => 0,
                'uvs' => 0,
                'bps' => 0,
                'bus' => 0,
                'stat' => 11,
                'ord' => 1,
                'limited' => 0,
                'infield' => 1,//内部场
                'groupkey' => ''
            ];
        }

        //新增场次数据
        if (count($rdata) > 0)
        {
            PrdBidRoundModel::M()->inserts($rdata);
        }
    }

    /**
     * 创建不良品场次（内部场）
     */
    private function createRound2()
    {
        //每天21点创建
        if (time() < strtotime(date('Y-m-d 21:00:00')))
        {
            return;
        }

        //加锁，防止重复创建
        if ($this->redis->setnx('sale_xianyu_create_round2_lock', time(), 3600) == false)
        {
            return;
        }

        //场次时间 1小时
        $roundLen = 3600;

        //场次整点时间
        $roundHours = [10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22];

        //组装场次数据
        foreach ($roundHours as $hour)
        {
            $rname = '闲鱼寄卖-' . $hour . '点场';
            $stime = strtotime(date("Y-m-d $hour:00:00")) + 86400;
            $etime = $stime + $roundLen;

            //检查场次是否已存在
            $where = [
                'rname' => $rname,
                'stime' => ['between' => [$stime, $etime]],
                'infield' => 1
            ];
            $exist = PrdBidRoundModel::M()->exist($where);
            if ($exist)
            {
                continue;
            }
            $where = [
                'rname' => $rname,
                'etime' => ['between' => [$stime, $etime]],
                'infield' => 1
            ];
            $exist = PrdBidRoundModel::M()->exist($where);
            if ($exist)
            {
                continue;
            }

            //组装场次数据
            $rdata[] = [
                'rid' => IdHelper::generate(),
                'plat' => 22,
                'tid' => 0,
                'mode' => 2,
                'rname' => $rname,
                'stime' => $stime,
                'etime' => $etime,
                'pvs' => 0,
                'uvs' => 0,
                'bps' => 0,
                'bus' => 0,
                'stat' => 11,
                'ord' => 1,
                'limited' => 0,
                'infield' => 1,//限定内部场
                'groupkey' => ''
            ];
        }

        //新增场次数据
        if (count($rdata) > 0)
        {
            PrdBidRoundModel::M()->inserts($rdata);
        }
    }

    /**
     * 创建不良品场次（特卖场）
     */
    private function createRound3()
    {
        //每天21点创建第二天场次 17:00确认寄卖要添加到第二天场次
        if (time() < strtotime(date('Y-m-d 16:50:00')))
        {
            return;
        }

        //加锁，防止重复创建
        if ($this->redis->setnx('sale_xianyu_create_round3_lock', time(), 3600) == false)
        {
            return;
        }

        //场次时间 每天两场
        $roundHours = [
            [
                'rname' => date('m月d日12:00结束',time()+86400),
                'stime' => date("Y-m-d 02:00:00"),
                'etime' => date("Y-m-d 12:00:00"),
            ],
            [
                'rname' => date('m月d日22:00结束',time()+86400),
                'stime' => date("Y-m-d 09:00:00"),
                'etime' => date("Y-m-d 22:00:00"),
            ],
        ];

        //组装场次数据
        foreach ($roundHours as $key => $value)
        {
            $rname = $value['rname'];
            $stime = strtotime($value['stime']) + 86400;
            $etime = strtotime($value['etime']) + 86400;

            //检查场次是否已存在
            $where = [
                'rname' => $rname,
                'stime' => ['between' => [$stime, $etime]],
                'tid' => 2
            ];
            $exist = PrdBidRoundModel::M()->exist($where);
            if ($exist)
            {
                continue;
            }
            $where = [
                'rname' => $rname,
                'etime' => ['between' => [$stime, $etime]],
                'tid' => 2
            ];
            $exist = PrdBidRoundModel::M()->exist($where);
            if ($exist)
            {
                continue;
            }

            //组装场次数据
            $rdata[] = [
                'rid' => IdHelper::generate(),
                'plat' => 22,
                'tid' => 2,//限定特卖场
                'mode' => 2,//暗拍
                'rname' => $rname,
                'stime' => $stime,
                'etime' => $etime,
                'pvs' => 0,
                'uvs' => 0,
                'bps' => 0,
                'bus' => 0,
                'stat' => 11,
                'ord' => 1,
                'limited' => 0,
                'infield' => 0,
                'groupkey' => ''
            ];
        }

        //新增场次数据
        if (count($rdata) > 0)
        {
            PrdBidRoundModel::M()->inserts($rdata);
        }
    }

    /**
     * 闲鱼寄卖场次规则组装
     * @return array
     */
    private function roundRule()
    {
        $roundLen = 4 * 60;//场次时间4分钟
        $rules = [
            ['rname' => '闲鱼寄卖苹果场', 'startHour' => '14:00'],
            ['rname' => '闲鱼寄卖OPPO场', 'startHour' => '14:10'],
            ['rname' => '闲鱼寄卖VIVO场', 'startHour' => '14:20'],
            ['rname' => '闲鱼寄卖华为场', 'startHour' => '14:30'],
            ['rname' => '闲鱼寄卖小米场', 'startHour' => '14:40'],
            ['rname' => '闲鱼寄卖混合场', 'startHour' => '14:50'],
            ['rname' => '闲鱼寄卖苹果场', 'startHour' => '20:00'],
            ['rname' => '闲鱼寄卖安卓混合场', 'startHour' => '20:10'],
            ['rname' => '闲鱼寄卖混合场', 'startHour' => '22:00'],
        ];

        foreach ($rules as $key => $rule)
        {
            //创建第二天场次
            $stime = strtotime(date('Y-m-d ' . $rule['startHour'])) + 86400;
            $etime = $stime + $roundLen;

            $rules[$key]['stime'] = $stime;
            $rules[$key]['etime'] = $etime;
        }

        //返回
        return $rules;
    }
}