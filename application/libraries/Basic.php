<?php
    /*
        name:常用方法附属类
        author:xiaoz.me
        QQ:337003006
    */
    class Basic{
        protected $CI;

        //构造函数
        public function __construct(){
            $this->CI = & get_instance();
        }

        /*
            该函数检测用户是否已经登录，只需要一个参数
            如果参数为FALSE时，不会exit中断只执行，仅返回bool类型结果
            如果参数为TURE时，如果没有登录会exit终止执行
        */
        public function is_login($type = FALSE){
            if (is_file(FCPATH.'data/install.lock') && is_file(FCPATH.'data/imgurl.db3') && !empty($_COOKIE['imgurl_session'])) {
                $this->CI->load->model('query', '', true);
                $this->CI->load->library('auth');
                $row = $this->CI->query->userinfo();
                $info = $row ? json_decode($row->values) : null;
                if ($info && isset($info->username, $info->password)
                    && $this->CI->auth->check($info->username, $info->password)) {
                    return true;
                }
            }
            if ($type === false) {
                return false;
            }
            show_error('权限不足，请重新登录。', 401);
        }
        //查询上传数量限制，需要传入访客IP
        public function uplimit($ip){
            
        }
        //CURL下载图片
        public function dl_pic($url){
            $this->CI->load->library('remote_image');
            return $this->CI->remote_image->download($url, upload_max_bytes());
        }
        //网站数据分析
        public function analyze(){
            //图片总数
            $data['num'] = $this->CI->db->count_all("images");
            //本月总数
            $data['month']  =   $this->CI->query->count_num('month')->num;
            //今日总数
            $data['day']  =   $this->CI->query->count_num('day')->num;
            //管理员上传总数
            $data['admin']  =   $this->CI->query->count_num('admin')->num;
            //游客上传总数
            $data['visitor']  =   $this->CI->query->count_num('visitor')->num;

            return $data;
        }
        //读取站点配置文件
        public function conf($arg = ''){
            //检查配置文件是否存在，并读取对应内容
            if(is_file(FCPATH."data/json/config.js")){
                $conf_path = FCPATH."data/json/config.js";
            }
            //配置文件不存在，读取默认配置
            else{
                $conf_path = FCPATH."data/json/config.simple.js";
            }
            $conf_path = str_replace("\\","/",$conf_path);
            //echo $conf_path;
            //读取配置文件内容
            $content = json_decode(file_get_contents($conf_path));
            
            //根据传入的参数来返回不同的数据
            switch ($arg) {
                case 'alert':
                    return $content->alert;
                    break;
                case 'info':
                    return $content->info;
                    break;
                default:
                    return $content;
                    break;
            }
        }
        //获取站点主域名
        public function domain(){
            $domain = $this->CI->query->get_domain();
            return $domain;
        }
    }
?>
