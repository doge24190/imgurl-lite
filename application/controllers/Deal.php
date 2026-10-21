<?php
/*
    name:图片处理控制器，图片鉴黄、图片压缩...
*/
    defined('BASEPATH') OR exit('No direct script access allowed');
    class Deal extends CI_Controller{
        //构造函数
        public function __construct(){
            parent::__construct();
            //设置超时时间为5分钟
            set_time_limit(300);
            //加载数据库类
            $this->load->database();
        }    
        //返回错误码
        protected function err_msg($msg){
            $arr = array(
                "code"      =>  0,
                "msg"       =>  $msg
            );
            $arr = json_encode($arr);
            echo $arr;
            exit;
        }
        //成功，返回正确的状态码
        protected function suc_msg($msg){
            $arr = array(
                "code"      =>  200,
                "msg"       =>  $msg
            );
            $arr = json_encode($arr);
            echo $arr;
        }
        //重置密码
        public function resetpass(){
            require_post();
            $password1 = $this->input->post('password1');
            $password2 = $this->input->post('password2');
            if (!is_string($password1) || !is_string($password2) || strlen($password2) < 8 || strlen($password2) > 72) {
                $this->err_msg('密码长度需要为 8 至 72 字节。');
            }
            //验证文件路径
            $pass_txt = FCPATH."data/password.txt";
            if(!file_exists($pass_txt)){
                $this->err_msg("没有权限，请参考帮助文档操作！");
            }
            else{
                if($password1 != $password2){
                    $this->err_msg("两次密码不一致！");
                }
                else if(str_contains($password2, "\0")){
                    $this->err_msg("密码格式有误！");
                    exit;
                }
                else{
                    //进行密码重置
                    $password = password_hash($password2, PASSWORD_DEFAULT);
                    
                    //加载数据库模型
                    $this->load->model('query','',TRUE);
                    $this->load->model('update','',TRUE);
                    //查询用户信息
                    $userinfo = $this->query->userinfo()->values;
                    $userinfo = json_decode($userinfo);
                    $userinfo->password = $password;
                    $values = json_encode($userinfo);
                    //更新数据库
                    if($this->update->password($values)){
                        //删除验证文件
                        unlink($pass_txt);
                        $this->suc_msg("密码已重置，请重新登录。");
                    }
                    else{
                        $this->err_msg("更新失败，未知错误！");
                    }
                }
            }
        }
    }
?>