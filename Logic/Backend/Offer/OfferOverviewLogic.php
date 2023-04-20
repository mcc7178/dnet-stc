<?php
namespace App\Module\Sale\Logic\Backend\Offer;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmOfferModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Sys\SysWhouseModel;
use App\Model\Topd\TopdCrmOfferRptDayModel;
use App\Traits\CalculateTrait;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 供应商概况相关接口逻辑
 * Class BidRoundLogic
 * @package App\Module\Sale\Logic\Backend\Offer
 */
class OfferOverviewLogic extends BeanCollector
{
    /**
     * 引用计算公用部分
     */
    use CalculateTrait;

    /**
     * 供应商翻页数据
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //数据条件
        $where = $this->getPagerWhere($query);

        //获取数据
        $cols = 'oid,acc,okey,oname,total,payqty,payamt,returns,stock,avgamt,atime,arrtime,paytime';
        $list = CrmOfferModel::M()->getList($where, $cols, ['atime' => -1], $size, $idx);
        if ($list == false)
        {
            return [];
        }

        //提取用户id
        $accs = ArrayHelper::map($list, 'acc');

        //获取用户信息字典
        $userDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $accs]], 'aid,mobile');

        //组装数据
        foreach ($list as $key => $item)
        {
            $mobiel = $userDict[$item['acc']]['mobile'] ?? '';
            $list[$key]['mobile'] = empty($mobiel) ? '-' : Utility::replaceMobile($mobiel, 4);
            $list[$key]['atime'] = DateHelper::toString($item['atime']);
            $list[$key]['arrtime'] = DateHelper::toString($item['arrtime']);
            $list[$key]['paytime'] = DateHelper::toString($item['paytime']);
            $list[$key]['okey'] = $item['okey'] ?: '-';
            $list[$key]['oname'] = $item['oname'] ?: '-';
        }

        //处理千分位
        ArrayHelper::fillThousandsSep($list);

        //返回
        return $list;
    }

    /**
     * 供应商总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //获取数据
        $count = CrmOfferModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 获取供应商详情
     * @param string $oid 供应商ID
     * @return bool|mixed
     * @throws
     */
    public function getInfo(string $oid)
    {
        //获取供应商信息
        $cols = 'oname,okey,acc,atime,total,returns,stock,payqty,payamt,paytime,arrtime,cmmamt';
        $info = CrmOfferModel::M()->getRowById($oid, $cols);
        if ($info == false)
        {
            throw new AppException('找不到供应商数据', AppException::NO_DATA);
        }

        //处理手机号
        $mobile = AccUserModel::M()->getOne(['aid' => $info['acc']], 'mobile');
        $info['mobile'] = Utility::replaceMobile($mobile, 4);

        //格式化时间
        $info['atime'] = DateHelper::toString($info['atime']);
        $info['paytime'] = DateHelper::toString($info['paytime']);
        $info['arrtime'] = DateHelper::toString($info['arrtime']);

        //计算退货率
        $info['returnRate'] = $this->calculateRate($info['returns'], $info['total'], 2);

        //处理千分位
        ArrayHelper::fillThousandsSep($info);

        //获取供应商统计数据
        $where = ['offer' => $oid];

        //所需字段
        $cols = TopdCrmOfferRptDayModel::M()->getField('rid,offer');

        //获取数据
        $list = TopdCrmOfferRptDayModel::M()->getList($where, $cols, ['rtime' => -1], 7);
        foreach ($list as $key => $value)
        {
            //统计日期
            $list[$key]['rtime'] = DateHelper::toString($value['rtime'], 'Y-m-d');

            //流拍率
            $list[$key]['nobidrate'] = $this->calculateRate($value['nobids'], $value['upshelfs'], 2);
        }

        //默认值处理
        ArrayHelper::fillDefaultValue($list, ['0', '0.00', 0, null]);

        //返回
        return [
            'info' => $info,
            'list' => $list
        ];
    }

    /**
     * 现存商品列表
     * @param string $oid 供应商ID
     * @param int $stcwhs 仓库位置
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getGoodsPager(string $oid, int $stcwhs, int $size, int $idx)
    {
        //查询条件
        $where = $this->getGoodsWhere($oid, $stcwhs);

        //获取数据
        $cols = 'bid,level,pname,bcode,stcwhs,rectime4';
        $list = PrdProductModel::M()->getList($where, $cols, ['rectime4' => -1], $size, $idx);
        if ($list == false)
        {
            return [];
        }

        //提取品牌ID
        $bids = ArrayHelper::map($list, 'bid');

        //获取品牌字典
        $brandDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bid,bname');

        //提取商品级别
        $levels = ArrayHelper::map($list, 'level');

        //获取级别字典
        $levelDict = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lkey,lname');

        //获取仓库字典
        $stcwhsDict = SysWhouseModel::M()->getDict('wid', [], 'wid,wname');

        //组装数据
        foreach ($list as $key => $value)
        {
            $list[$key]['bname'] = $brandDict[$value['bid']]['bname'] ?? '-';
            $list[$key]['lname'] = $levelDict[$value['level']]['lname'] ?? '-';
            $list[$key]['stcname'] = $stcwhsDict[$value['stcwhs']]['wname'] ?? '-';
            $list[$key]['rectime4'] = DateHelper::toString($value['rectime4']);
        }

        //返回
        return $list;
    }

    /**
     * 商品列表总数量
     * @param string $oid 供应商ID
     * @param int $stcwhs 仓库位置
     * @return int
     */
    public function getGoodsCount(string $oid, int $stcwhs)
    {
        //查询条件
        $where = $this->getGoodsWhere($oid, $stcwhs);

        //获取数据
        $count = PrdProductModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 供应商概览翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //固定条件
        $where = ['tid' => 2];

        //提取参数
        $oname = $query['oname'] ?? '';
        $mobile = $query['mobile'] ?? '';

        //如果有供应商名称
        if (!empty($oname))
        {
            $where['oname'] = ['like' => '%' . $oname . '%'];
        }

        //如果有手机号
        if (!empty($mobile))
        {
            $accList = AccUserModel::M()->getList(['mobile' => $mobile], 'aid');
            if ($accList)
            {
                $accs = ArrayHelper::map($accList, 'aid');
                $where['acc'] = ['in' => $accs];
            }
        }

        //返回
        return $where;
    }

    /**
     * 获取供应商现存商品数据条件
     * @param string $oid
     * @param int $stcwhs
     * @return array
     */
    private function getGoodsWhere(string $oid, int $stcwhs)
    {
        $where = [
            'offer' => $oid,
            'prdstat' => 1,
            'inway' => ['in' => [2, 21]]
        ];

        if (!empty($stcwhs))
        {
            $where['stcwhs'] = $stcwhs;
        }

        //返回
        return $where;
    }

}