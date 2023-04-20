<?php
namespace App\Module\Sale\Logic\Backend\Order;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Acc\AccUserModel;
use App\Model\Odr\OdrGoodsModel;
use App\Model\Odr\OdrOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcLogisticsModel;
use App\Model\Stc\StcStorageModel;
use App\Model\Sys\SysExpressCompanyModel;
use App\Model\Sys\SysPlatModel;
use App\Model\Sys\SysWarehouseModel;
use App\Module\Pub\Data\OdrDictData;
use App\Module\Pub\Logic\SysRegionLogic;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 自有订单相关接口逻辑
 * Class BidRoundLogic
 * @package App\Module\Sale\Logic\Backend\Offer
 */
class OrderLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var SysRegionLogic
     */
    private $sysRegionLogic;

    /**
     * 翻页数据
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
        $cols = 'buyer,plat,tid,okey,qty,otime,ostat,payno,recver,recreg,recdtl,dlykey,dlyway,dlytime3,exts,src,whs';
        $list = OdrOrderModel::M()->getList($where, $cols, ['otime' => -1], $size, $idx);
        if ($list == false)
        {
            return [];
        }

        //提取用户id
        $uids = ArrayHelper::map($list, 'buyer');

        //获取用户信息字典
        $userDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $uids]], 'aid,uname');

        //获取平台字典
        $platDict = SysPlatModel::M()->getDict('plat', [], 'plat,pname');

        //获取订单对应物流单字典
        $lkeys = ArrayHelper::map($list, 'dlykey', '-1');
        $logisticsDict = StcLogisticsModel::M()->getDict('lkey', ['lkey' => ['in' => $lkeys]], 'expway');

        //获取仓库字典
        $whss = ArrayHelper::map($list, 'whs', '-1');
        $sysWhouseDict = SysWarehouseModel::M()->getDict('wid', ['wid' => ['in' => $whss], 'tid' => 1], 'wname');

        //组装数据
        foreach ($list as $key => $value)
        {
            $list[$key]['uname'] = $userDict[$value['buyer']]['uname'] ?? '-';
            $list[$key]['plat'] = $platDict[$value['plat']]['pname'] ?? '-';
            $list[$key]['dlyway'] = OdrDictData::DLYWAY[$value['dlyway']] ?? '-';
            $list[$key]['srcplat'] = OdrDictData::SRC_PLATS[$value['plat']] ?? '-';
            $list[$key]['statName'] = OdrDictData::OSTAT[$value['ostat']] ?? '-';
            $list[$key]['typeName'] = OdrDictData::TID[$value['tid']] ?? '-';
            $list[$key]['otime'] = DateHelper::toString($value['otime']);
            $list[$key]['dlytime3'] = DateHelper::toString($value['dlytime3']);
            $list[$key]['recreg'] = $this->sysRegionLogic->getFullName($value['recreg']);
            $list[$key]['src'] = OdrDictData::SRC[$value['src']] ?? '-';

            $exts = ArrayHelper::toArray($value['exts']);
            $list[$key]['recorder'] = $exts['recorder'] ?? '-';

            //邮寄付费方式
            $expway = $logisticsDict[$value['dlykey']]['expway'] ?? 0;
            $list[$key]['expway'] = OdrDictData::EXPWAY[$expway] ?? '-';

            //分仓
            $list[$key]['whouse'] = $sysWhouseDict[$value['whs']]['wname'] ?? '-';

        }
        ArrayHelper::fillDefaultValue($list, ['', 0]);

        //返回
        return $list;
    }

    /**
     * 总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //获取数据
        $count = OdrOrderModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 获取订单详情
     * @param string $okey 订单编号
     * @return bool|mixed
     * @throws
     */
    public function getInfo(string $okey)
    {
        //获取订单详情;
        $info = OdrOrderModel::M()->getRow(['okey' => $okey]);
        if ($info == false)
        {
            throw new AppException('订单不存在', AppException::DATA_MISS);
        }

        //处理时间
        $otime = DateHelper::toString($info['otime']);
        $dlytime3 = DateHelper::toString($info['dlytime3']);
        $paytime = DateHelper::toString($info['paytime']);

        //获取下单人名称
        $uname = AccUserModel::M()->getOneById($info['buyer'], 'uname', [], '-');

        //获取录单人
        $exts = ArrayHelper::toArray($info['exts']);
        $recorder = $exts['recorder'] ?? '-';

        //转换状态类型
        $ostatname = OdrDictData::OSTAT[$info['ostat']] ?? '-';
        $tidname = OdrDictData::TID[$info['tid']] ?? '-';
        $dlyway = OdrDictData::DLYWAY[$info['dlyway']] ?? '-';
        $paytype = $info['paystat'] == 3 ? (OdrDictData::PAYTYPE[$info['paytype']] ?? '-') : '-';
        $src = OdrDictData::SRC[$info['src']] ?? '-';

        //来源渠道
        $plat = SysPlatModel::M()->getOne(['plat' => $info['plat']], 'pname', [], '-');

        //金额转换千分位
        $oamt = !empty($info['oamt']) ? Utility::formatNumber($info['oamt']) : '-';
        $payamt = !empty($info['payamt']) ? Utility::formatNumber($info['payamt']) : '-';

        //拼接收货地址
        $recreg = $this->sysRegionLogic->getFullName($info['recreg']);
        $recdtl = $recreg . $info['recdtl'];

        //快递信息
        $LogisticInfo = StcLogisticsModel::M()->getRow(['lkey' => $info['dlykey']], 'expid,expno');
        $expName = SysExpressCompanyModel::M()->getOne(['eid' => $LogisticInfo['expid']], 'ename', [], '-');

        //分仓信息
        $whouse = SysWarehouseModel::M()->getOneById($info['whs'], 'wname') ?: '-';

        //获取订单商品信息
        $where = [
            'okey' => $okey,
        ];
        $goods = OdrGoodsModel::M()->getList($where, 'gid,pid,yid,bcode,bprc,ostat,rtntype,dlykey', ['dlykey' => -1]);
        if (empty($goods))
        {
            throw new AppException('商品不存在', AppException::DATA_MISS);
        }
        $pids = ArrayHelper::map($goods, 'pid');

        //包裹数量
        $dlykeys = ArrayHelper::map($goods, 'dlykey');
        $count = count($dlykeys);

        //获取产品信息
        $prdProduct = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'pid,pname,slevel,sbid');

        //提取商品级别
        $levels = ArrayHelper::map($prdProduct, 'slevel');

        //获取级别字典
        $levelDict = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $levels]], 'lkey,lname');

        //提取品牌id
        $bids = ArrayHelper::map($prdProduct, 'sbid');

        //获取品牌字典
        $brandDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bid,bname');

        //获取物流数据
        $logiDict = StcLogisticsModel::M()->getDict('lkey', ['lkey' => ['in' => $dlykeys]], 'expid,expno,ltime3');

        //获取快递公司信息
        $expDict = [];
        $expids = ArrayHelper::map($logiDict, 'expid');
        if ($expids)
        {
            $expDict = SysExpressCompanyModel::M()->getDict('eid', ['eid' => ['in' => $expids]], 'ename');
        }

        //组装产品信息
        foreach ($prdProduct as $key => $value)
        {
            $bid = $value['sbid'];
            $level = $value['slevel'];

            $prdProduct[$key]['lname'] = $levelDict[$level]['lname'] ?? '-';
            $prdProduct[$key]['bname'] = $brandDict[$bid]['bname'] ?? '-';
        }

        //组装订单商品信息
        foreach ($goods as $key => $value)
        {
            $pid = $value['pid'];
            $dlykey = $value['dlykey'];
            $goods[$key]['lname'] = $prdProduct[$pid]['lname'] ?? '-';
            $goods[$key]['bname'] = $prdProduct[$pid]['bname'] ?? '-';
            $goods[$key]['mname'] = $prdProduct[$pid]['pname'] ?? '-';
            $goods[$key]['bprc'] = Utility::formatNumber($value['bprc'], 2);
            $goods[$key]['statname'] = OdrDictData::RTNTYPE[$value['rtntype']] ?? $ostatname;
            $goods[$key]['expname'] = $expDict[$logiDict[$dlykey]['expid'] ?? '']['ename'] ?? '-';
            $goods[$key]['expno'] = $logiDict[$dlykey]['expno'] ?? '-';
            $goods[$key]['ltime3'] = DateHelper::toString(($logiDict[$dlykey]['ltime3'] ?? 0));
            $goods[$key]['count'] = count(array_keys(array_column($goods, 'dlykey'), $dlykey));
        }

        //订单信息
        $orderInfo = [
            'okey' => $okey,
            'otime' => $otime,
            'plat' => $plat,
            'uname' => $uname,
            'recorder' => $recorder,
            'ostat' => $info['ostat'],
            'ostatname' => $ostatname,
            'tid' => $info['tid'],
            'tidname' => $tidname,
            'qty' => $info['qty'],
            'oamt' => $oamt,
            'payamt' => $payamt,
            'paytime' => $paytime,
            'paytype' => $paytype,
            'dlyway' => $dlyway,
            'dlytime3' => $dlytime3,
            'src' => $src,
            'count' => $count,
            'whouse' => $whouse,
        ];

        //收货信息
        $recInfo = [
            'recver' => $info['recver'] ?: '-',
            'rectel' => $info['rectel'] ?: '-',
            'recdtl' => $recdtl,
            'express' => $info['ostat'] > 21 ? $expName : '-',
            'expno' => ($info['dlyway'] == 1 && $info['ostat'] > 21) ? ($LogisticInfo['expno'] ?: '-') : '-'
        ];

        //返回
        return [
            'orderInfo' => $orderInfo,
            'recInfo' => $recInfo,
            'goodsInfo' => $goods
        ];
    }

    /**
     * 删除第三方订单
     * @param string $okey
     * @throws
     */
    public function delete(string $okey)
    {
        //获取订单数据
        $info = OdrOrderModel::M()->getRow(['okey' => $okey], 'ostat');
        if ($info == false)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }

        //验证订单状态是否是待发货
        if ($info['ostat'] != 21)
        {
            throw new AppException('订单状态只有待发货才可删除', AppException::NO_RIGHT);
        }

        //删除操作
        OdrOrderModel::M()->delete(['okey' => $okey]);
        OdrGoodsModel::M()->delete(['okey' => $okey]);
    }

    /**
     * 扫码搜索商品数据
     * @param int $type
     * @param string $bcode
     * @return array|bool
     * @throws
     */
    public function getSearch(int $type, string $bcode)
    {
        //获取商品数据
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode], 'pid,offer,bcode,bid,mid,pname,level,prdstat,stcwhs,stcstat');
        if ($info == false)
        {
            throw new AppException('商品数据不存在', AppException::NO_DATA);
        }

        //验证商品是否在库
        if ($info['prdstat'] != 1 || $info['stcwhs'] != 101 || !in_array($info['stcstat'], [11, 33, 34, 35]))
        {
            throw new AppException('商品状态不允许建单', AppException::NO_RIGHT);
        }

        //品牌、机型级别名称转换
        $info['bname'] = QtoBrandModel::M()->getOneById($info['bid'], 'bname', [], '-');
        $info['mname'] = QtoModelModel::M()->getOneById($info['mid'], 'mname', [], '-');
        $info['lname'] = QtoLevelModel::M()->getOneById($info['level'], 'lname', [], '-');

        //返回
        return $info;
    }

    /**
     * 导出
     * @param array $query
     * @return array
     */
    public function export(array $query)
    {
        //搜索条件
        $where = [
            'tid' => 32
        ];

        //库存编号
        $bcode = $query['bcode'] ?? '';
        if (!empty($bcode))
        {
            $where['bcode'] = $bcode;
        }

        //订单编号/第三方订单号
        $okey = $query['okey'] ?? '';
        if (!empty($okey))
        {
            //优先第三方订单号查询
            $okeys = OdrOrderModel::M()->getDict('okey', ['payno' => $okey]);
            if ($okeys)
            {
                $where['okey'] = ['in' => $okeys];
            }
            else
            {
                //如果找不到第三方订单号，按订单号查询
                $where['okey'] = $okey;
            }
        }

        //收货人
        $recver = $query['recver'] ?? '';
        if (!empty($recver))
        {
            $oWhere['recver'] = ['like' => '%' . $recver . '%'];
            $okeys = OdrOrderModel::M()->getDistinct('okey', $oWhere);
            if (count($okeys) > 0)
            {
                $where['okey'] = ['in' => $okeys];
            }
            else
            {
                $where['okey'] = -1;
            }
        }

        //联系电话
        $rectel = $query['rectel'] ?? '';
        if (!empty($rectel))
        {
            $oWhere['rectel'] = $rectel;
            $okeys = OdrOrderModel::M()->getDistinct('okey', $oWhere);
            if (count($okeys) > 0)
            {
                $where['okey'] = ['in' => $okeys];
            }
            else
            {
                $where['okey'] = -1;
            }
        }

        //来源渠道
        $plat = $query['plat'];
        if ($plat > 0)
        {
            $where['plat'] = $plat;
        }

        //时间范围
        $otime = $query['otime'] ?? [];
        if (count($otime) == 2)
        {
            $stime = strtotime($otime[0]);
            $etime = strtotime($otime[1]) + 86399;
            $where['otime'] = ['between' => [$stime, $etime]];
        }

        //获取数据
        $list = OdrGoodsModel::M()->getList($where, 'plat,okey,bcode,bprc', ['otime' => -1]);
        if ($list)
        {
            //提取字段
            $plats = ArrayHelper::map($list, 'plat');
            $bcodes = ArrayHelper::map($list, 'bcode');
            $okeys = ArrayHelper::map($list, 'okey');

            //获取渠道
            $platDict = SysPlatModel::M()->getDict('plat', ['plat' => ['in' => $plats]], 'plat,pname');

            //获取第三方订单号
            $odrDict = OdrOrderModel::M()->getDict('okey', ['okey' => ['in' => $okeys]], 'okey,payno');

            //获取品牌、机型
            $prdDict = PrdProductModel::M()->getDict('bcode', ['bcode' => ['in' => $bcodes]], 'bcode,bid,pname');
            if ($prdDict)
            {
                $bids = ArrayHelper::map($prdDict, 'bid');
                $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname,bid');
            }

            //组装数据
            foreach ($list as $key => $value)
            {
                $bid = $prdDict[$value['bcode']]['bid'] ?? '';
                $list[$key]['plat'] = $platDict[$value['plat']]['pname'] ?? '-';
                $list[$key]['payno'] = $odrDict[$value['okey']]['payno'] ?? '-';
                $list[$key]['pname'] = $prdDict[$value['bcode']]['pname'] ?? '-';
                $list[$key]['bname'] = empty($bid) ? '-' : $bidDict[$bid]['bname'] ?? '-';
            }
        }

        //设置表头
        $head = [
            'bcode' => '库存编号',
            'bname' => '品牌',
            'pname' => '机型',
            'bprc' => '售价',
            'okey' => '订单号',
            'payno' => '第三方订单号',
            'plat' => '渠道',
        ];

        //返回
        return [
            'head' => $head,
            'list' => $list
        ];
    }

    /**
     * 取消订单
     * @param string $okey
     * @throws
     */
    public function cancel(string $okey)
    {
        $time = time();

        //获取订单数据
        $order = OdrGoodsModel::M()->getRow(['okey' => $okey], 'tid,ostat');
        if ($order == false)
        {
            throw new AppException('订单数据不存在', AppException::NO_DATA);
        }
        if (!in_array($order['tid'], [31, 33, 34, 35]) || $order['ostat'] != 11)
        {
            throw new AppException('订单数据不允许取消', AppException::NO_RIGHT);
        }

        //获取订单商品数据
        $goods = OdrGoodsModel::M()->getDistinct('pid', ['okey' => $okey]);
        if ($goods == false)
        {
            throw new AppException('订单商品数据不存在', AppException::NO_DATA);
        }

        //更新订单状态
        $res = OdrOrderModel::M()->update(['okey' => $okey], [
            'ostat' => 51,
            'otime51' => $time,
            'mtime' => $time
        ]);
        if ($res == false)
        {
            throw new AppException(AppException::FAILED_UPDATE);
        }

        //更新订单商品状态
        $res = OdrGoodsModel::M()->update(['okey' => $okey], [
            'ostat' => 51,
            'rtntype' => 3,
            'mtime' => $time
        ]);
        if ($res == false)
        {
            throw new AppException(AppException::FAILED_UPDATE);
        }

        //更新商品库存状态
        PrdProductModel::M()->update(['pid' => $goods], ['stcstat' => 11, 'stctime' => $time]);
        StcStorageModel::M()->update(['pid' => $goods, 'stat' => 1], ['prdstat' => 11]);
    }

    /**
     * 获取修改收货信息
     * @param string $okey
     * @return array
     * @throws
     */
    public function getEditInfo(string $okey)
    {
        $info = OdrOrderModel::M()->getRow(['okey' => $okey], 'ostat,recreg,recver,rectel,recdtl,dlyway,dlykey');
        if (empty($info))
        {
            throw new AppException('未获取到订单数据');
        }

        if (!in_array($info['ostat'], [10, 11, 12, 13, 21]))
        {
            throw new AppException('只有未发货才可以修改订单收货信息');
        }

        $info['exprgn'] = (int)$info['recreg'];
        $info['city'] = intval(substr($info['exprgn'], 0, 4) . '00');
        $info['province'] = intval(substr($info['exprgn'], 0, 2) . '0000');

        //获取寄付到付
        $expway = 0;
        if ($info['dlykey'] != '')
        {
            $logistics = StcLogisticsModel::M()->getRow(['lkey' => $info['dlykey']], 'expway');
            if (isset($logistics['expway']))
            {
                $expway = $logistics['expway'];
            }
        }
        $info['expway'] = $expway;

        //返回
        return $info;
    }

    /**
     * 保存修改的收货信息
     * @param array $data
     * @throws
     */
    public function saveEditInfo(array $data)
    {
        //检查参数
        if (empty($data['recdtl']) || empty($data['recver']) || empty($data['rectel']) || $data['recreg'] < 100)
        {
            throw new AppException('请检查数据，所有选项均必填！');
        }
        if (Utility::isMobile($data['rectel']) == false)
        {
            throw new AppException('手机号有误');
        }

        $info = OdrOrderModel::M()->getRow(['okey' => $data['okey']], 'oid,ostat,recreg,recver,rectel,recdtl,dlyway,dlykey');
        if (empty($info))
        {
            throw new AppException('未获取到订单数据');
        }

        if (!in_array($info['ostat'], [10, 11, 12, 13, 21]))
        {
            throw new AppException('只有未发货才可以修改订单收货信息');
        }

        //修改收货信息
        OdrOrderModel::M()->updateById($info['oid'], [
            'recreg' => $data['recreg'],
            'recver' => $data['recver'],
            'rectel' => $data['rectel'],
            'recdtl' => $data['recdtl'],
            'dlyway' => $data['dlyway']
        ]);

        $dlykey = OdrGoodsModel::M()->getOne(['okey' => $data['okey']], 'dlykey');
        if ($dlykey != '')
        {
            //修改对应的物流单信息
            if ($data['dlyway'] == 2)
            {
                $data['expway'] = 0;
            }
            StcLogisticsModel::M()->update(['lkey' => $dlykey], [
                'recreg' => $data['recreg'],
                'recver' => $data['recver'],
                'rectel' => $data['rectel'],
                'recdtl' => $data['recdtl'],
                'lway' => $data['dlyway'],
                'expway' => $data['expway'],
            ]);
        }
    }

    /**
     * 竞拍场次翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        if ($query['tid'] == 32)
        {
            $where = ['tid' => ['in' => [32, 36]]];
        }
        else
        {
            $where = [
                'tid' => ['in' => [11, 12, 13, 31, 33, 34, 35]]
            ];
        }
        $where['plat'] = ['in' => [0, 19, 21, 24, 162]];

        //库存编号
        $bcode = $query['bcode'] ?? '';
        if (!empty($bcode))
        {
            //根据库存编号查订单id
            $pid = PrdProductModel::M()->getOne(['bcode' => $bcode], 'pid', [], '-');
            $goodsList = OdrGoodsModel::M()->getList(['pid' => $pid], 'okey');
            if ($goodsList)
            {
                $okeys = ArrayHelper::map($goodsList, 'okey');
                $where['okey'] = ['in' => $okeys];
            }
            else
            {
                $where['okey'] = -1;
            }
        }

        //订单编号
        $okey = $query['okey'] ?? '';
        if (!empty($okey))
        {
            $exist = OdrOrderModel::M()->exist(['okey' => $okey]);
            if ($exist)
            {
                $where['okey'] = $okey;
            }
            else
            {
                $where['payno'] = $okey;
            }
        }

        //下单人
        $uname = $query['uname'] ?? '';
        if (!empty($uname))
        {
            $oWhere['uname'] = ['like' => '%' . $uname . '%'];
            $acc = AccUserModel::M()->getDistinct('aid', $oWhere);
            if (count($acc) > 0)
            {
                $where['buyer'] = ['in' => $acc];
            }
            else
            {
                $where['buyer'] = -1;
            }
        }

        //联系电话
        $rectel = $query['rectel'] ?? '';
        if (!empty($rectel))
        {
            $where['rectel'] = $rectel;
        }

        //收货人
        $recver = $query['recver'] ?? '';
        if (!empty($recver))
        {
            $where['recver'] = $recver;
        }

        //订单类型
        $tid = $query['tid'] ?? 0;
        if ($tid > 0)
        {
            $where['tid'] = ($tid == 32) ? [32, 36] : $tid;
        }

        //来源渠道
        $plat = $query['plat'] ?? 0;
        if ($plat > 0)
        {
            $where['plat'] = $plat;
        }

        //销售类型
        $src = $query['src'] ?? 0;
        if ($src > 0)
        {
            $where['src'] = $src;
        }

        //订单状态
        $ostat = $query['ostat'] ?? 0;
        if ($ostat > 0)
        {
            $where['ostat'] = $ostat;
        }

        //发货方式
        $dlyway = $query['dlyway'] ?? 0;
        if ($dlyway > 0)
        {
            $where['dlyway'] = $dlyway;
        }

        //时间类型和时间范围
        $otime = $query['otime'] ?? [];
        if (count($otime) == 2)
        {
            $stime = strtotime($otime[0]);
            $etime = strtotime($otime[1]) + 86399;
            $where['otime'] = ['between' => [$stime, $etime]];
        }

        //来源平台
        if ($query['srcplat'])
        {
            $where['plat'] = $query['srcplat'];
        }

        if ($query['wid'])
        {
            $where['whs'] = $query['wid'];
        }

        //返回
        return $where;
    }
}