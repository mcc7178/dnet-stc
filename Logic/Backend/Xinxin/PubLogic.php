<?php
namespace App\Module\Sale\Logic\Backend\Xinxin;

use App\Lib\Qiniu\Qiniu;
use App\Model\Crm\CrmPurchaseModel;
use App\Module\Sale\Data\XinxinDictData;
use App\Service\Qto\QtoInquiryInterface;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Configer;
use Swork\Helper\ArrayHelper;

class PubLogic extends BeanCollector
{
    /**
     * @Reference("qto")
     * @var QtoInquiryInterface
     */
    private $qtoInquiryInterface;

    /**
     * 获取品牌列表
     * @return array
     */
    public function getBrands()
    {
        //获取采购表中的品牌
        $crmpurchase = CrmPurchaseModel::M()->getList([],'bid');
        $bids = ArrayHelper::map($crmpurchase,'bid');
        if($bids)
        {
            //获取品牌名
            $list = $this->qtoInquiryInterface->getMultBrands(21,$bids);
        }

        //返回
        return $list ?? [];
    }

    /**
     * 获取机型列表
     * @param int $bid 品牌id
     * @return array
     */
    public function getModels(int $bid)
    {
        if ($bid == 0)
        {
            return [];
        }

        //获取采购单中的机型
        $crmpurchase = CrmPurchaseModel::M()->getList(['bid' => $bid],'mid');
        $mids = ArrayHelper::map($crmpurchase,'mid');
        if($mids)
        {
            //获取机型名
            $list = $this->qtoInquiryInterface->getDictModels($mids);
        }

        //返回
        return $list ?? [];
    }

    /**
     * 获取等级列表
     * @return array
     */
    public function getLevels()
    {
        $levels = XinxinDictData::PRD_LEVEL;
        $levels = ArrayHelper::map($levels,'label');

        //返回
        return $levels;
    }

    /**
     * 获取七牛token
     * @return array
     * @throws
     */
    public function getQiniu()
    {
        //返回
        return [
            'token' => Qiniu::getToken(),
            'domain' => Configer::get('common')['qiniu']['default'] ?? ''
        ];
    }
}