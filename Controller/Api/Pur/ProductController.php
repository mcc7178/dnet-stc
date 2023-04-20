<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Module\Sale\Logic\Api\Pur\ProductLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Module\Sale\Middleware\Pur\SignMiddleware;
use App\Module\Sale\Middleware\Pur\ContextMiddleware;
use App\Module\Sale\Middleware\Pur\LoginMiddleware;
use App\Middleware\ApiResultFormat;

/**
 * 商品列表
 * @Controller("/sale/api/pur/product")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 * Class ProductController
 * @package App\Module\Sale\Controller\Api\Pur
 */
class ProductController extends BeanCollector
{
    /**
     * @Inject()
     * @var ProductLogic
     */
    private $productLogic;

    /**
     * 获取商品翻页数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $aacc = Context::get('userId');

        /**
         * 是否从首页传数据
         * @var int
         * @sample 1
         */
        $ishome = $argument->get('ishome', 0);

        /**
         * 首页搜索字段（聚合搜索，仅首页使用）
         * @var string
         * @sample 321
         */
        $message = $argument->get('message', '');

        /**
         * 供货商id
         * @var string
         * @sample 321
         */
        $merchant = $argument->get('merchant', '');

        /**
         * 库存编号
         * @var string
         * @sample 321
         */
        $bcode = $argument->get('bcode', '');

        /**
         * 采购单编号
         * @var string
         * @sample 321
         */
        $okey = $argument->get('okey', '');

        /**
         * 需求单编号
         * @var string
         * @sample 321
         */
        $dkey = $argument->get('dkey', '');

        /**
         * 退货单编号
         * @var string
         * @sample 321
         */
        $skey = $argument->get('skey', '');

        /**
         * 搜索商品状态 1已预入库 2已入库 3质检待确认 4已完成 5待退货 6待上架 7已退货 8已销售
         * 需求单单列表跳入商品列表时，传入对应状态
         * @var int
         * @sample 1
         */
        $stat = $argument->get('stat', 0);

        /**
         * 时间搜索类型 时间类型 1入库时间  2质检时间 3采购完成时间  4销售时间
         * @var int
         * @sample 321
         */
        $gtype = $argument->get('gtype', '');

        /**
         * 时间搜索
         * @var string
         * @sample 321
         */
        $gtime = $argument->get('gtime', '');

        //组装数据
        $data = [
            'ishome' => $ishome,
            'merchant' => $merchant,
            'bcode' => $bcode,
            'okey' => $okey,
            'dkey' => $dkey,
            'skey' => $skey,
            'stat' => $stat,
            'gtype' => $gtype,
            'gtime' => $gtime,
            'message' => $message
        ];

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

        //API返回
        /**
         *  {
        "gid": "123456", //
        "okey": "123456789", //归属采购单
        "pkey": "123", //计划单号
        "dkey": "456", //归属采购-需求单编号
        "did": "789",  //归属采购单-需求单ID
        "tid": "987", //归属采购任务
        "merchant": "654", //归属采购商家id
        "aacc": "321",  //采购人
        "bcode": "1902271720065",  //商品库存编号
        "gstat": "预入库", //商品状态
        "gtime1": 1604396051, //预入库时间
        "gtime2": 0, //已入库时间
        "gtime3": 0, //已质检（未确认）时间
        "gtime4": 0, //确认采购（质检后）时间
        "gtime5": 0, //确认退货（质检后）时间
        "atime": 1604396051,  //添加时间
        "manme": "-",  //供货商名称
        "need": "D3/16G/金色/国行/双网通/其他正常",  //需求
        "modelName": "iPhone 5s",  //机型
        "utime": "2020-11-03 17:34",  //期望交互时间
        "gtime": "2020-11-03 17:34" //更新时间
        }
         */

        return $this->productLogic->getPager($aacc, $data, $size, $idx);
    }

    /**
     * 获取商品详情
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function detail(Argument $argument)
    {
        /**
         * 需求商品表id
         * @var string
         * @require
         * @sample 20
         */
        $bcode =  $argument->get('bcode', '');

        /**
         * 采购单号（质检待确认时翻页使用）
         * @var string
         * @require
         * @sample 20
         */
        $okey =  $argument->get('okey', '');

        /**
         * 是否待确认 2是 1否（质检待确认时翻页使用）
         * @var string
         * @require
         * @sample 20
         */
        $stat =  $argument->get('stat', 0);

        //API返回
        /**
        "preBcode": "20111116804824",//上一个库存编码（翻页使用）
        "nextBcode": "",//下一个库存编码（翻页使用）
        "total": 4,//总待确认数量（翻页使用）
        "index": 4,//当前位置（翻页使用）
        "purOdrGood": {
        "gid": "123456",
        "okey": "123456789",
        "pkey": "123",
        "dkey": "456",
        "did": "789",
        "tid": "987",
        "merchant": "654",
        "aacc": "321",
        "bcode": "1902271720065",
        "gstat": 1,
        "gtime1": "2020-11-03 17:34", //预入库时间
        "gtime2": "-", //已入库时间
        "gtime3": "-",  //已质检时间
        "gtime4": "-",
        "gtime5": "-",  //确认退货时间
        "saletime":"-",  //销售时间
        'ftime': "-" //退货时间
        "atime": 1604396051,
        "pname": "iPhone 5s国行 双网通 金色 16G 其他正常",
        "imei": "860481048517792",
        "scost": "-",
        "saleamt": "0.00",
        "merchantName": "-",
        "bname": "苹果",
        "mname": "iPhone 5s",
        "lname": "D3",
        "imgpack": [
        {
        "src": "https://imgdev.sosotec.com/product/1902271720065/2019050916001656772.jpg"
        },
        {
        "src": "https://imgdev.sosotec.com/product/1902271720065/2019050916001729692.jpg"
        }
        ],
        "gstatName": "已预入库",
        "qcReport": "vivo Y83 黑色,4G+64G,移动定制全网通,无账号,屏幕轻微划痕（贴膜不可见）,显示正常,支架小花,机身背部明显划痕,边框细微划痕,无维修,无进水,功能正常"
        },
        "newList": [
        {
        "cname": "版本",
        "moname": "<span style=\"color: #ff0000\">移动版</span>",
        "doname": "双网通"
        },
        {
        "cname": "内存",
        "moname": "<span style=\"color: #ff0000\">4G+64G</span>",
        "doname": "16G"
        },
        {
        "cname": "屏幕外观",
        "moname": "屏幕有轻微划痕",
        "doname": ""
        },
        {
        "cname": "屏幕显示",
        "moname": "显示正常",
        "doname": ""
        },
        {
        "cname": "机身外观",
        "moname": "外观有轻微掉漆或磕碰",
        "doname": ""
        },
        {
        "cname": "维修",
        "moname": "无维修",
        "doname": ""
        },
        {
        "cname": "账号",
        "moname": "账号及开屏密码已注销",
        "doname": ""
        },
        {
        "cname": "销售地",
        "moname": "",
        "doname": "国行"
        },
        {
        "cname": "颜色",
        "moname": "",
        "doname": "金色"
        },
        {
        "cname": "保修",
        "moname": "",
        "doname": "其他正常"
        }
        ]
        }
         */
        return $this->productLogic->getDetail($bcode, $okey, $stat);
    }

    /**
     * 确认采购
     * pur_odr_goods 4、确认采购（质检后）
     * 查询prd_product
     * 新增prd_supply
     * prd_supply prdcost ryccost cost32 pcost == 采购单价
     *
     * 退货
     * pur_odr_goods gstat=5
     *
     */

    /**
     * 退货或者采购按钮
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return boolean
     * @throws
     */
    public function confirm(Argument $argument)
    {
        /**
         * 退货或者采购状态
         * @var int
         * @require
         * @sample 退货:1,采购:2
         */
        $stat = $argument->post('stat',0);

        /**
         * 商品编号
         * @var string
         * @require
         * @sample 123
         */
        $bcode = $argument->post('bcode','');

        //调用函数
        $this->productLogic->confirm($stat,$bcode);

        //API返回
        return 'success';
    }
}