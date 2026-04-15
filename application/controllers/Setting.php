<?php
    defined('BASEPATH') OR exit('No direct script access allowed');
    class Setting extends CI_Controller{
        //构造函数
        public function __construct(){
            parent::__construct();

            //加载模型
            $this->load->model('query','',TRUE);
            //加载辅助函数
            $this->load->helper('basic');
            $info = $this->query->userinfo()->values;
            $info = json_decode($info);

            //验证用户是否登录
            is_login($info->username,$info->password);
        }
        //站点设置
        public function site(){  
           $siteinfo = $this->query->site_setting();
           $siteinfo = json_decode($siteinfo->values);
           
            //页面标题
            $siteinfo->admin_title  =   '站点设置';
            
            //加载视图
            $this->load->view('admin/header',$siteinfo);
            $this->load->view('admin/left');
            $this->load->view('admin/site');
            $this->load->view('admin/footer');
        }
        //上传限制
        public function uplimit(){

            $siteinfo = $this->query->option('uplimit');
            
            $siteinfo = json_decode($siteinfo->values);
            if($siteinfo->limit != 0){
                $switch = 'checked';
            }
            else{
                $switch = '';
            }
            //页面标题
            $siteinfo->admin_title  =   '上传限制';
            $siteinfo->switch = $switch;
            //var_dump($siteinfo);
            //加载视图
            $this->load->view('admin/header',$siteinfo);
            $this->load->view('admin/left');
            $this->load->view('admin/uplimit');
            $this->load->view('admin/footer');
        }
    }
?>