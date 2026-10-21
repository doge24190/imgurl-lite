<?php
    defined('BASEPATH') OR exit('No direct script access allowed');

    class User extends CI_Controller{
        //构造函数
        public function __construct(){
            parent::__construct();
            //加载辅助函数
            $this->load->helper('basic');
        }
        //用户登录
        public function login(){
            //加载基础类
            $this->load->library('basic');
            //判断用户是否登录
            if($this->basic->is_login(FALSE)){
                //如果已经登录，则跳转到后台
                header("location:/admin/");
                return;
            }
            //加载数据库模型
            $this->load->model('query','',TRUE);
            //查询站点信息
            $siteinfo = $this->query->site_setting('1');
            $siteinfo->title = '管理员登录 - '.$siteinfo->title;
            
            //加载登录视图
            $this->load->view('user/header',$siteinfo);
            $this->load->view('user/login');
            $this->load->view('user/footer');
        }
        //生成一次性登录验证码
        public function captcha(){
            $this->load->library('login_security');
            $ip = $this->input->ip_address();
            $challenge = $this->login_security->create_challenge($ip);
            $image = $this->login_security->render_captcha($challenge['word']);

            if($image === FALSE){
                show_error('生成验证码需要启用 PHP GD 扩展。', 503);
                return;
            }

            $this->set_cookie_header(
                'imgurl_captcha',
                $challenge['token'],
                $challenge['expires'],
                TRUE,
                'Strict'
            );
            header('Content-Type: image/png');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            imagepng($image);
            unset($image);
            exit;
        }
        //验证用户名、密码是否正确
        public function verify(){
            if( ! isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST'){
                $this->json_response(array('code' => 405, 'msg' => '请求方式不正确'), 405);
                return;
            }
            $this->load->library('login_security');

            //获取用户输入的信息
            $user = $this->input->post('user',TRUE);
            $pass = $this->input->post('password');
            $captcha = $this->input->post('captcha',TRUE);
            $captcha_token = isset($_COOKIE['imgurl_captcha']) ? $_COOKIE['imgurl_captcha'] : '';
            $ip = $this->input->ip_address();

            $captcha_result = $this->login_security->consume_challenge($ip, $captcha, $captcha_token);
            $this->expire_cookie('imgurl_captcha', TRUE, 'Strict');

            if($captcha_result['locked']){
                $this->rate_limit_response($captcha_result['retry_after']);
                return;
            }

            if( ! $captcha_result['valid']){
                $this->json_response(array(
                    'code' => 0,
                    'msg' => '验证码不正确或已过期，请重试',
                    'refresh_captcha' => TRUE
                ));
                return;
            }

            $pass = is_string($pass) ? $pass : '';
            if (strlen($pass) > 4096) { $pass = ''; }

            //加载模型
            $this->load->model("query",'',TRUE);
            $info = $this->query->userinfo()->values;
            
            $info = json_decode($info);
            
            //获取真正的用户名
            $username = $info->username;
            
            $password = $info->password;

            $user_matches = is_string($user) && hash_equals((string)$username, $user);
            $legacy = preg_match('/^[a-f0-9]{32}$/i', $password) === 1;
            $prehashed = str_starts_with($password, 'sha384:');
            $password_matches = $legacy ? hash_equals($password, md5($pass.'imgurl'))
                : password_verify($prehashed ? base64_encode(hash('sha384', $pass, true)) : $pass, $prehashed ? substr($password, 7) : $password);

            if($user_matches && $password_matches){
                if ($legacy || password_needs_rehash($prehashed ? substr($password, 7) : $password, PASSWORD_DEFAULT)) {
                    $info->password = strlen($pass) > 72
                        ? 'sha384:'.password_hash(base64_encode(hash('sha384', $pass, true)), PASSWORD_DEFAULT)
                        : password_hash($pass, PASSWORD_DEFAULT);
                    $this->load->model('update');
                    if (!$this->update->password(json_encode($info))) {
                        show_error('无法升级密码，请检查数据库写入权限。', 503);
                        return;
                    }
                    $password = $info->password;
                }
                $this->load->library('auth');
                $this->auth->login($username, $password);
                $this->login_security->clear_failures($ip);
                //跳转到后台
                $this->json_response(array(
                    "code"  =>  200,
                    "msg"   =>  '登录成功！'
                ));
                return;
            }
            else{
                $retry_after = $this->login_security->record_failure($ip);
                //清除cookie
                $this->clean_cookies();
                if($retry_after > 0){
                    $this->rate_limit_response($retry_after);
                    return;
                }
                $this->json_response(array(
                    'code' => 0,
                    'msg' => '用户名或密码不正确',
                    'refresh_captcha' => TRUE
                ));
                return;
            }
        }
        public function logout(){
            if ($this->input->method() !== 'post') {
                echo '<!doctype html><meta charset="utf-8"><form method="post">'.csrf_field().'<button>确认退出</button></form>';
                return;
            }
            $this->clean_cookies();
            $this->delayed_redirect('您已退出，将在 3 秒后返回首页！', '/', 3);
        }
        //清除COOKIE
        protected function clean_cookies(){
            $this->load->library('auth');
            $this->auth->logout();
            $this->expire_cookie('user', TRUE, 'Lax');
            $this->expire_cookie('token', TRUE, 'Lax');

            unset($_COOKIE['user'], $_COOKIE['token']);
        }
        //错误消息
        protected function err_msg($msg){
            $this->json_response(array(
                "code"  =>  0,
                "msg"   =>  $msg
            ));
        }
        protected function rate_limit_response($retry_after){
            $retry_after = max(1, (int)$retry_after);
            header('Retry-After: '.$retry_after);
            $this->json_response(array(
                'code' => 429,
                'msg' => '登录尝试过于频繁，请稍后再试',
                'retry_after' => $retry_after,
                'refresh_captcha' => TRUE
            ), 429);
        }
        protected function json_response($data, $status = 200){
            $this->output->set_status_header((int)$status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($data);
        }
        protected function set_cookie_header($name, $value, $expires, $http_only = TRUE, $same_site = 'Lax'){
            $secure = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                || (bool)config_item('cookie_secure')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
            $cookie = rawurlencode($name).'='.rawurlencode($value).'; Path=/; Expires='.
                gmdate('D, d M Y H:i:s T', (int)$expires).'; Max-Age='.max(0, (int)$expires - time());
            if($secure){
                $cookie .= '; Secure';
            }
            if($http_only){
                $cookie .= '; HttpOnly';
            }
            $cookie .= '; SameSite='.$same_site;
            header('Set-Cookie: '.$cookie, FALSE);
        }
        protected function expire_cookie($name, $http_only = TRUE, $same_site = 'Lax'){
            $this->set_cookie_header($name, '', time() - 3600, $http_only, $same_site);
            unset($_COOKIE[$name]);
        }
        //重置密码
        public function resetpass(){
            //加载数据库模型
            $this->load->model('query','',TRUE);
            //查询站点信息
            $siteinfo = $this->query->site_setting('1');
            $siteinfo->title = '重置密码 - '.$siteinfo->title;
            //查询用户信息
            $userinfo = $this->query->userinfo()->values;
            $userinfo = json_decode($userinfo);
            //$userinfo = $userinfo['userinfo'];
            $siteinfo->username = $userinfo->username;
            //验证文件路径
            $pass_txt = FCPATH."data/password.txt";
            if(!file_exists($pass_txt)){
                echo "没有权限，请参考帮助文档重置密码！";
            }
            else{
                $this->load->view('user/header.php',$siteinfo);
                $this->load->view('user/resetpass.php');
                $this->load->view('user/footer.php');
            }
        }
        //手动跳转
        protected function delayed_redirect($msg, $url, $seconds = 3){
            $msg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
            $url = $url ?: '/';

            echo '<!doctype html>
            <html>
            <head>
                <meta charset="utf-8">
                <meta http-equiv="refresh" content="' . (int)$seconds . ';url=' . $url . '">
                <title>提示</title>
            </head>
            <body>
                <p>' . $msg . '</p>
                <p>' . (int)$seconds . ' 秒后自动跳转，若未跳转请 <a href="' . $url . '">点击这里</a>。</p>
            </body>
            </html>';
                exit;
        }
    }
?>
