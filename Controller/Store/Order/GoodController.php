<?php


namespace App\Module\Sale\Controller\Store\Order;

use App\Module\Sale\Logic\Store\Order\OrderGoodLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Validate;
use Swork\Server\Http\Argument;
use Swork\Bean\BeanCollector;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;

/**
 * 订单商品相关逻辑
 * @Controller("/sale/store/good")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class GoodController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderGoodLogic
     */
    private $ordergoodLogic;

    /**
     * 订单详情-待成交 删除订单商品
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $pid = $argument->post('pid', '');

        //删除数据
        $this->ordergoodLogic->delete($oid, $pid);

        //返回
        return 'success';
    }

    /**
     * 订单详情-待成交 保存商品议价金额
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $info = $argument->post('params', []);
        $oid = $info['oid'];
        $data = $info['data'] ?? [];

        //保存数据
        $this->ordergoodLogic->save($oid, $data);

        //返回
        return 'success';
    }
}