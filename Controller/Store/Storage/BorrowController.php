<?php
namespace App\Module\Sale\Controller\Store\Storage;

use App\Exception\AppException;
use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Store\Storage\StorageBorrowLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 外借单管理
 * @Controller("/sale/store/borrow")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class BorrowController extends BeanCollector
{

    /**
     * @Inject()
     * @var StorageBorrowLogic
     */
    private $borrowLogic;

    /**
     * 获取翻页数据
     * @param Argument $argument
     * @return array
     */
    public function pager(Argument $argument)
    {
        //外部参数
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->borrowLogic->getPager($query, $idx, $size);
    }

    /**
     * 获取翻页总数
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //外部参数
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->borrowLogic->getPagerCount($query);
    }

    /**
     * 获取单条详情信息
     * @Validate("skey", Validate::Required, "外借单号不能为空")
     * @param Argument $argument
     * @return array|bool
     * @throws
     */
    public function info(Argument $argument)
    {
        //外部参数
        $skey = $argument->get('skey', '');

        //返回
        return $this->borrowLogic->getInfo($skey);
    }

    /**
     * 检查库存编码、新增外借单
     * @Validate(Method::"Post")
     * @Validate("bcode", Validate::Required, "库存编码不能为空")
     * @param Argument $argument
     * @return array|bool
     * @throws
     */
    public function search(Argument $argument)
    {
        //外部参数
        $bcode = $argument->post('bcode', '');
        $sid = $argument->post('sid', '');

        //返回
        return $this->borrowLogic->search($bcode, $sid);
    }

    /**
     * 待领取状态下-删除外借单数据
     * @Validate(Method::"Post")
     * @Validate("sid", Validate::Required, "外借单号不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $sid = $argument->post('sid', '');

        //删除数据
        $this->borrowLogic->delete($sid);

        //返回
        return 'ok';
    }

    /**
     * 删除未出库外借单中单个商品
     * @Validate(Method::"Post")
     * @Validate("pid" ,Validate::Required, "库存编码不能为空")
     * @Validate("sid" ,Validate::Required, "外借单号不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delGoods(Argument $argument)
    {
        //外部参数
        $pid = $argument->post('pid', '');
        $sid = $argument->post('sid', '');

        //处理数据
        $this->borrowLogic->delGoods($pid, $sid);

        //返回
        return 'ok';
    }

    /**
     * 保存出库单-出库/待领取
     * @Validate(Method::"Post")
     * @Validate("sid", Validate::Required, "外借单号不能为空")
     * @Validate("sacc", Validate::Required, "外借人不能为空")
     * @Validate("dept", Validate::Required, "外借部门不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function save(Argument $argument)
    {
        //外部参数
        $data = [
            'sid' => $argument->post('sid', ''),
            'skey' => $argument->post('skey', ''),
            'sacc' => $argument->post('sacc', ''),
            'dept' => $argument->post('dept', 0),
            'rmk' => $argument->post('rmk', ''),
        ];

        //处理数据
        $this->borrowLogic->save($data);

        //返回
        return 'ok';
    }

    /**
     * 批量添加商品达到外借单
     * @Validate(Method::"Post")
     * @Validate("sid", Validate::Required, "外借单号不能为空")
     * @Validate("bcodes", Validate::Required, "库存编码不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function batchAdd(Argument $argument)
    {
        //外部参数
        $data = [
            'sid' => $argument->post('sid', ''),
            'bcodes' => $argument->post('bcodes', ''),
        ];

        //处理数据
        $this->borrowLogic->batchAdd($data);

        //返回
        return 'ok';
    }

    /**
     * 回仓检查库存编码
     * @Validate(Method::"Post")
     * @Validate("bcode", Validate::Required, "库存编码不能为空")
     * @param Argument $argument
     * @return array|bool
     * @throws AppException
     */
    public function searchBack(Argument $argument)
    {
        //外部参数
        $bcode = $argument->post('bcode', '');

        //返回
        return $this->borrowLogic->searchBack($bcode);
    }

    /**
     * 商品回仓
     * @Validate(Method::"Post")
     * @Validate("pids", Validate::Required, "库存编码不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function back(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $pids = $argument->post('pids', '');

        //处理数据
        $this->borrowLogic->back($acc, $pids);

        //返回
        return 'ok';
    }

    /**
     * 详情页导出
     * @param Argument $argument
     * @Validate("skey" ,Validate::Required, "外借单号不能为空")
     * @return array
     * @throws
     */
    public function export(Argument $argument)
    {
        //外部参数
        $skey = $argument->get('skey', '');

        //返回数据
        return $this->borrowLogic->export($skey);
    }

    /**
     * 获取查询条件
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        return [
            'bcode' => $argument->get('bcode', ''),
            'skey' => $argument->get('skey', ''),
            'dept' => $argument->get('dept', 0),
            'bacc' => $argument->get('bacc', ''),
            'racc' => $argument->get('racc', ''),
            'stat' => $argument->get('stat', ''),
            'ttype' => $argument->get('ttype', ''),
            'date' => $argument->get('date', []),
        ];
    }


}