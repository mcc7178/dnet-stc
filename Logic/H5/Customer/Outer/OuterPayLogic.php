<?php
namespace App\Module\Sale\Logic\H5\Customer\Outer;

use App\Exception\AppException;
use App\Model\Odr\OdrOrderModel;
use App\Module\Sale\Data\PayData;
use App\Module\Sale\Logic\Order\OrderPayCreateLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Configer;

class OuterPayLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderPayCreateLogic
     */
    private $orderPayCreateLogic;

    /**
     * 线上支付预下单
     * @param array $data 预下单参数
     * @param int  plat 来源渠道
     * @param string oids 订单ID（可多个） 1235,1236
     * @param string uid 用户ID
     * @param int payType 支付类型 11：微信支付；12：支付宝支付；13：线下支付
     * @param int tradeType 交易类型 1：小程序；2：APP；3：当面付(扫码)；4：手机网站H5
     * @return array
     * @throws
     */
    public function create(array $data)
    {
        //解析参数
        $plat = $data['plat'];//来源渠道
        $uid = $data['uid'];//用户ID
        $oid = $data['oid'];//订单ID
        $payType = $data['payType'];//支付类型 11：微信支付；12：支付宝支付；13：线下支付
        $tradeType = in_array($payType, [11, 12]) ? 3 : $data['tradeType'];//交易类型 1：小程序；2：APP；3：当面付(扫码)；4：手机网站H5

        //获取订单数据
        $cols = 'oid,plat,tid,src,buyer,okey,qty,otime,ostat,paystat,payamt,recver,rectel,recreg,recdtl,dlyway';
        $order = OdrOrderModel::M()->getRowById($oid, $cols);
        if (empty($order))
        {
            throw new AppException('订单不存在', AppException::NO_DATA);
        }

        //预支付数据
        $plat = $plat ?: $order['plat'];
        $payOids = [$oid];
        $payOkeys = [$order['okey']];
        $payamts = $order['payamt']; //支付订单支付总金额
        $payPrds = $order['qty']; //支付订单商品总数量

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

        //支付预下单
        $payConf = PayData::H5_PAY_CONF[$payType] ?? [];
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
        if (count($data) == 0)
        {
            throw new AppException('支付失败，请稍后重试', AppException::NO_DATA);
        }
        $data['payoid'] = $oid;
        $data['payamts'] = number_format($payamts, 2);
        $data['payodrs'] = 1;

        //生成支付宝支付二维码
        $data['qrcode'] = '';
        if ($payType == 12 && !empty($data['payData']))
        {
            $apiHost = Configer::get('common:apihost', '');
            $codeUrl = urlencode($data['payData']);
            $data['qrcode'] = "$apiHost/pub/qrcode/create?content=$codeUrl";
        }

        //返回
        return $data;
    }
}