<?php
namespace App\Module\Sale\Controller\Store\Storage;

use App\Middleware\ApiResultFormat;
use App\Middleware\LoginMiddleware;
use App\Module\Sale\Logic\Store\Storage\StorageLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 入库单、出库单管理
 * @Controller("/sale/store/storage")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class StorageController extends BeanCollector
{

    /**
     * @Inject()
     * @var StorageLogic
     */
    private $storageLogic;

    /**
     * 获取入库出库单翻页数据
     * @param Argument $argument
     * @return array|false|mixed
     */
    public function pager(Argument $argument)
    {
        //获取外部参数
        $query = $this->getPagerQuery($argument);
        $idx = $argument->get('idx', 1);
        $size = $argument->get('size', 25);

        //返回
        return $this->storageLogic->getPager($query, $idx, $size);
    }

    /**
     * 获取入库出库单详情
     * @Validate("skey" ,Validate::Required, "出库单号不能为空")
     * @param Argument $argument
     * @return mixed
     * @throws
     */
    public function info(Argument $argument)
    {
        //获取外部参数
        $skey = $argument->get('skey', '');

        //返回
        return $this->storageLogic->getInfo($skey);
    }

    /**
     * 获取翻页总数
     * @param Argument $argument
     * @return int
     */
    public function count(Argument $argument)
    {
        //获取外部参数
        $query = $this->getPagerQuery($argument);

        //返回
        return $this->storageLogic->getCount($query);
    }

    /**
     * 一键入库
     * @Validate(Method::"Post")
     * @Validate("sid" ,Validate::Required, "出库单号不能为空")
     * @param Argument $argument
     * @return bool
     * @throws
     */
    public function saveInput(Argument $argument)
    {
        //外部参数
        $acc = Context::get('acc');
        $sid = $argument->post('sid', '');

        //处理数据
        $this->storageLogic->saveInput($acc, $sid);

        //返回
        return 'ok';
    }

    /**
     * 检查库存编码、新增出库单
     * @Validate(Method::"Post")
     * @Validate("bcode" ,Validate::Required, "库存编码不能为空")
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
        return $this->storageLogic->search($bcode, $sid);
    }

    /**
     * 删除出库单列表中的商品
     * @Validate(Method::"Post")
     * @Validate("pid" ,Validate::Required, "库存编码不能为空")
     * @Validate("sid" ,Validate::Required, "出库单号不能为空")
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
        $this->storageLogic->delGoods($pid, $sid);

        //返回
        return 'ok';
    }

    /**
     * 保存出库单
     * @Validate(Method::"Post")
     * @Validate("sacc" ,Validate::Required, "接收人不能为空")
     * @Validate("twhs" ,Validate::Required, "接收仓库不能为空")
     * @Validate("sid" ,Validate::Required, "出库单号不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function saveOutput(Argument $argument)
    {
        //上下文参数
        $acc = Context::get('acc');

        //外部参数
        $query = [
            'sid' => $argument->post('sid', ''),
            'frmk' => $argument->post('frmk', ''),
            'twhs' => $argument->post('twhs', 0),
            'sacc' => $argument->post('sacc', ''),
        ];

        //处理数据
        $this->storageLogic->saveOutput($acc, $query);

        //返回
        return 'ok';
    }

    /**
     * 调整出库地点及接收人
     * @Validate(Method::Post)
     * @Validate("sid" ,Validate::Required, "出库单号不能为空")
     * @Validate("fwhs" ,Validate::Required, "接收仓库不能为空")
     * @Validate("receiver" ,Validate::Required, "接收人不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function adjust(Argument $argument)
    {
        //外部参数
        $query = [
            'sid' => $argument->post('sid', ''),
            'fwhs' => $argument->post('fwhs', 0),
            'sacc' => $argument->post('sacc', ''),
        ];

        //处理数据
        $this->storageLogic->adjust($query);

        //返回
        return 'ok';
    }

    /**
     * 删除出库单
     * @Validate(Method::Post)
     * @Validate("sid" ,Validate::Required, "出库单号不能为空")
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function delete(Argument $argument)
    {
        //外部参数
        $sid = $argument->post('sid', '');

        //处理数据
        $this->storageLogic->delete($sid);

        //返回
        return 'ok';
    }

    /**
     * 详情页导出
     * @param Argument $argument
     * @Validate("skey" ,Validate::Required, "出库单号不能为空")
     * @return array
     * @throws
     */
    public function export(Argument $argument)
    {
        //外部参数
        $skey = $argument->get('skey', '');

        //返回
        return $this->storageLogic->export($skey);
    }

    /**
     * 出库单接收仓库下拉列表数据
     * @return array
     */
    public function source()
    {
        //返回
        return $this->storageLogic->getSource();
    }

    /**
     * 查询条件
     * @param Argument $argument
     * @return array
     */
    private function getPagerQuery(Argument $argument)
    {
        return [
            'bcode' => $argument->get('bcode', ''),
            'skey' => $argument->get('skey', ''),
            'fwhs' => $argument->get('fwhs', 0),
            'twhs' => $argument->get('twhs', 0),
            'tacc' => $argument->get('tacc', ''),
            'facc' => $argument->get('facc', ''),
            'tstat' => $argument->get('tstat', 0),
            'fstat' => $argument->get('fstat', 0),
            'ttime' => $argument->get('ttime', []),
            'ftime' => $argument->get('ftime', []),
            'type' => $argument->get('type', 1),
        ];
    }

}