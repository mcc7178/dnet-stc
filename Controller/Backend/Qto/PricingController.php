<?php
namespace App\Module\Sale\Controller\Backend\Qto;

use App\Module\Sale\Logic\Backend\Qto\QtoPricingLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 填价接口
 * @Controller("/sale/backend/qto/pricing")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class PricingController extends BeanCollector
{
    /**
     * @Inject()
     * @var QtoPricingLogic
     */
    private $qtoPricingLogic;

    /**
     * 获取发货单分页
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);
        $query = $this->getPagerQuery($argument);

        //获取数据
        $list = $this->qtoPricingLogic->getPager($query, $size, $idx);

        //返回
        return $list;
    }

    /**
     * 获取分页数量
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //数据条件
        $count = $this->qtoPricingLogic->getPagerCount($query);

        //返回
        return $count;
    }

    /**
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    public function getPagerQuery(Argument $argument)
    {
        return [
            'bcode' => $argument->get('bcode', ''),
            'okey' => $argument->get('okey', ''),
            'bid' => $argument->get('bid', ''),
            'fillstat' => $argument->get('fillstat', 0),
        ];
    }

    /**
     * 商品详情
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function detail(Argument $argument)
    {
        //外部参数
        $pid = $argument->get('pid', '');

        //获取商品详情
        $detail = $this->qtoPricingLogic->getDetail($pid);

        //返回
        return $detail;
    }

    /**
     * 填价
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function fillprc(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $data = [
            'pid' => $argument->post('pid', ''),
            'sprc' => $argument->post('sprc', 0),
            'kprc' => $argument->post('kprc', 0),
            'aprc' => $argument->post('aprc', 0),
        ];

        //填价
        $this->qtoPricingLogic->fillprc($acc, $data);

        //返回
        return 'success';
    }

    /**
     * 获取品牌数据
     * @param Argument $argument
     * @return mixed
     */
    public function brands(Argument $argument)
    {
        //外部参数
        $fillstat = $argument->get('fillstat', 0);

        //获取数据
        $brands = $this->qtoPricingLogic->getBrands($fillstat);

        //返回
        return $brands;
    }
}