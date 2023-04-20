<?php

namespace App\Module\Sale\Controller\Backend\Xinxin\Atv;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Backend\Xinxin\Atv\ActiveLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 拼团活动
 * @Controller("/sale/backend/xinxin/atv/active")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class ActiveController extends BeanCollector
{
    /**
     * @Inject()
     * @var ActiveLogic
     */
    private $avtiveLogic;

    /**
     * 拼团活动列表
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
        $query = [
            'gname' => $argument->get('gname', ''),
            'mname' => $argument->get('mname', ''),
            'stime' => $argument->get('stime', []),
            'atime' => $argument->get('atime', []),
            'stat' => $argument->get('stat', 0),
        ];

        //返回
        return $this->avtiveLogic->getPager($query, $idx, $size);
    }

    /**
     * 拼团活动详情
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $pkey = $argument->get('gkey', '');

        //返回
        return $this->avtiveLogic->getInfo($pkey);
    }

    /**
     * 保存活动信息
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $gkey = $argument->post('gkey', '');
        $query = [
            'gname' => $argument->post('gname', ''),
            'stime' => $argument->post('stime', ''),
            'etime' => $argument->post('etime', ''),
            'mid' => $argument->post('mid', 0),
            'level' => $argument->post('level', 0),
            'mdram' => $argument->post('mdram', 0),
            'mdcolor' => $argument->post('mdcolor', 0),
            'mdnet' => $argument->post('mdnet', 0),
            'mdofsale' => $argument->post('mdofsale', 0),
            'oprice' => $argument->post('oprice', 0),
            'gprice' => $argument->post('gprice', 0),
            'groupqty' => $argument->post('groupqty', 0),
            'limitqty' => $argument->post('limitqty', 0),
            'groupimg' => $argument->post('groupimg', ''),
            'shareimg' => $argument->post('shareimg', ''),
            'describe' => $argument->post('describe', ''),
        ];

        //返回
        return $this->avtiveLogic->save($gkey, $query);
    }

    /**
     * 删除活动信息
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $gkey = $argument->post('gkey', '');

        //返回
        return $this->avtiveLogic->delete($gkey);
    }

    /**
     * 启用或禁用活动
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function update(Argument $argument)
    {
        //外部参数
        $gkey = $argument->post('gkey', '');

        //返回
        return $this->avtiveLogic->update($gkey);
    }

    /**
     * 通过输入的机型名称模糊搜索
     * @param Argument $argument
     * @throws
     * @return mixed
     */
    public function getModelNames(Argument $argument)
    {
        //外部参数
        $mname = $argument->get('mname', '');

        //返回
        return $this->avtiveLogic->getModelNames($mname);

    }

    /**
     * 根据不同机型获取不同类目
     * @param Argument $argument
     * @return mixed
     */
    public function getModelItems(Argument $argument)
    {
        //外部参数
        $mid = $argument->get('mid', 0);

        //返回
        return $this->avtiveLogic->getModelItems($mid);
    }
}
