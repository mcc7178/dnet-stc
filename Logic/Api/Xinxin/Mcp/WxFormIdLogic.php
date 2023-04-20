<?php
namespace App\Module\Sale\Logic\Api\Xinxin\Mcp;

use App\Exception\AppException;
use App\Model\Crm\CrmWeixinFormidModel;
use App\Service\Acc\AccUserInterface;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;

class WxFormIdLogic extends BeanCollector
{
    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * 保存小程序表单ID
     * @param string $acc
     * @param string $formid
     * @return mixed
     * @throws
     */
    public function save(string $acc, string $formid)
    {
        //检查参数
        if ($acc == '' || $formid == '')
        {
            throw new AppException(null, AppException::WRONG_ARG);
        }

        //获取新系统帐号ID
        $userInfo = $this->accUserInterface->getRow(['_id' => $acc], 'aid');
        if ($userInfo == false)
        {
            throw new AppException(null, AppException::NO_DATA);
        }

        //返回
        return CrmWeixinFormidModel::M()->insert(['fid' => $formid, 'buyer' => $userInfo['aid'], 'qty' => 1, 'atime' => time()]);
    }
}