<?php
namespace App\Module\Sale\Logic\Backend\Order;

use App\Exception\AppException;
use App\Lib\Express\Sf;
use App\Model\Stc\StcLogisticsModel;
use Swork\Bean\BeanCollector;

class OrderLogisticsLogic extends BeanCollector
{
    /**
     * 获取物流单路由动态
     * @param string $expno
     * @return array
     * @throws
     */
    public function getRoute(string $expno)
    {
        if ($expno == '')
        {
            throw new AppException('快递单号不能为空', AppException::MISS_ARG);
        }

        //根据物流单号获取寄件人号码
        $lnktel = StcLogisticsModel::M()->getOne(['expno' => $expno], 'rectel');

        $lnktel = $lnktel ?: '';

        /*
         * 请求物流接口获取物流路由动态
         * 逆向物流需要手机号码才能查询路由
         */
        $route = Sf::getRoute($expno, $lnktel);

        //返回
        return $route;
    }

}