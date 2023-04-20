<?php
namespace App\Module\Sale\Task\Round;

use App\Amqp\ActInterface;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdProductModel;
use Swork\Bean\BeanCollector;
use Swork\Helper\HttpHelper;
use Swork\Service;

/**
 * 时间到点（每天9点30），转场（从指定场次ID转至另外ID，程序写死）
 * @package App\Module\Sale\Task\Round
 */
class RoundTransGoodsTask extends BeanCollector implements ActInterface
{
    /**
     * 指定来源的场次ID
     * @var string
     */
    private $fromRoundId = '5e5dbc505d9b1501a57f6823'; //一口价待转场ID

    /**
     * 执行队列任务
     * @param array $data 队列数据
     * @return bool
     * @throws
     */
    function execute(array $data)
    {
        //获取所有此场次之下的商品列表
        $fromSales = PrdBidSalesModel::M()
            ->join(PrdProductModel::M(), ['pid' => 'pid'])
            ->getList(['A.rid' => $this->fromRoundId], 'bcode');

        //循环处理
        Service::$logger->info('RoundTransGoodsTask [Count: ' . count($fromSales) . ']');

        //拆成每20台一份
        $chunks = array_chunk($fromSales, 20);
        foreach ($chunks as $chunk)
        {
            $codes = array_column($chunk, 'bcode');
            $codes = join("\n", $codes);
            $form = "rid=18773&newrid=3529&bcodes=$codes";
            $opts = [
                'header' => [
                    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.5 Safari/605.1.15',
                    'Host: mis.sosotec.com',
                ]
            ];
            $rel = HttpHelper::post('https://mis.sosotec.com/ups/rounds/batchtransfer3.html?do=batchtransfer', $form, $opts);
            Service::$logger->info('RoundTransGoodsTask [' . $rel . ']');
        }

        //成功返回
        return true;
    }
}