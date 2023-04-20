<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Api\Pur\MerchantLogic;
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
 * 供货商管理
 * @Controller("/sale/api/pur/merchant")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class MerchantController extends BeanCollector
{
    /**
     * @Inject()
     * @var MerchantLogic
     */
    private $merchantLogic;

    /**
     * 获取供货商翻页数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        /**
         * idx页码
         * @var int
         * @sample 1
         */
        $idx = $argument->get('idx', 1);

        /**
         * size每页行数
         * @var int
         * @sample 10
         */
        $size = $argument->get('size', 10);

        /**
         * 供货商名称
         * @var string
         */
        $mname = $argument->get('mname', '');

        /**
         * 手机号码
         * @var string
         */
        $mobile = $argument->get('mobile', '');

        /**
         * 时间
         * @var array
         */
        $atime = $argument->get('atime', []);

        //组装参数
        $query = [
            'mname' => $mname,
            'mobile' => $mobile,
            'atime' => $atime,
        ];

        //API返回
        /**
         * {
         *  "mid": "5fa36c7263e59c004f7125c2",  //mid
         *  "mname": "小白5",  //供货商名称
         *  "mobile": "13480360262",  //手机号码
         *  "address": [  //地址
         *  "广州1",
         *  "深圳",
         *  "广州2"
         *  ],
         * "atime": "2020-11-05 11:07"  //添加时间
         * }
         */
        return $this->merchantLogic->getPager($query, $idx, $size);
    }

    /**
     * 保存供货商数据
     * @Validate(Method::Post)
     * @Validate("mname", Validate::Required, "供货商姓名不能为空")
     * @Validate("mobile", Validate::Required[Mobile], "手机号码为空或格式不正确")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $acc = Context::get('userId');

        /**
         * 供货商mid
         * @var string
         */
        $mid = $argument->post('mid', '');

        /**
         * 供货商名称
         * @var string
         * @required
         */
        $mname = $argument->post('mname', '');

        /**
         * 手机号码
         * @var string
         * @required
         */
        $mobile = $argument->post('mobile', '');

        /**
         * 地址数组
         * @var array
         */
        $address = $argument->post('address', []);

        //组装参数
        $query = [
            'mid' => $mid,
            'mname' => $mname,
            'mobile' => $mobile,
            'address' => $address,
        ];

        //请求接口
        $this->merchantLogic->save($query, $acc);

        //API返回
        /**
         * {"ok"}
         */
        return 'ok';
    }

    /**
     * 删除供货商地址
     * @Validate(Method::Post)
     * @Validate("mid", Validate::Required)
     * @Validate("address", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        /**
         * 供货商mid
         * @var string
         * @required
         */
        $mid = $argument->post('mid', '');

        /**
         * 地址
         * @var string
         * @required
         */
        $address = $argument->post('address', '');

        //请求接口
        $this->merchantLogic->delete($mid, $address);

        //API返回
        /**
         * {"ok"}
         */
        return 'ok';
    }

    /**
     * 获取供货商详情数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array|bool|mixed
     * @throws
     */
    public function info(Argument $argument)
    {
        /**
         * 供货商mid
         * @var string
         * @required
         */
        $mid = $argument->get('mid', '');

        //API返回
        /**
         * {
         *  "mname": "淘宝",
         *  "mobile": "13489360262",
         *  "address": [
         *      "深圳",
         *      "广州2"
         *  ]
         * }
         */
        return $this->merchantLogic->getInfo($mid);
    }
}