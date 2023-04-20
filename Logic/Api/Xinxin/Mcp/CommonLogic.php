<?php
namespace App\Module\Sale\Logic\Api\Xinxin\Mcp;

use App\Module\Pub\Data\SysConfData;
use App\Service\Acc\AccUserInterface;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;

class CommonLogic extends BeanCollector
{
    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * 获取用户id
     * @param int $uid
     * @return mixed
     * @throws
     */
    public function getAcc(int $uid)
    {
        //参数判断
        if (!$uid)
        {
            return false;
        }

        //获取用户
        $acc = $this->accUserInterface->getRow(['_id' => $uid], 'aid');
        if (!$acc)
        {
            return false;
        }

        //返回
        return $acc['aid'];
    }

    /**
     * 获取公开时间
     * @return mixed
     */
    public function getPtime()
    {
        //获取公开选项信息
        $item = SysConfData::D()->get('xinxin_public_time');

        $ptime = strtotime(date('Y-m-d 12:00:00'));
        if ($item)
        {
            if (time() >= $item['stime'] && time() <= $item['etime'])
            {
                $ptime = $item['ptime'];
            }
            else
            {
                $ptime = strtotime(date('Y-m-d ' . $item['day'] . ':00'));
            }
        }

        //返回数据
        return $ptime;
    }
}