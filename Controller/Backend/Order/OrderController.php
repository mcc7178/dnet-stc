<?php
namespace App\Module\Sale\Controller\Backend\Order;

use App\Module\Sale\Logic\Backend\Order\OrderLogic;
use App\Module\Sale\Logic\Backend\Order\OrderRefreshLogic;
use Swork\Context;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 订单接口
 * @Controller("/sale/backend/order")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OrderController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderLogic
     */
    private $orderLogic;

    /**
     * @Inject()
     * @var OrderRefreshLogic
     */
    private $orderRefreshLogic;

    /**
     * 获取翻页列表数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        //分页参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //查询参数
        $query = $this->getPagerQuery($argument);

        //获取数据
        $pager = $this->orderLogic->getPager($query, $size, $idx);

        //返回
        return $pager;
    }

    /**
     * 获取翻页总数
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //查询参数
        $query = $this->getPagerQuery($argument);

        //获取数据
        $count = $this->orderLogic->getCount($query);

        //返回
        return $count;
    }

    /**
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        $query =  [
            'bcode' => $argument->get('bcode', ''),
            'okey' => $argument->get('okey', ''),
            'uname' => $argument->get('uname', ''),
            'rectel' => $argument->get('rectel', ''),
            'recver' => $argument->get('recver', ''),
            'tid' => $argument->get('tid', 0),
            'plat' => $argument->get('plat', 0),
            'src' => $argument->get('src', 0),
            'srcplat' => $argument->get('srcplat', 0),
            'ostat' => $argument->get('ostat', 0),
            'dlyway' => $argument->get('dlyway', 0),
            'otime' => $argument->get('otime', []),
            'wid' => $argument->get('wid', 0),
        ];

        // 权限，读取指定仓库
        if ($query['wid'] == 1)
        {
            $query['wid'] = ['in' => Context::get('whsPermission')];
        }

        return $query;
    }

    /**
     * 获取订单详请
     * @Validate(Method::Get)
     * @Validate("okey",Validate::Required,"缺少订单编号参数")
     * @param Argument $argument
     * @return bool|mixed
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $okey = $argument->get('okey', '');

        //获取数据
        $info = $this->orderLogic->getInfo($okey);

        //返回
        return $info;
    }

    /**
     * 删除订单
     * @Validate(Method::Post)
     * @Validate("okey",Validate::Required,"缺少订单编号参数")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $okey = $argument->post('okey', '');

        //删除操作
        $this->orderLogic->delete($okey);

        //返回
        return 'success ';
    }

    /**
     * 扫码搜索订单
     * @Validate(Method::Get)
     * @Validate("bcode",Validate::Required,"缺少库存编号参数")
     * @param Argument $argument
     * @return array|bool
     * @throws
     */
    public function search(Argument $argument)
    {
        //外部参数
        $type = $argument->get('type', 0);
        $bcode = $argument->get('bcode', '');

        //获取商品数据
        $info = $this->orderLogic->getSearch($type, $bcode);

        //返回
        return $info;
    }

    /**
     * 导出订单
     * @param Argument $argument
     * @return array
     */
    public function export(Argument $argument)
    {
        //查询参数
        $query = $this->getPagerQuery($argument);

        //导出表格内容
        $list = $this->orderLogic->export($query);

        //返回
        return $list;
    }

    /***
     * 取消订单
     * @Validate(Method::Post)
     * @Validate("okey",Validate::Required,"缺少订单ID参数")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function cancel(Argument $argument)
    {
        //外部参数
        $okey = $argument->post('okey', '');

        //取消操作
        $this->orderLogic->cancel($okey);

        //返回
        return 'success ';
    }

    /**
     * 获取修改订单初始数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function getEditInfo(Argument $argument)
    {
        //外部参数
        $okey = $argument->get('okey', '');

        //获取商品数据
        $info = $this->orderLogic->getEditInfo($okey);

        //返回
        return $info;
    }

    /**
     * 保存修改订单初始数据
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function saveEditInfo(Argument $argument)
    {
        //外部参数
        $query = [
            'okey' => $argument->post('okey', ''),
            'dlyway' => $argument->post('dlyway', 0),
            'recver' => $argument->post('recver', ''),
            'rectel' => $argument->post('rectel', ''),
            'recreg' => $argument->post('exprgn', 0),
            'recdtl' => $argument->post('recdtl', ''),
            'expway' => $argument->post('expway', 0),
        ];

        //保存修改数据
        $this->orderLogic->saveEditInfo($query);

        return 'ok';
    }

    /**
     * 实时刷新淘宝订单状态
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function refresh(Argument $argument)
    {
        //外部参数
        $tid = $argument->get('tid', '');

        //刷新
        $info = $this->orderRefreshLogic->refresh($tid);

        //返回
        return $info;
    }
}