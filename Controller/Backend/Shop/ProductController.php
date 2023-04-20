<?php
namespace App\Module\Sale\Controller\Backend\Shop;

use App\Module\Sale\Logic\Backend\Shop\ShopProductLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 上架商品
 * @Controller("/sale/backend/shop/product")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class ProductController extends BeanCollector
{
    /**
     * @Inject()
     * @var ShopProductLogic
     */
    private $shopProductLogic;

    /**
     * 待上架商品翻页数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $size = $argument->get('size', 10);
        $idx = $argument->get('idx', 1);

        //分页参数
        $query = [
            'whsPermission' => Context::get('whsPermission'),
            'bcode' => $argument->get('bcode', ''),
            'plat' => $argument->get('plat', 0),
            'oname' => $argument->get('oname', ''),
            'bid' => $argument->get('bid', 0),
            'mid' => $argument->get('mid', 0),
            'level' => $argument->get('level', 0),
            'nobids' => $argument->get('nobids', 0),
            'upshelfs' => $argument->get('upshelfs', 0),
        ];

        //获取数据
        $pager = $this->shopProductLogic->getPager($query, $size, $idx);

        //返回
        return $pager;
    }

    /**
     * 待上架商品总数
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     * @throws
     */
    public function count(Argument $argument)
    {
        //分页参数
        $query = [
            'whsPermission' => Context::get('whsPermission'),
            'bcode' => $argument->get('bcode', ''),
            'plat' => $argument->get('plat', 0),
            'oname' => $argument->get('oname', ''),
            'bid' => $argument->get('bid', 0),
            'mid' => $argument->get('mid', 0),
            'level' => $argument->get('level', 0),
            'nobids' => $argument->get('nobids', 0),
            'upshelfs' => $argument->get('upshelfs', 0),
        ];

        //返回
        return $this->shopProductLogic->getCount($query);
    }

    /**
     * 上架商品
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');
        $whsPermission = Context::get('whsPermission');

        //外部参数
        $pids = $argument->post('pids', '');
        $type = $argument->post('type', 1);

        //查询参数
        $query = $argument->post('query', []);

        //补充分仓权限
        $query['whsPermission'] = $whsPermission;

        //上架
        $this->shopProductLogic->save($type, $pids, $query, $acc);

        //返回
        return 'success';
    }
}