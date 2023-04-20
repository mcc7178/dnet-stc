<?php
namespace App\Module\Sale\Logic\Backend\Pur;

use App\Model\Crm\CrmOfferModel;
use App\Model\Prd\PrdBidRoundModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurUserModel;
use Swork\Bean\BeanCollector;

/**
 * 电商采购公共使用
 * Class PubLogic
 * @package App\Module\Sale\Logic\Backend\Pur
 */
class PubLogic extends BeanCollector
{
    /**
     * 获取采购人
     * @return array
     */
    public function getPurUsers()
    {
        //返回
        return PurUserModel::M()->getList([], 'acc,rname');
    }

    /**
     * 获取采购商
     * @return array
     */
    public function getMerchants()
    {
        //返回
        return PurMerchantModel::M()->getList([], 'mid,mname');
    }

    /**
     * 获取所有供应商
     * @return array
     */
    public function getOffers()
    {
        //返回
        return CrmOfferModel::M()->getList([], 'oid,oname');
    }

    /**
     * 获取所有带公开场次
     * @return array
     */
    public function getRounds()
    {
        //返回
        return PrdBidRoundModel::M()->getList(['stat' => 11,'upshelfs' => ['>' => 0]],'rid,rname');
    }
}