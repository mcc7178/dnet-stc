<?php
namespace App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine;

use App\Exception\AppException;
use App\Model\Crm\CrmPrdRecommendModel;
use App\Model\Prd\PrdBidFavoriteModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdShopFavoriteModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Module\Sale\Logic\Api\Xinxin\Mcp\CommonLogic;
use App\Service\Acc\AccAuthInterface;
use App\Service\Qto\QtoInquiryInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

class FavoriteLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var CommonLogic
     */
    private $commonLogic;

    /**
     * @Reference("qto")
     * @var QtoInquiryInterface
     */
    private $qtoInquiryInterface;

    /**
     * 我的关注列表
     * @param array $query 查询参数
     * @param int $size 每页条数
     * @param int $idx 页码
     * @return array
     * @throws
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //获取用户id
        $acc = $this->commonLogic->getAcc($query['uid']);

        //数据条件
        $where = [
            'buyer' => $acc,
            'stat' => 1,
            'plat' => 21
        ];

        //外部条件
        //品牌id
        if ($query['bid'])
        {
            $where['bid'] = $query['bid'];
        }

        //机型id
        if ($query['mid'])
        {
            $where['mid'] = $query['mid'];
        }

        //获取关注列表
        $list = CrmPrdRecommendModel::M()->getList($where, 'rid,bid,mid,stat,mtime', ['mtime' => -1]);
        $count = 0;

        //如果有数据
        if ($list)
        {
            //获取总条数
            $count = count($list);

            //提取品牌机型id
            $bids = ArrayHelper::map($list, 'bid');
            $mids = ArrayHelper::map($list, 'mid');

            //获取品牌、机型字典
            $bidDict = $this->qtoInquiryInterface->getDictBrands($bids);
            $midDict = $this->qtoInquiryInterface->getDictModels($mids);

            //获取在售中的机器数量
            $onSales = $this->onSales($mids);

            //补充数据
            foreach ($list as $key => $value)
            {
                $list[$key]['bname'] = $bidDict[$value['bid']]['bname'];
                $list[$key]['mname'] = $midDict[$value['mid']]['mname'];
                $list[$key]['num'] = isset($onSales[$value['mid']]['num']) ? $onSales[$value['mid']]['num'] : 0;
                $list[$key]['focus'] = isset($onSales[$value['mid']]['focus']) ? $onSales[$value['mid']]['focus'] : 2;
                $list[$key]['mtime'] = DateHelper::toString($value['mtime']);
                $list[$key]['fstat'] = 'on';
            }
        }

        //返回数据
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => $count,
            ],
            'list' => $list
        ];
    }

    /**
     * 获取在售中的机器数据
     * @param array $mids
     * @return array
     */
    private function onSales(array $mids)
    {
        //获取一口价在售机器
        $shopWhere = [
            'mid' => ['in' => $mids],
            'isatv' => 1,
            'ptime' => ['<=' => time()],
            '$group' => ['mid']
        ];
        $shopList = PrdShopSalesModel::M()->getList($shopWhere, 'count(mid) as num,mid');

        //获取竞拍在售机器
        $bidWhere = [
            'plat' => 21,
            'mid' => ['in' => $mids],
            'stat' => ['in' => [12, 13]],
            '$group' => ['mid']
        ];
        $bidList = PrdBidSalesModel::M()->getList($bidWhere, 'count(mid) as num,mid');

        //重新处理数据
        $result = [];
        if ($shopList)
        {
            foreach ($shopList as $key => $value)
            {
                if (isset($result[$value['mid']]))
                {
                    $result[$value['mid']]['num'] += $value['num'];
                }
                else
                {
                    $result[$value['mid']] = $value;
                }
                $result[$value['mid']]['focus'] = 2;
            }
        }
        if ($bidList)
        {
            foreach ($bidList as $key => $value)
            {
                if (isset($result[$value['mid']]))
                {
                    $result[$value['mid']]['num'] += $value['num'];
                }
                else
                {
                    $result[$value['mid']] = $value;
                }
                $result[$value['mid']]['focus'] = 1;
            }
        }

        //返回数据
        return $result;
    }

    /**
     * 收藏（或取消收藏）
     * @param array $query
     * @return bool
     * @throws
     */
    public function save(array $query)
    {
        //参数处理
        if (!$query['bid'] || !$query['mid'])
        {
            throw new AppException(AppException::DATA_MISS);
        }

        //获取用户id
        $acc = $this->commonLogic->getAcc($query['uid']);

        //是否存在
        $where = ['plat' => 21, 'buyer' => $acc, 'bid' => $query['bid'], 'mid' => $query['mid']];
        $row = CrmPrdRecommendModel::M()->getRow($where, 'stat');

        if ($row === false)
        {
            $data = [
                'rid' => IdHelper::generate(),
                'plat' => 21,
                'buyer' => $acc,
                'bid' => $query['bid'],
                'mid' => $query['mid'],
                'stat' => 1,
                'mtime' => time(),
                'atime' => time()
            ];
            CrmPrdRecommendModel::M()->insert($data);
        }
        elseif ($row['stat'] == 0)
        {
            CrmPrdRecommendModel::M()->update($where, ['stat' => 1]);
        }

        //返回数据
        return true;
    }

    /**
     * 获取用户关注的品牌列表
     * @param int $uid
     * @return array
     * @return mixed
     * @throws
     */
    public function getBrands(int $uid)
    {
        //获取用户id
        $acc = $this->commonLogic->getAcc($uid);

        //获取用户关注的品牌列表
        $brands = [];
        $list = CrmPrdRecommendModel::M()->getList(['plat' => 21, 'buyer' => $acc, 'stat' => 1], 'bid');
        if ($list)
        {
            $bids = ArrayHelper::map($list, 'bid');

            //获取品牌数据
            $brands = $this->qtoInquiryInterface->getMultBrands(21, $bids);
        }
        array_unshift($brands, ['bid' => 0, 'bname' => '全部品牌']);

        //返回数据
        return $brands;
    }

    /**
     * 获取用户关注的机型列表
     * @param int $uid
     * @param int $bid
     * @return array
     * @throws
     */
    public function getModels(int $uid, int $bid)
    {
        //获取用户id
        $acc = $this->commonLogic->getAcc($uid);

        //获取用户关注的机型列表
        $models = [];
        $list = CrmPrdRecommendModel::M()->getList(['plat' => 21, 'buyer' => $acc, 'stat' => 1, 'bid' => $bid], 'mid');
        if ($list)
        {
            $mids = ArrayHelper::map($list, 'mid');

            //获取品牌数据
            $models = $this->qtoInquiryInterface->getDictModels($mids, 21);
            $models = array_values($models);
        }
        array_unshift($models, ['mid' => 0, 'mname' => '全部机型']);

        //返回数据
        return $models;
    }

    /**
     * 用户关注/取消关注某个品牌
     * @param int $uid 用户id
     * @param int $mid 品牌id
     * @return bool
     * @throws
     */
    public function focusModel(int $uid, int $mid, string $rid)
    {
        //获取用户id
        $acc = $this->commonLogic->getAcc($uid);

        //更新关注状态
        $time = time();
        $crmWhere = ['plat' => 21, 'buyer' => $acc, 'mid' => $mid];
        $stat = CrmPrdRecommendModel::M()->getOneById($rid, 'stat');

        CrmPrdRecommendModel::M()->update($crmWhere, ['stat' => $stat == 1 ? 0 : 1, 'mtime' => $time]);

        //返回数据
        return true;
    }

    /**
     * 关注按钮跳转类型
     * @param int $uid
     * @return mixed
     * @throws
     */
    public function focusType(int $uid)
    {
        //获取用户id
        $acc = $this->commonLogic->getAcc($uid);

        //获取当前用户关注竞拍和一口价的数量
        $bids = PrdBidFavoriteModel::M()->getCount(['plat' => 21, 'buyer' => $acc, 'isatv' => 1]);
        $shops = PrdShopFavoriteModel::M()->getCount(['buyer' => $acc, 'isatv' => 1]);

        //返回数据
        return ($bids && !$shops) ? 1 : 2;
    }
}