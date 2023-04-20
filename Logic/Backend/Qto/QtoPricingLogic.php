<?php
namespace App\Module\Sale\Logic\Backend\Qto;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Prd\PrdOrderModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoExmProductModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use App\Params\RedisKey;

/**
 * 填价逻辑
 * Class QtoPricingLogic
 * @package App\Module\Sale\Logic\Backend\Qto
 */
class QtoPricingLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 填价翻页数据
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
        $cols = 'eid,bid,mid,sprc,aprc,kprc,oid,pid,etime,filltime,pacc,fillstat';
        $list = QtoExmProductModel::M()->getList($where, $cols, ['etime' => 1], $size, $idx);

        //如果有数据
        if ($list)
        {
            //提取数据
            $pids = ArrayHelper::map($list, 'pid');
            $bids = ArrayHelper::map($list, 'bid');
            $mids = ArrayHelper::map($list, 'mid');
            $oids = ArrayHelper::map($list, 'oid');
            $accs = ArrayHelper::map($list, 'pacc');

            //获取商品数据
            $prdDict = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], 'bcode,chktime,level');

            //获取级别字典
            $levelDict = QtoLevelModel::M()->getDict('lkey');

            //获取品牌机型数据
            $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');
            $midDict = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids], 'plat' => 16], 'mname');

            //获取质检信息
            $reportDict = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => 21], 'bconc', ['atime' => -1]);

            //获取订单信息
            $oidDicy = PrdOrderModel::M()->getDict('oid', ['oid' => ['in' => $oids]], 'thrsn');

            //获取用户信息
            $accDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $accs]], 'rname');

            //补充数据
            foreach ($list as $key => $item)
            {
                $pid = $item['pid'];
                $list[$key]['bcode'] = $prdDict[$pid]['bcode'] ?? '-';
                $list[$key]['bname'] = $bidDict[$item['bid']]['bname'] ?? '-';
                $list[$key]['mname'] = $midDict[$item['mid']]['mname'] ?? '-';
                $list[$key]['etime'] = DateHelper::toString($item['etime']);
                $list[$key]['bconc'] = $reportDict[$pid]['bconc'] ?: '-';
                $list[$key]['sprc'] = $item['sprc'] > 0 ? $item['sprc'] : ($query['fillstat'] == 1 ? '' : '-');
                $list[$key]['aprc'] = $item['aprc'] > 0 ? $item['aprc'] : ($query['fillstat'] == 1 ? '' : '-');
                $list[$key]['kprc'] = $item['kprc'] > 0 ? $item['kprc'] : ($query['fillstat'] == 1 ? '' : '-');
                $list[$key]['okey'] = $oidDicy[$item['oid']]['thrsn'] ?? '-';
                $list[$key]['chktime'] = DateHelper::toString($prdDict[$pid]['chktime'] ?? 0);
                $list[$key]['filltime'] = DateHelper::toString($item['filltime']);
                $list[$key]['pacc'] = $accDict[$item['pacc']]['rname'] ?? '-';
                $list[$key]['lname'] = $levelDict[$prdDict[$pid]['level']]['lname'] ?? '-';
            }
        }

        //返回
        return $list;
    }

    /**
     * 填价数据总条数
     * @param array $query
     * @return int
     */
    public function getPagerCount(array $query)
    {
        //获取数据条件
        $where = $this->getPagerWhere($query);

        //获取数量
        $count = QtoExmProductModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 查询条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //默认条件
        $where = ['plat' => SaleDictData::XYC_BM_PLAT];

        //品牌
        $bid = $query['bid'];
        if ($bid)
        {
            $where['bid'] = $bid;
        }

        //是否填价
        $fillstat = $query['fillstat'];
        if ($fillstat)
        {
            $where['fillstat'] = $fillstat;
        }

        //库存编号
        $bcode = $query['bcode'];
        if ($bcode)
        {
            $where['pid'] = PrdProductModel::M()->getOne(['bcode' => $bcode], 'pid', [], -1);
        }

        //订单号
        $okey = $query['okey'];
        if ($okey)
        {
            $where['oid'] = PrdOrderModel::M()->getOne(['thrsn' => $okey], 'oid', [], -1);
        }

        //返回
        return $where;
    }

    /**
     * 商品详情
     * @param string $pid
     * @return array
     * @throws
     */
    public function getDetail(string $pid)
    {
        //获取商品信息
        $prdInfo = PrdProductModel::M()->getRowById($pid, 'bcode,level,chktime');
        if ($prdInfo == false)
        {
            throw new AppException('商品信息不存在', AppException::NO_DATA);
        }

        //获取核价单数据
        $cols = 'sprc,kprc,aprc,pid,mid,oid,etime,filltime,pacc';
        $info = QtoExmProductModel::M()->getRow(['pid' => $pid], $cols);
        if ($info == false)
        {
            throw new AppException('核价单数据不存在', AppException::NO_DATA);
        }

        //补充数据
        $info['sprc'] = $info['sprc'] ? number_format($info['sprc']) : '-';
        $info['kprc'] = $info['sprc'] ? number_format($info['kprc']) : '-';
        $info['aprc'] = $info['sprc'] ? number_format($info['aprc']) : '-';
        $info['bcode'] = $prdInfo['bcode'] ?: '-';
        $info['mname'] = QtoModelModel::M()->getOne(['mid' => $info['mid'], 'plat' => 16], 'mname', [], '-');
        $info['lname'] = QtoLevelModel::M()->getOneById($prdInfo['level'], 'lname', [], '-');
        $info['etime'] = DateHelper::toString($info['etime']);
        $info['filltime'] = DateHelper::toString($info['filltime']);
        $info['chktime'] = DateHelper::toString($prdInfo['chktime']);
        $info['pacc'] = AccUserModel::M()->getOneById($info['pacc'], 'rname', [], '-');
        $info['thrsn'] = PrdOrderModel::M()->getOneById($info['oid'], 'thrsn', [], '-');

        //返回
        return $info;
    }

    /**
     * 填价
     * @param string $pid
     * @param int $sprc
     * @param int $aprc
     * @param int $kprc
     * @throws
     */
    public function fillprc(string $acc, array $data)
    {
        //解析参数
        $pid = $data['pid'];
        $aprc = $data['aprc'];
        $kprc = $data['kprc'];
        $sprc = $data['sprc'];

        //获取商品信息
        $prdInfo = PrdProductModel::M()->getRowById($pid, 'inway,level');
        if ($prdInfo == false)
        {
            throw new AppException('商品信息不存在', AppException::NO_DATA);
        }

        //获取核价单数据
        $exmInfo = QtoExmProductModel::M()->getRow(['pid' => $pid], 'eid,chkpcost');
        if ($exmInfo == false)
        {
            throw new AppException('核价单数据不存在', AppException::NO_DATA);
        }

        //修改数据
        if ($sprc && $kprc && ($sprc > $kprc))
        {
            throw new AppException('秒杀价不能小于起拍价', AppException::OUT_OF_OPERATE);
        }
        if ($kprc && $sprc && $aprc && ($kprc - $sprc) < $aprc * 3)
        {
            throw new AppException('秒杀价-起拍价不能小于3个加拍价', AppException::OUT_OF_OPERATE);
        }
        if ($prdInfo['inway'] == 1611)
        {
            //服务费 10~150  最低167 最高2500
            $baseAmt = $exmInfo['chkpcost'];//保底价
            $minSprc = ceil($baseAmt / 0.94);
            if ($baseAmt <= 156)
            {
                $minSprc = $baseAmt + 10;
            }
            if ($baseAmt >= 2350)
            {
                $minSprc = $baseAmt + 150;
            }
            if ($sprc < $minSprc)
            {
                $msg = "当前保底价: $baseAmt , 不能低于: $minSprc";
                throw new AppException($msg, AppException::OUT_OF_OPERATE);
            }
        }

        $data = [
            'filltime' => time(),
            'sprc' => $sprc,
            'kprc' => $kprc,
            'aprc' => $aprc,
            'pacc' => $acc
        ];

        //推送队列 - 原条件 if (($sprc && $kprc && $aprc) || ($sprc > 0 && $prdInfo['level'] > 32))
        //2020-09-03 更改为三个价格都填写
        if ($sprc > 0 && $kprc > 0 && $aprc > 0)
        {
            $data['fillstat'] = 2;
            $this->redis->lPush(RedisKey::QTO_PUSH_PRICE, serialize(['eid' => $exmInfo['eid']]));
        }
        QtoExmProductModel::M()->updateById($exmInfo['eid'], $data);
    }

    /**
     * 获取品牌数据
     * @param int $fillstat
     * @return array
     */
    public function getBrands(int $fillstat)
    {
        //获取数据
        $data = [];
        $where = ['fillstat' => $fillstat, 'plat' => SaleDictData::XYC_BM_PLAT, '$group' => 'bid'];
        $list = QtoExmProductModel::M()->getList($where, 'bid,count(bid) as count', ['bid' => 1]);
        if ($list)
        {
            //获取品牌字典
            $bids = ArrayHelper::map($list, 'bid');
            $bidDict = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');
            foreach ($list as $key => $item)
            {
                $data[] = [
                    'bid' => $item['bid'],
                    'bname' => $bidDict[$item['bid']]['bname'] ?? '-',
                    'count' => $item['count']
                ];
            }
        }

        //返回
        return $data;
    }
}