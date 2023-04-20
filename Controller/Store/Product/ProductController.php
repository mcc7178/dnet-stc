<?php
namespace App\Module\Sale\Controller\Store\Product;

use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use App\Module\Sale\Logic\Store\Product\ProductLogic;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * @Controller("/sale/store/product")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class ProductController extends BeanCollector
{
    /**
     * @Inject()
     * @var ProductLogic
     */
    private $productLogic;

    /**
     * 获取分页参数
     * @param Argument $argument
     * @return array
     */
    private function getPagerParams(Argument $argument)
    {
        return [
            'stat' => $argument->get('stat', 0),
            'bcode' => $argument->get('bcode', ''),
            'plat' => $argument->get('plat', 0),
            'bid' => $argument->get('bid', 0),
            'mid' => $argument->get('mid', 0),
            'level' => $argument->get('level', 0),
            'ltime' => $argument->get('ltime', []),
        ];
    }

    /**
     * 商品列表
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        // 查询条件
        $query = $this->getPagerParams($argument);

        //返回
        return $this->productLogic->getPager($query, $size, $idx);
    }

    /**
     * 获取产品总条数
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $params = $this->getPagerParams($argument);

        //返回
        return $this->productLogic->getCount($params);
    }

    /**
     * 获取详情
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     * @throws
     */
    public function detail(Argument $argument)
    {
        //外部参数
        $pid = $argument->get('pid', '');

        //返回
        return $this->productLogic->getDetail($pid);
    }

    /**
     * 商品导出
     * @param Argument $argument
     * @return array
     */
    public function export(Argument $argument)
    {
        //外部参数
        $params = $this->getPagerParams($argument);
        $idx = 0;
        $size = 0;

        //返回
        return $this->productLogic->export($params,$size,$idx);
    }

    /**
     * 品牌列表
     * @return array
     */
    public function brandList()
    {
        return $this->productLogic->getBrandList();
    }
}