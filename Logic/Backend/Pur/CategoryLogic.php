<?php
namespace App\Module\Sale\Logic\Backend\Pur;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;
use App\Model\Pur\PurCategoryModel;

/**
 * 电商库存 - 分类管理
 * Class CategoryLogic
 * @package App\Module\Sale\Logic\Backend\Pur
 */
class CategoryLogic extends BeanCollector
{
    /**
     * 获取分页数据
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(int $size, int $idx)
    {
        // 获取列表数据
        $cols = 'cid,cname,cacc,ctime';
        $order = ['ctime' => -1];
        $where = ['cstat' => 1];
        $list = PurCategoryModel::M()->getList($where, $cols, $order, $size, $idx);

        $caccs = ArrayHelper::map($list, 'cacc');
        if ($caccs)
        {
            $rnameDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $caccs]], 'rname');
        }

        // 数据处理
        foreach ($list as $key => $value)
        {
            $list[$key]['cacc'] = $rnameDict[$value['cacc']]['rname'] ?? '-';
            $list[$key]['ctime'] = $value['ctime'] ? DateHelper::toString($value['ctime']) : '-';
        }

        // 返回
        return $list;
    }

    /**
     * 获取数据总条数
     * @return int
     */
    public function getCount()
    {
        // 获取总数
        $count = PurCategoryModel::M()->getCount(['cstat' => 1]);

        // 返回
        return $count;
    }

    /**
     * 新增分类
     * @param string $cname
     * @param string $acc
     * @return array
     * @throws
     */
    public function addCategory(string $cname, string $acc)
    {
        if (mb_strlen($cname) > 20)
        {
            throw new AppException('分类名称最多为20字！');
        }

        $cnameData = PurCategoryModel::M()->getRow(['cname' => $cname, 'cstat' => 1]);
        if ($cnameData)
        {
            throw new AppException('分类名称不能重复，请重新输入！');
        }

        // 组装数据
        $data = [
            'cid'   => IdHelper::generate(),
            'cstat' => 1,
            'cname' => $cname,
            'cacc'  => $acc,
            'ctime' => time()
        ];

        PurCategoryModel::M()->insert($data);
    }

    /**
     * 删除分类
     * @param string $cid
     * @param string $acc
     * @return array
     * @throws
     */
    public function delCategory(string $cid, string $acc)
    {
        $list = PurCategoryModel::M()->getRow(['cid' => $cid]);
        if (!$list)
        {
            throw new AppException('分类数据不存在！');
        }

        // 检查该分类下是否有商品
        $odrList = PurOdrGoodsModel::M()->getList(['cid' => $cid]);
        if ($odrList)
        {
            throw new AppException('请转出该分类下的商品，再删除分类！');
        }

        // 组装数据
        $data = [
            'cstat' => 0,
            'aacc' => $acc,
            'atime' => time()
        ];

        PurCategoryModel::M()->update(['cid' => $cid], $data);
    }
}