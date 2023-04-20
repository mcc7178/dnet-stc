<?php
namespace App\Module\Sale\Controller\Backend\Pub;

use App\Model\Sys\SysExpressModel;
use App\Model\Sys\SysPlatModel;
use App\Model\Sys\SysWhouseModel;
use App\Module\Pub\Data\OdrDictData;
use App\Module\Qto\Data\QtoBrandData;
use App\Module\Qto\Data\QtoModelData;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\BeanCollector;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use Swork\Server\Http\Argument;

/**
 * 筛选条件选项接口
 * @Controller("/sale/backend/pub/option")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OptionController extends BeanCollector
{
    /**
     * 订单类型
     * @return array
     */
    public function types()
    {
        $datas = OdrDictData::SUB_TID;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 订单来源
     * @return array
     */
    public function srcplats()
    {
        $datas = OdrDictData::SRC_PLATS;
        foreach ($datas as $key => $value)
        {
            if (!in_array($key, [19, 21, 24]))
            {
                unset($datas[$key]);
            }
        }

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 订单状态
     * @return array
     */
    public function ostats()
    {
        $datas = OdrDictData::SUB_OSTAT;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 发货方式
     * @return array
     */
    public function dlyWays()
    {
        $datas = OdrDictData::DLYWAY;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 来源渠道
     * @return array
     */
    public function fplats()
    {
        $plats = SaleDictData::SOURCE_PLAT;

        //返回
        return $this->getOptions($plats);
    }

    /**
     * 销售渠道
     * @return array
     */
    public function splats()
    {
        $plats = SysPlatModel::M()->getList(['tid' => 2], 'plat,pname');

        //返回
        return $plats;
    }

    public function express()
    {
        $express = SysExpressModel::M()->getList();

        //返回
        return $express;
    }

    /**
     * 仓库位置
     * @return array
     */
    public function stcwhs()
    {
        $stcwhs = SysWhouseModel::M()->getList([], 'wid,wname');

        //返回
        return $stcwhs;
    }

    /**
     * 广告投放位置
     * @return array
     */
    public function distpos()
    {
        $datas = SaleDictData::CMS_ADVERT_DISTPOS;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 广告投放位置
     * @return array
     */
    public function distchn()
    {
        $datas = SaleDictData::CMS_ADVERT_DISTCHN;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 商品库存状态
     * @return array
     */
    public function stcstat(Argument $argument)
    {
        //类型
        $type = $argument->get('type');

        $datas = SaleDictData::PRD_STCSTAT;
        if ($type == 'wait')
        {
            foreach ($datas as $key => $value)
            {
                if (!in_array($key, [11, 33, 34]))
                {
                    unset($datas[$key]);
                }
            }
        }

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 一口价商品状态
     * @return array
     */
    public function shopstat()
    {
        $datas = SaleDictData::SHOP_SALES_STAT;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 商品来源平台
     * @return array
     */
    public function sourceplat()
    {
        $datas = SaleDictData::SOURCE_PLAT;

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 订单来源
     * @return array
     */
    public function srcs()
    {
        $datas = OdrDictData::SRC;
        foreach ($datas as $key => $data)
        {
            if (in_array($key, [1, 23, 1101, 1102]))
            {
                unset($datas[$key]);
            }
        }

        //返回
        return $this->getOptions($datas);
    }

    /**
     * 转换数组结构
     * @param array $datas
     * @return array
     */
    private function getOptions(array $datas)
    {
        $newDatas = [];
        foreach ($datas as $key => $value)
        {
            $newDatas[] = [
                'id' => $key,
                'name' => $value
            ];
        }

        return $newDatas;
    }

    /**
     * 获取品牌列表
     * @param Argument $argument
     * @return array
     */
    public function brands(Argument $argument)
    {
        $ptype = $argument->get('ptype', 1);

        return QtoBrandData::D()->getList(-1, $ptype);
    }

    /**
     * 获取指定品牌列表
     * @return array
     */
    public function limitBrands()
    {
        //竞拍场次上架弹窗，品牌筛选按苹果、华为荣耀、小米、OPPO、VIVO、其他、平板显示
        $brands[] = ['bid' => 1, 'bname' => '苹果'];
        $brands[] = ['bid' => 2, 'bname' => '华为荣耀'];
        $brands[] = ['bid' => 3, 'bname' => '小米'];
        $brands[] = ['bid' => 4, 'bname' => 'OPPO'];
        $brands[] = ['bid' => 5, 'bname' => 'VIVO'];
        $brands[] = ['bid' => 6, 'bname' => '其他'];
        $brands[] = ['bid' => 7, 'bname' => '平板'];

        //返回
        return $brands;
    }

    /**
     * 获取机型列表
     * @param Argument $argument
     * @return array
     */
    public function models(Argument $argument)
    {
        //外部参数
        $bid = $argument->get('bid', 0);

        //获取机型列表
        $list = QtoModelData::D()->getList(0, $bid);
        foreach ($list as $key => $value)
        {
            $list[$key] = [
                'mid' => $value['mid'],
                'mname' => $value['mname'],
                'bid' => $bid,
            ];
        }

        //返回
        return $list;
    }
}