<?php
namespace App\Module\Sale\Logic\Order;

use App\Exception\AppException;
use App\Model\Acc\AccAuthModel;
use App\Model\Odr\OdrPaymentModel;
use App\Module\Api\Data\V2\PayData;
use App\Module\Pub\Logic\UniqueKeyLogic;
use App\Service\Pay\UserPayInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Configer;

/**
 * 订单支付预下单相关逻辑
 * @package App\Module\Sale\Logic\Order
 */
class OrderPayCreateLogic extends BeanCollector
{
    /**
     * @Reference()
     * @var UserPayInterface
     */
    private $userPayInterface;

    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * 支付预下单
     * @param array $data
     * @param array $payConf
     * @return array
     * @throws
     */
    public function handle(array $data, array $payConf)
    {
        //拆分参数
        $plat = $data['plat'] ?? 0;                 //订单平台
        $paySrc = $data['paySrc'] ?? 0;             //订单来源
        $uid = $data['uid'] ?? '';                  //用户id
        $tradeType = $data['tradeType'] ?? 0;       //交易类型 1：小程序 2：APP 3：当面付(扫码) 5：手机网站H5
        $payTid = $data['payTid'] ?? 2;             //支付订单类型 1：保证金订单支付 2：销售订单支付
        $payType = $data['payType'] ?? 0;           //支付类型，11-微信支付，12-支付宝支付，13-线下支付
        $payAmt = $data['payAmt'] ?? 0;             //支付金额
        $payPrdQty = $data['payPrdQty'] ?? 0;       //支付商品数
        $payOkeys = $data['payOkeys'] ?? [];        //支付订单号(可以有多个)
        $payDesc = $data['payDesc'] ?? '订单支付';   //支付描述

        try
        {
            //必传参数验证
            $necessary = [$plat, $paySrc, $uid, $payTid, $payType, $payAmt, $payOkeys];
            foreach ($necessary as $value)
            {
                if ($value == false)
                {
                    throw new AppException('预支付下单参数错误');
                }
            }

            //检查支付配置
            if ($payConf == false)
            {
                throw new AppException('缺少支付配置参数', AppException::NO_DATA);
            }
            $payChn = $payConf['paychn'];

            //获取当前服务器地址
            $apiHost = Configer::get('common:apihost');

            //生成支付订单号、支付交易单号
            $payId = $this->uniqueKeyLogic->getUniversal();
            $tradeNo = $this->uniqueKeyLogic->getPayTradeNo($payChn);

            //创建支付订单
            $res = OdrPaymentModel::M()->insert([
                'pid' => $payId,
                'plat' => $plat,
                'buyer' => $uid,
                'tid' => $payTid,
                'paychn' => $payChn,
                'paytype' => $payType,
                'payamts' => $payAmt,
                'payodrs' => count($payOkeys),
                'payprds' => $payPrdQty,
                'payokeys' => json_encode($payOkeys),
                'paystat' => 1,
                'tradeno' => $tradeNo,
                'atime' => time()
            ]);
            if ($res == false)
            {
                throw new AppException('支付失败，请稍后重试[01]', AppException::FAILED_INSERT);
            }

            //组装支付预下单公共参数
            $payParam = [
                'plat' => $plat,
                'uid' => $uid,
                'paysrc' => $paySrc,
                'paytype' => $payType,
                'payamt' => $payAmt,
                'paybid' => $payId,
                'paydesc' => $payDesc,
                'tradeNo' => $tradeNo,
                'tradeType' => $tradeType,
                'notifyUrl' => $apiHost . $payConf['notify_url']
            ];

            //支付预下单
            $result = [];
            switch ($payType)
            {
                //微信、支付宝支付
                case 11:
                case 12:
                    $this->createPay($payChn, $payParam, $result);
                    break;

                //线下支付
                case 13:
                    $result['payData'] = PayData::ACCOUNT;
                    break;
                default:
                    break;
            }

            //补充返回数据
            $result['tradeNo'] = $payId;
            $result['payType'] = $payType;

            //返回
            return $result;
        }
        catch (\Throwable $exception)
        {
            throw new AppException($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * 微信支付预下单
     * @param int $payChn
     * @param array $payParam
     * @param array $result
     * @throws
     */
    private function createPay(int $payChn, array $payParam, array &$result)
    {
        $payType = $payParam['paytype'];
        $plat = $payParam['plat'];
        $uid = $payParam['uid'];
        $payId = $payParam['paybid'];
        $tradeType = $payParam['tradeType'];
        $tradeNo = $payParam['tradeNo'];

        //支付宝支付：除闲鱼拍卖其他平台目前均为扫码支付
        if ($payType == 12)
        {
            $tradeType = $plat == 19 ? $payParam['tradeType'] : 3;
            $payParam['tradeType'] = $tradeType;
        }

        //查询第三方平台用户标识ID
        $thirdIdDict = [
            11 => [
                1 => 'wxmcpid',
                4 => 'wxopid',
            ],
            12 => [
                1 => 'openid',
            ],
        ];
        $queryCol = $thirdIdDict[$payType][$tradeType] ?? '';
        if ($queryCol)
        {
            $thirdId = AccAuthModel::M()->getOne(['acc' => $uid, 'plat' => $plat], $queryCol);
            if ($thirdId == false)
            {
                throw new AppException('支付失败，请稍后重试[02]', AppException::DATA_MISS);
            }
            $payParam['payacc'] = $thirdId;
        }

        //支付预下单
        $payInfo = $this->userPayInterface->pay($payChn, $payParam);
        if (empty($payInfo['paydata']))
        {
            throw new AppException('支付失败，请稍后重试[03]');
        }

        //如果交易单号不一致，更新交易单号
        if (!empty($payInfo['tradeno']) && $payInfo['tradeno'] != $tradeNo)
        {
            OdrPaymentModel::M()->updateById($payId, ['tradeno' => $payInfo['tradeno']]);
            $result['tradeNo'] = $payInfo['tradeno'];
        }

        $result['payData'] = $payInfo['paydata'];
    }
}