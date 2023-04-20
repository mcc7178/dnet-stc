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
use App\Model\Stc\StcBorrowGoodsModel;
use App\Model\Stc\StcBorrowSheetModel;
use App\Model\Stc\StcStorageModel;
use App\Model\Sys\SysDeptModel;
use App\Module\Pos\Logic\ShelfLogic;
use App\Module\Pub\Logic\UniqueKeyLogic;
use App\Module\Stc\Data\StcDictData;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Context;
use Swork\Db\Db;
use Swork\Exception\DbException;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;
use Throwable;

class StorageBorrowLogic extends BeanCollector
{

    /**
     * @Inject()
     * @var UniqueKeyLogic
     */
    private $uniqueKeyLogic;

    /**
     * @Inject()
     * @var ShelfLogic
     */
    private $shelfLogic;

    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

    /**
     * 翻页数据
     * @param array $query
     * @param int $idx
     * @param int $size
     * @return array
     */
    public function getPager(array $query, int $idx, int $size)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //所需字段
        $cols = 'sid,skey,qty,rqty,dept,bacc,racc,atime,btime,stat,ltime,rmk,fwhs';

        //排序
        $order = ['stat' => 1, 'ltime' => -1, 'atime' => -1];

        //获取翻页数据
        $list = StcBorrowSheetModel::M()->getList($where, $cols, $order, $size, $idx);

        //无数据返回空
        if (!$list)
        {
            return [];
        }

        //获取外借人、出库人字典
        $accs = ArrayHelper::maps([$list, $list], ['bacc', 'racc']);
        $accUser = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $accs]], 'rname');

        //部门字典
        $depts = ArrayHelper::map($list, 'dept');
        $dept = SysDeptModel::M()->getDict('did', ['did' => ['in' => $depts]], 'did, dname');

        //补充数据
        foreach ($list as $key => $value)
        {
            $baccId = $value['bacc'];
            $raccId = $value['racc'];

            //补充名字
            $list[$key]['baccname'] = $accUser[$baccId]['rname'] ?? '-';
            $list[$key]['raccname'] = $accUser[$raccId]['rname'] ?? '-';

            //补充状态
            $list[$key]['statname'] = StcDictData::BORROW_STAT[$value['stat']] ?? '-';

            //补充部门
            $list[$key]['dept'] = $dept[$value['dept']]['dname'] ?? '-';

            //时间格式化
            $list[$key]['atime'] = DateHelper::toString($value['atime']);
            $list[$key]['btime'] = DateHelper::toString($value['btime']);
            $list[$key]['ltime'] = DateHelper::toString($value['ltime']);

            //待归还数量
            $list[$key]['dqty'] = $value['stat'] == 0 ? 0 : $value['qty'] - $value['rqty'];
        }

        //填充默认值
        ArrayHelper::fillDefaultValue($list);

        //返回
        return $list;
    }

    /**
     * 获取翻页总数量
     * @param array $query
     * @return int
     */
    public function getPagerCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //条数
        return StcBorrowSheetModel::M()->getCount($where);
    }

    /**
     * 获取外借单详情
     * @param string $skey
     * @return array|bool
     * @throws AppException
     */
    public function getInfo(string $skey)
    {
        //查询字段
        $cols = 'sid,skey,qty,rqty,dept,bacc,racc,atime,btime,stat,ltime,rmk,fwhs';

        //获取数据
        $info = StcBorrowSheetModel::M()->getRow(['skey' => $skey], $cols);
        if ($info == false)
        {
            throw new AppException('此外借单不存在', AppException::NO_DATA);
        }

        //获取用户数据
        $accs = array_merge([$info['racc'], $info['bacc']]);
        $accUser = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $accs]], 'rname');

        //补充姓名：外借人bacc、创建人racc、出库人racc
        $info['baccname'] = $accUser[$info['bacc']]['rname'] ?? '-';
        $info['raccname'] = $accUser[$info['racc']]['rname'] ?? '-';

        //补充时间：出库时间btime、最后更新时间ltime
        $info['btime'] = DateHelper::toString($info['btime']);
        $info['ltime'] = DateHelper::toString($info['ltime']);

        //补充状态
        $info['statname'] = StcDictData::BORROW_STAT[$info['stat']] ?? '-';

        //补充部门
        $info['dept'] = SysDeptModel::M()->getOneById($info['dept'], 'dname', [], '-');

        //外借单明细
        $borrows = StcBorrowGoodsModel::M()->getList(['sid' => $info['sid']], 'gid, sid, pid, rstat, racc, rtime');

        //补充外借单商品明细
        $goods = [];
        if (count($borrows) > 0)
        {
            //user字典
            $raccs = ArrayHelper::map($borrows, 'racc');
            $racc = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $raccs]], 'aid, rname');

            //pid字典
            $pids = ArrayHelper::map($borrows, 'pid');

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
                foreach ($borrows as $value)
                {
                    $pid = $value['pid'];
                    //组装数据
                    $goods[] = [
                        'pid' => $pid,
                        'prdcost' => $prdProduct[$pid]['salecost'] ?? '-',
                        'bid' => $brand[$prdProduct[$pid]['bid']]['bname'] ?? '-',
                        'bcode' => $prdProduct[$pid]['bcode'] ?? '-',
                        'pname' => $model[$prdProduct[$pid]['mid']]['mname'] ?? '-',
                        'level' => $qtoLevel[$prdProduct[$pid]['level']]['lname'] ?? '-',
                        'stat' => StcDictData::BORROW_STAT[$value['rstat']] ?? '-',
                        'raccname' => $racc[$value['racc']]['rname'] ?? '-',
                        'rtime' => DateHelper::toString($value['rtime'])
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
     * 检查库存编码，新增库存
     * @param string $bcode
     * @return array|bool
     * @throws AppException
     * @throws Throwable
     */
    public function search(string $bcode, string $sid)
    {
        $acc = Context::get('acc');

        //所需字段
        $cols = 'pid,bcode,mid,prdstat,level,omid';

        //获取商品数据
        $product = PrdProductModel::M()->getRow(['bcode' => $bcode], $cols);
        if ($product == false)
        {
            throw new AppException('商品不存在', AppException::NO_DATA);
        }

        //组装查询条件
        $pid = $product['pid'];
        $where = [
            'pid' => $pid,
            'stat' => 1,
            'twhs' => 105
        ];

        $storage = StcStorageModel::M()->getRow($where, 'sid,twhs,pid,stat,prdstat');

        //查看是否在库
        if ($storage == false)
        {
            throw new AppException("商品[$bcode]不在库", AppException::NO_DATA);
        }

        //判断状态
        if (!in_array($storage['prdstat'], [11, 33, 34, 35]))
        {
            throw new AppException('商品[' . $bcode . ']状态不允许外借');
        }

        //获取机型数据
        $qtoModel = QtoModelModel::M()->getOne(['mid' => $product['mid']], 'mname');

        //组装机型、级别
        $product['pname'] = $qtoModel ?? '-';
        $product['level'] = QtoLevelModel::M()->getOne(['lkey' => $product['level']], 'lname', [], '-');

        //加锁，防止重复执行
        $locKey = "sale_store_storage_$bcode";
        if ($this->redis->setnx($locKey, $bcode, 30) == false)
        {
            throw new AppException('扫同一个库存编码过于频繁，请稍后重试', AppException::FAILED_LOCK);
        }

        //开始事务
        Db::beginTransaction();
        try
        {
            //当前时间参数
            $time = time();

            //判断否sid传入，有就更新，没有就新增
            if ($sid)
            {
                //获取商品数量
                $sheet = StcBorrowSheetModel::M()->getRowById($sid, 'skey, qty');
                if ($sheet)
                {
                    $skey = $sheet['skey'];

                    //组装数据
                    $updateSheetData = [
                        'qty' => $sheet['qty'] + 1,
                        'ltime' => $time,
                        'atime' => $time,
                    ];
                    //更新外借单数据
                    StcBorrowSheetModel::M()->updateById($sid, $updateSheetData);
                }
                else
                {
                    throw new AppException('已删除最后一个商品，外借单不存在，请重新新增外借单', AppException::NO_DATA);
                }
            }
            else
            {
                //生成sid和skey
                $sid = IdHelper::generate();
                $skey = $this->uniqueKeyLogic->getStcWJ();

                //组装外借单数据
                $sheetData = [
                    'sid' => $sid,
                    'tid' => 1,
                    'skey' => $skey,
                    'qty' => 1,
                    'fwhs' => 105,
                    'stat' => 0,
                    'racc' => $acc,
                    'ltime' => $time,
                    'atime' => $time,
                    '_id' => $sid
                ];
                //新增外借单数据
                StcBorrowSheetModel::M()->insert($sheetData);
            }

            //组装外借单明细数据
            $gid = IdHelper::generate();
            $goodsData = [
                'gid' => $gid,
                'sid' => $sid,
                'pid' => $pid,
                'racc' => '',
                'rtime' => 0,
                '_id' => $gid
            ];
            //新增外借单明细数据
            StcBorrowGoodsModel::M()->insert($goodsData);

            //更新仓库数据
            StcStorageModel::M()->update(['pid' => $pid, 'twhs' => 105], ['stat' => 1, 'prdstat' => 13]);

            //更新商品数据
            PrdProductModel::M()->update(
                ['pid' => $pid],
                ['prdstat' => 1, 'stcstat' => 13, 'stctime' => $time]
            );

            //增加返回一个sid和skey
            $product['sid'] = $sid;
            $product['skey'] = $skey;

            Db::commit();

            //解锁
            $this->redis->del($locKey);

            //返回
            return $product;
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
     * 删除外借单
     * @param string $sid
     * @throws AppException
     * @throws DbException
     * @throws Throwable
     */
    public function delete(string $sid)
    {
        //获取外借单数据
        $info = StcBorrowSheetModel::M()->getRowById($sid);
        if ($info == false)
        {
            throw new AppException('对应的外借单不存在', AppException::NO_DATA);
        }

        //检查是否在待领取状态
        if ($info['stat'] !== 0)
        {
            throw new AppException('对应的外借单不在待领取状态中', AppException::NO_DATA);
        }

        //是否有明细记录
        $goods = StcBorrowGoodsModel::M()->getList(['sid' => $sid], 'sid,pid');
        if ($goods == false)
        {
            throw new AppException('外借单对应的商品数据不存在', AppException::NO_DATA);
        }

        //开始事务
        Db::beginTransaction();
        try
        {
            //商品字典
            $pids = ArrayHelper::map($goods, 'pid');

            //删除外借单记录
            StcBorrowSheetModel::M()->deleteById($sid);
            StcBorrowGoodsModel::M()->delete(['sid' => $sid]);

            //更新仓库储存表
            StcStorageModel::M()->update(
                ['pid' => ['in' => $pids], 'twhs' => 105],
                ['stat' => 1, 'prdstat' => 11]
            );

            //更新商品状态
            PrdProductModel::M()->update(
                ['pid' => ['in' => $pids]],
                ['prdstat' => 1, 'stcstat' => 11, 'stctime' => time()]
            );

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();
            throw $throwable;
        }
    }

    /**
     * 删除外借单中的商品
     * @param string $pid
     * @param string $sid
     * @throws AppException
     * @throws DbException
     * @throws Throwable
     */
    public function delGoods(string $pid, string $sid)
    {
        //检查外借单数据
        $sheet = StcBorrowSheetModel::M()->getRowById($sid);

        if ($sheet == false)
        {
            throw new AppException('外借单不存在，无法操作', AppException::FAILED_OPERATE);
        }

        //待领取状态才可以删除
        if ($sheet['stat'] != 0)
        {
            throw new AppException('外借单已外借，无法删除', AppException::FAILED_OPERATE);
        }

        //获取外借单商品明细ID
        $exist = StcBorrowGoodsModel::M()->exist(['sid' => $sid, 'pid' => $pid]);
        if ($exist == false)
        {
            throw new AppException('外借单对应的商品数据不存在', AppException::NO_DATA);
        }

        Db::beginTransaction();
        try
        {
            //更新外借单状态
            StcBorrowGoodsModel::M()->delete(['sid' => $sid, 'pid' => $pid]);

            //获取外借单明细数量
            $qty = StcBorrowGoodsModel::M()->getCount(['sid' => $sid]);

            //更新外借单数量
            StcBorrowSheetModel::M()->updateById($sid, ['qty' => $qty]);

            //更新仓库状态
            StcStorageModel::M()->update(['pid' => $pid, 'twhs' => 105], ['prdstat' => 11, 'stat' => 1]);

            //更新商品状态
            PrdProductModel::M()->update(['pid' => $pid], ['prdstat' => 1, 'stcstat' => 11, 'stctime' => time()]);

            //商品全部删除之后直接删除外借单
            if ($qty == 0)
            {
                StcBorrowSheetModel::M()->deleteById($sid);
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
     * 保存外借单
     * @param array $query
     * @throws AppException
     * @throws DbException
     * @throws Throwable
     */
    public function save(array $query)
    {
        //提取参数
        $time = time();
        $sid = $query['sid'];
        $skey = $query['skey'];
        $sacc = $query['sacc'];
        $dept = $query['dept'];
        $rmk = $query['rmk'];

        //sid没有，就拿skey
        if ($sid == '')
        {
            $sid = StcBorrowSheetModel::M()->getOne(['skey' => $skey], 'sid');
        }

        //检查外借单是否存在
        $borrowSheet = StcBorrowSheetModel::M()->getRowById($sid);
        if (!$borrowSheet)
        {
            throw new AppException('没有此外借单', AppException::NO_DATA);
        }

        //检查接收人
        if (!$sacc)
        {
            throw new AppException('请选择接收人', AppException::MISS_ARG);
        }

        //查询接收人信息
        $exist1 = CrmStaffModel::M()->existById($sacc);
        if ($exist1 == false)
        {
            throw new AppException('接收人信息不存在', AppException::WRONG_ARG);
        }

        //检查接收部门
        if (!$dept)
        {
            throw new AppException('请选择接收人', AppException::MISS_ARG);
        }

        //查询接收部门信息
        $exist2 = SysDeptModel::M()->existById($dept);
        if ($exist2 == false)
        {
            throw new AppException('接收部门信息不存在', AppException::WRONG_ARG);
        }

        //开始事务
        Db::beginTransaction();
        try
        {
            //组装外借单数据
            $sheetData = [
                'stat' => 1,
                'dept' => $dept,
                'rmk' => $rmk,
                'bacc' => $sacc,
                'btime' => $time,
                'bdate' => $time,
                'ltime' => $time
            ];

            //更新外借单的状态
            StcBorrowSheetModel::M()->updateById($sid, $sheetData);

            $pids = StcBorrowGoodsModel::M()->getDistinct('pid', ['sid' => $sid]);

            //更新外借单明细的状态，待归还
            StcBorrowGoodsModel::M()->update(['sid' => $sid], ['rstat' => 1]);

            //更新仓库状态，出库
            StcStorageModel::M()->update(
                ['pid' => ['in' => $pids], 'twhs' => 105],
                ['stat' => 2, 'prdstat' => 21, 'otime' => $time]
            );

            //更新商品状态，出库
            PrdProductModel::M()->update(
                ['pid' => ['in' => $pids]],
                ['stcstat' => 21, 'stctime' => $time]
            );

            //通知pos解绑
            $bcodes = PrdProductModel::M()->getDistinct('bcode', ['pid' => ['in' => $pids]]);
            if ($bcodes)
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
     * 批量添加商品到外借单中
     * @param array $query
     * @throws AppException
     * @throws DbException
     * @throws Throwable
     */
    public function batchAdd(array $query)
    {
        //提取参数
        $time = time();
        $sid = $query['sid'];
        $bcodes = $query['bcodes'];

        //外借单是否存在
        $exists = StcBorrowSheetModel::M()->getRowById($sid, 'stat');
        if (!$exists)
        {
            throw new AppException('没有此外借单', AppException::NO_DATA);
        }

        if ($exists['stat'] !== 0)
        {
            throw new AppException('不是待领取状态，不能添加', AppException::OUT_OF_DELETE);
        }

        //过滤、移除
        $bcodes = rtrim(str_replace(' ', '', $bcodes), ',');

        //拆分为数组，去除重复值
        $bcodes = array_filter(array_unique(explode(',', $bcodes)));

        if (count($bcodes) == 0 || count($bcodes) > 50)
        {
            throw new AppException('数量不能超过50个,请分批添加', AppException::WRONG_ARG);
        }

        //获取商品信息
        $products = PrdProductModel::M()->getList(['bcode' => $bcodes], 'pid,bcode,prdstat,stcwhs,stcstat');
        if ($products == false)
        {
            throw new AppException('录入的库存编码有误，请检查！', AppException::NO_DATA);
        }

        $goodsData = [];
        foreach ($products as $key => $value)
        {
            $bcode = $value['bcode'];
            $prdstat = $value['prdstat'];
            $stcwhs = $value['stcwhs'];
            $stcstat = $value['stcstat'];

            //检查商品库存是否在公司仓库并且是在库状态
            if ($prdstat != 1 || $stcwhs != 105 || !in_array($stcstat, [11, 33, 34, 35]))
            {
                throw new AppException("商品{$bcode}，库存状态不在库-[$stcstat] - 01", AppException::NO_DATA);
            }

            $gid = IdHelper::generate();
            //组装数据
            $goodsData[] = [
                'gid' => $gid,
                'sid' => $sid,
                'pid' => $value['pid'],
                'racc' => '',
                'rtime' => 0,
                '_id' => $gid
            ];
        }

        Db::beginTransaction();
        try
        {
            //插进明细表
            StcBorrowGoodsModel::M()->inserts($goodsData);

            //计算商品数量
            $count = count($goodsData);
            $num = StcBorrowSheetModel::M()->getOneById($sid, 'qty');
            $num += $count;

            //更新外借单数量
            StcBorrowSheetModel::M()->updateById($sid, ['qty' => $num]);

            //pid字典
            $pids = ArrayHelper::map($products, 'pid');

            //更新仓库储存表状态
            StcStorageModel::M()->update(
                ['pid' => ['in' => $pids]],
                ['stat' => 1, 'prdstat' => 13]
            );

            //更新商品状态
            PrdProductModel::M()->update(
                ['pid' => ['in' => $pids]],
                ['stcstat' => 13, 'stctime' => $time]
            );

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();
            throw $throwable;
        }

    }

    /**
     * 回仓检查库存编码
     * @param string $bcode
     * @return array|bool
     * @throws AppException
     */
    public function searchBack(string $bcode)
    {
        //查询字段
        $cols = 'pid,bcode,mid,prdstat,level,omid';

        //获取数据
        $product = PrdProductModel::M()->getRow(['bcode' => $bcode], $cols);
        if ($product == false)
        {
            throw new AppException('商品不存在', AppException::NO_DATA);
        }

        //提取商品ID
        $pid = $product['pid'];

        //检测数据是否存在外借单并且未归还
        $borrowGoods = StcBorrowGoodsModel::M()->getOne(['pid' => $pid, 'rstat' => 1], 'sid');
        $borrowSheet = StcBorrowSheetModel::M()->getRowById($borrowGoods);

        //如果不存在待回仓记录
        if ($borrowSheet == false)
        {
            throw new AppException('此商品不存在待回仓记录', AppException::NO_DATA);
        }

        //获取仓库商品数据
        $storage = StcStorageModel::M()->getRow(['pid' => $pid, 'twhs' => 105]);
        if ($storage == false)
        {
            throw new AppException("商品[$bcode]不在库", AppException::NO_DATA);
        }

        //组装机型、级别
        $product['pname'] = QtoModelModel::M()->getOne(['mid' => $product['mid']], 'mname', [], '-');
        $product['level'] = QtoLevelModel::M()->getOne(['lkey' => $product['level']], 'lname', [], '-');

        //返回
        return $product;
    }

    /**
     * 商品回仓
     * @param string $acc
     * @param string $pids
     * @throws AppException
     * @throws Throwable
     */
    public function back(string $acc, string $pids)
    {
        //过滤pid
        $pids = rtrim(str_replace(' ', '', $pids), ',');
        $pids = array_filter(array_unique(explode(',', $pids)));

        //是否有传入商品
        if (count($pids) == 0)
        {
            throw new AppException('没有回仓的商品', AppException::NO_DATA);
        }

        //是否有外借单明细记录
        $sids = StcBorrowGoodsModel::M()->getDistinct('sid', ['pid' => ['in' => $pids], 'racc' => '']);
        if (!$sids)
        {
            throw new AppException('没有外借记录', AppException::NO_DATA);
        }

        //获取外借单数据
        $borrowSheetList = StcBorrowSheetModel::M()->getList(['sid' => ['in' => $sids]], 'sid,stat,qty');

        //获取仓库数据
        $storage = StcStorageModel::M()->getList(['twhs' => 105, 'pid' => ['in' => $pids]], 'stat,prdstat,pid');

        //提取库存状态字典
        $stat = ArrayHelper::map($storage, 'stat');

        //提取商品状态字典
        $prdstat = ArrayHelper::map($storage, 'prdstat');

        //是否还在外借中或者出库中
        if ($stat != [2] && $prdstat != [21])
        {
            throw new AppException('存在非外借中的数据，请检查', AppException::OUT_OF_USING);
        }

        //开始事务
        Db::beginTransaction();
        try
        {
            $time = time();

            //归还更新明细表
            StcBorrowGoodsModel::M()->update(
                ['sid' => ['in' => $sids], 'pid' => ['in' => $pids]],
                ['rstat' => 3, 'racc' => $acc, 'rtime' => $time]
            );

            foreach ($borrowSheetList as $key => $value)
            {
                $sid = $value['sid'];

                //获取已归还的数量
                $count = StcBorrowGoodsModel::M()->getCount(['sid' => $sid, 'rstat' => 3]);

                //更新外借单归还数量
                StcBorrowSheetModel::M()->update(
                    ['sid' => $sid],
                    ['stat' => $value['qty'] == $count ? 3 : 2, 'rqty' => $count, 'ltime' => $time]
                );
            }

            //更新仓库商品状态
            StcStorageModel::M()->update(
                ['pid' => ['in' => $pids], 'twhs' => 105],
                ['stat' => 1, 'prdstat' => 11]
            );

            //更新库存商品数据
            PrdProductModel::M()->update(
                ['pid' => ['in' => $pids]],
                ['stcstat' => 11, 'stctime' => $time]
            );

            Db::commit();
        }
        catch (Throwable $throwable)
        {
            Db::rollback();
            throw $throwable;
        }

    }

    /**
     * 外借单详情导出
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

        if (count($pids) > 0)
        {
            $mqcDict = MqcReportModel::M()->getDict('pid', ['pid' => ['in' => $pids], 'plat' => 21], 'mid,bconc');
        }

        $list = [];
        //组装数据
        foreach ($pids as $key => $pid)
        {
            $rmk = isset($mqcDict[$pid]) ? $mqcDict[$pid]['bconc'] : '-';
            $list[$key]['bcode'] = $goods[$key]['bcode'];
            $list[$key]['prdcost'] = $goods[$key]['prdcost'];
            $list[$key]['bid'] = $goods[$key]['bid'];
            $list[$key]['level'] = $goods[$key]['level'];
            $list[$key]['pname'] = $goods[$key]['pname'];
            $list[$key]['rmk'] = $rmk ? $rmk : '';
        }
        $num = count($pids);
        $list[] = ['bcode' => '', 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '', 'rmk' => ''];
        $list[] = ['bcode' => '', 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '', 'rmk' => ''];
        $list[] = ['bcode' => '框数:', 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '出库日期:' . $detail['btime'], 'rmk' => ''];
        $list[] = ['bcode' => '出库机器总数:' . $num, 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '出库人:' . $detail['raccname'], 'rmk' => ''];
        $list[] = ['bcode' => '实际总数量:' . $num, 'prdcost' => '', 'bid' => '', 'level' => '', 'pname' => '收货人:' . $detail['baccname'], 'rmk' => ''];

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

        //返回
        return $data;
    }

    /**
     * 查询条件
     * @param array $query
     * @return int[]
     */
    public function getPagerWhere(array $query)
    {
        //来源仓库105
        $where = ['tid' => 1, 'fwhs' => 105];

        //库存编号
        if ($query['bcode'])
        {
            $pid = PrdProductModel::M()->getOne(['bcode' => $query['bcode']], 'pid');

            $sids = StcBorrowGoodsModel::M()->getDistinct('sid', ['pid' => $pid]);

            //没有对应数据，则给-1
            $where['sid'] = $sids ? ['in' => $sids] : -1;
        }

        //入库单号
        if ($query['skey'])
        {
            $where['skey'] = $query['skey'];
        }

        //外借部门
        if ($query['dept'])
        {
            $where['dept'] = $query['dept'];
        }

        //外借人
        if ($query['bacc'])
        {
            $where['bacc'] = AccUserModel::M()->getOne(['rname' => $query['bacc']], 'aid') ?: -1;
        }

        //出库人
        if ($query['racc'])
        {
            $where['racc'] = AccUserModel::M()->getOne(['rname' => $query['racc']], 'aid') ?: -1;
        }

        //状态
        if ($query['stat'] !== '')
        {
            $where['stat'] = $query['stat'];
        }

        //日期
        if ($query['ttype'] && $query['date'])
        {
            $date = $query['date'];
            if (count($date) == 2)
            {
                //时间类型
                $dype = $query['ttype'];

                //一天中开始时间和结束时间
                $stime = strtotime($date[0] . ' 00:00:00');
                $etime = strtotime($date[1] . ' 23:59:59');

                //1：创建时间、2：出库时间、3：最后更新时间
                $where[$dype] = ['between' => [$stime, $etime]];
            }
        }

        return $where;
    }
}