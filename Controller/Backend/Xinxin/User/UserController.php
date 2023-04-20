<?php
namespace App\Module\Sale\Controller\Backend\Xinxin\User;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\Xinxin\User\UserLogic;
use App\Service\Crm\CrmStaffInterface;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * @Controller("/sale/backend/xinxin/user")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class UserController extends BeanCollector
{
    /**
     * @Inject()
     * @var UserLogic
     */
    private $userLogic;

    /**
     * @Reference()
     * @var CrmStaffInterface
     */
    private $crmStaffInterface;

    /**
     * 获取用户列表数据
     * @param Argument $argument
     * @return mixed
     */
    public function list(Argument $argument)
    {
        //外部参数
        $query = [
            'uname' => $argument->get('uname', ''),
            'loginid' => $argument->get('loginid', ''),
            'mobile' => $argument->get('mobile', ''),
            'fromstaff' => $argument->get('fromstaff', ''),
            'regtime' => $argument->get('regtime', []),
            'unlogin' => $argument->get('unlogin', 0),
        ];
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //获取数据
        $resp = $this->userLogic->getPager($query, $idx, $size);

        //返回
        return $resp;
    }

    /**
     * 获取员工列表
     * @return array|bool
     */
    public function staffList()
    {
        //返回
        return $this->crmStaffInterface->getList([], 'acc,sname');
    }

    /**
     * 获取用户详情
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function userInfo(Argument $argument){
        //外部参数
        $acc = $argument->get('acc', '');

        //获取数据
        $resp = $this->userLogic->getUserInfo($acc);

        //返回
        return $resp;
    }

    /**
     * 获取关注数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function favList(Argument $argument)
    {
        //外部参数
        $acc = $argument->get('acc', '');
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //获取数据
        $resp = $this->userLogic->getFavList($acc, $idx, $size);

        //返回
        return $resp;
    }

    /**
     * 获取搜索数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function searchList(Argument $argument)
    {
        //外部参数
        $acc = $argument->get('acc', '');
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //获取数据
        $resp = $this->userLogic->getSearchList($acc, $idx, $size);

        //返回
        return $resp;
    }

    /**
     * 获取搜索数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function visitList(Argument $argument)
    {
        //外部参数
        $acc = $argument->get('acc', '');
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //获取数据
        $resp = $this->userLogic->getVisitList($acc, $idx, $size);

        //返回
        return $resp;
    }

    /**
     * 获取未中标数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function unBidList(Argument $argument)
    {
        //外部参数
        $acc = $argument->get('acc', '');
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //获取数据
        $resp = $this->userLogic->getUnBidList($acc, $idx, $size);

        //返回
        return $resp;
    }

    /**
     * 获取中标订单数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function orderList(Argument $argument)
    {
        //外部参数
        $acc = $argument->get('acc', '');
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //获取数据
        $resp = $this->userLogic->getOrderList($acc, $idx, $size);

        //返回
        return $resp;
    }

    /**
     * 获取订单对应商品数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function goodsList(Argument $argument){
        //外部参数
        $okey = $argument->get('okey', '');
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //获取数据
        $resp = $this->userLogic->getGoodsList($okey, $idx, $size);

        //返回
        return $resp;
    }
}
