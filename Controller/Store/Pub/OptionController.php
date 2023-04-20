<?php


namespace App\Module\Sale\Controller\Store\Pub;

use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 筛选条件选项接口
 * @Controller("/sale/store/pub/option")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OptionController extends BeanCollector
{
    /**
     * 获取订单状态
     * @param
     * @return array
     * @throws
     */
    public function ostats()
    {
        $datas = SaleDictData::ORDER_OSTAT;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 获取订单类型
     * @param
     * @return array
     * @throws
     */
    public function types()
    {
        return $this->getOptions(SaleDictData::ORDER_TYPE);
    }

    /**
     * 获取来源平台
     * @param
     * @return array
     * @throws
     */
    public function platforms()
    {
        $datas = SaleDictData::SOLD_PLAT;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 转换数组结构
     * @param array $datas
     * @return array
     */
    private function getOptions(array $datas)
    {
        $newDatas = [];
        foreach ($datas as $key => $value)
        {
            $newDatas[] = [
                'id' => $key,
                'name' => $value
            ];
        }

        return $newDatas;
    }
}