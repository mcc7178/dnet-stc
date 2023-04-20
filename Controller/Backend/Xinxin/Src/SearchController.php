<?php

namespace App\Module\Sale\Controller\Backend\Xinxin\Src;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\Xinxin\Src\SearchLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 搜索记录
 * @Controller("/sale/backend/xinxin/src/search")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class SearchController extends BeanCollector
{
    /**
     * @Inject()
     * @var SearchLogic
     */
    private $searchLogic;

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
            'source' => $argument->post('source', 0),
            'stime' => $argument->post('stime', [])
        ];

        //返回
        return $this->searchLogic->getPager($query, $idx, $size);
    }
}
