<?php

namespace App\Module\Sale\Controller\Backend\Pur;

use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Omr\Middleware\ContextMiddleware;
use App\Module\Sale\Logic\Backend\Pur\CategoryLogic;

/**
 * 电商库存 - 分类管理
 * Class CategoryController
 * @Controller("/sale/backend/pur/category")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class CategoryController extends BeanCollector
{
    /**
     * @Inject()
     * @var CategoryLogic
     */
    private $categoryLogic;

    /**
     * 获取列表数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        // 外部参数
        $idx = $argument->get('idx', 0);
        $size = $argument->get('size', 25);

        // 获取数据
        $list = $this->categoryLogic->getPager($size, $idx);

        // 返回
        return $list;
    }

    /**
     * 获取总数据
     * @param Argument $argument
     * @return int
     * @throws
     */
    public function count(Argument $argument)
    {
        // 获取数据
        $count = $this->categoryLogic->getCount();

        // 返回
        return $count;
    }

    /**
     * 新增分类
     * @param Argument $argument
     * @Validate("cname",Validate::Required)
     * @return int
     * @throws
     */
    public function addCategory(Argument $argument)
    {
        // 外部数据
        $cname = $argument->get('cname', '');
        $acc = Context::get('acc');

        // 操作数据
        $this->categoryLogic->addCategory($cname, $acc);

        // 返回
        return 'ok';
    }

    /**
     * 删除分类
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("cid",Validate::Required)
     * @return int
     * @throws
     */
    public function delCategory(Argument $argument)
    {
        // 外部数据
        $cid = $argument->post('cid', '');
        $acc = Context::get('acc');

        // 操作数据
        $this->categoryLogic->delCategory($cid, $acc);

        // 返回
        return 'ok';
    }
}