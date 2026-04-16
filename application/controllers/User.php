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
        //验证用户名、密码是否正确
        public function verify(){
            //获取用户输入的信息
            $user = $this->input->post('user',TRUE);
            $pass = $this->input->post('password',TRUE);
            $pass = md5($pass.'imgurl');

            //加载模型
            $this->load->model("query",'',TRUE);
            $info = $this->query->userinfo()->values;
            
            $info = json_decode($info);
            
            //获取真正的用户名
            $username = $info->username;
            
            $password = $info->password;

            if(($user == $username) && ($pass == $password)){
                $token = token($username,$password);
                //生成COOKIE
                setcookie("user", $username, time()+ 7 * 24 * 60 * 60,"/");
                setcookie("token", $token, time()+ 7 * 24 * 60 * 60,"/");
                //跳转到后台
                $data = array(
                    "code"  =>  200,
                    "msg"   =>  '登录成功！'
                );
                $data = json_encode($data);
                echo $data;
                exit;
            }
            else{
                $this->err_msg('用户名或密码不正确');
                //清除cookie
                $this->clean_cookies();
                exit;
            }
        }
        public function logout(){
            $this->clean_cookies();
            $this->delayed_redirect('您已退出，将在 3 秒后返回首页！', '/', 3);
        }
        //清除COOKIE
        protected function clean_cookies(){
            setcookie("user", '', time() - 3600, "/");
            setcookie("token", '', time() - 3600, "/");

            unset($_COOKIE['user'], $_COOKIE['token']);
        }
        //错误消息
        protected function err_msg($msg){
            $data = array(
                "code"  =>  0,
                "msg"   =>  $msg
            );
            $data = json_encode($data);
            echo $data;
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