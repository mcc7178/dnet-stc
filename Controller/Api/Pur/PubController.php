<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Module\Sale\Data\PurDictData;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\BeanCollector;

/**
 * 公共方法
 * Class PubController
 * @Controller("/sale/api/pur/pub")
 * @package App\Module\Sale\Controller\Api\Pur
 */
class PubController extends BeanCollector
{
    /**
     * 获取采购状态
     * @param
     * @return array
     * @throws
     */
    public function purStatus()
    {
        $datas = PurDictData::PUR_ORDER_OSTAT;

        //API返回
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