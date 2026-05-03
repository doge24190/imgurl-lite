<?php
    /*
        name:首页
    */
    defined('BASEPATH') OR exit('No direct script access allowed');

    class Home extends CI_Controller{
        public function __construct(){
            parent::__construct();
            //检测是否已安装
            $lock_file = FCPATH.'data/install.lock';
            

            //如果锁文件不存在
            if(!is_file($lock_file)){
                header("location:/install/");
                exit;
            }
        }
        public function index(){
            $this->load->model('query','',TRUE);

            $siteinfo = $this->query->site_setting();
            $siteinfo = json_decode($siteinfo->values);

            $this->load->library("basic");

            // 读取上传限制
            $limitRaw = $this->query->get_limit();
            $limit = json_decode($limitRaw);

            $siteinfo->max_size = 10;
            $siteinfo->upload_limit = 0;

            if ($limit) {
                if (isset($limit->max_size) && (int)$limit->max_size > 0) {
                    $siteinfo->max_size = (int)$limit->max_size;
                }

                if (isset($limit->limit)) {
                    $siteinfo->upload_limit = (int)$limit->limit;
                }
            }

            // 动态首页通知
            if ($siteinfo->upload_limit > 0) {
                $siteinfo->info = '游客限制每日上传' . $siteinfo->upload_limit . '张，单张图片不能超过' . $siteinfo->max_size . 'MB，上传的图片将公开显示。';
            } else {
                $siteinfo->info = '游客上传次数不限，单张图片不能超过' . $siteinfo->max_size . 'MB，上传的图片将公开显示。';
            }

            $this->load->view('user/header.php', $siteinfo);
            $this->load->view('user/home.php', $siteinfo);
            $this->load->view('user/footer.php');
        }
        //首页多图上传
        public function multiple(){
            //加载数据库模型
            $this->load->model('query','',TRUE);

            $siteinfo = $this->query->site_setting();
            $siteinfo = json_decode($siteinfo->values);

            $limitRaw = $this->query->get_limit();
            $limit = json_decode($limitRaw);

            $siteinfo->max_size = 10; // 默认 10MB
            $siteinfo->upload_limit = 0;

            if ($limit) {
                if (isset($limit->max_size) && (int)$limit->max_size > 0) {
                    $siteinfo->max_size = (int)$limit->max_size;
                }
                if (isset($limit->limit)) {
                    $siteinfo->upload_limit = (int)$limit->limit;
                }
            }

            $this->load->view('user/header.php', $siteinfo);
            $this->load->view('user/multiple.php', $siteinfo);
            $this->load->view('user/footer.php');
        }
        //更新日志
        public function log(){
            //加载数据库模型
            $this->load->model('query','',TRUE);
            $siteinfo = $this->query->site_setting();
            $siteinfo = json_decode($siteinfo->values);
            //echo $siteinfo->title;
            //$data['title']  =   '图片上传';
            $siteinfo->title = "ImgURL更新日志";
            $this->load->view('user/header.php',$siteinfo);
            $this->load->view('user/log.php');
            $this->load->view('user/footer.php');
        }
        //站点地图页面
        public function sitemap(){
            //页面路径
            $page_path = FCPATH.'data/pages';
            $pages = scandir($page_path);
            
            foreach ($pages as $page) {
                if(($page === '.') OR ($page === '..')){
                    continue;
                }
                $page = str_replace('.md','',$page);
                echo "<li><a href = '/page/{$page}'>{$page}</a></li>";
            }
            
            //加载数据库模型
            $this->load->view('user/sitemap.php');
        }
    }
?>