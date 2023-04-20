<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Api\Pur\PurMqcLogic;
use App\Module\Sale\Middleware\Pur\ContextMiddleware;
use App\Module\Sale\Middleware\Pur\LoginMiddleware;
use App\Module\Sale\Middleware\Pur\SignMiddleware;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 采购单列表-进入已质检+质检待确认列表
 * @Controller("/sale/api/pur/purmqc")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PurMqcController extends BeanCollector
{
    /**
     * @Inject()
     * @var PurMqcLogic
     */
    private $purMqcLogic;

    /**
     * 获取质检采购单基本信息
     * @Validate(Method::Get)
     * @Validate("okey", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function purInfo(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');

        /**
         * 采购单号
         * @var string
         * @required
         */
        $okey = $argument->get('okey', '');

        //API返回
        /**
         * {
         *  "mname": "供货商C",          //供货商名称
         *  "mobile": "12345678913",    //手机号码
         *  "okey": "OR2011041815191",  //采购单号
         *  "inum": 1,                  //已质检数量
         *  "cnum": 1,                  //待确认数量
         *  "dnum": 0,                  //待退货数量
         *  "rnum": 0                   //已退货数量
         * }
         */
        return $this->purMqcLogic->getPurInfo($okey, $uid);
    }

    /**
     * 获取已质检商品列表(分页获取数据)
     * @Validate(Method::Get)
     * @Validate("okey", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');

        /**
         * 页码
         * @var int
         */
        $idx = $argument->get('idx', 1);

        /**
         * 条数
         * @var int
         */
        $size = $argument->get('size', 10);

        /**
         * 采购单号
         * @var string
         * @required
         */
        $okey = $argument->get('okey', '');

        /**
         * 状态，1：已质检，2：质检待确定
         * @var string
         */
        $stat = $argument->get('stat', 1);

        //API返回
        /**
         * {
         *  "bcode": "19081616899991",      //库存编码
         *  "dkey": "DE2011041812136",      //需求单号
         *  "gtime3": "2020-11-04 16:56",   //质检时间
         *  "midName": "iPhone 11 Pro"      //机型
         * }
         */
        return $this->purMqcLogic->getPager($okey, $stat, $uid, $idx, $size);
    }
}