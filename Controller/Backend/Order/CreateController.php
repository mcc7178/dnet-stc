<?php
namespace App\Module\Sale\Controller\Backend\Order;

use App\Module\Sale\Logic\Backend\Order\OrderCreateLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 扫码/导入订单接口
 * @Controller("/sale/backend/order/create")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 * @package App\Module\Sale\Controller\Backend\Order
 */
class CreateController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderCreateLogic
     */
    private $orderCreateLogic;

    /**
     * 扫码新增订单
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function scan(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $data = [
            'tid' => $argument->post('tid', 0),
            'src' => $argument->post('src', 0),
            'recver' => $argument->post('recver', ''),
            'rectel' => $argument->post('rectel', ''),
            'recdtl' => $argument->post('recdtl', ''),
            'expid' => $argument->post('expid', 0),
            'expno' => $argument->post('expno', ''),
            'exptime' => $argument->post('exptime', ''),
            'payno' => $argument->post('payno', ''),
            'goods' => $argument->post('goods', []),
            'acc' => $acc
        ];

        //保存订单
        $this->orderCreateLogic->scan($data);

        //返回
        return 'success';
    }

    /**
     * 导入新增订单
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function import(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $tid = $argument->get('tid', 0);
        $src = $argument->get('src', 0);
        $file = $argument->getFile('uploadfile');

        if (!is_array($file))
        {
            //request_method => OPTIONS 不处理,返回成功
            return 'success';
        }

        //保存订单
        $this->orderCreateLogic->import($acc, $tid, $src, $file);

        //返回
        return 'success';
    }
}