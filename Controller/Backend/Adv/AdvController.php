<?php

namespace App\Module\Sale\Controller\Backend\Adv;

use App\Module\Sale\Logic\Backend\Adv\AdvertLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;

/**
 * 广告位
 * Class AdvController
 * @Controller("/sale/backend/adv")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class AdvController extends BeanCollector
{
    /**
     * @Inject()
     * @var AdvertLogic
     */
    private $advertLogic;

    /**
     * 广告内容列表
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 0);
        $query = [
            'title' => $argument->get('title', '')
        ];

        //获取数据
        $list = $this->advertLogic->getPager($query, $idx, 25);

        //返回
        return $list;
    }

    /**
     * 获取广告列表总数量
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = [
            'title' => $argument->get('title', '')
        ];

        //获取数据
        $count = $this->advertLogic->getCount($query);

        //返回
        return $count;
    }

    /**
     * 获取广告详情
     * @Validate(Method::Get)
     * @Validate("aid",Validate::Required,"缺少广告ID参数")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $aid = $argument->get('aid', '');

        //获取数据
        $info = $this->advertLogic->getInfo($aid);

        //返回
        return $info;
    }

    /**
     * 设置广告状态
     * @Validate(Method::Post)
     * @Validate("aid",Validate::Required,"缺少广告ID参数")
     * @param Argument $argument
     * @return boolean
     * @throws
     */
    public function isatv(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $aid = $argument->post('aid', '');
        $stat = $argument->post('stat', 0);

        //设置状态
        $this->advertLogic->setIsAtv($acc, $aid, $stat);

        //返回
        return 'success';
    }

    /**
     * 保存广告内容
     * @Validate(Method::Post)
     * @Validate("title",Validate::Required,"缺少广告名称参数")
     * @Validate("stime",Validate::Required,"缺少发布开始时间参数")
     * @Validate("etime",Validate::Required,"缺少发布结束时间参数")
     * @Validate("distpos",Validate::Required,"缺少投放位置参数")
     * @Validate("distchn",Validate::Required,"缺少投放渠道参数")
     * @param Argument $argument
     * @return boolean
     * @throws
     */
    public function save(Argument $argument)
    {
        //组装参数
        $query = [
            'uacc' => Context::get('acc'),
            'aid' => $argument->post('aid', ''),
            'title' => $argument->post('title', ''),
            'content' => $argument->post('content', ''),
            'imgsrc' => $argument->post('imgsrc', ''),
            'distchn' => $argument->post('distchn', []),
            'distpos' => $argument->post('distpos', 0),
            'stime' => $argument->post('stime', ''),
            'etime' => $argument->post('etime', ''),
        ];

        //保存数据
        $res = $this->advertLogic->save($query);

        //返回
        return $res;
    }

    /**
     * 删除广告
     * @Validate(Method::Post)
     * @Validate("aid",Validate::Required,"缺少广告ID参数")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $aid = $argument->post('aid', '');

        //删除广告
        $this->advertLogic->delete($aid);

        //返回
        return 'success';
    }
}