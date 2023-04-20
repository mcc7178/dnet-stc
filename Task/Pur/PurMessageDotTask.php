<?php
namespace App\Module\Sale\Task\Pur;

use App\Amqp\ActInterface;
use App\Model\Crm\CrmMessageDotModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\IdHelper;
use Swork\Service;

/**
 * 电商采购新增消息红点
 */
class PurMessageDotTask extends BeanCollector implements ActInterface
{
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
            //校验参数
            if (empty($data))
            {
                return true;
            }
            if (!is_numeric($data['src']) || empty($data['uid']))
            {
                return true;
            }

            //红点只有 首页红点 + （1401：采购单-审核  1404：采购单-质检待确认 1405：采购单-待退货 PC红点提示）
            if (!in_array($data['src'], [1301, 1302, 1303, 1304, 1401, 1404, 1405, 1501, 1502]))
            {
                return true;
            }

            //消息已存在 不在处理
            $where = [
                'plat' => PurDictData::PUR_PLAT,
                'bid' => $data['bid'] ?? '',
                'uid' => $data['uid'],
                'src' => $data['src'],
            ];
            if (CrmMessageDotModel::M()->exist($where))
            {
                return true;
            }

            //新增消息红点数据
            $dtype = substr($data['src'], 0, 2);
            $mData = [
                'did' => IdHelper::generate(),
                'dtype' => $dtype,
                'plat' => PurDictData::PUR_PLAT,
                'bid' => $data['bid'] ?? '',
                'uid' => $data['uid'],
                'src' => $data['src'],
                'atime' => time()
            ];
            CrmMessageDotModel::M()->insert($mData);
        }
        catch (\Throwable $throwable)
        {
            //输出错误日志
            Service::$logger->error($throwable->getMessage());
        }

        //返回
        return true;
    }
}
