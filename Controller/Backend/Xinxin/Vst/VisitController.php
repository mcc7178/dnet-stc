<?php

namespace App\Module\Sale\Controller\Backend\Xinxin\Vst;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\Xinxin\Vst\VisitLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 访问统计
 * @Controller("/sale/backend/xinxin/visit")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class VisitController extends BeanCollector
{
    /**
     * @Inject()
     * @var VisitLogic
     */
    private $visitLogic;

    /**
     * 访问统计列表
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);
        $rtime = $argument->get('rtime', []);

        //返回
        return $this->visitLogic->getPager($rtime, $idx, $size);
    }
}
