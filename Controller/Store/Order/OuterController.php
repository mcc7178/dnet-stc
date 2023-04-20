<?php


namespace App\Module\Sale\Controller\Store\Order;

use App\Module\Sale\Logic\Store\Order\OrderOuterLogic;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Server\Http\Argument;
use Swork\Bean\BeanCollector;
use App\Middleware\LoginMiddleware;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\ApiResultFormat;

/**
 * 外单
 * @Controller("/sale/store/outer")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class OuterController extends BeanCollector
{
    /**
     * @Inject()
     * @var OrderOuterLogic
     */
    private $orderOuterLogic;

    /**
     * 新增外单 返回生成的订单主键
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     */
    public function generate(Argument $argument)
    {
        //返回
        return $this->orderOuterLogic->generate();
    }

    /**
     * 验证并返回当条数据
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return array
     * @throws
     */
    public function check(Argument $argument)
    {
        //外部参数
        $bcode = $argument->post('bcode', '');
        $oid = $argument->post('oid', '');
        $rate = $argument->post('rate', 0.00) / 100;

        //返回
        return $this->orderOuterLogic->check($bcode, $oid, $rate);
    }

    /**
     * 显示导入订单
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $oid = $argument->get('oid', '');

        //返回
        return $this->orderOuterLogic->getPager($oid);
    }

    /**
     * 导入订单计算按钮
     * @Validate(Method::POST)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function calculation(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $rate = $argument->post('rate', 0.00) / 100;

        //处理数据
        $this->orderOuterLogic->calculate($oid, $rate);

        //返回
        return 'success';
    }

    /**
     * 内部出价保存
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return array
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $pid = $argument->post('pid', '');
        $bprc = $argument->post('bprc', 0.00);

        //返回
        return $this->orderOuterLogic->save($oid, $pid, $bprc);
    }

    /**
     * 删除订单商品
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return array
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $pid = $argument->post('pid', '');

        //返回
        return $this->orderOuterLogic->delete($oid, $pid);

    }

    /**
     * 提交订单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function submit(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $change_price = $argument->post('change_price', 0);
        $internal_price = $argument->post('internal_price', 0);

        //处理数据
        $this->orderOuterLogic->submit($oid, $change_price, $internal_price);

        //返回
        return 'success';
    }

    /**
     * 外单待成交
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function detail(Argument $argument)
    {
        //外部参数
        $oid = $argument->get('oid', '');

        //返回
        return $this->orderOuterLogic->detail($oid);
    }

    /**
     * 外单详情修改订单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function modify(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //处理数据
        $this->orderOuterLogic->modify($oid);

        //返回
        return 'success';
    }

    /**
     * 外单详情删除订单
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function orderDelete(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //处理数据
        $this->orderOuterLogic->orderDelete($oid);

        //返回
        return 'success';
    }

    /**
     * 待成交修改价格
     * @param Argument $argument
     * @Validate(Method::POST)
     * @Validate("qid", Validate::Required, '报价id不能为空')
     * @Validate("bprc", Validate::Required, '价格不能为空')
     * @return string
     * @throws
     */
    public function change(Argument $argument)
    {
        //外部参数
        $qid = $argument->post('qid', '');
        $bprc = $argument->post('bprc', 0);

        //处理数据
        $this->orderOuterLogic->change($qid, $bprc);

        //返回
        return 'success';
    }

    /**
     * 用户成交
     * @param Argument $argument
     * @Validate(Method::POST)
     * @Validate("recver", Validate::Required, '联系人不能为空')
     * @Validate("rectel", Validate::Required[Mobile], '手机号码格式不对')
     * @return string
     * @throws
     */
    public function complete(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');
        $userId = $argument->post('userId', '');
        $recver = $argument->post('recver', '');
        $rectel = $argument->post('rectel', '');

        //处理数据
        $this->orderOuterLogic->complete($oid, $userId, $recver, $rectel);

        //返回
        return 'success';
    }

    /**
     * 刷新页面接口
     * @param Argument $argument
     * @Validate(Method::POST)
     * @return string
     * @throws
     */
    public function refresh(Argument $argument)
    {
        //外部参数
        $oid = $argument->post('oid', '');

        //返回
        return $this->orderOuterLogic->refresh($oid);
    }
}
