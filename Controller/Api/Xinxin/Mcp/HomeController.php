<?php
namespace App\Module\Sale\Controller\Api\Xinxin\Mcp;

use App\Module\Sale\Logic\Api\Xinxin\Mcp\Order\GroupLogic;
use App\Module\Sale\Logic\Api\Xinxin\SaleLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Module\Api\Middleware\ContextMiddleware;
use App\Module\Api\Middleware\LoginMiddleware;
use App\Module\Api\Middleware\ResultMiddleware;
use App\Module\Api\Middleware\SignMiddleware;

/**
 * 新新二手机-首页
 * @Controller("/sale/api/xinxin/mcp/home")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ResultMiddleware::class)
 */
class HomeController extends BeanCollector
{
    /**
     * @Inject()
     * @var SaleLogic
     */
    private $saleLogic;

    /**
     * @Inject()
     * @var GroupLogic
     */
    private $groupLogic;

    /**
     * 首页特惠竞卖商品列表
     * @return mixed
     * @throws
     */
    public function auction()
    {
        //获取数据
        $list = $this->saleLogic->auction();

        //返回
        return $list;
    }

    /**
     * 首页一口价热卖商品列表
     * @return mixed
     */
    public function todaySale()
    {
        //获取数据
        $list = $this->saleLogic->todaySale();

        //返回
        return $list;
    }

    /**
     * 首页热门已售商品
     * @return mixed
     */
    public function hotSale()
    {
        //获取数据
        $list = $this->saleLogic->hotSale();

        //返回
        return $list;
    }

    /**
     * 首页-今日竞拍
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function sale(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');

        //获取数据
        $result = $this->saleLogic->getList($uid);

        //返回数据
        return $result;
    }

    /**
     * 设置提醒
     * @Validate(Method::Post)
     * @Validate("tid",Validate::Ins[101|103])
     * @param Argument $argument
     * @return boolean
     * @throws
     */
    public function remind(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');
        $rtype = $argument->post('tid', 103);

        //获取数据
        $this->saleLogic->remind($uid, $rtype);

        //返回数据
        return 'success';
    }

    /**
     * 竞拍排行
     */
    public function bidrank()
    {
        //获取数据
        $result = $this->saleLogic->bidrank();

        //返回数据
        return $result;
    }

    /**
     * 首页banner-轮播图
     * @return array
     */
    public function banner()
    {
        //获取数据
        $result = $this->saleLogic->banner();

        //返回数据
        return $result;
    }

    /**
     * 等级说明列表
     * @return array
     */
    public function levelList()
    {
        //获取数据
        $result = $this->saleLogic->levelList();

        //返回数据
        return $result;
    }

    /**
     * 是否已经关注公众号
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function issub(Argument $argument)
    {
        //外部参数
        $uid = Context::get('userId');

        //获取数据
        $result = $this->saleLogic->isSub($uid);

        //返回数据
        return $result;
    }

    /**
     * 拼团活动数据
     * @return mixed
     * @throws
     */
    public function group()
    {
        //获取数据
        $result = $this->groupLogic->group();

        //返回数据
        return $result;
    }

    /**
     * 拼团详情
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function groupinfo(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);
        $gkey = $argument->post('gkey', '');

        //获取数据
        $result = $this->groupLogic->groupInfo($uid, $gkey);

        //返回数据
        return $result;
    }

    /**
     * 创建订单
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function createorder(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);
        $gkey = $argument->post('gkey', '');
        $num = $argument->post('num', 0);

        //获取数据
        $result = $this->groupLogic->createorder($uid, $gkey, $num);

        //返回数据
        return $result;
    }

    /**
     * 支付详情页面
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function payinfo(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);
        $oid = $argument->post('oid', '');

        //获取数据
        $result = $this->groupLogic->payInfo($uid, $oid);

        //返回数据
        return $result;
    }

    /**
     * 支付结果查询
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function payresult(Argument $argument)
    {
        //外部参数
        $args = [
            'uid' => $argument->post('uid', 0),
            'oid' => $argument->post('oid', '')
        ];

        //获取数据
        $result = $this->groupLogic->payResult($args);

        //返回数据
        return $result;
    }

    /**
     * 验证用户购买权限
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function checkpermis(Argument $argument)
    {
        //外部参数
        $uid = $argument->post('uid', 0);
        $gkey = $argument->post('gkey', '');
        $num = $argument->post('num', 0);

        //获取数据
        $result = $this->groupLogic->checkGroupBuyPermis($uid, $gkey, $num);

        //返回数据
        return $result;
    }
}