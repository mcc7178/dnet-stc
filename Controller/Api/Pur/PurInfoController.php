<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Api\Pur\PurInfoLogic;
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
 * 采购单 - 详情
 * @Controller("/sale/api/pur/purinfo")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PurInfoController extends BeanCollector
{
    /**
     * @Inject()
     * @var PurInfoLogic
     */
    private $purInfoLogic;

    /**
     * 采购单数据（采购单基本信息 + 下属的需求列表数据）
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function purData(Argument $argument)
    {
        //外部参数
        $aacc = Context::get('userId');

        /**
         * 采购单id
         * @var string
         * @require
         * @sample 123
         */
        $okey = $argument->get('okey', '');

        /**
         * 状态
         * @var int
         * @require
         * @sample 1
         */
        $ostat = $argument->get('ostat', 0);

        /**
         * 状态
         * @var int
         * @require
         * @sample 1
         */
        $cstat = $argument->get('cstat', 0);

        //API返回
        /**
         * "purOdrOrder": {
         * "okey": "OR2011041813337", //采购单号
         * "tnum": 10, //需求单总数量
         * "snum": 10, //审核通过数量
         * "fnum": 0, //审核驳回数量
         * "ostat": 1, //采购单状态 1、进行中 2、已完成 3、已中止
         * "cstat": 3, //采购单审核状态  1、待审核 2、部分通过 3、全部通过 4、全部拒绝
         * "stock": 1, //采购单库存状态
         * "merchant": "5f5870238dacd02bdd6e7164", //供货商编号
         * "mname": "供货商A"  //供货商
         * "atime": 1604505600, //添加时间（提交审核时间）
         * "ctime": 1604484781
         * },
         * "purOdrDemand": [
         * {
         * "dkey": "DE2011041812369",  //需求单号
         * "unum": 140, //分配采购数量
         * "rnum": 120, //实际采购数量（需要预入库数量）
         * "snum": 100, //已入库数量（已在库数量）
         * "mnum": 0, //已质检数量
         * "pnum": 80, //已完成数量
         * "scost": "3359.00",  //采购单价
         * "tcost": "50385.00", //采购总价
         * "cstat": 2,
         * "cstatName": "已通过", //需求单状态
         * "dstat": 3,
         * "rmk": "",  //驳回备注
         * "utime": "2020-11-05 15:06",
         * "bname": "VIVO",  //品牌
         * "mname": "vivo X9",  //机型
         * "need": "A1 移动定制全网通 灰色 128G 其他正常"
         * }]
         */
        return $this->purInfoLogic->purData($aacc, $okey, $ostat, $cstat);
    }

    /**
     * 获取要修改的采购需求单信息
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function getDemandInfo(Argument $argument)
    {
        //外部参数
        $aacc = Context::get('userId');

        /**
         * 采购单id
         * @var string
         * @require
         * @sample 123
         */
        $did = $argument->get('did', '');

        //API返回
        /**
         * {
         * "okey": "OR2011041813337",
         * "dkey": "DE2011041812369",  //采购单号
         * "unum": 140, //数量
         * "scost": "3359.00",  //采购单价
         * "tcost": "50385.00",  // 采购总价
         * "need": "A1 移动定制全网通 灰色 128G 其他正常",
         * "mname": "vivo X9"
         * }
         */
        return $this->purInfoLogic->getDemandInfo($aacc, $did);
    }

    /**
     * 保存修改的需求单
     * @Validate(Method::Post)
     * @Validate("rnum", Validate::Required, "缺少数量")
     * @Validate("dkey", Validate::Required, "缺少需求单号")
     * @Validate("okey", Validate::Required, "缺少采购单号")
     * @Validate("scost", Validate::Required, "单价不能为空")
     * @param Argument $argument
     * @return boolean
     * @throws
     */
    public function saveDemand(Argument $argument)
    {
        //外部参数
        $aacc = Context::get('userId');

        /**
         * 需求单编号
         * @var string
         * @require
         * @sample 123
         */
        $dkey = $argument->post('dkey', '');

        /**
         * 采购单编号
         * @var string
         * @require
         * @sample 123
         */
        $okey = $argument->post('okey', '');

        /**
         * 数量
         * @var int
         * @require
         * @sample 123
         */
        $rnum = $argument->post('rnum', 0);

        /**
         * 单价
         * @var int
         * @require
         * @sample 123
         */
        $scost = $argument->post('scost', 0);

        //调用函数
        $this->purInfoLogic->saveDemand($aacc, $dkey, $okey, $rnum, $scost);

        //API返回
        return 'success';
    }
}