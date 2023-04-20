<?php
namespace App\Module\Sale\Controller\Backend\Bid;

use App\Module\Sale\Logic\Backend\Bid\InfieldRoundLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 内部场次相关接口
 * @Controller("/sale/backend/bid/infield")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class InfieldController extends BeanCollector
{
    /**
     * @Inject()
     * @var InfieldRoundLogic
     */
    private $infieldRoundLogic;

    /**
     * 获取场次翻页列表数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        //分页参数
        $size = $argument->get('size', 25);
        $idx = $argument->get('idx', 1);

        //查询参数
        $query = [
            'rname' => $argument->get('rname', ''),
            'bcode' => $argument->get('bcode', ''),
            'rtime' => $argument->get('rtime', []),
            'stat' => $argument->get('stat', 0),
        ];

        //获取数据
        $pager = $this->infieldRoundLogic->getPager($query, $size, $idx);

        //返回
        return $pager;
    }

    /**
     * 获取场次条数
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //查询参数
        $query = [
            'rname' => $argument->get('rname', ''),
            'bcode' => $argument->get('bcode', ''),
            'rtime' => $argument->get('rtime', []),
            'stat' => $argument->get('stat', 0),
        ];

        //获取数据
        $pager = $this->infieldRoundLogic->getCount($query);

        //返回
        return $pager;
    }

    /**
     * 获取场次信息
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $rid = $argument->get('rid', '');

        //获取数据
        $info = $this->infieldRoundLogic->getInfo($rid);

        //返回
        return $info;
    }
}