<?php

namespace App\Module\Sale\Logic\Backend\Xinxin\Pcs;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Crm\CrmPurchaseDynamicModel;
use App\Model\Crm\CrmPurchaseModel;
use App\Model\Qto\QtoOptionsModel;
use App\Module\Pub\Logic\PubProductLogic;
use App\Module\Sale\Data\XinxinDictData;
use App\Service\Acc\AccUserInterface;
use App\Service\Qto\QtoInquiryInterface;
use App\Service\Qto\QtoLevelInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

class PurchaseLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var PubProductLogic
     */
    private $pubProductLogic;

    /**
     * @Reference("qto")
     * @var QtoLevelInterface
     */
    private $qtoLevelInterface;

    /**
     * @Reference("qto")
     * @var QtoInquiryInterface
     */
    private $qtoInquiryInterface;

    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * 采购单列表
     * @param array $query 查询参数
     * @param int $idx 页码
     * @param int $size 每页数量
     * @return mixed
     * @throws
     */
    public function getPager(array $query, int $idx, int $size)
    {
        //查询条件
        $where = [];

        //默认排序
        $order = ['mtime' => -1,'atime' => -1];

        //待确认状态
        if ($query['tid'] == 1)
        {
            $where['pcsstat'] = 1;
            $where['stat'] = ['in' => [1, 2]];
            $order = ['atime' => 1];
        }

        //待二次确认状态
        if ($query['tid'] == 2)
        {
            $where['pcsstat'] = 2;
            $where['stat'] = ['in' => [1, 2]];
            $order = ['mtime' => 1];
        }

        //挂起中状态
        if ($query['tid'] == 3)
        {
            $where['pcsstat'] = 3;
            $where['stat'] = ['in' => [1, 2]];
        }

        //待采购状态
        if ($query['tid'] == 4)
        {
            $where['pcsstat'] = 4;
            $where['stat'] = ['in' => [1, 2]];
            $order = ['mtime' => 1];
        }

        //已采购状态
        if ($query['tid'] == 5)
        {
            $where['pcsstat'] = 6;
        }

        //已取消状态
        if ($query['tid'] == 6)
        {
            $where['stat'] = 3;
            $order = ['ctime' => -1];
        }

        //已过期状态
        if ($query['tid'] == 7)
        {
            $where['expired'] = ['<=' => time()];
            $where['stat'] = ['!=' => 3];
            $order = ['expired' => -1];
        }

        //品牌
        if (!empty($query['bid']))
        {
            $where['bid'] = $query['bid'];
        }

        //机型
        if (!empty($query['mid']))
        {
            $where['mid'] = $query['mid'];
        }

        //级别
        if (!empty($query['level']))
        {
            $where['level'] = ['like' => '%' . $query['level'] . '%'];
        }

        //取消原因
        if (!empty($query['creason']))
        {
            $where['creason'] = $query['creason'];
        }

        //客户名称
        $queryname = $query['rname'];
        if (!empty($queryname))
        {
            $where1['$or'] = [
                ['rname' => ['like' => "%$queryname%"]],
                ['uname' => ['like' => "%$queryname%"]],
            ];
            $aids = $this->accUserInterface->getList(21,$where1,'aid');
            $aids = ArrayHelper::map($aids, 'aid');
            if (count($aids) == 0)
            {
                return $this->emptyData();
            }
            $where['buyer'] = ['in' => $aids];
        }

        //手机号
        if (!empty($query['mobile']))
        {
            $aids = $this->accUserInterface->getList(21,['mobile' => $query['mobile'],'aid']);
            $aids = ArrayHelper::map($aids, 'aid');
            if (count($aids) == 0)
            {
                return $this->emptyData();
            }
            $where['buyer'] = ['in' => $aids];
        }

        //发布时间
        if (count($query['atime']) == 2)
        {
            $stime = strtotime($query['atime'][0]);
            $etime = strtotime($query['atime'][1]) + 86399;
            $where['atime'] = ['between' => [$stime, $etime]];
        }

        //有效期限
        if (count($query['expired']) == 2)
        {
            $stime = strtotime($query['expired'][0]);
            $etime = strtotime($query['expired'][1]) + 86399;
            $where['expired'] = ['between' => [$stime, $etime]];
        }

        //确定时间
        if (count($query['chktime']) == 2)
        {
            $stime = strtotime($query['chktime'][0]);
            $etime = strtotime($query['chktime'][1]) + 86399;
            $where['chktime'] = ['between' => [$stime, $etime]];
        }

        //采购时间
        if (count($query['pcstime']) == 2)
        {
            $stime = strtotime($query['pcstime'][0]);
            $etime = strtotime($query['pcstime'][1]) + 86399;
            $where['pcstime'] = ['between' => [$stime, $etime]];
        }

        //取消时间
        if (count($query['ctime']) == 2)
        {
            $stime = strtotime($query['ctime'][0]);
            $etime = strtotime($query['ctime'][1]) + 86399;
            $where['ctime'] = ['between' => [$stime, $etime]];
        }

        //获取翻页数据
        $list = CrmPurchaseModel::M()->getList($where, '*', $order, $size, $idx);
        $count = CrmPurchaseModel::M()->getCount($where);

        //补充品牌机型级别信息
        $list = $this->pubProductLogic->addModelInfo($list, 'bid', 'mid');

        //如果有数据
        if (count($list) > 0)
        {
            //获取客户名称,电话
            $aids = ArrayHelper::maps([$list, $list, $list], ['buyer', 'chkstaff', 'pcsstaff']);
            $accDict = $this->accUserInterface->getAccDict($aids,'uname,rname,mobile');

            //获取采购单最新备注
            $pkeys = ArrayHelper::map($list, 'pkey');
            $rmkDict = CrmPurchaseDynamicModel::M()->getDict('pkey', ['pkey' => ['in' => $pkeys],'rmk' => ['!=' => '']], 'rmk');

            //获取QTO级别
            $levelDict = $this->qtoLevelInterface->getDict();

            foreach ($list as $key => $value)
            {
                $rname = $accDict[$value['buyer']]['rname'] ?? '';
                $uname = $accDict[$value['buyer']]['uname'] ?? '-';
                $chkrname = $accDict[$value['chkstaff']]['rname'] ?? '';
                $chkuname = $accDict[$value['chkstaff']]['uname'] ?? '-';
                $pcsrname = $accDict[$value['pcsstaff']]['rname'] ?? '';
                $pcsuname = $accDict[$value['pcsstaff']]['uname'] ?? '-';
                $mobile = $accDict[$value['buyer']]['mobile'] ?? '';

                //补充信息
                $list[$key]['rname'] = empty($rname) ? $uname : $rname;
                $list[$key]['chkrname'] = empty($chkrname) ? $chkuname : $chkrname;
                $list[$key]['pcsrname'] = empty($pcsrname) ? $pcsuname : $pcsrname;
                $list[$key]['mobile'] = Utility::replaceMobile($mobile);
                $list[$key]['rmk'] = $rmkDict[$value['pkey']]['rmk'] ?? '-';
                $list[$key]['creason'] = XinxinDictData::PCS_CREASON[$value['creason']] ?? '-';

                //处理级别
                $levels = ArrayHelper::toArray($value['level']);
                foreach ($levels as $key2 => $level)
                {
                    $levels[$key2] = $levelDict[$level]['lname'] ?? '-';
                }
                $list[$key]['level'] = join('、', $levels);

                //处理时间
                $list[$key]['atime'] = DateHelper::toString($value['atime']);
                $list[$key]['expired'] = DateHelper::toString($value['expired'], 'Y-m-d');
                $list[$key]['chktime'] = empty($value['chktime']) ? '-' : DateHelper::toString($value['chktime']);
                $list[$key]['pcstime'] = empty($value['pcstime']) ? '-' : DateHelper::toString($value['pcstime']);
                $list[$key]['ctime'] = empty($value['ctime']) ? '-' : DateHelper::toString($value['ctime']);

                //处理可接受范围
                $price = $value['price1'] . '~' . $value['price2'];
                $list[$key]['acceptable'] = empty($value['price1']) && empty($value['price2']) ? '-' : $price;
            }
        }

        //返回
        return [
            'pager' => [
                'idx' => $idx,
                'size' => $size,
                'count' => $count
            ],
            'list' => $list
        ];
    }

    /**
     * 采购单详情
     * @param string $pkey 采购单ID
     * @return mixed
     * @throws
     */
    public function getInfo(string $pkey)
    {
        //检查参数
        if (empty($pkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取数据
        $info = CrmPurchaseModel::M()->getRowById($pkey, '*');

        //补充机型信息
        $modelname = $this->qtoInquiryInterface->getModelName($info['mid'], 0);

        //获取客户及工作人员名称电话
        $aids = [$info['buyer'], $info['chkstaff'], $info['pcsstaff']];
        if ($aids)
        {
            $accDict = $this->accUserInterface->getAccDict($aids,'uname,rname,mobile');
            $buyername = $accDict[$info['buyer']]['rname'] ?? '';
            $buyeuname = $accDict[$info['buyer']]['uname'] ?? '-';
            $buyername = empty($buyername) ? $buyeuname : $buyername;
            $chkrname = $accDict[$info['chkstaff']]['rname'] ?? '';
            $chkuname = $accDict[$info['chkstaff']]['uname'] ?? '-';
            $chkname = empty($chkrname) ? $chkuname : $chkrname;
            $pcsrname = $accDict[$info['pcsstaff']]['rname'] ?? '';
            $pcsuname = $accDict[$info['pcsstaff']]['uname'] ?? '-';
            $pcsname = empty($pcsrname) ? $pcsuname : $pcsrname;
            $mobile = $accDict[$info['buyer']]['mobile'] ?? '-';
        }

        //处理级别
        $levelDict = $this->qtoLevelInterface->getDict();
        $levels = ArrayHelper::toArray($info['level']);
        foreach ($levels as $key => $level)
        {
            $levels[$key] = $levelDict[$level]['lname'];
        }
        $levelname = join('；', $levels);

        //处理内存,网络制度,颜色,销售地
        $mdrams = ArrayHelper::toArray($info['mdram']);
        $mdnets = ArrayHelper::toArray($info['mdnet']);
        $mdcolors = ArrayHelper::toArray($info['mdcolor']);
        $mdofsales = ArrayHelper::toArray($info['mdofsale']);
        $oids = array_merge($mdrams, $mdnets, $mdcolors, $mdofsales);
        if ($oids)
        {
            $cidDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $oids]], 'oname');
            $mdram = $this->addOptionInfo($mdrams, $cidDict);
            $mdnet = $this->addOptionInfo($mdnets, $cidDict);
            $mdcolor = $this->addOptionInfo($mdcolors, $cidDict);
            $mdofsale = $this->addOptionInfo($mdofsales, $cidDict);
        }

        //组装数据
        $info = [
            'atime' => DateHelper::toString($info['atime']),
            'expired' => DateHelper::toString($info['expired'],'Y-m-d'),
            'ctime' => empty($info['ctime']) ? '-' : DateHelper::toString($info['ctime']),
            'pcstime' => empty($info['pcstime']) ? '-' : DateHelper::toString($info['pcstime']),
            'chktime' => empty($info['chktime']) ? '-' : DateHelper::toString($info['chktime']),
            'buyername' => $buyername ?? '-',
            'mobile' => $mobile ?? '-',
            'chkname' => $chkname ?? '-',
            'pcsname' => $pcsname ?? '-',
            'modelname' => $modelname ?? '-',
            'levelname' => $levelname ?? '-',
            'mdram' => $mdram ?? '-',
            'mdnet' => $mdnet ?? '-',
            'mdcolor' => $mdcolor ?? '-',
            'mdofsale' => $mdofsale ?? '-',
            'qty' => $info['qty'],
            'acceptable' => empty($info['price1']) && empty($info['price2']) ? '-' : $info['price1'] . '~' . $info['price2'],
            'rmk' => empty($info['rmk']) ? '-' : $info['rmk'],
            'creason' => empty($info['creason']) ? '-' : XinxinDictData::PCS_CREASON[$info['creason']],
        ];

        //返回
        return $info;
    }

    /**
     * 不同状态采购单数量
     */
    public function loadNumber()
    {
        $confirm = $this->getCount(1);
        $reconfirm = $this->getCount(2);
        $lock = $this->getCount(3);
        $purchase = $this->getCount(4);

        //返回
        return [
            'confirm' => $confirm,
            'reconfirm' => $reconfirm,
            'lock' => $lock,
            'purchase' => $purchase
        ];
    }

    /**
     * @param int $pcsstat 采购单状态
     * @return int
     */
    private function getCount(int $pcsstat)
    {
        $where = [
            'pcsstat' => $pcsstat,
            'stat' => ['in' => [1, 2]]
        ];
        $count = CrmPurchaseModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * @param string $pkey 采购单ID
     * @throws
     * @return mixed
     */
    public function getWater(string $pkey)
    {
        //检查参数
        if (empty($pkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取流水信息
        $waterList = CrmPurchaseDynamicModel::M()->getList(['pkey' => $pkey], '*', ['atime' => -1]);
        if ($waterList)
        {
            $aids = ArrayHelper::map($waterList, 'staff');
            $accDict = $this->accUserInterface->getAccDict($aids,'rname,uname');

            //补充数据
            foreach ($waterList as $key => $value)
            {
                $rname = $accDict[$value['staff']]['rname'] ?? '';
                $uname = $accDict[$value['staff']]['uname'] ?? '-';
                $waterList[$key]['aname'] = empty($rname) ? $uname : $rname;
                $waterList[$key]['atime'] = DateHelper::toString($value['atime']);
                $waterList[$key]['type'] = XinxinDictData::PCS_WATER[$value['dtype']];
                $waterList[$key]['rmk'] = empty($value['rmk']) ? '-' : $value['rmk'];
            }
        }

        //返回
        return $waterList;
    }

    /**
     * 采购单挂起
     * @param string $acc 挂起人ID
     * @param string $pkey 采购单ID
     * @param string $rmk 备注
     * @throws
     * @return mixed
     */
    public function Lock(string $acc, string $pkey, string $rmk)
    {
        $res = $this->actStat($acc, $pkey, $rmk, 3, 1);

        //返回
        return $res;
    }

    /**
     * 提交采购单
     * @param string $acc 处理人ID
     * @param string $pkey 采购单ID
     * @param string $rmk 备注
     * @throws
     * @return mixed
     */
    public function submit(string $acc, string $pkey, string $rmk)
    {
        $res = $this->actStat($acc, $pkey, $rmk, 4, 2);

        //返回
        return $res;
    }

    /**
     * 转接采购单
     * @param string $acc 处理人ID
     * @param string $pkey 采购单ID
     * @param string $rmk 备注
     * @throws
     * @return bool
     */
    public function transfer(string $acc, string $pkey, string $rmk)
    {
        $res = $this->actStat($acc, $pkey, $rmk, 2, 4);

        //返回
        return $res;
    }

    /**
     * 采购单采购成功
     * @param string $acc 处理人ID
     * @param string $pkey 采购单ID
     * @param string $rmk 备注
     * @throws
     * @return bool
     */
    public function success(string $acc, string $pkey, string $rmk)
    {
        $res = $this->actStat($acc, $pkey, $rmk, 6, 5);

        //返回
        return $res;
    }

    /**
     * 取消采购单
     * @param string $acc 处理人ID
     * @param string $pkey 采购单ID
     * @param string $rmk 备注
     * @param int $reason 原因
     * @throws
     * @return bool
     */
    public function cancel(string $acc, string $pkey, string $rmk, int $reason)
    {
        $res = $this->actStat($acc, $pkey, $rmk, 0, 3, $reason);

        //返回
        return $res;
    }

    /**
     * 采购单状态更改
     * @param string $acc 处理人ID
     * @param string $pkey 采购单ID
     * @param string $rmk 备注
     * @param int $pcsstat 采购单需要变更的状态
     * @param int $tid 采购单流水类型
     * @param int $reason 取消原因
     * @throws
     * @return string
     */
    private function actStat(string $acc, string $pkey, string $rmk, int $pcsstat, int $tid, int $reason = 0)
    {
        //外部参数
        $time = time();

        //检查参数
        if (empty($acc) || empty($pkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }
        if ($pcsstat == 2 || $pcsstat == 3)
        {
            if (trim($rmk) == '')
            {
                throw new AppException('请输入备注');
            }
        }

        //更改采购单状态
        $data = [
            'mtime' => $time,
            'pcsstat' => $pcsstat
        ];

        if ($pcsstat == 6)
        {
            //已采购
            $data['pcsstaff'] = $acc;
            $data['pcstime'] = $time;
        }
        else
        {
            $data['chkstaff'] = $acc;
            $data['chktime'] = $time;

            //取消
            if ($pcsstat == 0)
            {
                $data['stat'] = 3;
                $data['creason'] = $reason;
                $data['ctime'] = $time;
            }
        }
        CrmPurchaseModel::M()->updateById($pkey, $data);

        //新增流水记录
        $data = [
            'did' => IdHelper::generate(),
            'pkey' => $pkey,
            'staff' => $acc,
            'dtype' => $tid,
            'rmk' => $rmk,
            'atime' => $time
        ];
        CrmPurchaseDynamicModel::M()->insert($data);

        //返回
        return 'ok';
    }

    /**
     * 补充内存，颜色，渠道，网络制度信息
     * @param array $mdInfo
     * @param array $cidDict
     * @return string
     */
    private function addOptionInfo(array $mdInfo, array $cidDict)
    {
        $mdInfo = array_flip($mdInfo);
        foreach ($mdInfo as $key => $value)
        {
            $mdInfo[$key] = $cidDict[$key]['oname'];
        }

        //将数组合并为字符串
        $mdInfo = empty($mdInfo) ? '-' : join('；', $mdInfo);

        //返回
        return $mdInfo;
    }

    /**
     * 返回空数据
     */
    private function emptyData()
    {
        return [
            'pager' => [
                'idx' => 1,
                'size' => 0,
                'count' => 0
            ],
            'list' => [],
        ];
    }
}
