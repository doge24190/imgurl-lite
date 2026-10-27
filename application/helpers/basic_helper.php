<?php
// Shared request validation and context-specific output encoding.
function h($value): string {
    return htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function js_arg($value): string {
    return h(json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE));
}
function csrf_field(): string {
    $CI =& get_instance();
    return '<input type="hidden" name="'.h($CI->security->get_csrf_token_name()).'" value="'.h($CI->security->get_csrf_hash()).'">';
}
function require_post(): void {
    $CI =& get_instance();
    if ($CI->input->method() !== 'post') {
        header('Allow: POST');
        show_error('此操作只接受 POST 请求。', 405);
    }
}
function get_ip(): string {
    $CI =& get_instance();
    return $CI->input->ip_address();
}
function get_ua(): string {
    return is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : '';
}
function is_login($user = null, $pass = null): bool {
    $CI =& get_instance();
    $CI->load->library('basic');
    return $CI->basic->is_login(true);
}
function upload_max_bytes(): int {
    $CI =& get_instance();
    $CI->load->model('query');
    $limit = json_decode($CI->query->get_limit());
    $megabytes = isset($limit->max_size) && (int)$limit->max_size > 0 ? (int)$limit->max_size : 10;
    return min($megabytes, 1024) * 1024 * 1024;
}
function image_details(string $path) {
    $info = @getimagesize($path);
    if (!is_array($info) || $info[0] <= 0 || $info[1] <= 0 || $info[0] > 20000000 / $info[1]) {
        return false;
    }
    if (!in_array($info[2], [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP, IMAGETYPE_WEBP], true)) {
        return false;
    }
    return $info;
}
function valid_raster_image(string $path): bool {
    if (!image_details($path)) { return false; }
    try {
        $image = @imagecreatefromstring(file_get_contents($path));
        $valid = $image !== false;
        unset($image);
        return $valid;
    } catch (Throwable $error) {
        return false;
    }
}
function image_operation_lock() {
    $lock = @fopen(FCPATH.'data/.upload.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX)) {
        throw new RuntimeException('Cannot lock image storage');
    }
    return $lock;
}
function safe_image_path($path) {
    if (!is_string($path) || !str_starts_with($path, '/imgs/') || str_contains($path, "\0")) {
        return false;
    }
    $root = realpath(FCPATH.'imgs');
    $full = realpath(FCPATH.ltrim($path, '/'));
    if ($root === false || $full === false || !str_starts_with($full, $root.DIRECTORY_SEPARATOR) || !is_file($full)) {
        return false;
    }
    return $full;
}
    //判断文件MIME类型
    function mime($path){
        $mime = mime_content_type($path);
        switch ( $mime )
        {
            case 'image/gif':
            case 'image/png':
            case 'image/jpeg':
            case 'image/bmp':
            case 'image/x-ms-bmp':
            case 'image/webp':
                return TRUE;
                break;		
            default:
                return FALSE;
                break;
        }
    }
    //根据MIME类型返回文件后缀
    function ext($path){
        $mime = mime_content_type($path);
        switch ( $mime )
        {
            case 'image/gif':
                return '.gif';
                break;
            case 'image/png':
                return '.png';
                break;
            case 'image/jpeg':
                return '.jpg';
                break;
            case 'image/bmp':
            case 'image/x-ms-bmp':
                return '.bmp';
                break;
            case 'image/webp':
                return '.webp';
                break;	
            default:
                return FALSE;
                break;
        }
    }
    //获取文件大小
    function file_size($path){
        //先判断文件是否存在
        if(!is_file($path)){
            $name = '0 byte';
        }
        else{
            //继续执行
            $size = filesize($path);
            //转换为KB
            $size = $size / 1024;
            $size = round($size,1);
            $name = $size.' KB';
            //转换为Mb
            if($size >= 1024){
                $size = $size / 1024;
                $size = round($size,1);
                $name = $size.' MB';
            }
            
        }
        return $name;
    }
    //缩略图函数
    function thumbnail($img){
        //返回路径
        $dir = dirname($img['path']);
        $thumbnail_name = $dir.'/'.$img['imgid'].'_thumb'.$img['ext'];

        
        //缩略图完整地址
        $fullpath = FCPATH.$thumbnail_name;
        //echo $fullpath;
        //判断缩略图是否存在
        if(is_file($fullpath)){
            return $thumbnail_name;
        }
        //$thumbnail_name = $dir.$name.'_thumb';
        //返回缩略图地址，不带文件名
        //返回原图
        else{
            return $img['path'];
        }
    }

function GetRandStr($len): string {
    return substr(bin2hex(random_bytes(max(1, (int)$len))), 0, (int)$len);
}

// Branding URLs are either trusted defaults, generated local files, or a legacy Logo URL.
function branding_url($value, string $fallback): string {
    if (!is_string($value) || $value === '') { return $fallback; }
    if (preg_match('~^/imgs/branding/[a-f0-9]{64}\.(png|jpeg|webp|gif)$~D', $value)) {
        return safe_image_path($value) ? $value : $fallback;
    }
    if ($fallback === '/static/images/logo.png'
        && (preg_match('~^/(?!/)[a-zA-Z0-9_./%-]+$~D', $value)
            || (filter_var($value, FILTER_VALIDATE_URL) && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)))) {
        return $value;
    }
    return $fallback;
}
