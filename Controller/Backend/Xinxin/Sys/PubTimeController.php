<?php

namespace App\Module\Sale\Controller\Backend\XinXin\Sys;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Pub\Data\SysConfData;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Helper\DateHelper;
use Swork\Server\Http\Argument;

/**
 * @Controller("/sale/backend/xinxin/sys/pubtime")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PubTimeController extends BeanCollector
{
    /**
     * 公开时间配置key
     * @var string
     */
    private $ckey = 'xinxin_public_time';

    /**
     * 读取竞拍公开时间
     * @param Argument $argument
     * @return array|bool
     */
    public function config(Argument $argument)
    {
        //外部参数
        $today = date('Y-m-d');
        $type = 1; //默认时间

        //获取数据
        $config = SysConfData::D()->get($this->ckey);

        //统一格式化时间
        foreach (['stime', 'etime'] as $item)
        {
            $config[$item] = empty($config[$item]) ? $today : DateHelper::toString($config[$item], 'Y-m-d');
        }

        //公开时间拆分数组返回
        if (empty($config['ptime']))
        {
            $ptime = [
                $today,
                date('H:i')
            ];
        }
        else
        {
            $ptime = [
                date('Y-m-d', $config['ptime']),
                date('H:i', $config['ptime'])
            ];
        }

        //判断今天是否在指定时间内
        if($today >= $config['stime'] && $today <= $config['etime'])
        {
            $type = 2;
        }

        //组装参数
        $config = [
            'day' => $config['day'] ?? '',
            'effectTime' => [
                $config['stime'],
                $config['etime'],
            ],
            'ptime' => $ptime,
            'type' => $type
        ];

        //返回
        return $config;
    }

    /**
     * 保存竞拍公开时间
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $day = $argument->post('day', '');
        $effectTime = $argument->post('effectTime', []);
        $ptime = $argument->post('ptime', '');

        //组装参数
        $content = [];
        if (!empty($day))
        {
            $content['day'] = $day;
        }
        if (!empty($ptime))
        {
            $content['ptime'] = strtotime($ptime);
        }
        if (count($effectTime) == 2)
        {
            $content['stime'] = strtotime($effectTime[0]);
            $content['etime'] = strtotime($effectTime[1]) + 86399;
        }

        //保存数据
        SysConfData::D()->set($this->ckey, $content, $acc);

        //返回
        return true;
    }
}
