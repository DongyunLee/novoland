<?php
/**
 * Index.php-novoland
 * By lidongyun@shuwang-tech.com
 * On 2018/7/4 16:22
 * Doing good deeds without asking for reward
 */

namespace app\user\controller;

use app\robot\controller\Api;
use app\user\model\User;
use app\user\model\UserBbs;
use ErrorException;
use think\Controller;
use think\Db;
use think\Loader;
use think\Request;
use think\Session;

use function json_decode;
use function json_encode;
use function md5;

/**
 * 用户控制器
 *
 * @package app\user\controller
 */
class Index extends Controller
{
    private const BBS_URL = 'https://9zer.doylee.cn';
    
    /**
     * 登录页面
     *
     * @return mixed
     */
    public function index()
    {
        if (!empty(Session::get('user')) && !empty(Session::get('user.user_id'))) {
            $this->redirect("/");
        }
        $this->assign('bbs_id', Session::get('bbs_id') ?? '');
        
        return $this->fetch();
    }
    
    /**
     * 登录操作
     *
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function login(): array
    {
        try {
            // 参数过滤
            $params = Request::instance()
                ->param();
            $validate = Loader::validate('user');
            if (!$validate->scene('login')
                ->check($params)
            ) {
                throw new ErrorException($validate->getError(), 10301);
            }
            
            // 登录验证
            $result = (new User())->login($params['username'], $params['password']);
            // 判断是否需要更新论坛标识
            $this->isNeedUpdateBBSMark($params);
            if ($result['code'] !== 0) {
                throw new ErrorException($result['msg'], $result['code']);
            }
            
            Session::set('user', $result['data']);
            
            return $result;
        } catch (ErrorException $e) {
            return [
                'data' => null,
                'code' => $e->getCode(),
                'msg' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 注册页面
     *
     * @return mixed
     */
    public function signIn()
    {
        $this->assign('bbs_id', Session::get('bbs_id') ?? '');
        
        return $this->fetch();
    }
    
    /**
     * 注册操作
     * register
     *
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\BindParamException
     * @throws \think\exception\PDOException
     */
    public function register(): array
    {
        try {
            // 过滤参数
            $params = Request::instance()
                ->param();
            
            $validate = Loader::validate('User');
            
            if (!$validate->scene('register')
                ->check($params)
            ) {
                throw new ErrorException($validate->getError(), 10311);
            }
            
            // 注册
            $users = new User();
            $result = $users->register(
                [
                    'username' => $params['username'],
                    'password' => $params['password'],
                    'mobile' => $params['mobile'],
                    'email' => $params['email'],
                    'qq' => $params['qq']
                ]
            );
            // 判断是否需要更新论坛标识
            $this->isNeedUpdateBBSMark($params);
            if ($result['code'] !== 0) {
                throw new ErrorException($result['msg'], $result['code']);
            }
            
            return $result;
        } catch (ErrorException $error) {
            return [
                'code' => $error->getCode(),
                'msg' => $error->getMessage(),
                'data' => null
            ];
        }
    }
    
    /**
     * @param $params
     *
     * @throws \ErrorException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function isNeedUpdateBBSMark($params)
    {
        if (isset($params['bbs_id']) && !empty($params['bbs_id'])) {
            $user_bbs = new UserBbs();
            if (!$user_bbs->issetAccount($params['bbs_id']))
            if (!((new UserBbs())->bindAccount($params['username'], $params['bbs_id']))) {
                throw  new ErrorException('绑定失败，请尝试重启浏览器以重新绑定或直接使用游戏帐户登录', 10302);
            }
            Session::delete('bbs_id');
        }
    }
    
    /**
     * 退出登录
     */
    public function logout()
    {
        // 清除session（当前作用域）
        Session::clear();
        // 清除think作用域
        Session::clear('think');
        // 清除当前请求有效的session
        Session::flush();
        $this->redirect('/');
    }
    
    /**
     * 使用论坛账户登录页面
     *
     * @return mixed
     */
    public function logAsBbs()
    {
        return $this->fetch();
    }
    
    /**
     * 论坛账户登录验证
     *
     * @return string code（0：登录成功进行跳转，1：登录失败，2：未绑定账号登录成功弹窗选择注册新账号还是登录以绑定已有账号）
     * @throws \think\Exception
     * @throws \think\exception\DbException
     */
    public function loginBbs()
    {
        $username = Request::instance()
            ->param('username');
        $password = Request::instance()
            ->param('password');
        
        // 判断是否存在已绑定论坛账号的游戏账号
        $user_info = Db::name('user u')
            ->join('__USER_BBS__ ub', 'u.user_id=ub.user_id')
            ->where('ub.bbs_email', 'eq', md5($username))
            ->find();
        $isset_user = $user_info === false || $user_info === [] || $user_info === null
            ? 0
            : 1;
        
        // 验证论坛账号
        $form_data = [
            'email' => $username,
            'password' => md5($password)
        ];
        $json = Api::request_post(self::BBS_URL . '/user-login.htm?ajax=1', $form_data);
        $result = json_decode($json, true);
        try {
            // 登录验证失败
            if ($result['code'] !== 0 && $result['code'] !== '0') {
                throw new ErrorException($result['message'], 1);
            }
            // 未绑定游戏账号
            if ($isset_user !== 1) {
                throw new ErrorException(md5($username), 2);
            }
            Session::set('user', $user_info);
            exit(
            json_encode(
                [
                    'code' => 0,
                    'msg' => '登录成功，正在跳转',
                    'data' => null
                ]
            )
            );
        } catch (ErrorException $error_exception) {
            $code = $error_exception->getCode();
            if ($code === 2) {
                Session::set('bbs_id', $error_exception->getMessage());
            }
            $msg = $code === 2
                ? '验证成功，需要绑定一个游戏账号'
                : $error_exception->getMessage();
            exit(
            json_encode(
                [
                    'code' => $code,
                    'msg' => $msg,
                    'data' => null
                ]
            )
            );
        }
    }
    
}




