<?php
namespace App\Module\Sale\Controller\H5\Customer\Outer;

use App\Module\Sale\Logic\H5\Customer\Outer\OuterPayLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use App\Middleware\LoginMiddleware;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 客户-外单订单支付相关接口
 * @Controller("/sale/h5/customer/outer/pay")
 * @Middleware(ApiResultFormat::class)
 */
//* @Middleware(LoginMiddleware::class)
class PayController extends BeanCollector
{
    /**
     * @Inject()
     * @var OuterPayLogic
     */
    private $outerPayLogic;

    /**
     * 支付预下单
     * @Validate(Method::Post)
     * @Validate("paytype", Validate::Required, '缺少支付方式')
     * @Validate("oid", Validate::Required, '缺少订单ID参数')
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function create(Argument $argument)
    {
        //上下文参数
        $plat = Context::get('plat');
        $uid = Context::get('acc');
        $plat = 23;
        $uid = '5e154874724ee505ba426690';

        /**
         * 支付类型 11：微信支付；12：支付宝支付；13：线下支付
         * @var int
         * @sample 12
         */
        $payType = $argument->query('paytype', 0);

        /**
         * 交易类型，1：小程序；2：APP；3：当面付(扫码)；4：手机网站H5
         * @var int
         * @sample 1
         */
        $tradeType = $argument->query('tradetype', 4);

        /**
         * 订单ID（可多个）
         * @var string
         * @sample 123,123,123
         */
        $oid = $argument->query('oid', '');

        //组装参数
        $data = [
            'oid' => $oid,
            'payType' => $payType,
            'tradeType' => $tradeType,
            'plat' => $plat,
            'uid' => $uid,
        ];

        //支付预下单
        $data = $this->outerPayLogic->create($data);

        //API返回
        /**
         * {
         *      "tradeNo": "20200220123778", //支付商户号
         *      "paydata": "20387874993", //支付宝预下单号trade_no
         *      "ptype": 12, //支付类型，12-支付宝，13-线下支付
         *      "payoids": ["123", "456"], //支付订单ID
         *      "payamts": 4,700, //支付金额
         *      "payodrs": 2, //支付订单数
         *      "calodrs": 1, //支付超时订单数
         *      "payidx": 2, //是否有多个订单，1：单个订单、2：多个订单
         * }
         */
        return $data;
    }
}