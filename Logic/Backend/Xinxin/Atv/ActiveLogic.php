<?php

namespace App\Module\Sale\Logic\Backend\Xinxin\Atv;

use App\Exception\AppException;
use App\Lib\Utility;
use App\Model\Odr\OdrOrderModel;
use App\Model\Qto\QtoOptionsModel;
use App\Model\Sale\SaleGroupBuyModel;
use App\Module\Sale\Data\XinxinDictData;
use App\Params\Common;
use App\Service\Qto\QtoInquiryInterface;
use App\Service\Qto\QtoLevelInterface;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Reference;
use Swork\Bean\BeanCollector;
use Swork\Client\Redis;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

class ActiveLogic extends BeanCollector
{
    /**
     * @Inject()
     * @var Redis
     */
    private $redis;

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
     * 拼团活动列表
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

        //团购名称
        if (!empty($query['gname']))
        {
            $where['gname'] = ['like' => '%' . $query['gname'] . '%'];
        }

        //机型
        if (!empty($query['mname']))
        {
            $mids = $this->qtoInquiryInterface->getSearchModelNames($query['mname']);
            if (count($mids) == 0)
            {
                return Common::emptyPager($size, $idx);
            }
            $mids = ArrayHelper::map($mids, 'mid');
            $where['mid'] = ['in' => $mids];
        }

        //活动开始时间
        if (count($query['stime']) == 2)
        {
            $stime = strtotime($query['stime'][0]);
            $etime = strtotime($query['stime'][1]) + 86399;
            $where['stime'] = ['between' => [$stime, $etime]];
        }

        //创建时间
        if (count($query['atime']) == 2)
        {
            $stime = strtotime($query['atime'][0]);
            $etime = strtotime($query['atime'][1]) + 86399;
            $where['atime'] = ['between' => [$stime, $etime]];
        }

        //状态
        if (!empty($query['stat']))
        {
            $where['stat'] = $query['stat'];
        }

        //获取翻页数据
        $list = SaleGroupBuyModel::M()->getList($where, '*', ['atime' => -1], $size, $idx);
        $count = SaleGroupBuyModel::M()->getCount($where);

        //如果有数据
        if (count($list) > 0)
        {
            //获取内存，版本，网络制，颜色等信息
            $options = ArrayHelper::maps([$list, $list, $list, $list], ['mdram', 'mdnet', 'mdcolor', 'mdofsale']);
            $optDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $options]], 'oname');

            //获取机型
            $mids = ArrayHelper::map($list, 'mid');
            $midDict = $this->qtoInquiryInterface->getDictModels($mids, 21);

            foreach ($list as $key => $value)
            {
                //补充信息
                $list[$key]['model'] = $midDict[$value['mid']]['mname'] ?? '-';
                $list[$key]['mdram'] = $optDict[$value['mdram']]['oname'] ?? '-';
                $list[$key]['mdnet'] = $optDict[$value['mdnet']]['oname'] ?? '-';
                $list[$key]['mdcolor'] = $optDict[$value['mdcolor']]['oname'] ?? '-';
                $list[$key]['mdofsale'] = $optDict[$value['mdofsale']]['oname'] ?? '-';

                //处理时间
                $list[$key]['atime'] = DateHelper::toString($value['atime']);
                $list[$key]['stime'] = DateHelper::toString($value['stime']);
                $list[$key]['etime'] = DateHelper::toString($value['etime']);

                //判断状态
                switch ($value['stat'])
                {
                    case 1:
                        $list[$key]['stattext'] = '未公开';
                        break;
                    case 2:
                        $list[$key]['stattext'] = '拼团中';
                        break;
                    case 3:
                        $list[$key]['stattext'] = '拼团成功';
                        break;
                    case 4:
                        $list[$key]['stattext'] = '拼团失败';
                        break;
                }
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
     * 拼团活动详情
     * @param string $gkey 团购编号
     * @return mixed
     * @throws
     */
    public function getInfo(string $gkey)
    {
        //检查参数
        if (empty($gkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取数据
        $info = SaleGroupBuyModel::M()->getRowById($gkey, '*');
        if (!$info)
        {
            throw new AppException('找不到该条团购信息', AppException::DATA_MISS);
        }

        //补充处理数据
        $extrainfo = [
            'mname' => $this->qtoInquiryInterface->getModelName($info['mid'], 21),
            'atime' => DateHelper::toString($info['atime']),
            'stime' => DateHelper::toString($info['stime']),
            'etime' => DateHelper::toString($info['etime']),
            'mdnet' => empty($info['mdnet']) ? '' : $info['mdnet'],
            'mdram' => empty($info['mdram']) ? '' : $info['mdram'],
            'mdcolor' => empty($info['mdcolor']) ? '' : $info['mdcolor'],
            'mdofsale' => empty($info['mdofsale']) ? '' : $info['mdofsale'],
            'limitqty' => empty($info['limitqty']) ? '' : $info['limitqty'],
        ];

        $info = array_merge($info, $extrainfo);

        //返回
        return $info;
    }

    /**
     * @param string $gkey 拼团编号
     * @param array $query 拼团信息
     * @throws
     * @return mixed
     */
    public function save(string $gkey, array $query)
    {
        //校验
        if ($query['limitqty'] > $query['groupqty'])
        {
            throw new AppException('每人限购数量不能超过团购商品总数');
        }
        if ($query['groupqty'] == 0)
        {
            throw new AppException('团购数量不能为0');
        }
        if ($query['oprice'] == 0)
        {
            throw new AppException('原价不能为0');
        }
        if ($query['gprice'] == 0)
        {
            throw new AppException('拼团价不能为0');
        }
        if ($query['gprice'] > $query['oprice'])
        {
            throw new AppException('团购价格不得高于原价');
        }

        //组装数据
        $data = $query;
        $bid = $this->qtoInquiryInterface->getBrand(21, $query['mid']);
        $data['bid'] = $bid['bid'];
        $opts = [$query['mdram'], $query['mdnet'], $query['mdcolor'], $query['mdofsale']];
        if (count($opts) > 0)
        {
            $optDict = QtoOptionsModel::M()->getDict('oid', ['oid' => ['in' => $opts]], 'oname');
        }
        $level = XinxinDictData::PRD_LEVEL[$query['level']]['label'];
        $mdram = $optDict[$query['mdram']]['oname'] ?? '';
        $mdnet = $optDict[$query['mdnet']]['oname'] ?? '';
        $mdcolor = $optDict[$query['mdcolor']]['oname'] ?? '';
        $mdofsale = $optDict[$query['mdofsale']]['oname'] ?? '';
        $label = [
            'level' => $level,
            'mdram' => $mdram,
            'mdnet' => $mdnet,
            'mdcolor' => $mdcolor,
            'mdofsale' => $mdofsale,
        ];
        $mname = $this->qtoInquiryInterface->getModelName($query['mid'], 21);
        $data['label'] = json_encode($label);
        $data['pname'] = $mname;
        $data['stime'] = strtotime($query['stime']);
        $data['etime'] = strtotime($query['etime']);
        if ($data['stime'] > $data['etime'])
        {
            throw new AppException('结束时间不能比开始时间早');
        }

        //获取数据
        $info = SaleGroupBuyModel::M()->getRowById($gkey, 'stime,isatv');
        if ($info)
        {
            //编辑操作
            if ($info['stime'] <= time() && $info['isatv'])
            {
                throw new AppException('活动开始后不允许编辑');
            }

            //组装修改数据
            $data['mtime'] = time();
            SaleGroupBuyModel::M()->updateById($gkey, $data);
        }
        else
        {
            //新增操作
            $data['gkey'] = $this->generateSaleGrpKey();
            $data['atime'] = time();
            $data['stat'] = 1;
            $data['isatv'] = 0;
            SaleGroupBuyModel::M()->insert($data);
        }

        //返回
        return 'ok';
    }

    /**
     * 删除拼团活动
     * @param string $gkey 活动编号
     * @throws
     * @return mixed
     */
    public function delete(string $gkey)
    {
        //检查参数
        if (empty($gkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取数据
        $info = SaleGroupBuyModel::M()->getRowById($gkey, 'stat,stime');
        if (!$info)
        {
            throw new AppException('找不到该条团购信息', AppException::DATA_MISS);
        }

        //活动已经开始
        if ($info['stime'] >= time())
        {
            return false;
        }

        //删除该条记录
        SaleGroupBuyModel::M()->deleteById($gkey);

        //返回
        return true;
    }

    /**
     * 更新拼团活动状态
     * @param string $gkey 活动编号
     * @throws
     * @return mixed
     */
    public function update(string $gkey)
    {
        //检查参数
        if (empty($gkey))
        {
            throw new AppException(null, AppException::MISS_ARG);
        }

        //获取数据
        $info = SaleGroupBuyModel::M()->getRowById($gkey, 'isatv,stat');
        if (!$info)
        {
            throw new AppException('找不到该条团购信息', AppException::DATA_MISS);
        }

        //禁用或者启用
        $isatv = $info['isatv'] == 1 ? 0 : 1;
        $data = [
            'isatv' => $isatv,
            'mtime' => time(),
        ];

        //活动结束之后不能禁用
        if($info['stat'] == 3 || $info['stat'] == 4)
        {
            throw new AppException('活动结束后不能进行禁用操作', AppException::DATA_MISS);
        }

        //拼团中活动
        if ($info['stat'] == 2)
        {
            //禁用的话状态变更，对应订单都变成购买失败
            if ($isatv == 0)
            {
                $data['stat'] = 4;

                //获取已经支付成功的订单
                $where = [
                    'groupbuy' => $gkey,
                    'tid' => 13,
                    'ostat' => 13,
                    'paystat' => 3,
                ];

                //更新订单状态
                $orderData['ostat'] = 53;
                OdrOrderModel::M()->update($where,$orderData);

                //获取待支付的订单
                $where = [
                    'groupbuy' => $gkey,
                    'tid' => 13,
                    'ostat' => 11,
                ];

                //更新订单状态
                $orderData['ostat'] = 51;
                OdrOrderModel::M()->update($where,$orderData);
            }
        }

        //更新拼团活动状态
        SaleGroupBuyModel::M()->updateById($gkey, $data);

        //返回
        return true;
    }

    /**
     * 通过输入的机型名称模糊搜索
     * @param string $mname 机型名称
     * @return array
     */
    public function getModelNames(string $mname)
    {
        $models = $this->qtoInquiryInterface->getSearchModelNamesWithHprc(21, $mname);
        $newModels = [];
        foreach ($models as $key => $value)
        {
            $newModels[$key]['mid'] = $key;
            $newModels[$key]['mname'] = $value['mname'];
        }

        //返回
        return $newModels;
    }

    /**
     * 根据不同机型获取不同类目
     * @param int $mid 机型名称
     * @return  array
     */
    public function getModelItems(int $mid)
    {
        $info = $this->qtoInquiryInterface->getItems(0, $mid);

        //组装返回数据
        $opts = [];
        foreach ($info['items'] as $value)
        {
            //17000 => 内存, 14000 => '版本',16000 => '颜色', 15000 => '网络制式'
            if (in_array($value['cid'], [17000, 14000, 16000, 16000]))
            {
                foreach ($value['opts'] as $k => $val)
                {
                    $opts[$value['cid']][$k] = [
                        'oid' => $val['oid'],
                        'oname' => $val['oname'],
                    ];
                }
            }
        }

        //获取成色
        $levelData = XinxinDictData::PRD_LEVEL;
        foreach ($levelData as $key => $value)
        {
            $opts['level'][$key] = [
                'oid' => $key,
                'oname' => $value['label'],
            ];
        }

        //返回
        return $opts;
    }

    /**
     * 按当天日期生成拼团活动编号
     * @return string
     */
    private function generateSaleGrpKey()
    {
        $time = strtotime(date('Ymd 00:00:00', time()));
        $today = date('Ymd', $time);
        $rkey = 'generate_sale_group_gkey_' . $today;

        while (true)
        {
            //递增当天外借单数
            $num = $this->redis->incr($rkey);
            if ($num == 1)
            {
                //获取当天外借单数重置计数器（防止因清空缓存导致产生重复报单号）
                $count = SaleGroupBuyModel::M()->getCount(['atime' => ['>=' => $time]]);
                if ($count > 0)
                {
                    $num = $this->redis->incrBy($rkey, $count);
                }

                //设置缓存1天有效期
                $this->redis->expire($rkey, 86400);
            }

            /*
             * 加锁防止重复生成订单号
             * 1：加锁失败则重新生成（表示已存在）
             * 2：加锁成功则跳出循环（表示不存在）
             */
            $gkey = 'GB' . $today . str_pad($num, 4, '0', STR_PAD_LEFT);
            if (SaleGroupBuyModel::M()->exist(['gkey' => $gkey]))
            {
                continue;
            }

            //有数据结束循环
            break;
        }

        //返回
        return $gkey;
    }
}
