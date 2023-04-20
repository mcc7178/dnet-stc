<?php


namespace App\Module\Sale\Logic\Api\Pur;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmMessageDotModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurTaskModel;
use App\Model\Qto\QtoModelModel;
use App\Module\Sale\Data\PurDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;

/**
 * 用户主页逻辑
 * Class HomeLogic
 * @package App\Module\Api\Pur
 */
class HomeLogic extends BeanCollector
{
    /**
     * 首页
     * @param string $uid
     * @return array
     * @throws
     */
    public function getInfo(string $uid)
    {
        //用户数据
        $userInfo = self::getUser($uid);

        //获取数据更新状态
        $updateStatus = self::getStatus($uid);

        //获取待办需求单
        $demandList = self::getDemand($uid);

        //返回
        return [
            'user' => $userInfo,
            'updateStatus' => $updateStatus,
            'demandList' => $demandList,
        ];
    }

    /**
     * 获取用户帐号信息
     * @param string $uid 用户ID
     * @return mixed
     * @throws
     */
    private function getUser(string $uid)
    {
        //获取用户头像/昵称/手机号
        $user = AccUserModel::M()->getRowById($uid, 'uname,avatar');
        if ($user == false)
        {
            throw new AppException('找不到用户帐号数据', AppException::NO_LOGIN);
        }
        $userInfo = [
            'uname' => $user['uname'],
            'avatar' => Utility::supplementAvatarImgsrc($user['avatar']),
        ];

        //返回
        return $userInfo;
    }

    /**
     * 获取数据是否更新
     * @param string $uid 用户ID
     * @return mixed
     * @throws
     */
    private function getStatus(string $uid)
    {
        //查询条件
        $where = [
            'uid' => $uid,
            'dtype' => 13,
            'plat' => 24
        ];

        //查询所有更新数据
        $messageInfo = CrmMessageDotModel::M()->getList($where, 'did,src');

        //初始化状态
        $info = [
            'DemandList' => 0,
            'PurchaseOrder' => 0,
            'waitReturned' => 0,
            'Returned' => 0,
        ];

        //数据处理
        if (!empty($messageInfo))
        {
            foreach ($messageInfo as $key => $value)
            {
                switch ($value['src'])
                {
                    case 1301:
                        $info['DemandList'] = 1; //需求单
                        break;
                    case 1302:
                        $info['PurchaseOrder'] = 1; //采购单
                        break;
                    case 1303:
                        $info['waitReturned'] = 1; //待退货
                        break;
                    case 1304:
                        $info['Returned'] = 1; //已退货
                        break;
                }
            }
        }

        //返回
        return $info;
    }

    /**
     * 获取待办需求单信息
     * @param string $uid 用户ID
     * @return mixed
     * @throws
     */
    private function getDemand(string $uid)
    {
        //应采购数量
        $purNum = 0;
        //已入库数量
        $inStcNum = 0;
        //已采购数量
        $hasPurNum = 0;
        //退货数量
        $returnNum = 0;
        //已完成数量
        $finishedNum = 0;

        //获取需求任务信息
        $purTaskList = PurTaskModel::M()
            ->join(PurDemandModel::M(), ['A.dkey' => 'B.dkey'])
            ->getList(['A.pacc' => $uid, 'A.tstat' => ['in' => [1, 2]]], 'A.dkey,A.tstat,A.unum,A.snum,A.pnum', ['B.utime' => -1, 'B.dkey' => -1]);
        if ($purTaskList)
        {
            $taskLists = ArrayHelper::map($purTaskList, 'dkey');

            //获取对应的已采购和退货的商品统计信息
            $goodsList = PurOdrGoodsModel::M()->getList(['aacc' => $uid, 'dkey' => ['in' => $taskLists], '$group' => ['gstat']], 'gstat,count(*) as count');
            foreach ($goodsList as $key2 => $value2)
            {
                if ($value2['gstat'] == 4)
                {
                    $hasPurNum = $value2['count'];
                }
                if ($value2['gstat'] == 5)
                {
                    $returnNum = $value2['count'];
                }
            }

            //获取对应的需求信息
            $purDemandDict = PurDemandModel::M()->getDict('dkey', ['dkey' => ['in' => $taskLists], 'dstat' => ['in' => [2, 3]]], 'ptype,pkey,mid');
            if (empty($purDemandDict))
            {
                return [];
            }

            $mids = ArrayHelper::map($purDemandDict, 'mid', -1);

            //获取机型信息
            $qtoModelDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

            $where = [
                'aacc' => $uid,
                'dkey' => ['in' => $taskLists],
                'gstat' => 4,
                '$group' => ['dkey']
            ];

            //获取对应的确认采购商品统计信息
            $purGoodsDict = PurOdrGoodsModel::M()->getDict('dkey', $where, 'count(*) as count');

            foreach ($purTaskList as $key => $value)
            {
                $demandStat = $value['tstat'];
                $demandMid = $purDemandDict[$value['dkey']]['mid'];
                $planType = $purDemandDict[$value['dkey']]['ptype'];
                $demandDetail[] = [
                    'dkey' => $value['dkey'],
                    'mname' => $qtoModelDict[$demandMid]['mname'] ?? '-',
                    'demandNum' => $value['unum'] ?? 0,
                    'comfiredNum' => $purGoodsDict[$value['dkey']]['count'] ?? 0,
                    'dstatName' => PurDictData::TASK_TSTAT[$demandStat],
                    'ptypeName' => PurDictData::PUR_PLAN_TYPE[$planType],
                ];

                //需求单分配采购总数
                $purNum += $value['unum'];

                //需求单已入库数量
                $inStcNum += $value['snum'];

                //需求单已完成总数
                $finishedNum += $value['pnum'];
            }
        }
        $demandInfo = [];
        $demandInfo['demandDetail'] = $demandDetail ?? [];
        $demandInfo['purNum'] = $purNum ?? 0;
        $demandInfo['inStcNum'] = $inStcNum ?? 0;
        $demandInfo['hasPurNum'] = $hasPurNum ?? 0;
        $demandInfo['returnNum'] = $returnNum ?? 0;
        $demandInfo['waitNum'] = ($purNum - $finishedNum) ?? 0;

        //返回
        return $demandInfo;
    }
}