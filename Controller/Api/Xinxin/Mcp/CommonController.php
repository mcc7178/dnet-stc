<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use App\Module\Sale\Logic\Api\Xinxin\Mcp\CommonLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use App\Middleware\ApiResultFormat;

/**
 * 公共接口
 * @Controller("/sale/api/xinxin/mcp/common")
 * @Middleware(ApiResultFormat::class)
 */
class CommonController extends BeanCollector
{
    /**
     * @Inject()
     * @var CommonLogic
     */
    private $commonLogic;

    /**
     * 获取新新公开时间
     * @return mixed
     */
    public function getptime()
    {
        return $this->commonLogic->getPtime();
    }
}