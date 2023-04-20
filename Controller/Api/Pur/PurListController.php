<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Api\Pur\PurListLogic;
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
 * 采购单 - 列表
 * 包含内容：列表筛选 + 列表数据 + 小红点展示
 * @Controller("/sale/api/pur/purlist")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PurListController extends BeanCollector
{
    /**
     * @Inject()
     * @var PurListLogic
     */
    private $purListLogic;

    /**
     * 采购单列表
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
         * 供货商
         * @var string
         * @sample 5fa9182a724ee52ca9171f0c
         */
        $merchant = $argument->get('merchant', '');

        /**
         * 品牌
         * @var string
         * @sample 20000
         */
        $bid = $argument->get('bid', 0);

        /**
         * 机型
         * @var string
         * @sample 20002
         */
        $mid = $argument->get('mid', 0);

        /**
         * 库存编号
         * @var string
         * @sample 20111016861802
         */
        $bcode = $argument->get('bcode', '');

        /**
         * 采购单号
         * @var string
         * @sample OR2011101938806
         */
        $okey = $argument->get('okey', '');

        /**
         * 需求单号
         * @var string
         * @sample DE2011111035594
         */
        $dkey = $argument->get('dkey', '');

        /**
         * 状态
         * @var int
         * @sample 1：进行中   2：已完成    3：已中止
         */
        $ostat = $argument->get('ostat', 0);

        //组装数据
        $query = [
            'uid' => $uid,
            'merchant' => $merchant,
            'bcode' => $bcode,
            'okey' => $okey,
            'dkey' => $dkey,
            'bid' => $bid,
            'mid' => $mid,
            'ostat' => $ostat,
        ];

        //API返回
        /**
         * [
         * {
         * "okey": "OR2011041815139",       //采购单号
         * "ostat": 1,
         * "ltime": "2020-11-04 18:15",      //更新时间
         * "cstat": 3,
         * "snum": "110",               //审核通过数量
         * "merchant": "供货商B",         //供货商
         * "atime": "2020-11-04 18:15",              //提交时间
         * "tnum": 4,
         * "stat": {
         * "examineStat": 0,       //审核红点（0：无  1：有）
         * "checkedStat": 0,         //已质检红点（0：无  1：有）
         * "finishedStat": 0,         //已完成红点（0：无  1：有）
         * "waitStat": 0,          //质检待确认红点（0：无  1：有）
         * "waitGoodsStat": 0,           //待退货红点（0：无  1：有）
         * "stcStat": 0,             //预入库红点（0：无  1：有）
         * "instcStat": 0,           //入库红点（0：无  1：有）
         * "returnedStat": 0           //已退货红点（0：无  1：有）
         * },
         * "subNum": 10,            //提交数量
         * "finishedNum": 0,       //已完成数量
         * "instcNum": 0,          //入库数量
         * "checkedNum": 0,        //已质检数量
         * "stcNum": 8,          //预入库数量
         * "waitNum": 4,            //质检待确认数量
         * "returnedNum": 2,       //已退货已退货
         * "waitGoodsNum": 0,        //待退货数量
         * "ostatName": "进行中",           //采购单状态
         * "cstatName": "全部通过",        //审核状态
         * },
         * ]
         */
        return $this->purListLogic->getPager($query, $size, $idx);
    }
}