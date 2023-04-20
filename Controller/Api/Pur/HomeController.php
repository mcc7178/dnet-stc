<?php


namespace App\Module\Sale\Controller\Api\Pur;

use App\Module\Sale\Logic\Api\Pur\HomeLogic;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Module\Sale\Middleware\Pur\SignMiddleware;
use App\Module\Sale\Middleware\Pur\ContextMiddleware;
use App\Module\Sale\Middleware\Pur\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;

/**
 * 用户主页
 * @Controller("/sale/api/pur/home")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class HomeController extends BeanCollector
{
    /**
     * @Inject()
     * @var HomeLogic
     */
    private $homeLogic;

    /**
     * 获取首页信息
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //上下文参数
        $uid = Context::get('userId');

        //获取数据
        $info = $this->homeLogic->getInfo($uid);

        //API返回
        /**
         * {
         * "user": {
         * "uname": "林漫芬",             //用户昵称
         * "avatar": "https://imgdev.sosotec.com/avatar/2018102011163908506.jpg"       //用户头像
         * },
         * "updateStatus": {
         * "DemandList": 1,          //需求单红点（0：无  1：有）
         * "PurchaseOrder": 0,        //采购单红点（0：无  1：有）
         * "waitReturned": 1,        //待退货红点（0：无  1：有）
         * "Returned": 0           //已退货红点（0：无  1：有）
         * },
         * "demandList": {
         * "demandDetail": [
         * {
         * "dkey": "DE2011041812136",        //需求单号
         * "mname": "iPhone 11 Pro",          //机型
         * "demandNum": 120,               //需求数量
         * "comfiredNum": 2,               //确认采购数量
         * "dstatName": "采购中",             //状态
         * "ptypeName": "定期采购"           //计划类型
         * },
         * {
         * "dkey": "DE2011041812369",
         * "mname": "vivo X9",
         * "demandNum": 80,
         * "comfiredNum": 1,
         * "dstatName": "采购中",
         * "ptypeName": "定期采购"
         * },
         * ],
         * "purNum": 390,                //应采购数量
         * "inStcNum": 260,             //已入库数量
         * "hasPurNum": 3,                //已采购数量
         * "returnNum": 1,              //退货数量
         * "waitNum": 260               //待完成数量
         * }
         */
        return $info;
    }
}