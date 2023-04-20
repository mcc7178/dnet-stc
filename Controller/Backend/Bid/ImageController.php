<?php
namespace App\Module\Sale\Controller\Backend\Bid;

use App\Module\Sale\Logic\Backend\Bid\BidImageLogic;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use App\Middleware\LoginMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Server\Http\Argument;

/**
 * 图片处理
 * @Controller("/sale/backend/bid/image")
 * @Middleware(LoginMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class ImageController extends BeanCollector
{
    /**
     * @Inject()
     * @var BidImageLogic
     */
    private $bidImageLogic;

    /**
     * 删除商品图片
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function del(Argument $argument)
    {
        //外部参数
        $pid = $argument->post('pid', '');
        $imgs = $argument->post('imgs', []);
        $src = $argument->post('src', '');

        //删除图片
        $this->bidImageLogic->del($pid, $imgs, $src);

        //返回
        return 'success';
    }

    /**
     * 上传图片
     * @Validate(Method::Post)
     * @param Argument $argument
     * @return string
     * @throws
     */
    public function upload(Argument $argument)
    {
        //外部参数
        $pid = $argument->post('pid', '');
        $src = $argument->post('src', '');

        //删除图片
        $this->bidImageLogic->upload($pid, $src);

        //返回
        return 'success';
    }
}