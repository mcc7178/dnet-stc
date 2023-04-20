<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Module\Sale\Logic\Api\Pur\PreStcLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use App\Module\Sale\Middleware\Pur\SignMiddleware;
use App\Module\Sale\Middleware\Pur\ContextMiddleware;
use App\Module\Sale\Middleware\Pur\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Context;

/**
 * 预入库
 * @Controller("/sale/api/pur/prestc")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 * Class ProductController
 * @package App\Module\Sale\Controller\Api\Pur
 */
class PreStcController extends BeanCollector
{
    /**
     * @Inject()
     * @var PreStcLogic
     */
    private $preStcLogic;

    /**
     * 获取已预入库商品翻页数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //上下文参数
        $uid = Context::get('userId');

        /**
         * 采购单号
         * @var string
         */
        $okey = $argument->get('okey', '');

        /**
         * 需求单号
         * @var string
         */
        $dkey = $argument->get('dkey', '');

        /**
         * 页码
         * @var int
         * @sample 1
         */
        $size = $argument->get('size', 10);

        /**
         * 页码
         * @var int
         * @sample 1
         */
        $idx = $argument->get('idx', 1);

        //外部参数
        $query = [
            'okey' => $okey,
            'dkey' => $dkey,
            'uid' => $uid,
        ];

        //获取数据
        $data = $this->preStcLogic->getPager($query, $size, $idx);

        //API返回
        /*
        *{
        *    "info": {
        *      "pretime": "2020-11-05 00:00",//预入库时间
        *      "prenum": 10//预入库数量
        *    },
        *    "list": [
        *      {
        *        "atime": "2020-11-05 20:04",//添加时间
        *        "bcode": "20110516802912",//商品编码
        *        "scost": "4777.00",//采购单价
        *        "mname": "魅族 Note9",//机型
        *        "optionsData": "A1 全网通 幻黑 4G+64G 其他正常"//采购需求配置
        *      },
        *      {
        *        "atime": "2020-11-05 20:04",
        *        "bcode": "20110516891941",
        *        "scost": "4777.00",
        *        "mname": "魅族 Note9",
        *        "optionsData": "A1 全网通 幻黑 4G+64G 其他正常"
        *      }
        *    ]
        *}
         */

        return $data;
    }

    /**
     * 扫码预入库
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function pre(Argument $argument)
    {
        //上下文参数
        $uid = Context::get('userId');

        /**
         * 采购单号
         * @var string
         */
        $okey = $argument->post('okey', '');

        /**
         * 需求单号
         * @var string
         */
        $dkey = $argument->post('dkey', '');

        /**
         * 库存编码
         * @var string
         */
        $bcode = $argument->post('bcode', '');

        //外部参数
        $query = [
            'okey' => $okey,
            'dkey' => $dkey,
            'bcode' => $bcode
        ];

        //执行预入库
        $this->preStcLogic->pre($query, $uid);

        //API返回
        /*
         * ok
         */

        return 'ok';
    }

    /**
     * 预入库详情
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        /**
         * 采购单号
         * @var string
         */
        $okey = $argument->get('okey', '');

        /**
         * 需求单号
         * @var string
         */
        $dkey = $argument->get('dkey', '');

        //获取数据
        $data = $this->preStcLogic->getInfo($okey, $dkey);

        //API返回
        /*
         *{
         *    "merchant": "供货商A",
         *    "mobile": "12345678911",
         *    "okey": "OR2011041813337",//采购单号
         *    "dkey": "DE2011041813243",//需求单号
         *    "rnum": 19,//提交数量
         *    "unum": 19,//分配数量
         *    "prenum": 10,//预入库数量
         *    "waitnum": 9,//待预入库数量
         *    "pretime": "2020-11-05 00:00",//预入库时间
         *    "atime": "2020-11-04 18:13",//提交时间
         *    "mname": "魅族 Note9",//机型
         *    "optionsData": "全网通 幻黑 4G+64G 其他正常"//需求
         *}
         */

        return $data;
    }
}