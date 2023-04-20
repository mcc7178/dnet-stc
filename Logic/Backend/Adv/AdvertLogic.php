<?php
namespace App\Module\Sale\Logic\Backend\Adv;

use App\Exception\AppException;
use App\Model\Acc\AccUserModel;
use App\Model\Cms\CmsAdvertModel;
use App\Module\Sale\Data\SaleDictData;
use Swork\Bean\BeanCollector;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;

/**
 * 广告位接口逻辑
 * Class AdvertLogic
 */
class AdvertLogic extends BeanCollector
{
    /**
     * 广告内容列表
     * @param array $query 搜索
     * @param int $idx 页码
     * @param int $size 页量
     * @return array
     */
    public function getPager(array $query, int $idx = 1, $size = 25)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //获取翻页数据
        $cols = 'aid,distchn,distpos,title,stime,etime,stat,atime,mtime,muser';
        $list = CmsAdvertModel::M()->getList($where, $cols, ['atime' => -1], $size, $idx);
        if ($list == false)
        {
            return [];
        }

        //获取创建人姓名
        $uaccs = ArrayHelper::map($list, 'muser');
        $accDict = AccUserModel::M()->getDict('aid', ['aid' => ['in' => $uaccs]], 'aid,uname,rname');

        //补充数据
        $time = time();
        foreach ($list as $key => $value)
        {
            $stime = $value['stime'];
            $etime = $value['etime'];

            $rname = $accDict[$value['muser']]['rname'] ?? '';
            $uname = $accDict[$value['muser']]['uname'] ?? '-';
            $list[$key]['mname'] = $rname ?: $uname;
            $list[$key]['distpos'] = SaleDictData::CMS_ADVERT_DISTPOS[$value['distpos']] ?? '-';
            $list[$key]['atime'] = $value['atime'] > 0 ? DateHelper::toString($value['atime']) : '-';
            $list[$key]['mtime'] = $value['mtime'] > 0 ? DateHelper::toString($value['mtime']) : '-';
            $list[$key]['stime'] = DateHelper::toString($stime, 'Y-m-d');
            $list[$key]['etime'] = DateHelper::toString($etime, 'Y-m-d');

            //转换投放渠道
            $distchn = ArrayHelper::toArray($value['distchn']);
            $newDistchn = [];
            foreach ($distchn as $item)
            {
                $newDistchn[] = SaleDictData::CMS_ADVERT_DISTCHN[$item] ?? '';
            }

            $list[$key]['distchn'] = join('、', $newDistchn);
            $list[$key]['tstat'] = 0;

            //转换状态
            $stat = $value['stat'];
            if ($stat == 2)
            {
                $list[$key]['sname'] = '未启用';
                if ($stime >= $time)
                {
                    $list[$key]['tstat'] = 1;
                }
            }
            else
            {
                if ($stime >= $time)
                {
                    $list[$key]['sname'] = '待公开';
                }
                if ($etime <= $time)
                {
                    $list[$key]['sname'] = '已结束';
                }
                if ($stime < $time && $etime > $time)
                {
                    $list[$key]['sname'] = '启用中';
                }
            }
        }

        //返回
        return $list;
    }

    /**
     * 广告总条数
     * @param array $query
     * @return int
     */
    public function getCount(array $query)
    {
        //查询条件
        $where = $this->getPagerWhere($query);

        //获取数据
        $count = CmsAdvertModel::M()->getCount($where);

        //返回
        return $count;
    }

    /**
     * 广告详情
     * @param string aid 主键
     * @return array
     * @throws
     */
    public function getInfo(string $aid)
    {
        //获取详情页数据
        $cols = 'title,stime,etime,distchn,distpos,content,imgsrc';
        $info = CmsAdvertModel::M()->getRowById($aid, $cols);
        if ($info == false)
        {
            throw new AppException(AppException::NO_DATA);
        }

        //投放渠道
        $info['distchn'] = ArrayHelper::toArray($info['distchn']);

        //格式化时间
        $info['stime'] = date("Y-m-d", $info['stime']);
        $info['etime'] = date("Y-m-d", $info['etime']);

        //返回
        return $info;
    }

    /**
     * 更新广告状态
     * @param string $acc 用户id
     * @param string $aid 活动ID
     * @param int $stat 活动状态码
     * @throws
     */
    public function setIsAtv(string $acc, string $aid, int $stat)
    {
        //更新状态
        CmsAdvertModel::M()->updateById($aid, ['stat' => $stat, 'mtime' => time(), 'muser' => $acc]);
    }

    /**
     * 保存广告
     * @param array $query
     * @throws
     */
    public function save(array $query)
    {
        //外部参数
        $time = time();
        $aid = $query['aid'];
        $uacc = $query['uacc'];
        $stime = strtotime($query['stime']);
        $etime = strtotime($query['etime']) + 86399;
        if (count($query['distchn']) == 0)
        {
            $distchn = '[]';
        }
        else
        {
            $distchn = json_encode($query['distchn']);
        }

        $data = [
            'title' => $query['title'],
            'content' => $query['content'],
            'imgsrc' => $query['imgsrc'],
            'distchn' => $distchn,
            'distpos' => $query['distpos'],
            'stime' => $stime,
            'etime' => $etime,
            'mtime' => $time,
            'muser' => $uacc,
        ];

        if (empty($aid))
        {
            $today = date('ymd', $time);
            $todaytime = strtotime(date('Y-m-d 00:00:00'));

            //获取目前行数
            $num = CmsAdvertModel::M()->getCount(['atime' => ['>=' => $todaytime]]);

            //新增
            $data['aid'] = $today . str_pad($num, 2, '0', STR_PAD_LEFT);
            $data['atime'] = $time;
            $data['auser'] = $uacc;
            $data['stat'] = 2;
            CmsAdvertModel::M()->insert($data);
        }
        else
        {
            $info = CmsAdvertModel::M()->getRowById($aid, 'stime,etime,stat');
            if ($info == false)
            {
                throw new AppException(AppException::NO_DATA);
            }

            //校验广告状态
            if ($info['stat'] == 1 && (($info['stime'] < $time && $info['etime'] > $time) || $info['etime'] <= $time))
            {
                throw new AppException('广告状态启用中或已结束，无法进行修改操作', AppException::NO_RIGHT);
            }

            //修改
            CmsAdvertModel::M()->updateById($aid, $data);
        }
    }

    /**
     * 删除广告
     * @param string aid
     * @throws
     */
    public function delete($aid)
    {
        $info = CmsAdvertModel::M()->getRowById($aid, 'stime,etime,stat');
        if ($info == false)
        {
            throw new AppException(AppException::NO_DATA);
        }

        //校验广告状态
        $time = time();
        if ($info['stat'] == 1 && (($info['stime'] < $time && $info['etime'] > $time) || $info['etime'] <= $time))
        {
            throw new AppException('广告状态启用中或已结束，无法进行删除操作', AppException::NO_RIGHT);
        }

        //删除数据（标记删除）
        CmsAdvertModel::M()->updateById($aid, ['stat' => -9]);
    }

    /**
     * 广告列表翻页数据条件
     * @param array $query
     * @return array
     */
    private function getPagerWhere(array $query)
    {
        //数据条件
        $where = ['stat' => ['!=' => -9]];

        $title = $query['title'];
        if ($title != '')
        {
            $where['title'] = ['like' => '%' . $title . '%'];
        }

        //返回
        return $where;
    }

}