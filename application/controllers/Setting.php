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
        // Admin-only, bounded image-library picker. Paths come exclusively from database rows.
        public function images(){
            $field = $this->input->get('field');
            $page = $this->input->get('page') ?? '1';
            $search = $this->input->get('search') ?? '';
            $this->load->library('branding');
            if (!is_string($field) || !in_array($field, Branding::FIELDS, true)
                || !is_string($page) || !ctype_digit($page) || (int)$page < 1 || (int)$page > 100000
                || !is_string($search) || strlen($search) > 200) {
                show_error('图片筛选参数错误。', 400); return;
            }
            $this->db->select('a.imgid,a.path,a.thumb_path,a.level,b.client_name,b.width,b.height')
                ->from('images AS a')->join('imginfo AS b', 'a.imgid=b.imgid')
                ->where('a.level !=', 'adult');
            if ($search !== '') {
                $this->db->group_start()->like('b.client_name', $search)->or_like('a.imgid', $search)->group_end();
            }
            $rows = $this->db->order_by('a.id', 'DESC')->limit(25, ((int)$page - 1) * 24)->get()->result();
            $more = count($rows) > 24;
            $items = [];
            foreach (array_slice($rows, 0, 24) as $image) {
                $item = ['imgid' => $image->imgid, 'name' => $image->client_name,
                    'width' => (int)$image->width, 'height' => (int)$image->height,
                    'url' => '', 'eligible' => false, 'reason' => ''];
                if (safe_image_path($image->path)) {
                    $item['url'] = safe_image_path($image->thumb_path) ? $image->thumb_path : $image->path;
                }
                try {
                    $info = $this->branding->inspect($image, $field);
                    $item['width'] = $info['width'];
                    $item['height'] = $info['height'];
                    $item['eligible'] = true;
                } catch (InvalidArgumentException $exception) {
                    $item['reason'] = $exception->getMessage();
                }
                $items[] = $item;
            }
            $this->output->set_header('Cache-Control: no-store')->set_content_type('application/json')
                ->set_output(json_encode(['items' => $items, 'page' => (int)$page, 'more' => $more]));
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
        //登录安全设置
        public function security(){
            $this->load->library('login_security');
            $data = array_merge(
                $this->login_security->get_settings(),
                $this->login_security->get_status()
            );
            $siteinfo = (object)$data;
            $siteinfo->admin_title = '登录安全';

            $this->load->view('admin/header',$siteinfo);
            $this->load->view('admin/left');
            $this->load->view('admin/security');
            $this->load->view('admin/footer');
        }
    }
?>
