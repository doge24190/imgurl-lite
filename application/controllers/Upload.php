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
                mkdir($this->upload_path,0777,TRUE);
            }
            //保证 $this->temp 存在
            if (!is_dir($this->temp)) {
                mkdir($this->temp, 0777, TRUE);
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
                    echo $url;
                    break;
                case 'html':
                    echo "<img src = '$url' />";
                    break;
                case 'markdown':
                    echo "![]($url)";
                    break;
                case 'bbcode':
                    echo "[img]".$url."[/img]";
                    break;
                default:
                    $this->succeed_msg($info);
                    break;
            }
        }
        //上传成功返回json
        protected function succeed_msg($data){
            $info = json_encode($data);
            echo $info;
            exit;
        }
        //上传失败返回json
        protected function error_msg($msg){
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
        $url = trim((string)$this->input->post('url', TRUE));
        $this->load->library('basic');
        $this->basic->is_login(TRUE);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error_msg('不是有效的URL地址！');
        }

        $picData = $this->basic->dl_pic($url);
        if (!$picData) {
            $this->error_msg('远程图片下载失败！');
        }

        $tmpName = $this->temp . md5($url . microtime(true));
        file_put_contents($tmpName, $picData);

        $clientName = basename(parse_url($url, PHP_URL_PATH));
        if ($clientName === '' || $clientName === false) {
            $clientName = 'remote-file';
        }

        $this->finalizeUpload($tmpName, $clientName);
    }
        //粘贴上传
        public function parse()
    {
        $date = date('Y-m-d H:i:s', time());
        $tmpName = md5(get_ip() . get_ua() . $date);
        $tmpFile = $this->temp . $tmpName;

        $content = isset($_POST['content']) ? $_POST['content'] : '';

        if ($content === '') {
            $this->error_msg('粘贴内容为空！');
        }

        if (strpos($content, 'base64,') !== false) {
            $content = substr($content, strpos($content, 'base64,') + 7);
        }

        $picfile = base64_decode($content, true);

        if ($picfile === false) {
            $this->error_msg('粘贴内容解析失败！');
        }

        file_put_contents($tmpFile, $picfile);

        $this->finalizeUpload($tmpFile, $tmpName);
    }
        /*
        1. 该方法生成图片的唯一删除token
        2. 参数为一个数组，内容为IP/UA/DATE
        3. ip + ua + date + 4位随机数，进行md5加密得到token
        */
        protected function token($arr){
            $ip = $arr['ip'];
            $ua = $arr['ua'];
            $date = $arr['date'];
            //生成4位随机数
            $str =  GetRandStr(4);
            $token = $ip.$ua.$date.$str;
            $token = md5($token);
            //token只需要16位
            $token = substr($token, 8, 16);
            return $token;
        }
        /*
        1. 先拿到一个临时文件，就都走这一条收口
        */
        protected function finalizeUpload(string $tmpPath, string $clientName, string $type = 'json')
{
    if (!is_file($tmpPath)) {
        $this->error_msg('上传文件不存在！');
    }

    if (!mime($tmpPath)) {
        @unlink($tmpPath);
        $this->error_msg('不允许的文件类型！');
    }

    $imgid = substr(md5_file($tmpPath), 8, 16);

    // 查重：如果已经存在，直接返回已有信息
    if ($imginfo = $this->query->repeat($imgid)) {
        $ext = pathinfo($imginfo->path, PATHINFO_EXTENSION);
        $relativePath = $imginfo->path;
        $thumbnailPath = $imginfo->thumb_path;
        $domain = $this->query->domain('localhost');

        $payload = array(
            'code' => 200,
            'id' => $imginfo->id,
            'imgid' => $imgid,
            'relative_path' => $relativePath,
            'url' => $domain . $relativePath,
            'thumbnail_url' => $domain . $thumbnailPath
        );

        @unlink($tmpPath);
        $this->re_data($type, $payload);
    }

    $ext = ext($tmpPath);
    if ($ext === FALSE) {
        @unlink($tmpPath);
        $this->error_msg('无法识别文件后缀！');
    }

    $fileName = $imgid . $ext;
    $fullPath = $this->upload_path . $fileName;
    $relativePath = $this->relative_path . $fileName;

    if (!@rename($tmpPath, $fullPath)) {
        if (!@copy($tmpPath, $fullPath)) {
            @unlink($tmpPath);
            $this->error_msg('保存文件失败！');
        }
        @unlink($tmpPath);
    }

    $thumbnailPath = $this->relative_path . $imgid . '_thumb' . $ext;

    $this->load->library('image');
    if (!$this->image->thumbnail($fullPath, 290, 175)) {
        $thumbnailPath = $relativePath;
    }

    $imgInfo = @getimagesize($fullPath);
    $width = is_array($imgInfo) ? (int)$imgInfo[0] : 0;
    $height = is_array($imgInfo) ? (int)$imgInfo[1] : 0;
    $mimeType = is_array($imgInfo) && isset($imgInfo['mime']) ? $imgInfo['mime'] : mime_content_type($fullPath);

    $tokenData = array(
        'ip' => get_ip(),
        'ua' => get_ua(),
        'date' => $this->date
    );
    $token = $this->token($tokenData);

    $imageRow = array(
        'imgid'      => $imgid,
        'path'       => $relativePath,
        'thumb_path' => $thumbnailPath,
        'storage'    => 'localhost',
        'ip'         => get_ip(),
        'ua'         => get_ua(),
        'date'       => $this->date,
        'user'       => $this->user,
        'level'      => 'unknown',
        'token'      => $token
    );

    $imgInfoRow = array(
        'imgid'       => $imgid,
        'mime'        => $mimeType,
        'width'       => $width,
        'height'      => $height,
        'ext'         => $ext,
        'client_name' => $clientName
    );

    $this->load->model('insert', '', TRUE);
    $id = $this->insert->createImageWithInfo($imageRow, $imgInfoRow);

    if ($id === false) {
        $this->error_msg('数据库写入失败！');
    }

    $domain = $this->query->domain('localhost');
    $delete = $this->main_domain . '/delete/' . $token;

    $payload = array(
        'code' => 200,
        'id' => $id,
        'imgid' => $imgid,
        'relative_path' => $relativePath,
        'url' => $domain . $relativePath,
        'thumbnail_url' => $domain . $thumbnailPath,
        'width' => $width,
        'height' => $height,
        'delete' => $delete
    );

    $this->re_data($type, $payload);
}
    }
?>