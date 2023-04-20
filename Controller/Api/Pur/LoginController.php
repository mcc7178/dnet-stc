<?php
namespace App\Module\Sale\Controller\Api\Pur;

use App\Module\Acc\Logic\AccLoginLogic;
use App\Module\Sale\Middleware\Pur\ContextMiddleware;
use App\Module\Sale\Middleware\Pur\SignMiddleware;
use App\Middleware\ApiResultFormat;
use Swork\Bean\Annotation\Controller;
use Swork\Bean\Annotation\Inject;
use Swork\Bean\Annotation\Middleware;
use Swork\Bean\Annotation\Validate;
use Swork\Bean\BeanCollector;
use Swork\Context;
use Swork\Server\Http\Argument;

/**
 * 登录授权接口
 * @Controller("/sale/api/pur/login")
 * @Middleware(SignMiddleware::class)
 * @Middleware(ContextMiddleware::class)
 * @Middleware(ApiResultFormat::class)
 */
class LoginController extends BeanCollector
{
    /**
     * @Inject()
     * @var AccLoginLogic
     */
    private $accLoginLogic;

    /**
     * 微信小程序授权登录
     * @Validate(Method::Post)
     * @Validate("code", Validate::Required, "缺少授权code参数")
     * @param Argument $argument
     * @return array
     * @throws
     */
    public function weixin(Argument $argument)
    {
        //外部参数
        $plat = Context::get('plat');
        $srdist = $argument->get('srdist', 0);
        $srchn =  $argument->get('srchn', 0);
        $spchn =  $argument->get('spchn', 0);
        $ip = Context::get('ip');

        /**
         * 授权code
         * @var string
         * @required
         * @sample b29559e480f54c8ab30ab6002778OX99
         */
        $code = $argument->post('code', '');

        /**
         * 用户昵称
         * @var string
         * @sample 张三
         */
        $nickname = $argument->post('nickname', '');

        /**
         * 用户头像
         * @var string
         * @sample https://tfs.alipayobjects.com/images/partner/T1AyhcXfVGXXXXXXXX
         */
        $avatar = $argument->post('avatar', '');

        /**
         * 帐号不存在时是否直接注册
         * @var int
         * @sample 1
         */
        $register = $argument->post('register', 0);

        //组装用户数据
        $userInfo = [
            'register' => $register,
            'uname' => $nickname,
            'avatar' => $avatar,
            'srdist' => $srdist,
            'srchn' => $srchn,
            'sracc' => '',
            'spchn' => $spchn,
            'ip' => $ip,
        ];

        //通过授权码登录
        $loginInfo = $this->accLoginLogic->byWeixinMcpCode($plat, $code, $userInfo);

        //更新登录信息
        $this->accLoginLogic->updateInfo($plat, $loginInfo['uid'], $userInfo);
        $this->accLoginLogic->updateToken($plat, $userInfo, $loginInfo);

        //API返回
        /**
         * {
         *      "uid":de92f1f991d17b500001b, //新系统用户ID
         *      "uid2": 111, //老系统用户ID
         *      "uname":张三, //用户昵称
         *      "avatar":"https://img.sosotec.com/avatar/2018090600310705440.jpg", //用户头像
         *      "mobile":"13800138000", //用户手机号码
         *      "register": 0|1, //是否新用户注册
         *      "regtime": 1582292249, //用户注册时间
         *      "token":"900a0b0035e4ad3060582747cb10f2a0", //登录token
         * }
         */
        return $loginInfo;
    }
}