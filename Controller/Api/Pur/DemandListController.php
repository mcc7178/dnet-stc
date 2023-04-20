<?php


namespace App\Module\Sale\Controller\Api\Pur;

use App\Module\Sale\Logic\Api\Pur\DemandListLogic;
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
 * 需求单
 * @Controller("/sale/api/pur/demand")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class DemandListController extends BeanCollector
{
    /**
     * @Inject()
     * @var DemandListLogic
     */
    private $demandListLogic;

    /**
     * 获取需求单列表数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        /**
         * 分页idx
         * @var string
         * @sample 1
         */
        $idx = $argument->get('idx', 1);

        /**
         * 分页size
         * @var string
         * @sample 25
         */
        $size = $argument->get('size', 25);

        //外部参数
        $uid = Context::get('userId');

        /**
         * 需求单号
         * @var string
         * @sample DE2011111035594
         */
        $dkey = $argument->get('dkey', '');

        /**
         * 类型
         * @var int
         * @sample 1：定期采购   2：紧急采购
         */
        $ptype = $argument->get('ptype', 0);

        /**
         * 状态
         * @var int
         * @sample 1：待采购   2：采购中    3：已完成      4：已中止
         */
        $tstat = $argument->get('tstat', 0);

        //查询参数
        $query = [
            'uid' => $uid,
            'dkey' => $dkey,
            'ptype' => $ptype,
            'tstat' => $tstat,
        ];

        //API返回
        /**
         * [
         * {
         * "dkey": "DE2011041812369",    //需求单号
         * "unum": 100,           //预计采购数量
         * "snum": 70,        //已入库数量
         * "pnum": 30,       //已完成数量
         * "dstat": 2,
         * "ptype": 2,
         * "utime": "2020-11-05 15:06",         //期望交付时间
         * "bid": 80000,
         * "mid": 80086,
         * "level": 11,
         * "mdram": 17601,
         * "mdcolor": 16111,
         * "mdofsale": 0,
         * "mdnet": 15205,
         * "mdwarr": 24305,
         * "dstatName": "采购中",      //状态
         * "ptypeName": "紧急采购",          //计划类型
         * "optionsData": "VIVO/vivo X9/A1/128G/灰色//移动定制全网通/其他正常"      //采购需求说明
         * },
         * ]
         */
        return $this->demandListLogic->getPager($query, $size, $idx);
    }

    /**
     * 获取需求单详情信息
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function detail(Argument $argument)
    {
        //上下文参数
        $uid = Context::get('userId');

        /**
         * 需求单号dkey
         * @var int
         * @sample DE2011041812493
         */
        $dkey = $argument->get('dkey', '');

        //API返回
        /**
         * {
         * "dkey": "DE2011041812369",      //需求单号
         * "atime": "2020-11-04 18:12",     //生成时间
         * "utime": "2020-11-05 15:06",        //期望交付时间
         * "ptypeName": "紧急采购",        //计划类型
         * "dstatName": "采购中",           //状态
         * "optionsData": "VIVO vivo X9 A1 移动定制全网通 灰色 128G 其他正常",         //采购需求说明
         * "purTaskNum": 100,            //计划采购数量
         * "snum": 390,             //需求单已入库数量
         * "pnum": 330,               //需求单已完成数量
         * "scost": "438.30",       //采购单价
         * "tcost": 206000,       //采购总价
         * "returnNum": 5,       //需求单已退货数量
         * "waitNum": 2,       //需求单待退货数量
         * "substat": 1,         //提交采购单按钮状态（1：不可点击  2：可点击）
         * "demandstat": 1,          //完成需求单和中止需求单按钮状态（1：不可点击  2：可点击）
         * "purData": {
         * "OR2011041815139": {
         * "okey": "OR2011041815139",           //采购单号
         * "ltime": "2020-11-04 18:15",           //更新时间
         * "merchant": "供货商B",               //供应商
         * "atime": "2020-11-04 18:15",           //提交时间
         * "stcNum": 2,              //采购单预入库数量
         * "finishedNum": "0",        //采购单已完成数量
         * "examineStat": "待审核",            //采购单审核状态
         * "mnum": "2",          //采购单已质检数量
         * "rnum": 130,               //采购单提交数量
         * "snum": 110,                //采购单入库数量
         * "mname": "vivo X9",          //机型
         * "reviewedNum": 0         //采购单已退货数量
         * "waitNum": 0,            //采购单待退货数量
         * },
         * }
         * }
         */
        return $this->demandListLogic->getDetail($dkey, $uid);
    }

    /**
     * 完成需求单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function complete(Argument $argument)
    {
        //上下文参数
        $uid = Context::get('userId');

        /**
         * 需求单号dkey
         * @var int
         * @sample DE2011041812493
         */
        $dkey = $argument->post('dkey', '');

        //修改状态
        $this->demandListLogic->complete($dkey, $uid);

        //API返回
        /**
         * {
         * 'success'
         * }
         */
        return 'success';
    }

    /**
     * 中止需求单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function stop(Argument $argument)
    {
        //上下文参数
        $uid = Context::get('userId');

        /**
         * 需求单号dkey
         * @var int
         * @sample DE2011041812493
         */
        $dkey = $argument->post('dkey', '');

        //修改状态
        $this->demandListLogic->stop($dkey, $uid);

        //API返回
        /**
         * {
         * 'success'
         * }
         */
        return 'success';
    }
}