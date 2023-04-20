<?php
namespace App\Module\Sale\Logic\Backend\Bid;

use App\Exception\AppException;
use App\Lib\Qiniu\Qiniu;
use App\Model\Prd\PrdProductModel;
use App\Model\Prd\PrdSupplyModel;
use Swork\Bean\BeanCollector;

/**
 * 图片处理
 * Class BidImageLogic
 * @package App\Module\Sale\Logic\Backend\Bid
 */
class BidImageLogic extends BeanCollector
{
    /**
     * 删除图片
     * @param string $pid
     * @param array $imgs
     * @param string $src
     * @throws
     */
    public function del(string $pid, array $imgs, string $src)
    {
        //数据验证
        $src = explode('com/', $src)[1];
        $exist = PrdProductModel::M()->existById($pid);
        if ($exist == false)
        {
            throw new AppException('商品不存在', AppException::NO_DATA);
        }
        $imgList = PrdSupplyModel::M()->getList(['pid' => $pid], 'sid,imgpack');
        if ($imgList == false)
        {
            throw new AppException('供应数据不存在', AppException::NO_DATA);
        }

        //更新图片
        foreach ($imgList as $item)
        {
            $imgs = json_decode($item['imgpack'], true);
            foreach ($imgs as $k => $value)
            {
                if ($value['src'] == $src)
                {
                    unset($imgs[$k]);
                }
            }
            PrdSupplyModel::M()->updateById($item['sid'], ['imgpack' => json_encode(array_values($imgs))]);
        }

        //删除七牛图片
        Qiniu::batchDelete($src);
    }

    /**
     * 上传图片
     * @param string $pid
     * @param string $src
     * @throws
     */
    public function upload(string $pid, string $src)
    {
        //数据验证
        $exist = PrdProductModel::M()->existById($pid);
        if ($exist == false)
        {
            throw new AppException('商品不存在', AppException::NO_DATA);
        }

        //获取供应商品信息
        $supplyList = PrdSupplyModel::M()->getList(['pid' => $pid], 'sid,imgpack');
        if ($supplyList == false)
        {
            throw new AppException('供应商品不存在', AppException::NO_DATA);
        }

        //更新数据
        foreach ($supplyList as $item)
        {
            $imgpack = json_decode($item['imgpack'], true);
            $imgpack[]['src'] = $src;
            $data = [
                'imgpack' => json_encode($imgpack),
                'imgsrc' => $imgpack[0]['src']
            ];
            PrdSupplyModel::M()->updateById($item['sid'], $data);
        }
    }
}