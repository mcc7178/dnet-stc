<?php
namespace App\Module\Sale\Logic\Api\Xinxin\Mcp\Mine;

use App\Exception\AppException;
use App\Module\Qto\Data\QtoBrandData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use App\Model\Crm\CrmPurchaseModel;
use App\Model\Prd\PrdSupplyModel;
use App\Model\Prd\PrdBidSalesModel;
use App\Model\Prd\PrdShopSalesModel;
use App\Model\Prd\PrdProductModel;
use App\Module\Sale\Data\XinxinDictData;
use App\Service\Qto\QtoInquiryInterface;
use App\Service\Qto\QtoOptionsInterface;
use App\Service\Acc\AccUserInterface;
use Swork\Helper\ArrayHelper;

/**
 * 个人中心-采购需求逻辑
 */
class MinePurchaseLogic extends BeanCollector
{
    /**
     * @Reference()
     * @var AccUserInterface
     */
    private $accUserInterface;

    /**
     * @Reference("qto")
     * @var QtoInquiryInterface
     */
    private $qtoInquiryInterface;

    /**
     * @Inject()
     * @var QtoBrandData
     */
    private $qtoBrandData;

    /**
     * @Reference("qto")
     * @var QtoOptionsInterface
     */
    private $qtoOptionsInterface;

    /**
     * 获取列表数据
     * @param string $uid 用户id
     * @param
     * @return array
     * @throws
     */
    public function list(string $uid)
    {
        //数据检测
        if ($uid == '')
        {
            throw new AppException('缺少用户', AppException::NO_DATA);
        }

        //获取内部系统uid
        $acc = $this->accUserInterface->getRow(['_id' => $uid], 'aid');
        if ($acc == false)
        {
            throw new AppException('用户不存在', AppException::NO_DATA);
        }

        //获取列表数据
        $list = CrmPurchaseModel::M()->getList(['buyer' => $acc['aid']], '*', ['stat' => 1, 'atime' => -1]);

        //如果有数据
        if ($list)
        {
            //提取mid集合
            $mids = ArrayHelper::map($list, 'mid');
            $midels = $this->qtoInquiryInterface->getDictModels($mids, 0);

            //获取内存字典
            $mdrams = $this->qtoOptionsInterface->getList(['plat' => 0, 'cid' => 17000], 'oid,oname');
            $mdramsDict = ArrayHelper::dict($mdrams, 'oid');

            //已过期主键
            $pkeys = [];

            //补充数据
            foreach ($list as $key => $value)
            {
                $list[$key]['statdesc'] = XinxinDictData::PCS_STAT[$value['stat']] ?? '-';
                $list[$key]['mname'] = $midels[$value['mid']]['mname'] ?? '-';
                $stat = $value['stat'];

                //组装级别
                $levels = json_decode($value['level']);
                $levelarray = [];
                foreach ($levels as $val)
                {
                    $levelarray[] = XinxinDictData::PCS_LEVEL[$val];
                }
                $list[$key]['levels'] = $levelarray;

                //组装内存
                $mdram = json_decode($value['mdram']);
                $mdramarray = [];
                foreach ($mdram as $val)
                {
                    $mdramarray[] = isset($mdramsDict[$val]) ? $mdramsDict[$val]['oname'] : '-';
                }
                $list[$key]['rams'] = $mdramarray;

                //获取在售（只显示生效的）
                $list[$key]['onsale'] = 0;
                if ($value['stat'] == 1)
                {
                    $salePids = $this->getSalePid($value);
                    $list[$key]['onsale'] = count($salePids);
                }

                //判断数据是否过期
                if ($value['expired'] < time())
                {
                    $stat = 2;
                    if ($value['stat'] == 1)
                    {
                        $pkeys[] = $list[$key]['pkey'];
                    }
                }

                $list[$key]['stat'] = $stat;
                $list[$key]['statdesc'] = XinxinDictData::PCS_STAT[$stat] ?? '-';
                $list[$key]['time'] = date("Y-m-d", $value['expired']);
            }

            //有已过期的更新数据
            if (count($pkeys) > 0)
            {
                CrmPurchaseModel::M()->update(['pkey' => ['in' => $pkeys]], ['stat' => 2]);
            }

            //重新排序，将有在售商品的排在最上面
            $havasale = [];
            foreach ($list as $key => $value)
            {
                if ($value['onsale'] > 0)
                {
                    $havasale[] = $value;
                    unset($list[$key]);
                }
            }

            //合并数据
            $list = array_merge($havasale, $list);
        }

        //返回
        return $list;
    }

    /**
     * 获取详情
     * @param string $pkey 主键
     * @param
     * @return array
     * @throws
     */
    public function info(string $pkey)
    {
        //数据检测
        if ($pkey == '')
        {
            throw new AppException('缺少参数', AppException::NO_DATA);
        }

        //获取详情页
        $info = CrmPurchaseModel::M()->getRowById($pkey, '*');

        //如果有数据
        if ($info)
        {
            //补充数据
            $optionData = XinxinDictData::PCS_OPTION;

            //组装返回的数据结构
            $options = [];

            //组装数据
            foreach ($optionData as $key => $value)
            {
                //对应选项
                $opts = '';

                //获取内存
                if ($key == 17000)
                {
                    $opts = $info['mdram'];
                }

                //获取网络制式
                if ($key == 15000)
                {
                    $opts = $info['mdnet'];
                }

                //获取颜色
                if ($key == 16000)
                {
                    $opts = $info['mdcolor'];
                }

                //获取销售地
                if ($key == 14000)
                {
                    $opts = $info['mdofsale'];
                }

                //将json转为数组进行处理
                $opts = json_decode($opts);
                if (count($opts) > 0)
                {
                    //组合选项数据
                    $optDesc = $this->getOptions($opts, $key);
                    $options[] = [
                        'cid' => $key,
                        'cname' => $value,
                        'opts' => $optDesc,
                    ];
                }
            }

            //添加成色
            $levelKeys = ArrayHelper::toArray($info['level']);
            $levelList = [];
            foreach ($levelKeys as $value)
            {
                $levelList[] = [
                    'oid' => $value,
                    'oname' => XinxinDictData::PCS_LEVEL[$value],
                    'chk' => 0,
                ];
            }
            $options[] = [
                'cid' => 10,
                'cname' => '成色',
                'opts' => $levelList,
            ];

            $info['time'] = date('Y-m-d', $info['expired']);
            $info['options'] = $options;
        }

        //返回
        return $info;
    }

    /**
     * 获取所有品牌
     * @param int $plat 平台ID
     * @param int $ptype 品牌类型（1：手机，2：平板，3：电脑，4：数码，0：全部）
     * @return mixed
     * @throws
     */
    public function getBrands(int $plat, int $ptype)
    {
        return $this->qtoBrandData->getList($plat, $ptype);
    }

    /**
     * 获取某品牌下的所有机型
     * @param int $bid 品牌id
     * @return mixed
     * @throws
     */
    public function getModels(int $bid)
    {
        return $this->qtoInquiryInterface->getModels(11, $bid);
    }

    /**
     * 根据机型获取选项
     * @param int $plat 平台
     * @param int $mid 品牌id
     * @return mixed
     * @throws
     */
    public function options(int $plat, int $mid)
    {
        //获取选项数据
        $info = $this->qtoInquiryInterface->getItems($plat, $mid);

        //组装返回数据
        $opts = [];
        foreach ($info['items'] as $key => $value)
        {
            //是否获取选项
            $item = 'Y';

            //内存17000、版本14000、颜色16000、网络15000
            if (in_array($value['cid'], [17000, 14000, 16000, 15000]))
            {
                //版本只取国行和其他
                $opt = [];
                foreach ($value['opts'] as $val)
                {
                    //特殊机型网络只显示全网通和其他
                    if (in_array($mid, XinxinDictData::PCS_SPECIALMID) && $value['cid'] == 15000)
                    {
                        if ($item == 'Y')
                        {
                            $mdnet = XinxinDictData::PCS_MDNET;
                            foreach ($mdnet as $k => $v)
                            {
                                $opt[] = [
                                    'oid' => $k,
                                    'oname' => $v,
                                    'chk' => 0
                                ];
                            }
                            $item = 'N';
                        }
                    }
                    else
                    {
                        if ($value['cid'] == 14000)
                        {
                            if ($item == 'Y')
                            {
                                $mdofsale = XinxinDictData::PCS_MDOFSALE;
                                foreach ($mdofsale as $k => $v)
                                {
                                    $opt[] = [
                                        'oid' => $k,
                                        'oname' => $v,
                                        'chk' => 0
                                    ];
                                }
                                $item = 'N';
                            }
                        }
                        else
                        {
                            $opt[] = [
                                'oid' => $val['oid'],
                                'oname' => $val['oname'],
                                'chk' => 0,
                            ];
                        }
                    }
                }

                $opts[] = [
                    'cid' => $value['cid'],
                    'cname' => XinxinDictData::PCS_OPTION[$value['cid']],
                    'opts' => $opt,
                ];
            }
        }

        //获取成色
        $levelData = XinxinDictData::PCS_LEVEL;
        $levels = [];
        foreach ($levelData as $key => $value)
        {
            $levels[] = [
                'oid' => $key,
                'oname' => $value,
                'chk' => 0,
            ];
        }

        //添加成色
        $opts[] = [
            'cid' => '10',
            'cname' => '成色',
            'opts' => $levels
        ];

        //返回
        return $opts;
    }

    /**
     * 保存采购需求
     * @param string $sdata 保存数据
     * @param string $uid 用户id
     * @return mixed
     * @throws
     */
    public function save(string $sdata, string $uid)
    {
        if ($sdata == '')
        {
            throw new AppException('没有数据', AppException::NO_DATA);
        }

        if ($uid == '')
        {
            throw new AppException('请登录', AppException::NO_DATA);
        }

        //获取用户
        $accuser = $this->accUserInterface->getRow(['_id' => $uid], 'aid');
        $buyer = $accuser['aid'];

        //获取保存数据
        $data = json_decode($sdata, true);
        $options = $data['options'];
        $actform = $data['actForm'];
        $level = '[]';
        $mdram = '[]';
        $mdnet = '[]';
        $mdcolor = '[]';
        $mdofsale = '[]';

        //获取选项数据
        foreach ($options as $value)
        {
            //选项值
            $opts = $value['opts'];

            //获取级别
            if ($value['cid'] == 10)
            {
                $level = $this->getChk($opts);
            }

            //获取内存
            if ($value['cid'] == 17000)
            {
                $mdram = $this->getChk($opts);
            }

            //获取网络制式
            if ($value['cid'] == 15000)
            {
                $mdnet = $this->getChk($opts);
            }

            //获取颜色
            if ($value['cid'] == 16000)
            {
                $mdcolor = $this->getChk($opts);
            }

            //获取销售地
            if ($value['cid'] == 14000)
            {
                $mdofsale = $this->getChk($opts);
            }
        }

        //组装保存数据
        $pkey = $sid = $sid = date("ymdHis") . sprintf("%04d", rand(1, 9999));
        $savedata = [
            'pkey' => $pkey,
            'buyer' => $buyer,
            'bid' => $data['bid'],
            'mid' => $data['mid'],
            'level' => $level,
            'mdram' => $mdram,
            'mdnet' => $mdnet,
            'mdcolor' => $mdcolor,
            'mdofsale' => $mdofsale,
            'mdwarr' => '[]',
            'qty' => $actform['qty'],
            'stat' => 1,
            'pcsstat' => 1,
            'price1' => $actform['min'],
            'price2' => $actform['max'],
            'expired' => strtotime($actform['time']) + 86399,
            'rmk' => $actform['rmk'],
            'atime' => time(),
        ];

        //新增数据
        CrmPurchaseModel::M()->insert($savedata);

        //获取符合条件的数据
        $salePids = $this->getSalePid($savedata);

        //返回
        return [
            'saleqty' => count($salePids),
            'pkey' => $pkey
        ];
    }

    /**
     * 保存采购需求
     * @param string $pkey 主键
     * @return int
     * @throws
     */
    public function cancel(string $pkey)
    {
        if ($pkey == '')
        {
            throw new AppException('缺少参数', AppException::NO_DATA);
        }

        //取消采购需求
        $stat = 3;
        CrmPurchaseModel::M()->updateById($pkey, ['stat' => $stat, 'ctime' => time()]);

        //返回
        return $stat;
    }

    /**
     * 获取在售商品
     * @param string $uid 登录者
     * @return boolean
     * @throws
     */
    public function stat(string $uid)
    {
        //数据检测
        if ($uid == '')
        {
            return 0;
        }

        //获取内部系统uid
        $acc = $this->accUserInterface->getRow(['_id' => $uid], 'aid');
        if ($acc == false)
        {
            return 0;
        }

        //获取用户所有未完需求
        $where = [
            'buyer' => $acc['aid'],
            'stat' => 1
        ];
        $list = CrmPurchaseModel::M()->getList($where, '*');

        //数据检测
        if ($list == false)
        {
            //没有需求
            $stat = 0;
        }
        else
        {
            //有采购需求
            $stat = 1;

            //是否有在售商品
            foreach ($list as $key => $value)
            {
                //获取符合条件的数据
                $salePids = $this->getSalePid($value);
                $saleQty = count($salePids);
                if ($saleQty > 0)
                {
                    //有在售商品
                    $stat = 2;
                }
            }
        }

        //返回
        return $stat;
    }

    /**
     * 获取符合符合用户设置的在售商品
     * @param string $pkey 需求id
     * @return array
     * @throws
     */
    public function sale(string $pkey)
    {
        //数据检测
        if ($pkey == '')
        {
            throw new AppException('缺少参数', AppException::NO_DATA);
        }

        //获取数据
        $info = CrmPurchaseModel::M()->getRow(['pkey' => $pkey, 'stat' => 1], '*');

        //是否存在数据
        if ($info == false)
        {
            throw new AppException('没有数据', AppException::NO_DATA);
        }

        //获取符合条件的所有商品
        $salePids = $this->getSalePid($info);
        $pids = ArrayHelper::map($salePids, 'pid');

        //获取老系统pid
        $opids = PrdProductModel::M()->getList(['pid' => ['in' => $pids]], '_id');
        $opidsmap = ArrayHelper::map($opids, '_id');

        //返回
        return $opidsmap;
    }

    /**
     * 获取选中的值
     * @param array $opts 选项数据
     * @return string
     * @throws
     */
    public function getChk(array $opts)
    {
        //取出已选择的选项
        $chk = [];
        foreach ($opts as $val)
        {
            if ($val['chk'] == 1)
            {
                $chk[] = $val['oid'];
            }
        }

        //格式化字符串
        $chkstr = '[' . implode(",", $chk) . ']';

        //返回
        return $chkstr;
    }

    /**
     * 根据选项id获取选项描述
     * @param array $opts 选项数据
     * @param int $cid 选项数据
     * @return array
     * @throws
     */
    public function getOptions(array $opts, int $cid)
    {
        //返回数据
        $options = [];

        //取出已选择的选项
        $oids = implode('#', $opts);

        //获取选项描述
        $info = $this->qtoOptionsInterface->getOptsDetail(0, $oids, 'oid,oname');

        //如果数据
        if ($info)
        {
            //修改版本排序
            if ($cid == 14000)
            {
                if (count($info) == 2)
                {
                    $mdofsale = XinxinDictData::PCS_MDOFSALE;
                    foreach ($mdofsale as $key => $value)
                    {
                        $options[] = [
                            'oid' => $key,
                            'oname' => $value,
                            'chk' => 0
                        ];
                    }
                }
                else
                {
                    $options[] = [
                        'oid' => $info[0]['oid'],
                        'oname' => XinxinDictData::PCS_MDOFSALE[$info[0]['oid']],
                        'chk' => 0
                    ];
                }
            }
            else
            {
                foreach ($info as $value)
                {
                    $options[] = [
                        'oid' => $value['oid'],
                        'oname' => $value['oname'],
                        'chk' => 0
                    ];
                }
            }
        }

        //返回
        return $options;
    }

    /**
     * 获取在售机器数量
     * @param array $value 选项数据
     * @return array
     * @throws
     */
    public function getSalePid(array $value)
    {
        //获取符合条件的数据
        $where = [
            'bid' => $value['bid'],
            'mid' => $value['mid'],
            'salestat' => 1
        ];

        $level = json_decode($value['level']);
        $mdram = json_decode($value['mdram']);
        $mdnet = json_decode($value['mdnet']);
        $mdcolor = json_decode($value['mdcolor']);
        $mdofsale = json_decode($value['mdofsale']);
        if (count($level) > 0)
        {
            $where['level'] = ['in' => $level];
        }
        if (count($mdram) > 0)
        {
            $where['mdram'] = ['in' => $mdram];
        }
        if (count($mdnet) > 0)
        {
            //特殊机型网络制式处理-如果选择了其他 则匹配所有版本
            if (!in_array($value['mid'], XinxinDictData::PCS_SPECIALMID) || !in_array(15601, $mdnet))
            {
                $where['mdnet'] = ['in' => $mdnet];
            }
        }
        if (count($mdcolor) > 0)
        {
            $where['mdcolor'] = ['in' => $mdcolor];
        }
        if (count($mdofsale) > 0)
        {
            //选择其他版本时匹配所有版本
            if (!in_array(14107, $mdofsale))
            {
                $where['mdofsale'] = ['in' => $mdofsale];
            }
        }

        //获取数据
        $listSupply = PrdSupplyModel::M()->getList($where, 'sid,pid');

        //提取pid
        $pids = ArrayHelper::map($listSupply, 'pid');

        //获取符合条件的pid集合
        $salePids = [];
        if (count($pids) > 0)
        {
            //获取竞价商城上架中的数据
            $bidList = PrdBidSalesModel::M()->getList(['pid' => ['in' => $pids], 'plat' => 21, 'stat' => ['in' => [12, 13]], 'isatv' => 1], 'pid');

            //获取一口价在销售的商品
            $shopList = PrdShopSalesModel::M()->getList([
                'pid' => ['in' => $pids],
                'stat' => 31,
                'isatv' => 1,
                'ptime' => ['<=' => time()],
            ], 'pid');

            //合并数据
            $salePids = array_merge($bidList, $shopList);
        }

        //返回
        return $salePids;
    }

    /**
     * 获取匹配上架商品的采购需求
     * @param array $pids 选项数据
     * @return array
     * @throws
     */
    public function demand(array $pids)
    {
        //是否存在数据
        if ($pids == false)
        {
            throw new AppException('缺少参数', AppException::NO_DATA);
        }

        //获取新系统pid
        $plist = PrdProductModel::M()->getList(['_id' => ['in' => $pids]], 'pid');
        $pids = ArrayHelper::map($plist, 'pid');

        $spids = [];
        if (count($pids) > 0)
        {
            //获取竞价商城上架中的数据
            $bidList = PrdBidSalesModel::M()->getList(['pid' => ['in' => $pids], 'stat' => ['in' => [12, 13]], 'isatv' => 1], 'pid');

            //获取一口价在销售的商品
            $shopList = PrdShopSalesModel::M()->getList([
                'pid' => ['in' => $pids],
                'stat' => 31,
                'isatv' => 1,
                'ptime' => ['<=' => time()],
            ], 'pid');

            //合并数据
            $salelist = array_merge($bidList, $shopList);
            $spids = ArrayHelper::map($salelist, 'pid');
        }

        //获取供应库数据
        $supplylist = [];
        if ($spids)
        {
            $supplylist = PrdSupplyModel::M()->getList(['pid' => ['in' => $spids]], '*');
        }

        //判断这些产品是否存在采购需求
        $purchase = [];
        if ($supplylist)
        {
            foreach ($supplylist as $value)
            {
                //获取符合条件的数据
                $where = [
                    'bid' => $value['bid'],
                    'mid' => $value['mid'],
                    'stat' => 1,
                ];

                //获取采购需求数据
                $plist = CrmPurchaseModel::M()->getList($where, '*');

                if ($plist)
                {
                    foreach ($plist as $val)
                    {
                        $plevel = $this->matching($value['level'], json_decode($val['level']));
                        $mdram = $this->matching($value['mdram'], json_decode($val['mdram']));
                        $mdnet = $this->matching($value['mdnet'], json_decode($val['mdnet']));
                        $mdcolor = $this->matching($value['mdcolor'], json_decode($val['mdcolor']));
                        $mdofsale = $this->matching($value['mdofsale'], json_decode($val['mdofsale']));

                        //如果采购需求销售地选择“其他版本”则不匹配版本
                        if (in_array(14107, json_decode($val['mdofsale'])))
                        {
                            if ($plevel && $mdram && $mdnet && $mdcolor)
                            {
                                $purchase[] = [
                                    'key' => $value['mid'] . $val['buyer'] . $val['pkey'],
                                    'pkey' => $val['pkey'],
                                    'pid' => $value['pid'],
                                    'bid' => $value['bid'],
                                    'mid' => $value['mid'],
                                    'uid' => $val['buyer'],
                                    'qty' => $val['qty'],
                                    'rmk' => $val['rmk'],
                                    'atime' => $val['atime'],
                                ];
                            }
                        }
                        elseif (in_array($val['mid'], XinxinDictData::PCS_SPECIALMID) && in_array(15601, json_decode($val['mdnet'])))
                        {
                            if ($plevel && $mdram && $mdcolor && $mdofsale)
                            {
                                $purchase[] = [
                                    'key' => $value['mid'] . $val['buyer'] . $val['pkey'],
                                    'pkey' => $val['pkey'],
                                    'pid' => $value['pid'],
                                    'bid' => $value['bid'],
                                    'mid' => $value['mid'],
                                    'uid' => $val['buyer'],
                                    'qty' => $val['qty'],
                                    'rmk' => $val['rmk'],
                                    'atime' => $val['atime'],
                                ];
                            }
                        }
                        else
                        {
                            if ($plevel && $mdram && $mdnet && $mdcolor && $mdofsale)
                            {
                                $purchase[] = [
                                    'key' => $value['mid'] . $val['buyer'] . $val['pkey'],
                                    'pkey' => $val['pkey'],
                                    'pid' => $value['pid'],
                                    'bid' => $value['bid'],
                                    'mid' => $value['mid'],
                                    'uid' => $val['buyer'],
                                    'qty' => $val['qty'],
                                    'rmk' => $val['rmk'],
                                    'atime' => $val['atime'],
                                ];
                            }
                        }
                    }
                }
            }
        }

        //处理数据相同的机型相同的客户同一个需求只推一台，相同的机型多个需求推多条
        $purchase = ArrayHelper::dict($purchase, 'key');
        $list = [];
        foreach ($purchase as $value)
        {
            $list[] = $value;
        }

        //返回
        return $list;
    }

    /**
     * 匹配条件
     * @param string $svalue 选项数据
     * @param array $pvalue 选项数据
     * @return boolean
     * @throws
     */
    public function matching(string $svalue, array $pvalue)
    {
        if (($svalue > 0 && count($pvalue) > 0 && in_array($svalue, $pvalue)) || ($svalue == 0 && count($pvalue) == 0))
        {
            return true;
        }

        //返回
        return false;
    }
}