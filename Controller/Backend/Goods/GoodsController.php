<?php

namespace App\Module\Sale\Controller\Backend\Goods;

use App\Module\Sale\Logic\Backend\Goods\GoodsLogic;
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
 * 商品管理
 * Class GoodsController
 * @Controller("/sale/backend/goods")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class GoodsController extends BeanCollector
{
    /**
     * @Inject()
     * @var GoodsLogic
     */
    private $goodsLogic;

    /**
     * 商品列表
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 0);
        $query = $this->getPagerQuery($argument);

        //获取数据
        $list = $this->goodsLogic->getPager($query, $idx, 25);

        //返回
        return $list;
    }

    /**
     * 商品列表总数量
     * @Validate(Method::Get)
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //获取数据
        $count = $this->goodsLogic->getCount($query);

        //返回
        return $count;
    }

    /**
     * 获取翻页查询字段
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        //查询条件
        $query = [
            'whs' => $argument->get('whs', 0),
            'src' => $argument->get('src', ''),
            'bcode' => $argument->get('bcode', ''),
            'plat' => $argument->get('plat', 0),
            'oname' => $argument->get('oname', ''),
            'bid' => $argument->get('bid', 0),
            'mid' => $argument->get('mid', 0),
            'level' => $argument->get('level', 0),
            'stcstat' => $argument->get('stcstat', 0),
            'stcstats' => $argument->get('stcstats', []),
            'nobids' => $argument->get('nobids', 0),
            'onshelf' => $argument->get('onshelf', 0),
            'timetype' => $argument->get('timetype', ''),
            'date' => $argument->get('date', []),
            'memory' => $argument->get('memory', 0),
        ];

        //只能读取指定仓库的数据
        if (empty($query['whs']) || !in_array($query['whs'], Context::get('whsPermission')))
        {
            $query['whs'] = Context::get('whsPermission');
        }

        //返回
        return $query;
    }

    /**
     * 获取商品详情
     * @Validate(Method::Get)
     * @Validate("pid",Validate::Required,"缺少商品ID参数")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $type = $argument->get('type', '');
        $pid = $argument->get('pid', '');
        $sid = $argument->get('sid', '');

        //获取数据
        $info = $this->goodsLogic->getInfo($type, $pid, $sid);

        //返回
        return $info;
    }

    /**
     * 出价记录列表
     * @Validate(Method::Get)
     * @Validate("pid",Validate::Required,"缺少商品ID参数")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function report(Argument $argument)
    {
        //外部参数
        $pid = $argument->get('pid', '');

        //获取质检报告
        $list = $this->goodsLogic->getReport($pid);

        //返回
        return $list;
    }

    /**
     * 出价记录列表
     * @Validate(Method::Get)
     * @Validate("pid",Validate::Required,"缺少商品ID参数")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function price(Argument $argument)
    {
        //外部参数
        $pid = $argument->get('pid', '');

        //获取出价记录列表
        $list = $this->goodsLogic->getPriceList($pid);

        //返回
        return $list;
    }

    /**
     * 操作流水列表
     * @Validate(Method::Get)
     * @Validate("pid",Validate::Required,"缺少商品ID参数")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function water(Argument $argument)
    {
        //外部参数
        $pid = $argument->get('pid', '');

        //获取数据
        $list = $this->goodsLogic->getWaterList($pid);

        //返回
        return $list;
    }

    /**
     * 商品图片
     * @Validate(Method::Get)
     * @Validate("pid",Validate::Required,"缺少商品ID参数")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function photos(Argument $argument)
    {
        //外部参数
        $pid = $argument->get('pid', '');

        //获取数据
        $list = $this->goodsLogic->getPhotos($pid);

        //返回
        return $list;
    }

    /**
     * 处理商品图片
     * @Validate(Method::Post)
     * @Validate("pid",Validate::Required,"缺少商品ID参数")
     * @Validate("photo",Validate::Url,"缺少图片路径")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function matting(Argument $argument)
    {
        //外部参数
        $pid = $argument->post('pid', '');
        $photo = $argument->post('photo', '');

        //获取数据
        $list = $this->goodsLogic->matting($pid, $photo);

        //返回
        return $list;
    }

    /**
     * 自建订单删除商品
     * @Validate(Method::Post)
     * @Validate("gid",Validate::Required,"缺少订单商品ID参数")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $gid = $argument->post('gid', '');

        //取消操作
        $this->goodsLogic->delete($gid);

        //返回
        return 'success ';
    }

    /**
     * 下载图片
     * @param Argument $argument
     * @throws
     */
    public function downImg(Argument $argument)
    {
        $fileName = $argument->get('filename', '');//下载名称
        $imgSrc = $argument->get('imgsrc', '');//全路径图片地址
        $data = file_get_contents($imgSrc);

        $argument->download($data, $fileName);
    }

    /**
     * 新增备注
     * @param Argument $argument
     * @Validate("rmk",Validate::Required)
     * @Validate("bcode",Validate::Required)
     * @return string
     * @throws
     */
    public function addWater(Argument $argument)
    {
        // 外部参数
        $rmk = $argument->get('rmk', '');
        $bcode = $argument->get('bcode', '');
        $acc = Context::get('acc');

        // 操作数据
        $this->goodsLogic->addWater($rmk, $bcode, $acc);

        // 返回
        return 'ok';
    }

    /**
     * 删除备注
     * @param Argument $argument
     * @Validate(Method::Post)
     * @Validate("wid",Validate::Required)
     * @return string
     * @throws
     */
    public function delWater(Argument $argument)
    {
        // 外部参数
        $id = $argument->post('wid', '');
        $acc = Context::get('acc');

        // 操作数据
        $this->goodsLogic->delWater($id, $acc);

        // 返回
        return 'ok';
    }
}