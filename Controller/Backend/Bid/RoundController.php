<?php
namespace App\Module\Sale\Controller\Backend\Bid;

use App\Module\Sale\Logic\Backend\Bid\BidRoundLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 竞拍场次相关接口
 * @Controller("/sale/backend/bid/round")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class RoundController extends BeanCollector
{
    /**
     * @Inject()
     * @var BidRoundLogic
     */
    private $bidRoundLogc;

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
        $query = $this->getQuery($argument);

        //返回
        return $this->bidRoundLogc->getPager($query, $size, $idx);
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
        $query = $this->getQuery($argument);

        //返回
        return $this->bidRoundLogc->getCount($query);
    }

    /** 导出数据
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function export(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //查询参数
        $query = $this->getQuery($argument);

        //返回
        return $this->bidRoundLogc->export($query, $acc);
    }

    /**
     * 获取场次列表数据（即转场列表）
     * @Validate(Method::Get)
     * @Validate("date", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function list(Argument $argument)
    {
        //外部参数
        $date = $argument->get('date', '');
        $rid = $argument->get('rid', '');

        //返回
        return $this->bidRoundLogc->getList($date, $rid);
    }

    /**
     * 获取编辑场次详情数据
     * @Validate(Method::Get)
     * @Validate("rid", Validate::Required)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function edit(Argument $argument)
    {
        //外部参数
        $rid = $argument->get('rid', '');

        //返回
        return $this->bidRoundLogc->getEditInfo($rid);
    }

    /**
     * 保存新增或编辑场次数据
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //上下文参数
        $uid = Context::get('acc');
        $plat = Context::get('plat');

        //外部参数
        $data = [
            'rid' => $argument->post('rid', ''),
            'rname' => $argument->post('rname', ''),
            'stime' => $argument->post('stime', ''),
            'len' => $argument->post('len', 0),
            'limited' => $argument->post('limited', 0),
            'tid' => $argument->post('tid', 0),
        ];

        //保存数据
        $this->bidRoundLogc->save($data, $uid, $plat);

        //返回
        return 'success';
    }

    /**
     * 复制指定日期的场次数据（即批量新增）
     * @Validate("cdate", Validate::Required)
     * @Validate("pdate", Validate::Required)
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function copy(Argument $argument)
    {
        //外部参数
        $cdate = $argument->post('cdate', '');
        $pdate = $argument->post('pdate', '');

        //复制数据
        $this->bidRoundLogc->copy($cdate, $pdate);

        //返回
        return 'success';
    }

    /**
     * 公开或取消公开场次（需要支持批量）
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function stat(Argument $argument)
    {
        //外部参数
        $rid = $argument->post('rid', '');
        $date = $argument->post('date', '');
        $stat = $argument->post('stat', 0);
        $type = $argument->post('type', 0);

        //公开场次
        $this->bidRoundLogc->changeStat($rid, $date, $stat, $type);

        //返回
        return 'success';
    }

    /**
     * 删除指定场次数据
     * @Validate(Method::Post)
     * @Validate("rid", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function del(Argument $argument)
    {
        //外部参数
        $rid = $argument->post('rid', '');

        //删除数据
        $this->bidRoundLogc->delete($rid);

        //返回
        return 'success';
    }

    /**
     * 确认价格
     * @Validate(Method::Post)
     * @Validate("rid", Validate::Required)
     * @Validate("stat", Validate::Required)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function confirm(Argument $argument)
    {
        //外部参数
        $rid = $argument->post('rid', '');
        $stat = $argument->post('stat', '');

        //删除数据
        $this->bidRoundLogc->confirm($rid, $stat);

        //返回
        return 'success';
    }

    /**
     * 场次数据统计
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function statistics(Argument $argument)
    {
        //外部参数
        $rid = $argument->get('rid', '');

        //返回
        return $this->bidRoundLogc->statistics($rid);
    }

    /**
     * 批量公开/转场场次日期数据
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function batch(Argument $argument)
    {
        //外部参数
        $rid = $argument->get('rid', '');

        //返回
        return $this->bidRoundLogc->batch($rid);
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

        //返回
        return $this->bidRoundLogc->getInfo($rid);
    }

    /**
     * 获取外部参数
     * @param Argument $argument
     * @return array
     */
    private function getQuery(Argument $argument)
    {
        //外部参数
        $query = [
            'rname' => $argument->get('rname', ''),
            'bcode' => $argument->get('bcode', ''),
            'rtime' => $argument->get('rtime', []),
            'infield' => $argument->get('infield', 0),
        ];

        //返回
        return $query;
    }
}