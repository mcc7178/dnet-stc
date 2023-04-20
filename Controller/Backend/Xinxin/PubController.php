<?php
namespace App\Module\Sale\Controller\Backend\Xinxin;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\XinXin\PubLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * @Controller("/sale/backend/xinxin/pub")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PubController extends BeanCollector
{
    /**
     * @Inject()
     * @var PubLogic
     */
    private $pubLogic;

    /**
     * 获取采购中的品牌列表
     * @return array
     */
    public function brands()
    {
        return $this->pubLogic->getBrands();
    }

    /**
     * 获取采购中的机型列表
     * @param Argument $argument
     * @return array
     */
    public function models(Argument $argument)
    {
        $bid = $argument->get('brand', 0);

        return $this->pubLogic->getModels($bid);
    }

    /**
     * 获取等级
     * @return array
     */
    public function levels()
    {
        return $this->pubLogic->getLevels();
    }

    /**
     * 获取七牛配置
     * @return array
     */
    public function qiniu()
    {
        return $this->pubLogic->getQiniu();
    }
}
