<?php
namespace App\Module\Sale\Middleware\Pur;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\BeanCollector;
use Swork\Configer;
use Swork\Middleware\BeforeMiddlewareInterface;
use Swork\Server\ArgumentInterface;

/**
 * 上下文中间件
 */
class SignMiddleware extends BeanCollector implements BeforeMiddlewareInterface
{
    /**
     * 中间件处理层
     * @param ArgumentInterface $argument
     * @throws
     */
    public function process(ArgumentInterface $argument)
    {
        return;

        //外部参数
        $sign = $argument->get('sign', '');
        $signData = $argument->query();
        unset($signData['sign']);

        //检查必要参数
        if (empty($sign))
        {
            throw new AppException('缺少签名参数', AppException::MISS_ARG);
        }

        //获取API签名密钥
        $apiSignKey = PurDictData::API_SECRET;
        if (empty($apiSignKey))
        {
            throw new AppException('Internal Server Error', 500);
        }

        //连接签名字符串、补充key
        $linkStr = Utility::linkSignString($signData);
        $linkStr = $linkStr . '&key=' . $apiSignKey;

        //对比签名
        if (strtoupper(md5($linkStr)) != $sign)
        {
            throw new AppException('Invalid signature', AppException::WRONG_SIGN);
        }
    }
}