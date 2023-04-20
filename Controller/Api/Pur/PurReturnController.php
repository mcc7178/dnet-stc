<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Api\Pur\PurReturnLopgic;
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
 * 采购单列表-待退货+已退货
 * @Controller("/sale/api/pur/purreturn")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PurReturnController extends BeanCollector
{
    /**
     * @Inject()
     * @var PurReturnLopgic
     */
    private $purReturnLogic;

    /**
     * 退货单列表（分页获取数据）
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $acc = Context::get('userId');

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
         * 供货商mid
         * @var string
         */
        $merchant = $argument->get('mid', '');

        /**
         * 库存编号
         * @var string
         */
        $bcode = $argument->get('bcode', '');

        /**
         * 采购单号
         * @var string
         */
        $okey = $argument->get('okey', '');

        /**
         * 退货单号
         * @var string
         */
        $skey = $argument->get('skey', '');

        /**
         * 时间搜索
         * @var array
         */
        $mtime = $argument->get('mtime', []);

        /**
         * 退货单号状态，0:全部，1：待退货，2：已退货
         * @var int
         */
        $stat = $argument->get('stat', 0);

        //组装参数
        $query = [
            'acc' => $acc,
            'merchant' => $merchant,
            'bcode' => $bcode,
            'okey' => $okey,
            'skey' => $skey,
            'mtime' => $mtime,
            'stat' => $stat,
        ];

        //API返回
        /**
         * [
         *  {
         *      "rtnskey": "22",        //退货单号
         *      "num": 1,               //数量
         *      "merchant": "供货商B",   //供货商名称
         *      "prdstat": 3,           //
         *      "mtime": "-",           //更新时间
         *      "stat": "已退货"         //状态
         *  },
         *  {
         *      "rtnskey": "11",
         *      "num": 2,
         *      "merchant": "供货商C",
         *      "prdstat": 1,
         *      "mtime": "-",
         *      "stat": "待退货"
         *  }
         * ]
         */
        return $this->purReturnLogic->getPager($query, $idx, $size);
    }

    /**
     * 退货单对应商品列表（分页获取数据）
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function goods(Argument $argument)
    {
        //外部参数
        $acc = Context::get('userId');

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
         * 退货单号
         * @var string
         */
        $skey = $argument->get('skey', '');

        /**
         * 采购单号
         * @var string
         */
        $okey = $argument->get('okey', '');

        /**
         * 状态
         * @var int
         */
        $stat = $argument->get('stat', 0);

        //组装参数
        $query = [
            'acc' => $acc,
            'skey' => $skey,
            'okey' => $okey,
            'stat' => $stat,
        ];

        //API返回
        /**
         * {
         * "merchant": "供货商C",                        //供货商名称
         * "num": 2,                                    //数量
         * "stime": "2020-11-05 08:00",                 //确认退货时间
         * "stat": "待退货",                             //状态
         * "goods": [                                   //翻页列表
         *      {
         *          "rtnskey": "CR20110617339267",      //退货单号
         *          "dkey": "DE2011041812369",          //需求单号
         *          "bcode": "1908131681009",           //库存编码
         *          "merchant": "5f5870238dacd02bdd6e7166",
         *          "prdstat": 1,
         *          "midName": "vivo X9",               //机型
         *          "mtime": "2020-11-05 08:00"         //更新时间
         *      },
         *      {
         *          "rtnskey": "CR20110617339267",
         *          "dkey": "DE2011041812369",
         *          "bcode": "1905221685006",
         *          "merchant": "5f5870238dacd02bdd6e7165",
         *          "prdstat": 1,
         *          "midName": "vivo X9",
         *          "mtime": "2020-11-05 08:00"
         *      }
         *  ]
         * }
         *
         */
        return $this->purReturnLogic->getGoodsList($query, $idx, $size);
    }
}