<?php
namespace App\Module\Sale\Controller\Backend\Pur;

use App\Model\Crm\CrmMessageDotModel;
use App\Module\Sale\Logic\Backend\Pur\PubLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Omr\Middleware\ContextMiddleware;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 电商采购公共使用
 * Class PlanController
 * @Controller("/sale/backend/pur/pub")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ContextMiddleware::class)
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
     * 获取采购人
     * @return array
     */
    public function getPurUsers()
    {
        //返回
        return $this->pubLogic->getPurUsers();
    }

    /**
     * 获取供货商
     * @return array
     */
    public function getMerchants()
    {
        //返回
        return $this->pubLogic->getMerchants();
    }

    /**
     * 获取所有供应商
     * @return array
     */
    public function getOffers()
    {
        //返回
        return $this->pubLogic->getOffers();
    }

    /**
     * 获取所有场次
     * @return array
     */
    public function getRounds()
    {
        //返回
        return $this->pubLogic->getRounds();
    }

    /**
     * 获取小红点
     * @return array
     * */
    public function getDot(){
        //外部参数
        $acc = Context::get('acc');
        /**
         * 1501  1502
         * planstat
         * pricestat
         *
         */
        $messaData = [];
        $messaPlan = CrmMessageDotModel::M()->getList(['uid' => $acc, 'src' => 1501], 'src');
        if ($messaPlan)
        {
            $messaData['planstat'] = 1;
        }
        else
        {
            $messaData['planstat'] = 0;
        }

        $messaPrice = CrmMessageDotModel::M()->getList(['uid' => $acc, 'src' => 1502], 'src');
        if ($messaPrice)
        {
            $messaData['pricestat'] = 1;
        }
        else
        {
            $messaData['pricestat'] = 0;
        }

        // 返回结果
        return $messaData;

    }
}
