<?php
namespace App\Module\Sale\Logic\Api\Pur;

use App\Exception\AppException;
use App\Model\Pur\PurMerchantModel;
use Swork\Bean\BeanCollector;
use Swork\Db\Db;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;
use Throwable;

class MerchantLogic extends BeanCollector
{
    /**
     * 获取供货商翻页数据（不要分页 展示所有）
     * @param int $idx
     * @param int $size
     * @param array $query
     * @return array
     */
    public function getPager(array $query, int $idx, int $size)
    {
        if ($idx > 1)
        {
            return [];
        }

        //查询条件
        $where = $this->getPagerWhere($query);

        //获取供货商数据
        $list = PurMerchantModel::M()->getList($where, 'mid,mname,mobile,address,atime', ['atime' => -1], 200);

        //补充数据
        foreach ($list as $key => $value)
        {
            $list[$key]['atime'] = DateHelper::toString($value['atime']);
            $list[$key]['address'] = json_decode($value['address']);
        }

        //填充默认值
        ArrayHelper::fillDefaultValue($list);

        //返回
        return $list;
    }

    /**
     * 保存供货商数据
     * @param array $query
     * @param string $acc
     * @throws AppException
     * @throws Throwable
     * @throws
     */
    public function save(array $query, string $acc)
    {
        //提取参数
        $mid = $query['mid'];
        $mname = $query['mname'];
        $mobile = $query['mobile'];
        $address = $query['address'];

        if ($mid)
        {
            //检查供货商数据是否存在
            $info = PurMerchantModel::M()->getOneById($mid, 'mid');
            if ($info == false)
            {
                throw new AppException('供货商不存在', AppException::NO_DATA);
            }

            //组装更新数据
            $data = [
                'mid' => $mid,
                'mname' => $mname,
                'mobile' => $mobile,
                'address' => json_encode($address, JSON_UNESCAPED_UNICODE),
                'mtime' => time()
            ];
        }
        else
        {
            $mid = IdHelper::generate();

            //组装插入数据
            $data = [
                'mid' => $mid,
                'mname' => $mname,
                'mobile' => $mobile,
                'address' => json_encode($address, JSON_UNESCAPED_UNICODE),
                'facc' => $acc,
                'atime' => time()
            ];
        }

        try
        {
            //开始事务
            Db::beginTransaction();

            //执行操作
            PurMerchantModel::M()->insert($data, true);

            //检查数据是否重复
            $count = PurMerchantModel::M()->getCount(['mname' => $mname, 'mobile' => $mobile]);
            if ($count >= 2)
            {
                throw new AppException('该数据已被其他供货商使用，请修改其他数据', AppException::OUT_OF_USING);
            }

            //提交事务
            Db::commit();
        }
        catch (Throwable $throwable)
        {
            //回滚
            Db::rollback();

            //抛出异常
            throw $throwable;
        }
    }

    /**
     * 删除地址
     * @param string $mid
     * @param string $address
     * @throws
     */
    public function delete(string $mid, string $address)
    {
        //获取供货商地址数据
        $addressInfo = PurMerchantModel::M()->getOneById($mid, 'address');
        if ($addressInfo == false)
        {
            throw new AppException('供货商数据不存在', AppException::NO_DATA);
        }
        $addressInfo = json_decode($addressInfo);
        if (!in_array($address, $addressInfo))
        {
            throw new AppException('供货商没有此地址', AppException::NO_DATA);
        }

        $addressInfo = array_merge(array_diff($addressInfo, [$address]));

        //执行更新
        PurMerchantModel::M()->updateById($mid, ['address' => json_encode($addressInfo)]);
    }

    /**
     * 查询条件
     * @param array $query
     * @return array
     */
    public function getPagerWhere(array $query)
    {
        $where = [];

        //供货商姓名
        if ($query['mname'])
        {
            $where['mname'] = ['like' => "%{$query['mname']}%"];
        }

        //手机号码
        if ($query['mobile'])
        {
            $where['mobile'] = $query['mobile'];
        }

        //添加时间
        if ($query['atime'] && count($query['atime']) == 2)
        {
            $date = $query['atime'];
            $stime = strtotime($date[0] . ' 00:00:00');
            $etime = strtotime($date[1] . ' 23:59:59');
            $where['atime'] = ['between' => [$stime, $etime]];
        }

        //返回
        return $where;
    }

    /**
     * 获取供货商详情
     * @param string $mid
     * @return array|bool|mixed
     * @throws
     */
    public function getInfo(string $mid)
    {
        //获取供货商详情数据
        $purMerchantInfo = PurMerchantModel::M()->getRowById($mid, 'mname,mobile,address');
        if ($purMerchantInfo == false)
        {
            throw new AppException('供货商数据不存在', AppException::NO_DATA);
        }

        //地址转换格式
        $purMerchantInfo['address'] = ArrayHelper::toArray($purMerchantInfo['address']);

        //返回
        return $purMerchantInfo;
    }
}