<?php
namespace App\Module\Sale\Data;

use Swork\Bean\BeanCollector;

class PayData extends BeanCollector
{
    /**
     * 支付订单超时取消时间
     */
    const PAY_CANCEL_TIME = [
        'ctime' => 10800,
        'offpay' => 86400,
    ];

    /**
     * 支付类型
     */
    const PAY_TYPE = [
        11 => '微信支付',
        12 => '支付宝支付',
        13 => '线下支付'
    ];

    /**
     * 支付渠道
     */
    const PAY_PAYCHN = [
        321 => '支付宝支付',
        331 => '银行转账',
    ];

    /**
     * 支付配置
     */
    const PAY_CONF = [
        11 => ['paytype' => 11, 'paychn' => 211, 'trade_type' => 3, 'notify_url' => '/sale/customer/pay/notify/allin'],
        12 => ['paytype' => 12, 'paychn' => 211, 'trade_type' => 3, 'notify_url' => '/sale/customer/pay/notify/allin'],
        13 => ['paytype' => 13, 'paychn' => 1],
    ];

    /**
     * 支付配置
     */
    const H5_PAY_CONF = [
        11 => ['paytype' => 11, 'paychn' => 211, 'trade_type' => 4, 'notify_url' => '/sale/customer/pay/notify/allin'],
        12 => ['paytype' => 12, 'paychn' => 211, 'trade_type' => 3, 'notify_url' => '/sale/customer/pay/notify/allin'],
        13 => ['paytype' => 13, 'paychn' => 1],
    ];

    /**
     * 线下支付对公账号
     */
    const ACCOUNT = [
        [
            'bname' => '招商银行深圳分行软件基地支行',
            'uname' => '深圳市收收科技有限公司',
            'bcard' => '7559 3574 6010 901'
        ]
    ];
}