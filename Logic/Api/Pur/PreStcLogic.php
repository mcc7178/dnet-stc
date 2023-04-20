<?php
namespace App\Module\Sale\Logic\Api\Pur;

use App\Amqp\AmqpQueue;
use App\Exception\AppException;
use App\Model\Crm\CrmMessageDotModel;
use App\Model\Pur\PurDemandModel;
use App\Model\Pur\PurMerchantModel;
use App\Model\Pur\PurOdrDemandModel;
use App\Model\Pur\PurOdrGoodsModel;
use App\Model\Pur\PurOdrOrderModel;
use App\Model\Qto\QtoLevelModel;
use App\Model\Qto\QtoModelModel;
use App\Model\Qto\QtoOptionsModel;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\BeanCollector;
use Swork\Client\Amqp;
use Swork\Helper\ArrayHelper;
use Swork\Helper\DateHelper;
use Swork\Helper\IdHelper;

/**
 * 采购预入库
 * Class PreStcLogic
 * @package App\Module\Sale\Logic\Api\Pur
 */
class PreStcLogic extends BeanCollector
{
    /**
     * @Inject("amqp_common_task")
     * @var Amqp
     */
    private $amqp_common;

    /**
     * 获取当前已预入库的商品
     * @param array $query
     * @param int $size
     * @param int $idx
     * @return array
     */
    public function getPager(array $query, int $size, int $idx)
    {
        //获取预入库基本信息
        $where = [];
        if ($query['okey'] != '')
        {
            $where['okey'] = $query['okey'];
        }
        if ($query['dkey'] != '')
        {
            $where['dkey'] = $query['dkey'];
        }
        if (empty($where))
        {
            return [];
        }
        $prenum = PurOdrGoodsModel::M()->getCount($where);
        $pretime = PurOdrOrderModel::M()->getOneById($query['okey'], 'pretime');
        $info = [
            'pretime' => DateHelper::toString($pretime),
            'prenum' => $prenum,
        ];

        //清除预入库红点
        CrmMessageDotModel::M()->delete(['bid' => $query['okey'], 'uid' => $query['uid'], 'src' => 1406]);

        //获取分页数据
        $cols = 'atime,bcode,dkey';
        $list = PurOdrGoodsModel::M()->getList($where, $cols, ['atime' => -1], $size, $idx);

        //获取采购需求配置
        $dkeys = ArrayHelper::map($list, 'dkey');
        $optionsDict = [];
        foreach ($dkeys as $dkey)
        {
            $optionsDict[$dkey] = $this->getDemandConf($dkey);
        }

        //获取采购单价
        $scost = PurOdrDemandModel::M()->getOne($where, 'scost');

        //补充数据
        foreach ($list as $key => $value)
        {
            $list[$key]['scost'] = $scost;
            $list[$key]['mname'] = $optionsDict[$value['dkey']]['mname'];
            $list[$key]['optionsData'] = $optionsDict[$value['dkey']]['opts'];
            $list[$key]['atime'] = DateHelper::toString($value['atime']);
        }

        //返回
        return [
            'info' => $info,
            'list' => $list,
        ];
    }

    /**
     * 扫码预入库
     * @param array $query
     * @param string $uid
     * @throws
     */
    public function pre(array $query, string $uid)
    {
        $okey = $query['okey'];
        $dkey = $query['dkey'];
        $bcode = $query['bcode'];
        $time = time();

        //检查编码规则
        if (!preg_match('/^\d{6}168\d{4,5}$/', $bcode))
        {
            throw new AppException('库存编码格式有误', AppException::WRONG_ARG);
        }
        if (PurOdrGoodsModel::M()->exist(['bcode' => $bcode]))
        {
            throw new AppException('库存编码已使用', AppException::WRONG_ARG);
        }
        $purOrder = PurOdrOrderModel::M()->getRowById($okey);
        if (empty($purOrder))
        {
            throw new AppException('未找到数据', AppException::DATA_DONE);
        }
        if ($purOrder['ostat'] != 1)
        {
            throw new AppException('只有进行中的采购单才可以预入库', AppException::WRONG_ARG);
        }

        //获取采购需求单数据
        $purDemand = PurOdrDemandModel::M()->getRow(['okey' => $okey, 'dkey' => $dkey]);
        if (empty($purDemand))
        {
            throw new AppException('未找到需求单', AppException::DATA_DONE);
        }
        if ($purDemand['cstat'] != 2 || $purDemand['dstat'] != 1)
        {
            throw new AppException('只有审核通过&&进行中的需求单才可以预入库', AppException::WRONG_ARG);
        }

        //组装采购单预入库商品数据
        $gData = [
            'gid' => IdHelper::generate(),
            'okey' => $okey,
            'pkey' => $purDemand['pkey'],
            'dkey' => $dkey,
            'did' => $purDemand['did'],
            'tid' => $purDemand['tid'],
            'merchant' => $purOrder['merchant'],
            'aacc' => $purDemand['aacc'],
            'bcode' => $bcode,
            'gstat' => 1,
            'prdstat' => 0,
            'stcstat' => 0,
            'gtime1' => $time,
            'atime' => $time,
        ];
        PurOdrGoodsModel::M()->insert($gData);

        //更新采购单数据
        $data = [
            'pretime' => $time,
            'ltime' => $time
        ];
        if ($purOrder['stock'] == 0)
        {
            $data['stock'] = 1;
        }
        //存在已入库商品时，更新为部分入库（当前预入库还未入库）
        $goodsNum = PurOdrGoodsModel::M()->getCount(['okey' => $okey, 'gtime2' => ['>' => 0]]);
        if ($goodsNum > 0)
        {
            $data['stock'] = 2;
        }
        PurOdrOrderModel::M()->updateById($okey, $data);

        //检查需求单状态是否采购中
        $demand = PurDemandModel::M()->getRowById($dkey, 'dstat');
        if ($demand['dstat'] == 2)
        {
            PurDemandModel::M()->updateById($dkey, ['dstat' => 3, 'ltime' => $time]);
        }

        //红点提示
        $toAcc = $purOrder['aacc'];
        //首页显示 - 采购单 + 需求单
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1301, 'uid' => $toAcc,]);
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1302, 'uid' => $toAcc]);

        //组装小红点数据（采购列表显示 - 预入库）
        AmqpQueue::deliver($this->amqp_common, 'sale_pur_message_dot', ['src' => 1406, 'uid' => $toAcc, 'bid' => $okey]);
    }

    /**
     * @param string $okey
     * @param string $dkey
     * @return array
     * @throws
     */
    public function getInfo(string $okey, string $dkey)
    {
        //获取采购单数据
        $purOrder = PurOdrOrderModel::M()->getRowById($okey);
        if (empty($purOrder))
        {
            throw new AppException('未找到数据', AppException::DATA_DONE);
        }
        if ($purOrder['ostat'] != 1)
        {
            throw new AppException('只有进行中的采购单才可以预入库', AppException::WRONG_ARG);
        }

        //获取采购需求单数据
        $purDemand = PurOdrDemandModel::M()->getRow(['okey' => $okey, 'dkey' => $dkey]);
        if (empty($purDemand))
        {
            throw new AppException('未找到需求单', AppException::DATA_DONE);
        }
        if ($purDemand['cstat'] != 2 || $purDemand['dstat'] != 1)
        {
            throw new AppException('只有审核通过&&进行中的需求单才可以预入库', AppException::WRONG_ARG);
        }
        //获取供货商
        $merchant = PurMerchantModel::M()->getRowById([$purOrder['merchant']], 'mname,mobile');

        $info = [];
        $info['merchant'] = $merchant['mname'] ?? '-';
        $info['mobile'] = $merchant['mobile'] ?? '-';
        $info['okey'] = $okey;
        $info['dkey'] = $dkey;
        $info['rnum'] = $purDemand['rnum'];
        $info['unum'] = $purDemand['unum'];
        $info['prenum'] = PurOdrGoodsModel::M()->getCount(['okey' => $okey, 'dkey' => $dkey]);
        $info['waitnum'] = intval($info['rnum'] - $info['prenum']);
        $info['pretime'] = DateHelper::toString($purOrder['pretime']);
        $info['atime'] = DateHelper::toString($purDemand['atime']);

        //获取采购需求配置
        $demandConf = $this->getDemandConf($dkey);
        $info['mname'] = $demandConf['mname'];
        $info['optionsData'] = $demandConf['opts'];

        //返回
        return $info;
    }

    /**
     * 获取采购需求对应选项
     * @param string $dkey 需求单号
     * @param string $glue 分割符号
     * @return array
     */
    private function getDemandConf(string $dkey, string $glue = ' ')
    {
        //获取采购需求
        $demand = PurDemandModel::M()->getRowById($dkey, 'mid,level,mdram,mdcolor,mdofsale,mdnet,mdwarr');

        //获取级别
        $level = QtoLevelModel::M()->getOneById($demand['level'], 'lname');

        //获取采购机型需求
        $opts = [$demand['mdram'], $demand['mdcolor'], $demand['mdofsale'], $demand['mdnet'], $demand['mdwarr']];
        $opts = QtoOptionsModel::M()->getList(['oid' => ['in' => $opts], 'plat' => 0], 'oname');
        $opts = ArrayHelper::toJoin($opts, 'oname', $glue);
        $opts = $level . $glue . $opts;

        //获取机型名称
        $mname = QtoModelModel::M()->getOneById($demand['mid'], 'mname');

        //返回
        return [
            'mname' => $mname,
            'opts' => $opts
        ];
    }
}