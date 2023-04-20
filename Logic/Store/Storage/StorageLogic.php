<?php
namespace App\Module\Sale\Logic\Store\Storage;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Crm\CrmStaffModel;
use App\Model\Mqc\MqcReportModel;
use App\Model\Prd\PrdProductModel;
use App\Model\Qto\QtoBrandModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Stc\StcInoutGoodsModel;
use App\Model\Stc\StcInoutSheetModel;
use App\Model\Stc\StcStorageModel;
use App\Model\Sys\SysWarehouseModel;
use App\Module\Pos\Logic\ShelfLogic;
use App\Module\Pub\Data\SysWarehouseData;
use App\Module\Pub\Logic\UniqueKeyLogic;
use App\Module\Sale\Data\SaleDictData;
use App\Module\Stc\Data\StcDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Context;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;
use Throwable;

class StorageLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var ShelfLogic
     */
    private $shelfLogic;

    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 获取翻页列表
     * @param array $query
     * @param int $idx
     * @param int $size
     * @return array|false|mixed
     */
    public function getPager(array $query, int $idx, int $size)
    {
        //数据条件
        $where = $this->getPagerWhere($query);

        //所需字段
        $cols = 'sid,skey,fwhs,twhs,qty,ftime,facc,sacc,fstat,tstat,ttime,tacc,aacc,atime';

        //1：入库，2：出库
        if ($query['type'] && $query['type'] == 2)
        {
            $order = [
                'fstat' => 1,
                'ftime' => -1,
                'atime' => -1,
            ];
        }
        else
        {
            $order = [
                'tstat' => 1,
                'ttime' => -1,
                'atime' => -1,
            ];
        }

        //获取数据列表
        $list = StcInoutSheetModel::M()->getList($where, $cols, $order, $size, $idx);
        if ($list == false)
        {
            return [];
        }

        //出库人、接收人、入库人、创建人
        $accs = ArrayHelper::maps([$list, $list, $list, $list], ['facc', 'sacc', 'tacc', 'aacc']);
        $accUser = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $accs]], 'rname');

        //获取分仓字典
        $whsDict = SysWarehouseData::D()->getDict();

        //补充数据
        foreach ($list as $key => $value)
        {
            //出库人、接收人、入库人、创建人
            $list[$key]['facc'] = $accUser[$value['facc']]['rname'] ?? '-';
            $list[$key]['sacc'] = $accUser[$value['sacc']]['rname'] ?? '-';
            $list[$key]['tacc'] = $accUser[$value['tacc']]['rname'] ?? '-';
            $list[$key]['aacc'] = $accUser[$value['aacc']]['rname'] ?? '-';

            //时间格式化
            $list[$key]['ftime'] = DateHelper::toString($value['ftime']);
            $list[$key]['ttime'] = DateHelper::toString($value['ttime']);
            $list[$key]['atime'] = DateHelper::toString($value['atime']);

            //来源、状态、入库状态、出库状态
            $list[$key]['fwhs'] = $whsDict[$value['fwhs']] ?? '-';
            $list[$key]['twhs'] = $whsDict[$value['twhs']] ?? '-';
            $list[$key]['tstat'] = StcDictData::IN_STAT[$value['tstat']] ?? '-';
            $list[$key]['fstat'] = StcDictData::OUT_STAT[$value['fstat']] ?? '-';

            //补充单类型，1：入库，2：出库
            $list[$key]['type'] = $query['type'] ?? 1;
        }

        //填充默认值
        ArrayHelper::fillDefaultValue($list);

        //返回
        return $list;
    }

    /**
     * 获取入库单详情
     * @param string $skey
     * @return array|bool|mixed
     * @throws
     */
    public function getInfo(string $skey)
    {
        //查询的字段
        $cols = 'sid,skey,fwhs,twhs,qty,ftime,facc,sacc,fstat,tstat,ttime,tacc,aacc,atime';

        //获取数据
        $info = StcInoutSheetModel::M()->getRow(['skey' => $skey], $cols);
        if ($info == false)
        {
            throw new AppException('没有该数据', AppException::NO_DATA);
        }

        //补充来源，目标
        $info['fwhs'] = SysWarehouseData::D()->getName($info['fwhs']) ?? '-';
        $info['twhs'] = SysWarehouseData::D()->getName($info['twhs']) ?? '-';

        //姓名字典
        $accs = array_merge([$info['facc'], $info['sacc'], $info['tacc'], $info['aacc']]);
        $userList = AccUserModel::M()->getList(['aid' => ['in' => $accs]], 'aid, rname');
        $accUser = array_column($userList, 'rname', 'aid');

        //补充姓名
        $info['facc'] = $accUser[$info['facc']] ?? '-';
        $info['sacc'] = $accUser[$info['sacc']] ?? '-';
        $info['tacc'] = $accUser[$info['tacc']] ?? '-';
        $info['aacc'] = $accUser[$info['aacc']] ?? '-';

        //时间格式
        $info['ftime'] = DateHelper::toString($info['ftime']);
        $info['ttime'] = DateHelper::toString($info['ttime']);
        $info['atime'] = DateHelper::toString($info['atime']);

        //补充状态
        $info['tstatname'] = StcDictData::IN_STAT[$info['tstat']] ?? '-';
        $info['fstatname'] = StcDictData::OUT_STAT[$info['fstat']] ?? '-';

        //仓库进出单据商品明细
        $stcGoods = StcInoutGoodsModel::M()->getList(['sid' => ['in' => [$info['sid']]]], 'pid,tacc');

        //补充商品明细
        $goods = [];
        if (count($stcGoods) > 0)
        {
            //user字典
            $taccs = ArrayHelper::map($stcGoods, 'tacc');
            $tacc = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $taccs]], 'rname');

            //机型字典
            $pids = ArrayHelper::map($stcGoods, 'pid');

            //查询商品
            $cols = 'bcode,level,mid,pid,bid,salecost,pname';
            $prdProduct = PrdProductModel::M()->getDict('pid', ['pid' => ['in' => $pids]], $cols);

            if (count($prdProduct) > 0)
            {
                //机型等级字典
                $lkeys = ArrayHelper::map($prdProduct, 'level');
                $qtoLevel = QtoLevelModel::M()->getDict('lkey', ['lkey' => ['in' => $lkeys]], 'lname');

                //品牌字典
                $bids = ArrayHelper::map($prdProduct, 'bid');
                $brand = QtoBrandModel::M()->getDict('bid', ['bid' => ['in' => $bids]], 'bname');

                //机型字典
                $mids = ArrayHelper::map($prdProduct, 'mid');
                $model = QtoModelModel::M()->getDict('mid', ['mid' => ['in' => $mids]], 'mname');

                //补充机型数据
                foreach ($stcGoods as $value)
                {
                    $pid = $value['pid'];
                    $goods[] = [
                        'pid' => $pid,
                        'prdcost' => $prdProduct[$pid]['salecost'] ?? '-',
                        'bid' => $brand[$prdProduct[$pid]['bid']]['bid'] ?? '-',
                        'bname' => $brand[$prdProduct[$pid]['bid']]['bname'] ?? '-',
                        'bcode' => $prdProduct[$pid]['bcode'] ?? '-',
                        'pname' => $model[$prdProduct[$pid]['mid']]['mname'] ?? '-',
                        'level' => $qtoLevel[$prdProduct[$pid]['level']]['lname'] ?? '-',
                        'tacc' => $tacc[$value['tacc']]['rname'] ?? '-',
                    ];
                }

            }
        }
        //增加右边的机型列表
        $info['goods'] = $goods;

        //填充默认值
        ArrayHelper::fillDefaultValue($info);

        //返回
        return $info;
    }

    /**
     * 获取列表总数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //获取查询条件
        $where = $this->getPagerWhere($query);

        //获取数据
        return StcInoutSheetModel::M()->getCount($where);
    }

    /**
     * 一键入库
     * @param string $acc
     * @param string $sid
     * @return bool
     * @throws
     */
    public function saveInput(string $acc, string $sid)
    {
        //查询的字段
        $col = 'sid,skey,fwhs,twhs,qty,tstat,facc,ttime,sacc,tacc,ftime';

        //获取数据
        $info = StcInoutSheetModel::M()->getRowById($sid, $col);
        if ($info == false)
        {
            throw new AppException('没有该入库单', AppException::NO_DATA);
        }

        //检查是否已入库
        if ($info['tstat'] == 2)
        {
            throw new AppException('该商品已入库', AppException::FAILED_OPERATE);
        }

        //获取明细
        $goods = StcInoutGoodsModel::M()->getList(['sid' => $sid], 'pid,sid');

        //pids字典
        $pids = ArrayHelper::map($goods, 'pid', '-1');

        //查询条件
        $where = ['pid' => ['in' => $pids], 'twhs' => $info['twhs']];

        //获取仓库
        $storageDict = StcStorageModel::M()->getDistinct('pid', $where);

        $time = time();

        $insertData = $updPids = [];
        foreach ($pids as $value)
        {
            //是否有本部门储存记录
            if (in_array($value, $storageDict))
            {
                $updPids[] = $value;
            }
            else
            {
                //生成sid
                $sid = IdHelper::generate();

                //组装数据
                $insertData[] = [
                    'sid' => $sid,
                    'twhs' => 105,
                    'pid' => $value,
                    'stat' => 1,
                    'prdstat' => 11,
                    'ftime' => $time,
                    'ltime' => $time,
                    'otime' => 0,
                    '_id' => $sid
                ];
            }
        }

        //加锁，防止重复执行
        $locKey = "sale_store_storage_$sid";
        if ($this->redis->setnx($locKey, $sid, 30) == false)
        {
            throw new AppException('一键入库过于频繁，请稍后重试', AppException::FAILED_LOCK);
        }

        //开启事务
        Db::beginTransaction();
        try
        {
            //插入仓库
            if ($insertData)
            {
                StcStorageModel::M()->inserts($insertData);
            }

            //更新仓库
            if ($updPids)
            {
                $where = [
                    'pid' => ['in' => $updPids],
                    'twhs' => $info['twhs']
                ];
                $data1 = [
                    'twhs' => 105,
                    'stat' => 1,
                    'prdstat' => 11,
                    'ltime' => $time
                ];

                StcStorageModel::M()->update($where, $data1);
            }

            //组装数据
            $data = [
                'tacc' => $acc,
                'ttime' => $time,
                'tstat' => 2,
            ];

            //更新出入库状态
            StcInoutSheetModel::M()->updateById($info['sid'], $data);
            StcInoutGoodsModel::M()->update(['sid' => $info['sid']], ['tacc' => $acc]);

            //更新商品状态
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], [
                'prdstat' => 1,
                'stcwhs' => 105,
                'stcstat' => 11,
                'stctime' => $time,
            ]);

            Db::commit();

            //解锁
            $this->redis->del($locKey);

            return true;
        }
        catch (Throwable $throwable)
        {
            Db::rollback();

            //解锁
            $this->redis->del($locKey);

            throw $throwable;
        }

    }

    /**
     * 检查库存编码
     * @param string $bcode
     * @param string $sid
     * @return array|bool
     * @throws
     */
    public function search(string $bcode, string $sid)
    {
        //上下文参数
        $acc = Context::get('acc');

        //所需字段
        $cols = 'pid,bcode,mid,prdstat,level,plat,recstat,chkstat,rectime4,stcwhs,stcstat';

        //获取商品信息
        $info = PrdProductModel::M()->getRow(['bcode' => $bcode], $cols);
        if ($info == false)
        {
            throw new AppException('商品[' . $bcode . ']不存在');
        }

        //检查商品状态
        if ($info['prdstat'] != 1 || $info['stcwhs'] != 105 || !in_array($info['stcstat'], [11, 33, 34, 35]))
        {
            throw new AppException('商品[' . $bcode . ']状态不允许出库');
        }

        $recstat = $info['recstat'];
        if (!in_array($recstat, [7, 61]))
        {
            throw new AppException("商品{$bcode}，交易状态不允许出库-[$recstat]", AppException::NO_RIGHT);
        }

        //获取商品ID
        $pid = $info['pid'];

        //查询条件
        $where = [
            'twhs' => 105,
            'pid' => $pid,
            'stat' => 1,
        ];

        //获取库存数据
        $storage = StcStorageModel::M()->getRow($where);
        if ($storage == false)
        {
            throw new AppException('商品[' . $bcode . ']不在库');
        }

        //组装机型、级别
        $info['pname'] = QtoModelModel::M()->getOne(['mid' => $info['mid']], 'mname', [], '-');
        $info['level'] = QtoLevelModel::M()->getOne(['lkey' => $info['level']], 'lname', [], '-');

        //加锁，防止重复执行
        $locKey = "sale_store_storage_$bcode";
        if ($this->redis->setnx($locKey, $bcode, 30) == false)
        {
            throw new AppException('扫同一个库存编码过于频繁，请稍后重试', AppException::FAILED_LOCK);
        }

        try
        {
            Db::beginTransaction();

            $time = time();

            if ($sid)
            {
                //获取商品数量
                $sheet = StcInoutSheetModel::M()->getRowById($sid, 'skey, qty');
                if ($sheet)
                {
                    $skey = $sheet['skey'];

                    //组装数据
                    $updateSheetData = [
                        'qty' => $sheet['qty'] + 1
                    ];

                    //更新外借单数据
                    StcInoutSheetModel::M()->updateById($sid, $updateSheetData);
                }
                else
                {
                    throw new AppException('已删除最后一个商品，出库单不存在，请重新新增出库单', AppException::NO_DATA);
                }
            }
            else
            {
                //生成sid和skey
                $skey = $this->uniqueKeyLogic->getStcCR();
                $sid = IdHelper::generate();

                //组装数据
                $data = [
                    'sid' => $sid,
                    'skey' => $skey,
                    'offer' => '',
                    'tid' => 1,
                    'qty' => 1,
                    'fwhs' => 105,
                    'twhs' => 0,
                    'facc' => '',
                    'sacc' => '',
                    'tacc' => '',
                    'fstat' => 1,
                    'tstat' => 0,
                    'ftime' => 0,
                    'ttime' => 0,
                    'frmk' => '',
                    'aacc' => $acc,
                    'atime' => $time,
                ];

                //新增仓库进出单据
                StcInoutSheetModel::M()->insert($data);
            }

            $gid = IdHelper::generate();
            $goodsData = [
                'gid' => $gid,
                'sid' => $sid,
                'pid' => $pid,
                'facc' => $acc,
                'tacc' => '',
                '_id' => $gid
            ];

            //新增仓库进出单据商品明细
            StcInoutGoodsModel::M()->insert($goodsData);

            //更新仓库储存表
            StcStorageModel::M()->update(['twhs' => 105, 'pid' => $pid], ['stat' => 1, 'prdstat' => 13]);

            //更新商品
            PrdProductModel::M()->update(['pid' => $pid], ['stcstat' => 13, 'stctime' => time()]);

            Db::commit();

            $info['sid'] = $sid;
            $info['skey'] = $skey;

            //解锁
            $this->redis->del($locKey);

            //返回
            return $info;
        }
        catch (Throwable $throwable)
        {
            Db::rollback();

            //解锁
            $this->redis->del($locKey);

            throw $throwable;
        }

    }

    /**
     * 删除出库单列表中的商品
     * @param string $pid
     * @param string $sid
     * @throws
     */
    public function delGoods(string $pid, string $sid)
    {
        //获取出入库sid
        $inoutSheet = StcInoutSheetModel::M()->getRowById($sid, 'sid,fstat');
        if ($inoutSheet == false)
        {
            throw new AppException('出库单不存在，无法操作', AppException::FAILED_OPERATE);
        }

        if ($inoutSheet['fstat'] != 1)
        {
            throw new AppException('出库单已出库，不能删除', AppException::FAILED_OPERATE);
        }

        //获取出库单商品明细ID
        $exist = StcInoutGoodsModel::M()->exist(['sid' => $sid, 'pid' => $pid]);
        if ($exist == false)
        {
            throw new AppException('外借单对应的商品数据不存在', AppException::NO_DATA);
        }
        Db::beginTransaction();

        try
        {
            //删除仓库进出单据商品明细
            StcInoutGoodsModel::M()->delete(['sid' => $sid, 'pid' => $pid]);

            //重新算数量
            $count = StcInoutGoodsModel::M()->getCount(['sid' => $sid]);
            if ($count > 0)
            {
                //如果还有更新出库单
                StcInoutSheetModel::M()->updateById($sid, ['qty' => $count]);
            }
            else
            {
                //没有直接删除出库单
                StcInoutSheetModel::M()->deleteById($sid);
            }

            //更新仓库储存表
            StcStorageModel::M()->update(
                ['pid' => $pid, 'twhs' => 105],
                ['stat' => 1, 'prdstat' => 11]
            );

            //组装数据，更新商品的状态
            PrdProductModel::M()->update(['pid' => $pid], [
                'prdstat' => 1,
                'stcstat' => 11,
                'stctime' => time()
            ]);

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();
            throw $throwable;
        }
    }

    /**
     * 保存出库
     * @param string $acc
     * @param array $query
     * @throws
     */
    public function saveOutput(string $acc, array $query)
    {
        //提取参数
        $time = time();
        $sid = $query['sid'];
        $sacc = $query['sacc'];
        $twhs = $query['twhs'];
        $frmk = $query['frmk'];

        //检查出库单状态
        $sheet = StcInoutSheetModel::M()->getRowById($sid, 'fstat,fwhs');
        $fstat = $sheet['fstat'];

        if ($sheet == false)
        {
            throw new AppException('出库单不存在', AppException::MISS_ARG);
        }
        if ($fstat == 2)
        {
            throw new AppException('该单已出库', AppException::MISS_ARG);
        }

        //检查接收人
        if (!$sacc)
        {
            throw new AppException('请选择接收人', AppException::MISS_ARG);
        }

        //查询接收人信息
        $exist = CrmStaffModel::M()->existById($sacc);
        if ($exist == false)
        {
            throw new AppException('接收人信息不存在', AppException::MISS_ARG);
        }

        //检查接收仓库
        if ($twhs == 0)
        {
            throw new AppException('请选择接收仓库', AppException::MISS_ARG);
        }

        //查询仓库信息
        $exist2 = SysWarehouseModel::M()->existById($twhs);
        if ($exist2 == false)
        {
            throw new AppException('接收仓库不存在', AppException::WRONG_ARG);
        }

        //查询商品的明细
        $pids = StcInoutGoodsModel::M()->getDistinct('pid', ['sid' => $sid]);

        //查询商品表
        $product = PrdProductModel::M()->getList(['pid' => ['in' => $pids]], 'pid,plat,bcode,inway,recstat');

        //检查商品是否允许出库
        foreach ($product as $value)
        {
            $bcode = $value['bcode'];
            $inway = $value['inway'];

            //平台19 161只能出库到优品和良品
            if ($twhs != '' && in_array($inway, [91, 1611]) && !in_array($twhs, [102, 103]))
            {
                throw new AppException("商品{$bcode}，只能出库到优品或小槌子", AppException::NO_DATA);
            }

            if ($inway == 51)
            {
                throw new AppException("商品{$bcode}为电商采购商品，不允许在新新销售", AppException::NO_DATA);
            }

            //检查交易状态是否可以出库
            $recstat = $value['recstat'];
            if (!in_array($recstat, [7, 61]))
            {
                throw new AppException("商品{$bcode}，交易状态不允许出库-[$recstat]", AppException::NO_RIGHT);
            }
        }

        Db::beginTransaction();
        try
        {
            //组装出库单数据
            $sheetData = [
                'twhs' => $twhs,
                'facc' => $acc,
                'sacc' => $sacc,
                'tacc' => '',
                'fstat' => 2,
                'tstat' => 1,
                'ftime' => $time,
                'ttime' => 0,
                'frmk' => $frmk
            ];

            //更新出库状态
            StcInoutSheetModel::M()->updateById($sid, $sheetData);

            //更新仓库状态
            StcStorageModel::M()->update(
                ['twhs' => 105, 'pid' => ['in' => $pids]],
                ['stat' => 2, 'prdstat' => 22, 'otime' => $time]
            );

            //更新商品的出库状态
            PrdProductModel::M()->update(
                ['pid' => ['in' => $pids]],
                ['stcstat' => 22, 'stctime' => $time]
            );

            //通知pos解绑
            $bcodes = PrdProductModel::M()->getDistinct('bcode', ['pid' => ['in' => $pids]]);
            if (count($bcodes) > 0)
            {
                $this->shelfLogic->takeout($bcodes);
            }

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();
            throw $throwable;
        }

    }

    /**
     * 调整出库地点及接收人
     * @param array $query
     * @throws
     */
    public function adjust(array $query)
    {
        //提取参数
        $sid = $query['sid'];
        $fwhs = $query['fwhs'];
        $sacc = $query['sacc'];

        //检查订单数据
        $exists = StcInoutSheetModel::M()->existById($sid);
        if ($exists == false)
        {
            throw new AppException('出库单不存在', AppException::NO_DATA);
        }

        //组装数据
        $data = [
            'twhs' => $fwhs,
            'sacc' => $sacc
        ];

        //更新数据
        StcInoutSheetModel::M()->updateById($sid, $data);
    }

    /**
     * 删除出库单
     * @param string $sid
     * @throws
     */
    public function delete(string $sid)
    {
        //获取出入库sid
        $inoutSheet = StcInoutSheetModel::M()->getRowById($sid, 'sid,fstat');
        if ($inoutSheet == false)
        {
            throw new AppException('出库单不存在，无法操作', AppException::FAILED_OPERATE);
        }

        //出库单商品
        $stcGoods = StcInoutGoodsModel::M()->getList(['sid' => $sid]);
        if (count($stcGoods) < 0)
        {
            throw new AppException('出库单数据不存在，无法操作', AppException::FAILED_OPERATE);
        }

        //获取pid字典
        $pids = ArrayHelper::map($stcGoods, 'pid');

        Db::beginTransaction();
        try
        {
            //更新仓库储存表
            StcStorageModel::M()->update(['pid' => ['in' => $pids], 'twhs' => 105], ['stat' => 1, 'prdstat' => 11]);

            //组装数据，更新商品的状态
            PrdProductModel::M()->update(['pid' => ['in' => $pids]], [
                'prdstat' => 1,
                'stcstat' => 11,
                'stctime' => time()
            ]);

            //删除出库单
            StcInoutSheetModel::M()->delete(['sid' => $sid]);

            //删除出库商品单
            StcInoutGoodsModel::M()->delete(['sid' => $sid]);

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();

            throw $throwable;
        }
    }

    /**
     * 导出详情
     * @param string $skey
     * @return array
     * @throws AppException
     */
    public function export(string $skey)
    {
        //获取外借单商品数据
        $detail = $this->getInfo($skey);
        $goods = $detail['goods'];

        //提取参数
        $pids = ArrayHelper::map($goods, 'pid');

        //获取质检备注
        if (count($pids) > 0)
        {
            $mqcDict = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => 21], 'mid,bconc');
        }

        $list = [];
        //获取商品数据
        foreach ($pids as $key => $value)
        {
            $rmk = isset($mqcDict[$value]) ? $mqcDict[$value]['bconc'] : '-';
            $list[$key]['bcode'] = $goods[$key]['bcode'];
            $list[$key]['prdcost'] = $goods[$key]['prdcost'];
            $list[$key]['bid'] = $goods[$key]['bname'];
            $list[$key]['level'] = $goods[$key]['level'];
            $list[$key]['pname'] = $goods[$key]['pname'];
            $list[$key]['rmk'] = $rmk ? $rmk : '';
        }
        $num = count($pids);
        $list[] = ['bcode' => '', 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '', 'rmk' => ''];
        $list[] = ['bcode' => '', 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '', 'rmk' => ''];
        $list[] = ['bcode' => '框数:', 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '出库日期:' . $detail['ftime'], 'rmk' => ''];
        $list[] = ['bcode' => '出库机器总数:' . $num, 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '出库人:' . $detail['facc'], 'rmk' => ''];
        $list[] = ['bcode' => '实际总数量:' . $num, 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '收货人:' . $detail['tacc'], 'rmk' => ''];

        //拼装excel数据
        $data = [
            'list' => $list,
            'header' => [
                'bcode' => '库存编号',
                'prdcost' => '成本价',
                'bid' => '品牌',
                'level' => '级别',
                'pname' => '商品名称',
                'rmk' => '质检备注',
            ]
        ];

        //返回数据
        return $data;
    }

    /**
     * 出库单接收仓库下拉列表数据
     * @param
     * @return array
     * @throws
     */
    public function getSource()
    {
        $source = [];
        $data = SaleDictData::STC_SOURCE;

        $i = 0;
        foreach ($data as $ket => $value)
        {
            $source[$i]['id'] = $ket;
            $source[$i]['name'] = $value;
            $i++;
        }

        //返回
        return $source;
    }

    /**
     * 获取翻页数据条件
     * @param array $query
     * @return mixed
     */
    private function getPagerWhere(array $query)
    {

        //1：入库单，2：出库单
        if ($query['type'] && $query['type'] == 2)
        {
            //固定条件
            $where = [
                'tid' => 1,
                'fstat' => ['in' => [1, 2]],
                'fwhs' => 105
            ];

            //接收仓库
            if ($query['twhs'])
            {
                $where['twhs'] = $query['twhs'];
            }

            //状态
            if ($query['fstat'])
            {
                $where['fstat'] = $query['fstat'];
            }

            //出库人
            if ($query['facc'])
            {
                $facc = AccUserModel::M()->getOne(['rname' => $query['facc']], 'aid');
                $where['facc'] = $facc ? $facc : -1;
            }

            //日期
            if ($query['ftime'])
            {
                $date = $query['ftime'];
                if (count($date) == 2)
                {
                    $stime = strtotime($date[0] . ' 00:00:00');
                    $etime = strtotime($date[1] . ' 23:59:59');
                    $where['ftime'] = ['between' => [$stime, $etime]];
                }
            }

        }
        else
        {
            //固定条件
            $where = [
                'tid' => 1,
                'twhs' => 105,
                'tstat' => ['in' => [1, 2]],
                'fwhs' => ['not in' => [0, 900]],//来源不包含供应商
            ];

            //来源仓库
            if ($query['fwhs'])
            {
                $where['fwhs'] = $query['fwhs'];
            }

            //状态
            if ($query['tstat'])
            {
                $where['tstat'] = $query['tstat'];
            }

            //入库人
            if ($query['tacc'])
            {
                $acc = AccUserModel::M()->getOne(['rname' => $query['tacc']], 'aid');
                $where['tacc'] = $acc ? $acc : -1;
            }

            //日期
            if ($query['ttime'])
            {
                $date = $query['ttime'];
                if (count($date) == 2)
                {
                    $stime = strtotime($date[0] . ' 00:00:00');
                    $etime = strtotime($date[1] . ' 23:59:59');
                    $where['ttime'] = ['between' => [$stime, $etime]];
                }
            }

        }

        //库存编号
        if ($query['bcode'])
        {
            $pid = PrdProductModel::M()->getOne(['bcode' => $query['bcode']], 'pid');
            if ($pid)
            {
                $sids = StcInoutGoodsModel::M()->getDistinct('sid', ['pid' => $pid]);
            }

            //没有对应数据，则给-1
            $where['sid'] = $sids ? ['in' => $sids] : -1;
        }

        //入库单号
        if ($query['skey'])
        {
            $where['skey'] = $query['skey'];
        }

        return $where;
    }

}