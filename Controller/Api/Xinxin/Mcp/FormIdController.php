<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use App\Module\Sale\Logic\Api\Xinxin\Mcp\WxFormIdLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;

/**
 * 微信小程序表单ID（收集起来用于发送服务通知）
 * @Controller("/sale/api/xinxin/mcp/formid")
 * @Middleware(ApiResultFormat::class)
 */
class FormIdController extends BeanCollector
{
    /**
     * @Inject()
     * @var WxFormIdLogic
     */
    private $wxFormIdLogic;

    /**
     * 保存小程序表单ID
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $acc = $argument->post('uid', '');
        $formid = $argument->post('formid', '');

        //保存表单ID
        $this->wxFormIdLogic->save($acc, $formid);

        //返回
        return 'success';
    }
}