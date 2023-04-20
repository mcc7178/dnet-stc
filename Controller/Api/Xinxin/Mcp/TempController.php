<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use App\Model\Dnet\OdrOrderModel;
use App\Module\Crm\Logic\CrmAddressLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 用于过渡新老系统的临时接口
 * @Controller("/sale/api/xinxin/mcp/temp")
 * @package App\Module\Sale\Controller\Api\Xinxin\Mcp
 */
class TempController extends BeanCollector
{
    /**
     * @Inject()
     * @var CrmAddressLogic
     */
    private $crmAddressLogic;

    /**
     * 同步新新用户地址库
     * @param Argument $argument
     * @return string
     */
    public function syncAddress(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //获取订单
        $order = OdrOrderModel::M()->getRow(['_id' => $oid]);
        if ($order == false)
        {
            return 'syncAddressFail';
        }
        $order2 = \App\Model\Odr\OdrOrderModel::M()->getRowById($oid, 'buyer');
        if ($order2 == false)
        {
            return 'syncAddressFail';
        }

        //同步地址
        $this->crmAddressLogic->save(21, $order2['buyer'], [
            'way' => $order['lway'],
            'lnker' => $order['recver'],
            'lnktel' => $order['rectel'],
            'rgnid' => $order['recreg'],
            'rgndtl' => $order['recdtl'],
        ]);

        //返回
        return 'syncAddressSuccess';
    }
}