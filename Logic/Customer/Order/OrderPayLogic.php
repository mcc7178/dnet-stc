<?php
namespace App\Module\Sale\Logic\Customer\Order;

use App\Exception\AppException;
use App\Model\Odr\OdrOrderModel;
use App\Module\Api\Data\V1\QtoData;
use App\Module\Sale\Logic\Order\OrderPayCreateLogic;
use App\Module\Sale\Data\PayData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Configer;

class OrderPayLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderPayCreateLogic
     */
    private $orderPayCreateLogic;

    /**
     * @Inject()
     * @var QtoData
     */
    private $qtoData;

    /**
     * 支付预下单
     * @param string $oid 订单id
     * @param int $payType 支付类型 11：微信支付；12：支付宝支付；13：线下支付
     * @return array
     * @throws
     */
    public function create(string $oid, int $payType)
    {
        //获取订单数据
        $cols = 'oid,plat,tid,src,buyer,okey,qty,otime,ostat,paystat,payamt,recver,rectel,recreg,recdtl,dlyway';
        $order = OdrOrderModel::M()->getRowById($oid, $cols);
        if (empty($order))
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }
        $plat = $order['plat'];//来源渠道
        $uid = $order['buyer'];//用户ID
        $tradeType = 3;//交易类型 1：小程序；2：APP；3：当面付(扫码)；5：手机网站H5

        //返回结果数据
        $payOids = [];
        $payOkeys = [];
        $payamts = 0; //支付订单支付总金额
        $payPrds = 0; //支付订单商品总数量

        //检查用户权限
        if ($order['buyer'] != $uid)
        {
            throw new AppException(AppException::NO_RIGHT);
        }

        //检查订单是否已支付
        if ($order['paystat'] == 3)
        {
            throw new AppException("订单：{$order['okey']} 已支付，请勿重复操作", AppException::DATA_DONE);
        }

        //累加数据
        $payOids[] = $order['oid'];
        $payOkeys[] = $order['okey'];
        $payamts += $order['payamt'];
        $payPrds += $order['qty'];

        //支付预下单
        $payConf = PayData::PAY_CONF[$payType] ?? [];
        $data = $this->orderPayCreateLogic->handle([
            'plat' => $plat,
            'paySrc' => $order['src'],
            'uid' => $uid,
            'payTid' => $order['tid'] == 10 ? 1 : 2,
            'payType' => $payType,
            'tradeType' => $tradeType,
            'payAmt' => $payamts,
            'payPrdQty' => $payPrds,
            'payOkeys' => $payOkeys,
        ], $payConf);
        $data['payoids'] = $payOids;
        $data['payamts'] = $this->qtoData->format($payamts);
        $data['payodrs'] = count($payOids);

        //生成支付二维码
        $data['qrcode'] = '';
        if (in_array($order['ostat'], [11, 12]) && !empty($data['payData']))
        {
            $apiHost = Configer::get('common:apihost', '');
            $codeUrl = urlencode($data['payData']);
            $data['qrcode'] = "$apiHost/pub/qrcode/create?content=$codeUrl";
        }

        //返回
        return $data;
    }
}