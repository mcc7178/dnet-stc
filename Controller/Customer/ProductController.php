<?php
namespace App\Module\Sale\Controller\Customer;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Customer\ProductLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 客户端商品信息相关接口
 * @Controller("/sale/customer/product")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class ProductController extends BeanCollector
{
    /**
     * @Inject()
     * @var ProductLogic
     */
    private $productLogic;

    /**
     * 检查商品状态
     * @param Argument $argument
     * @return array|bool
     * @throws
     */
    public function check(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $bcode = $argument->get('bcode', '');

        //返回
        return $this->productLogic->check($bcode, $acc);
    }

    /**
     * 获取商品质检报告
     * @param Argument $argument
     * @return array|bool
     * @throws
     */
    public function detail(Argument $argument)
    {
        //外部参数
        $bcode = $argument->get('bcode', '');

        //返回
        return $this->productLogic->getDetail($bcode);
    }
}