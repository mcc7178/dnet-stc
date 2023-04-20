<?php
namespace App\Module\Sale\Controller\Customer\Pay;

use App\Lib\Utility;
use App\Module\Sale\Logic\Order\OrderPaySuccessLogic;
use App\Service\Pay\UserPayInterface;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use Swork\Service;

/**
 * @Controller("/sale/customer/pay/notify")
 */
class NotifyController extends BeanCollector
{
    /**
     * @Reference()
     * @var UserPayInterface
     */
    private $userPayInterface;

    /**
     * @Inject()
     * @var OrderPaySuccessLogic
     */
    private $orderPaySuccessLogic;

    /**
     * 微信支付回调通知
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function weixin(Argument $argument)
    {
        try
        {
            //处理回调参数
            $callbackArgs = Utility::xml2array($argument->raw());

            //处理支付回调
            $payData = $this->userPayInterface->notify('WxPayPlugin', $callbackArgs, [
                'chn' => 'weixin',
                'ip' => $argument->getUserIP(),
            ]);

            //处理支付成功逻辑
            $this->orderPaySuccessLogic->handle($payData);
        }
        catch (\Throwable $throwable)
        {
            //打印日志
            Service::$logger->error('微信支付回调通知发生异常：' . $throwable->getMessage());

            //如果不是已处理则返回失败
            if ($throwable->getCode() != 1011)
            {
                return Utility::array2xml(['return_code' => 'FAILED', 'return_msg' => 'FAILED']);
            }
        }

        //返回成功
        return Utility::array2xml(['return_code' => 'SUCCESS', 'return_msg' => 'OK']);
    }

    /**
     * 支付宝支付回调通知
     * @param Argument $argument
     * @param string $pluginName 回调插件
     * @return string
     * @throws
     */
    public function alipay(Argument $argument, string $pluginName = 'AliPayPlugin')
    {
        try
        {
            //处理支付回调
            $payData = $this->userPayInterface->notify($pluginName, $argument->post(), [
                'chn' => 'alipay',
                'ip' => $argument->getUserIP(),
            ]);

            //处理支付成功逻辑
            $this->orderPaySuccessLogic->handle($payData);
        }
        catch (\Throwable $throwable)
        {
            //打印日志
            Service::$logger->error('支付宝支付回调通知发生异常：' . $throwable->getMessage());

            //如果不是已处理则返回失败
            if ($throwable->getCode() != 1011)
            {
                return 'failed';
            }
        }

        //返回成功
        return 'success';
    }

    /**
     * 支付宝支付回调通知
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function alipay2(Argument $argument)
    {
        return $this->alipay($argument, 'AliPayCertPlugin');
    }

    /**
     * 通联支付回调通知
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function allin(Argument $argument)
    {
        try
        {
            //处理支付回调
            $payData = $this->userPayInterface->notify('AllinPayPlugin', $argument->post(), [
                'chn' => 'allin',
                'ip' => $argument->getUserIP(),
            ]);

            //处理支付成功逻辑
            $this->orderPaySuccessLogic->handle($payData);
        }
        catch (\Throwable $throwable)
        {
            //打印日志
            Service::$logger->error('通联支付回调通知发生异常：' . $throwable->getMessage());

            //如果不是已处理则返回失败
            if ($throwable->getCode() != 1011)
            {
                return 'failed';
            }
        }

        //返回成功
        return 'success';
    }

    /**
     * 银联支付回调通知
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function union(Argument $argument)
    {
        try
        {
            //处理支付回调
            $payData = $this->userPayInterface->notify('UnionPayPlugin', $argument->post(), [
                'chn' => 'union',
                'ip' => $argument->getUserIP(),
            ]);

            //处理支付成功逻辑
            $this->orderPaySuccessLogic->handle($payData);
        }
        catch (\Throwable $throwable)
        {
            //打印日志
            Service::$logger->error('银联支付回调通知发生异常：' . $throwable->getMessage());

            //如果不是已处理则返回失败
            if ($throwable->getCode() == 1011)
            {
                return 'FAILED';
            }
        }

        //返回成功
        return 'SUCCESS';
    }
}