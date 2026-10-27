<?php
    /* 
    name:ImgURL上传控制器
    author:xiaoz.me
    QQ:337003006
    */

    //允许跨域请求
    header("Access-Control-Allow-Origin: *");
    defined('BASEPATH') OR exit('No direct script access allowed');

    class Upload extends CI_Controller{
        //声明上传文件路径
        public $upload_path;
        //声明文件相对路径
        public $relative_path;
        public $image_lib;
        //当前时间
        public $date;
        //设置临时目录
        public $temp;
        //用户是否已经登录的属性
        protected $user;
        //获取站点主域名
        protected $main_domain;
        //构造函数
        public function __construct()
        {
            parent::__construct();
            //设置上传文件路径
            $this->upload_path = FCPATH.'imgs/'.date('Y',time()).'/'.date('m',time()).'/';
            $this->upload_path = str_replace('\\','/',$this->upload_path);
            $this->relative_path = "/imgs/".date('Y',time()).'/'.date('m',time()).'/';
            $this->relative_path = str_replace('\\','/',$this->relative_path);
            $this->temp = str_replace('\\', '/', FCPATH . 'data/temp/');
            //如果文件夹不存在，则创建文件夹
            if(!is_dir($this->upload_path)){
                //递归模式创建目录
                mkdir($this->upload_path,0755,TRUE);
            }
            //保证 $this->temp 存在
            if (!is_dir($this->temp)) {
                mkdir($this->temp, 0700, TRUE);
            }
            $this->date = date('Y-m-d H:i',time());
            //加载辅助函数
            $this->load->helper('basic');
            $ip = get_ip();
            //加载基本类
            $this->load->library('basic');
            //加载查询模型
            $this->load->model('query','',TRUE);
            $this->main_domain = $this->basic->domain();
            
            //用户已经登录
            if($this->basic->is_login(FALSE)){
                $this->user = 'admin';
            }
            else{
                $this->user = 'visitor';
                //限制上传数量
                if($this->query->uplimit($ip) === FALSE){
                    $this->error_msg("上传达到上限！");
                }
            }
        }
        //通用上传设置
        protected function config($upload_path = ''){
            $limitRaw = $this->query->get_limit();
            $limit = json_decode($limitRaw);

            // 默认 10MB
            $max_size = 10 * 1024;

            if ($limit && isset($limit->max_size)) {
                $tmp = (int)$limit->max_size;
                if ($tmp > 0) {
                    $max_size = $tmp * 1024;
                }
            }

            if($upload_path == ''){
                $upload_path = $this->upload_path;
            }

            $config['upload_path'] = $upload_path;
            $config['allowed_types'] = 'gif|jpg|jpeg|png|bmp|webp';
            $config['max_size'] = $max_size;
            $config['file_ext_tolower'] = TRUE;
            $config['overwrite'] = TRUE;
            $config['encrypt_name'] = TRUE;

            return $config;
        }
        public function localhost($type = 'json')
        {
        require_post();
        $config = $this->config($this->temp);
        $this->load->library('upload', $config);

        if (!$this->upload->do_upload('file')) {
            $msg = strip_tags($this->upload->display_errors());
            $this->error_msg($msg);
        }

        $data = $this->upload->data();
        $tmpPath = $data['full_path'];
        $clientName = $data['client_name'];

        $this->finalizeUpload($tmpPath, $clientName, $type);
        }
        //根据不同的类型返回不同的数据
        protected function re_data($type,$info){
            $url = $info['url'];
            switch ($type) {
                case 'json':
                    $this->succeed_msg($info);
                    break;
                case 'url':
                    header('Content-Type: text/plain; charset=utf-8');
                    echo $url;
                    break;
                case 'html':
                    header('Content-Type: text/html; charset=utf-8');
                    echo '<img src="'.h($url).'" />';
                    break;
                case 'markdown':
                    header('Content-Type: text/plain; charset=utf-8');
                    echo "![]($url)";
                    break;
                case 'bbcode':
                    header('Content-Type: text/plain; charset=utf-8');
                    echo "[img]".$url."[/img]";
                    break;
                default:
                    $this->succeed_msg($info);
                    break;
            }
        }
        //上传成功返回json
        protected function succeed_msg($data){
            header('Content-Type: application/json; charset=utf-8');
            $info = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
            echo $info;
            exit;
        }
        //上传失败返回json
        protected function error_msg($msg){
            header('Content-Type: application/json; charset=utf-8');
            $data = array(
                "code"  =>  0,
                "msg"   =>  $msg
            );

            $data = json_encode($data);
            echo $data;
            exit;
        }
        //URL上传
        public function url()
    {
        require_post();
        $url = $this->input->post('url');
        if (!is_string($url) || strlen($url) > 4096) { $this->error_msg('URL 参数不正确。'); }
        $url = trim($url);
        $this->load->library('basic');
        $this->basic->is_login(TRUE);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error_msg('不是有效的URL地址！');
        }

        try {
            $picData = $this->basic->dl_pic($url);
        } catch (Throwable $error) {
            $this->error_msg($error->getMessage());
        }
        if ($picData === false || $picData === '') {
            $this->error_msg('远程图片下载失败！');
        }

        $tmpName = tempnam($this->temp, 'remote-');
        if ($tmpName === false || file_put_contents($tmpName, $picData) === false) {
            $this->error_msg('临时文件写入失败！');
        }

        $urlPath = parse_url($url, PHP_URL_PATH);
        if (!is_string($urlPath) || $urlPath === '') {
            $clientName = 'remote-file';
        } else {
            $clientName = basename($urlPath);
            if ($clientName === '' || $clientName === '.' || $clientName === '/') {
                $clientName = 'remote-file';
            }
        }

        $this->finalizeUpload($tmpName, $clientName);
    }
        //粘贴上传
        public function parse()
    {
        require_post();
        $content = $this->input->post('content');
        $maxBytes = upload_max_bytes();
        if (!is_string($content) || $content === '' || strlen($content) > 4 * (int)ceil($maxBytes / 3) + 128) {
            $this->error_msg('粘贴内容为空、格式错误或超过大小限制。');
        }
        if (str_starts_with($content, 'data:')) {
            if (!preg_match('#^data:image/(?:png|jpeg|gif|webp|bmp);base64,#i', $content, $match)) {
                $this->error_msg('不支持的粘贴图片类型。');
            }
            $content = substr($content, strlen($match[0]));
        }
        $picfile = base64_decode($content, true);
        if ($picfile === false || strlen($picfile) > $maxBytes) {
            $this->error_msg('粘贴内容解析失败或超过大小限制。');
        }
        $tmpFile = tempnam($this->temp, 'paste-');
        if ($tmpFile === false || file_put_contents($tmpFile, $picfile) === false) {
            $this->error_msg('临时文件写入失败。');
        }
        $this->finalizeUpload($tmpFile, 'pasted-image');
    }
        // Shared with the authenticated API; keep legacy response formats and public delete links.
        protected function finalizeUpload(string $tmpPath, string $clientName, string $type = 'json')
        {
            $this->load->library('image_storage');
            try {
                $result = $this->image_storage->store($tmpPath, $clientName, $this->user);
            } catch (Image_storage_error $error) {
                $this->error_msg($error->getMessage());
            } catch (Throwable $error) {
                log_message('error', 'Image upload failed ('.get_class($error).').');
                $this->error_msg('暂时无法保存图片，请稍后重试。');
            }
            $this->re_data($type, $result['payload']);
        }
    }
?>
