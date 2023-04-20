<?php

namespace App\Module\Sale\Controller\Backend\Goods;

use App\Module\Sale\Logic\Backend\Goods\GoodsLogic;
use App\Module\Sale\Logic\Backend\Goods\XyeGoodsLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 闲鱼已验货导入
 * Class XyeGoodsController
 * @Controller("/sale/backend/goods/xyegoods")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class XyeGoodsController extends BeanCollector
{
    /**
     * @Inject()
     * @var XyeGoodsLogic
     */
    private $xyeGoodsLogic;

    /**
     * 商品列表
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function import(Argument $argument)
    {
        //外部参数
        $file = $argument->getFile('uploadfile');

        if (!is_array($file))
        {
            //request_method => OPTIONS 不处理,返回成功
            return 'success';
        }

        $this->xyeGoodsLogic->import($file);

        //返回
        return 'ok';
    }
}