<?php

namespace App\Module\Sale\Logic\Xinxin;

use App\Exception\AppException;
use App\Lib\Weixin\WxApi;
use App\Model\Crm\CrmWeixinFormidModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Sale\SaleGroupBuyModel;
use App\Params\RedisKey;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Configer;

class XinXinGroupBuyLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var \Swork\Client\Redis
     */
    private $redis;

    /**
     * @Reference()
     * @var \App\Service\Acc\AccAuthInterface
     */
    private $accAuthInterface;

    /**
     * 拼团活动状态变更
     * @return bool
     */
    public function groupBuyStatChange()
    {
        $time = time();
        $changeLockKey = 'sale_group_buy_change';
        $endLockPrefix = 'sale_group_buy_end_';
        $expired = 30;

        //加锁，防止重复执行
        if ($this->redis->setnx($changeLockKey, $time, $expired) == false)
        {
            return false;
        }

        //获取所有启用中的拼团活动
        $list = SaleGroupBuyModel::M()->getList(['isatv' => 1], 'gkey,pname,groupqty,buyqty,payqty,stat,stime,etime', ['stime' => 1, 'etime' => 1]);
        if ($list == false)
        {
            return $this->redis->del($changeLockKey);
        }

        $startGroupBuy = []; //开始拼团集合
        $endGroupBuy1 = []; //拼团成功集合
        $endGroupBuy2 = []; //拼团失败集合
        $endGroupBuy3 = []; //已结束的拼团集合
        $endGroupBuy4 = []; //已结束+已支付拼团集合

        //拆分需要更新的拼团活动
        foreach ($list as $value)
        {
            $gkey = $value['gkey'];
            $stat = $value['stat'];
            $groupqty = $value['groupqty'];
            $buyqty = $value['buyqty'];
            $stime = $value['stime'];
            $etime = $value['etime'];
            $endLockKey = $endLockPrefix . $gkey;

            /*
             * 检查是否达到开始拼团条件
             * 条件1：启用状态为启用中
             * 条件2：当前时间大于开始拼团时间
             */
            if ($stat == 1 && $stime <= $time)
            {
                $startGroupBuy[] = $gkey;
                continue;
            }

            /*
             * 检查是否达到拼团成功条件
             * 条件1：活动状态为拼团中
             * 条件2：已购买商品数大于等于拼团所需商品数
             */
            if ($stat == 2 && $buyqty >= $groupqty)
            {
                if ($this->redis->setnx($endLockKey, $time, $expired))
                {
                    $endGroupBuy1[] = $value;
                    $endGroupBuy3[] = $gkey;
                }
                continue;
            }

            /*
             * 检查是否达到拼团失败条件
             * 条件1：活动状态为拼团中
             * 条件2：当前时间大于结束拼团时间
             * 条件3：已购买商品数低于拼团所需商品数
             */
            if ($stat == 2 && $etime <= $time)
            {
                if ($this->redis->setnx($endLockKey, $time, $expired))
                {
                    $endGroupBuy2[] = $value;
                    $endGroupBuy3[] = $gkey;
                    $endGroupBuy4[] = $gkey;
                }
            }
        }

        if ($startGroupBuy)
        {
            //更新活动为拼团中状态
            SaleGroupBuyModel::M()->update(['gkey' => ['in' => $startGroupBuy]], ['stat' => 2, 'mtime' => $time]);
        }
        if ($endGroupBuy1)
        {
            //提取活动编号
            $gkeys = ArrayHelper::map($endGroupBuy1, 'gkey');

            //更新活动为拼团成功状态并写入拼团成功通知队列
            $status = SaleGroupBuyModel::M()->update(['gkey' => ['in' => $gkeys]], ['stat' => 3, 'gtime' => $time, 'isatv' => 0, 'mtime' => $time]);
            if ($status)
            {
                foreach ($endGroupBuy1 as $item)
                {
                    //补充成团时间
                    $item['gtime'] = $time;
                    $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_PROCESS_3, serialize($item));
                }
            }
        }
        if ($endGroupBuy2)
        {
            //提取活动编号
            $gkeys = ArrayHelper::map($endGroupBuy2, 'gkey');

            //更新活动为拼团失败状态并写入拼团失败通知队列
            $status = SaleGroupBuyModel::M()->update(['gkey' => ['in' => $gkeys]], ['stat' => 4, 'isatv' => 0, 'mtime' => $time]);
            if ($status)
            {
                foreach ($endGroupBuy2 as $item)
                {
                    $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_PROCESS_4, serialize($item));
                }
            }
        }

        //取消未支付的拼团订单
        $this->updateUnpaiedOrder($endGroupBuy3);

        //取消已支付的拼团失败的订单
        $this->updateGroupbuyFailedOrder($endGroupBuy4);

        //解锁
        return $this->redis->del($changeLockKey);
    }

    /**
     * 处理拼团成功服务通知
     * @param array $args 拼团活动参数
     * @throws
     */
    public function processGroupBuyNotify3(array $args)
    {
        //提取参数
        $gkey = $args['gkey'] ?? '';
        $pname = $args['pname'] ?? '';
        $stime = $args['stime'] ?? 0;
        $etime = $args['etime'] ?? 0;
        $gtime = $args['gtime'] ?? 0;
        $payqty = $args['payqty'] ?? 0;

        //检查参数
        if ($gkey == '' || $stime == 0 || $etime == 0 || $payqty == 0)
        {
            throw new AppException(null, AppException::WRONG_ARG);
        }

        //数据条件
        $where = [
            'tid' => 13,
            'otime' => ['between' => [$stime, $etime]],
            'ostat' => 13,
            'groupbuy' => $gkey
        ];

        //获取拼团订单列表
        $orderList = OdrOrderModel::M()->getList($where, 'buyer,okey,qty');
        if ($orderList == false)
        {
            return;
        }

        //更新订单为待发货状态
        OdrOrderModel::M()->update($where, ['ostat' => 21]);

        //写入推送服务通知
        foreach ($orderList as $value)
        {
            $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_3, serialize([
                'buyer' => $value['buyer'],
                'okey' => $value['okey'],
                'qty' => $value['qty'],
                'pname' => $pname,
                'payqty' => $payqty,
                'gtime' => $gtime,
            ]));
        }
    }

    /**
     * 处理拼团失败服务通知
     * @param array $args 拼团活动参数
     * @throws
     */
    public function processGroupBuyNotify4(array $args)
    {
        //提取参数
        $gkey = $args['gkey'] ?? '';
        $pname = $args['pname'] ?? '';
        $stime = $args['stime'] ?? 0;
        $etime = $args['etime'] ?? 0;

        //检查参数
        if ($gkey == '' || $stime == 0 || $etime == 0)
        {
            throw new AppException(null, AppException::WRONG_ARG);
        }

        //数据条件
        $where = [
            'tid' => 13,
            'otime' => ['between' => [$stime, $etime]],
            'ostat' => 13,
            'groupbuy' => $gkey
        ];

        //获取拼团订单列表并且写入推送服务通知
        $orderList = OdrOrderModel::M()->getList($where, 'buyer,okey,oamt');
        foreach ($orderList as $value)
        {
            $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_4, serialize([
                'buyer' => $value['buyer'],
                'okey' => $value['okey'],
                'oamt' => $value['oamt'],
                'pname' => $pname,
            ]));
        }
    }

    /**
     * 发送参团成功服务通知
     * @param array $args 参数
     * @return bool
     * @throws
     */
    public function sendGroupBuyNotify1(array $args)
    {
        //提取参数
        $gkey = $args['gkey'] ?? '';
        $okey = $args['okey'] ?? '';

        //获取小程序配置
        $mpconf = Configer::get('common:weixin:2102');
        if ($mpconf == false)
        {
            throw new AppException('缺少小程序配置', AppException::WRONG_ARG);
        }

        //获取拼团活动数据
        $groupBuyInfo = SaleGroupBuyModel::M()->getRowById($gkey, 'pname');
        if ($groupBuyInfo == false)
        {
            throw new AppException('找不到拼团活动数据', AppException::NO_DATA);
        }

        //获取拼团订单数据
        $odrOrderInfo = OdrOrderModel::M()->getRow(['okey' => $okey], 'buyer,oamt');
        if ($odrOrderInfo == false)
        {
            throw new AppException('找不到拼团订单数据', AppException::NO_DATA);
        }

        //获取客户小程序openid
        $authInfo = $this->accAuthInterface->getAuthInfo($odrOrderInfo['buyer'], 21, 'wxmcpid');
        if ($authInfo == false)
        {
            throw new AppException('找不到小程序openid', AppException::NO_DATA);
        }
        $openid = $authInfo['wxmcpid'];

        //获取微信服务通知表单ID
        $formId = $this->getBuyerWeiXinFormId($odrOrderInfo['buyer']);
        if ($formId == false)
        {
            throw new AppException('找不到服务通知form_id', AppException::NO_DATA);
        }
        //组装消息模板参数
        $msgData = [
            'touser' => $openid,
            'template_id' => 'q7OCHjxlKTfMLPgmdLG0s4fuCv5CP5RzB3HyOTi2hz8',
            'page' => 'pages/home/index',
            'form_id' => $formId,
            'data' => [
                'keyword1' => ['value' => $okey],
                'keyword2' => ['value' => $groupBuyInfo['pname']],
                'keyword3' => ['value' => $odrOrderInfo['oamt']],
            ],
            'emphasis_keyword' => 'keyword2.DATA',
        ];

        //请求远程接口
        $url = "https://api.weixin.qq.com/cgi-bin/message/wxopen/template/send?access_token=[token]";
        $res = WxApi::sendRequest($mpconf, $url, json_encode($msgData, JSON_UNESCAPED_UNICODE));
        if (!isset($res['errcode']))
        {
            return false;
        }
        $errcode = $res['errcode'];

        //如果是form_id过期或不正确则重新写入队列等待重发
        if (in_array($errcode, [41028, 41029]))
        {
            $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_1, serialize($args));

            return false;
        }

        //返回
        return $errcode == 0;
    }

    /**
     * 发送参团失败服务通知
     * @param array $args 参数
     * @return bool
     * @throws
     */
    public function sendGroupBuyNotify2(array $args)
    {
        //提取参数
        $gkey = $args['gkey'] ?? '';
        $okey = $args['okey'] ?? '';

        //获取小程序配置
        $mpconf = Configer::get('common:weixin:2102');
        if ($mpconf == false)
        {
            throw new AppException('缺少小程序配置', AppException::WRONG_ARG);
        }

        //获取拼团活动数据
        $groupBuyInfo = SaleGroupBuyModel::M()->getRowById($gkey, 'pname');
        if ($groupBuyInfo == false)
        {
            throw new AppException('找不到拼团活动数据', AppException::NO_DATA);
        }

        //获取拼团订单数据
        $odrOrderInfo = OdrOrderModel::M()->getRow(['okey' => $okey], 'buyer,oamt');
        if ($odrOrderInfo == false)
        {
            throw new AppException('找不到拼团订单数据', AppException::NO_DATA);
        }

        //获取客户小程序openid
        $authInfo = $this->accAuthInterface->getAuthInfo($odrOrderInfo['buyer'], 21, 'wxmcpid');
        if ($authInfo == false)
        {
            throw new AppException('找不到小程序openid', AppException::NO_DATA);
        }
        $openid = $authInfo['wxmcpid'];

        //获取微信服务通知表单ID
        $formId = $this->getBuyerWeiXinFormId($odrOrderInfo['buyer']);
        if ($formId == false)
        {
            throw new AppException('找不到服务通知form_id', AppException::NO_DATA);
        }

        //组装消息模板参数
        $msgData = [
            'touser' => $openid,
            'template_id' => 'hQ9YSOdnbBzRKU7r9Apyc6umvqm-mbAkAAXNoUURQQA',
            'page' => 'pages/home/index',
            'form_id' => $formId,
            'data' => [
                'keyword1' => ['value' => $groupBuyInfo['pname']],
                'keyword2' => ['value' => $okey],
                'keyword3' => ['value' => $odrOrderInfo['oamt']],
                'keyword4' => ['value' => '很遗憾的通知您，您参与的拼团活动失败了，相关款项将在24小时内退回您的账户'],
            ],
            'emphasis_keyword' => 'keyword1.DATA',
        ];

        //请求远程接口
        $url = "https://api.weixin.qq.com/cgi-bin/message/wxopen/template/send?access_token=[token]";
        $res = WxApi::sendRequest($mpconf, $url, json_encode($msgData, JSON_UNESCAPED_UNICODE));
        if (!isset($res['errcode']))
        {
            return false;
        }
        $errcode = $res['errcode'];

        //如果是form_id过期或不正确则重新写入队列等待重发
        if (in_array($errcode, [41028, 41029]))
        {
            $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_2, serialize($args));

            return false;
        }

        //返回
        return $errcode == 0;
    }

    /**
     * 发送拼团成功服务通知
     * @param array $args 拼团活动参数
     * @return mixed
     * @throws
     */
    public function sendGroupBuyNotify3(array $args)
    {
        //获取小程序配置
        $mpconf = Configer::get('common:weixin:2102');
        if ($mpconf == false)
        {
            throw new AppException('缺少小程序配置', AppException::WRONG_ARG);
        }

        //获取用户openid
        $authInfo = $this->accAuthInterface->getAuthInfo($args['buyer'], 21, 'wxmcpid');
        $openid = $authInfo['wxmcpid'];

        //获取微信服务通知表单ID
        $formId = $this->getBuyerWeiXinFormId($args['buyer']);

        //组装消息模板参数
        $msgData = [
            'touser' => $openid,
            'template_id' => 'E7U4e_dDhRKyvHl5gzeZBEIws9-J9_YOHaLac9yoa80',
            'form_id' => $formId,
            'data' => [
                'keyword1' => ['value' => $args['pname']],
                'keyword2' => ['value' => $args['okey']],
                'keyword3' => ['value' => $args['qty']],
                'keyword4' => ['value' => $args['payqty']],
                'keyword5' => ['value' => DateHelper::toString($args['gtime'], 'Y-m-d H:i:s')],
            ],
            'emphasis_keyword' => 'keyword1.DATA',
        ];

        //请求远程接口
        $url = "https://api.weixin.qq.com/cgi-bin/message/wxopen/template/send?access_token=[token]";
        $res = WxApi::sendRequest($mpconf, $url, json_encode($msgData, JSON_UNESCAPED_UNICODE));
        if (!isset($res['errcode']))
        {
            return false;
        }
        $errcode = $res['errcode'];

        //如果是form_id过期或不正确则重新写入队列等待重发
        if (in_array($errcode, [41028, 41029]))
        {
            $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_3, serialize($args));

            return false;
        }

        //返回
        return $errcode == 0;
    }

    /**
     * 发送拼团失败服务通知
     * @param array $args 拼团活动参数
     * @return mixed
     * @throws
     */
    public function sendGroupBuyNotify4(array $args)
    {
        //提取参数
        $buyer = $args['buyer'] ?? '';
        $okey = $args['okey'] ?? '';
        $oamt = $args['oamt'] ?? 0;
        $pname = $args['pname'] ?? '-';

        //获取小程序配置
        $mpconf = Configer::get('common:weixin:2102');
        if ($mpconf == false)
        {
            throw new AppException('缺少小程序配置', AppException::WRONG_ARG);
        }

        //获取客户小程序openid
        $authInfo = $this->accAuthInterface->getAuthInfo($buyer, 21, 'wxmcpid');
        if ($authInfo == false)
        {
            throw new AppException('找不到小程序openid', AppException::NO_DATA);
        }
        $openid = $authInfo['wxmcpid'];

        //获取微信服务通知表单ID
        $formId = $this->getBuyerWeiXinFormId($buyer);
        if ($formId == false)
        {
            throw new AppException('找不到服务通知form_id', AppException::NO_DATA);
        }

        //组装消息模板参数
        $msgData = [
            'touser' => $openid,
            'template_id' => 'hQ9YSOdnbBzRKU7r9Apyc6umvqm-mbAkAAXNoUURQQA',
            'page' => 'pages/home/index',
            'form_id' => $formId,
            'data' => [
                'keyword1' => ['value' => $pname],
                'keyword2' => ['value' => $okey],
                'keyword3' => ['value' => $oamt],
                'keyword4' => ['value' => '活动时间内未达到成团数量，相关款项将在24小时内退回您的账户'],
            ],
            'emphasis_keyword' => 'keyword1.DATA',
        ];

        //请求远程接口
        $url = "https://api.weixin.qq.com/cgi-bin/message/wxopen/template/send?access_token=[token]";
        $res = WxApi::sendRequest($mpconf, $url, json_encode($msgData, JSON_UNESCAPED_UNICODE));
        if (!isset($res['errcode']))
        {
            return false;
        }
        $errcode = $res['errcode'];

        //如果是form_id过期或不正确则重新写入队列等待重发
        if (in_array($errcode, [41028, 41029]))
        {
            $this->redis->lPush(RedisKey::SALE_GROUP_BUY_NOTIFY_SEND_4, serialize($args));

            return false;
        }

        //返回
        return $errcode == 0;
    }

    /**
     * 获取卖家的微信服务通知FormID
     * @param string $buyer 卖家ID
     * @return mixed
     * @throws
     */
    private function getBuyerWeiXinFormId(string $buyer)
    {
        if (empty($buyer))
        {
            return null;
        }

        //获取服务通知表单ID
        $info = CrmWeixinFormidModel::M()->getRow(['buyer' => $buyer], 'fid,qty', ['atime' => 1]);
        if ($info == false)
        {
            return null;
        }

        //提取参数
        $formId = $info['fid'];
        $qty = $info['qty']; //可用次数

        //更新或删除表单ID（可用次数大于1则继续保留）
        if ($qty > 1)
        {
            CrmWeixinFormidModel::M()->increaseById($formId, ['qty' => -1]);
        }
        else
        {
            CrmWeixinFormidModel::M()->deleteById($formId);
        }

        //返回
        return $formId;
    }

    /**
     * 取消未支付的拼团订单
     * @param array $gkeys 拼团活动编号
     */
    private function updateUnpaiedOrder(array $gkeys)
    {
        if (count($gkeys) > 0)
        {
            OdrOrderModel::M()->update(['tid' => 13, 'ostat' => 11, 'groupbuy' => ['in' => $gkeys]], ['ostat' => 51, 'paystat' => 0]);
        }
    }

    /**
     * 取消已支付的拼团失败的订单
     * @param array $gkeys
     */
    private function updateGroupbuyFailedOrder(array $gkeys)
    {
        if (count($gkeys) > 0)
        {
            OdrOrderModel::M()->update(['tid' => 13, 'ostat' => 13, 'groupbuy' => ['in' => $gkeys]], ['ostat' => 51]);
        }
    }
}