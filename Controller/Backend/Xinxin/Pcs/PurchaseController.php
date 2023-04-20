<?php

namespace App\Module\Sale\Controller\Backend\Xinxin\Pcs;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\XinXin\Pcs\PurchaseLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 采购单
 * @Controller("/sale/backend/xinxin/pcs/purchase")
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
     * 采购单列表
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->post('idx', 1);
        $size = $argument->post('size', 25);

        // 查询条件
        $query = [
            'tid' => $argument->post('tid', 0),
            'bid' => $argument->post('bid', 0),
            'mid' => $argument->post('mid', 0),
            'level' => $argument->post('level', 0),
            'stat' => $argument->post('stat', 0),
            'rname' => $argument->post('rname', ''),
            'mobile' => $argument->post('mobile', ''),
            'creason' => $argument->post('cause', 0),
            'atime' => $argument->post('atime', []),
            'expired' => $argument->post('expired', []),
            'chktime' => $argument->post('chktime', []),
            'pcstime' => $argument->post('pcstime', []),
            'ctime' => $argument->post('ctime', []),
        ];

        //返回
        return $this->purchaseLogic->getPager($query, $idx, $size);
    }

    /**
     * 采购单详情
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $pkey = $argument->post('pkey', 0);

        //返回
        return $this->purchaseLogic->getInfo($pkey);
    }

    /**
     * 不同状态采购单数量
     * @param Argument $argument
     * @return mixed
     */
    public function loadNumber(Argument $argument)
    {
        return $this->purchaseLogic->loadNumber();
    }

    /**
     * 获取采购单操作流水及备注列表
     * @param Argument $argument
     * @throws
     * @return mixed
     */
    public function water(Argument $argument)
    {
        //外部参数
        $pkey = $argument->post('pkey', 0);

        //返回
        return $this->purchaseLogic->getWater($pkey);
    }

    /**
     * 采购单挂起
     * @param Argument $argument
     * @return mixed
     * @throws
     * @return
     */
    public function lock(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', 0);
        $rmk = $argument->post('rmk', '');

        //返回
        return $this->purchaseLogic->Lock($acc, $pkey, $rmk);
    }

    /**
     * 提交采购单
     * @param Argument $argument
     * @return mixed
     * @throws
     * @return
     */
    public function submit(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', 0);
        $rmk = $argument->post('rmk', '');

        //返回
        return $this->purchaseLogic->submit($acc, $pkey, $rmk);
    }

    /**
     * 采购单转给客服
     * @param Argument $argument
     * @return mixed
     * @throws
     * @return
     */
    public function transfer(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', 0);
        $rmk = $argument->post('rmk', '');

        //返回
        return $this->purchaseLogic->transfer($acc, $pkey, $rmk);
    }

    /**
     * 采购单采购成功
     * @param Argument $argument
     * @return mixed
     * @throws
     * @return
     */
    public function success(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', 0);
        $rmk = $argument->post('rmk', '');

        //返回
        return $this->purchaseLogic->success($acc, $pkey, $rmk);
    }

    /**
     * 取消采购单
     * @param Argument $argument
     * @return mixed
     * @throws
     * @return
     */
    public function cancel(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pkey = $argument->post('pkey', 0);
        $rmk = $argument->post('rmk', '');
        $reason = $argument->post('reason', 0);

        //返回
        return $this->purchaseLogic->cancel($acc, $pkey, $rmk, $reason);
    }
}
