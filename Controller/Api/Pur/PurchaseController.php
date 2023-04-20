<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Api\Pur\PurchaseLogic;
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
 * 首页-新增采购
 * @Controller("/sale/api/pur/purchase")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PurchaseController extends BeanCollector
{
    /**
     * @Inject()
     * @var PurchaseLogic
     */
    private $purchaseLogic;

    /**
     * 保存新增采购单
     * @Validate(Method::Post)
     * @Validate("mid", Validate::Required, "供应商名称不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');

        /**
         * 采购单号
         * @var string
         */
        $okey = $argument->post('okey', '');

        /**
         * 供应商mid
         * @var string
         * @required
         */
        $merchant = $argument->post('mid', '');

        /**
         * dkey：需求单号，rnum：数量，scost：价格
         * @var array
         * @required
         */
        $demand = $argument->post('demand', []);

        //组装参数
        $query = [
            'okey' => $okey,
            'merchant' => $merchant,
            'demand' => $demand,
        ];

        //请求数据
        $this->purchaseLogic->save($query, $uid);

        //API返回
        /**
         * {"ok"}
         */
        return 'ok';
    }

    /**
     * 获取新增采购单型号列表
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function model(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');

        /**
         * idx页码
         * @var int
         */
        $idx = $argument->get('idx', 1);

        /**
         * size每页行数
         * @var int
         * @required
         */
        $size = $argument->get('size', 10);

        //API返回
        /**
         * {
         *  "dkey": "PL20110214438582",  //需求单号
         *  "midName": "ivvi i3 Play",  //模型名称
         *  "levelName": "A1",  //等级
         *  "snum": "0",  //入库
         *  "pnum": "0",  //已完成
         *  "taskNum": 300, //任务
         *  "utime": "2020-11-04 17:45",  //期望交付时间
         *  "options": "6G+128G 墨岩黑 欧版 全网通 保修期一个月以上"  //类目选项
         * }
         */
        return $this->purchaseLogic->getModel($uid, $idx, $size);
    }

    /**
     * 获取新增采购详情详情
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');

        /**
         * 需求单号
         * @var string
         * @required
         */
        $dkey = $argument->get('dkey', '');

        //API返回
        /**
         * {
         *  "dkey": "PL20110214438582",  //需求单号
         *  "midName": "ivvi i3 Play",  //模型名称
         *  "levelName": "A1",  //等级
         *  "rnum": 0,  //数量
         *  "options": "6G+128G 墨岩黑 欧版 全网通 保修期一个月以上"  //类目选项
         * }
         */
        return $this->purchaseLogic->getInfo($uid, $dkey);
    }
}