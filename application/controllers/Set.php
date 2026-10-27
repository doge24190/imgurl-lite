<?php
/*
    name:各种设置、更新、删除操作
*/
    defined('BASEPATH') OR exit('No direct script access allowed');
    class Set extends CI_Controller{
        //构造函数
        public function __construct(){
            parent::__construct();

            //加载模型
            $this->load->model('update','',TRUE);
            //加载类
            $this->load->library('basic');
            //验证用户是否登录
            $this->basic->is_login(TRUE);
            require_post();
        }
        //更新站点设置
        public function site(){
            $this->load->library('branding');
            $this->load->model('query');
            $fields = [];
            foreach (['title', 'keywords', 'description', 'analytics'] as $field) {
                $value = $this->input->post($field);
                if (!is_string($value) || strlen($value) > ($field === 'analytics' ? 65536 : 4096)) {
                    show_error('站点设置参数格式错误。', 400);
                    return;
                }
                $fields[$field] = $value;
            }
            $selections = [];
            foreach (Branding::FIELDS as $field) { $selections[$field] = $this->input->post($field.'_image'); }
            try {
                $this->branding->save($fields, $selections, $this->input->post('logo'));
            } catch (InvalidArgumentException $exception) {
                show_error($exception->getMessage(), 400); return;
            } catch (Throwable $exception) {
                log_message('error', 'Branding save failed: '.$exception->getMessage());
                show_error('保存失败，请检查数据库和 imgs 目录的写入权限。', 500); return;
            }
            $this->delayed_redirect('更新成功，3 秒后返回上一页！', '/setting/site', 3);
        }
        //更新上传限制
        public function uplimit(){
            $size = $this->input->post('max_size');
            $limit = $this->input->post('limit');
            if (!is_string($size) || !ctype_digit($size) || (int)$size < 1 || (int)$size > 1024
                || !is_string($limit) || !ctype_digit($limit) || (int)$limit > 1000000) {
                show_error('上传大小应为 1 至 1024 MB，上传数量应为 0 至 1000000。', 400);
                return;
            }
            $data = ['max_size' => (int)$size, 'limit' => (int)$limit];

            $data = json_encode($data);
            
            //如果更新成功
            if($this->update->site('uplimit',$data)){
                $ref = '/setting/uplimit';
                $this->delayed_redirect('更新成功，3 秒后返回上一页！', $ref, 3);
            }
            else{
                echo '更新发生错误!';
                exit;
            } 
        }
        //更新登录安全设置
        public function security(){
            $this->load->library('login_security');
            $input = array(
                'captcha_length' => $this->input->post('captcha_length', TRUE),
                'captcha_ttl' => $this->input->post('captcha_ttl', TRUE),
                'max_attempts' => $this->input->post('max_attempts', TRUE),
                'attempt_window' => $this->input->post('attempt_window', TRUE),
                'lockout' => $this->input->post('lockout', TRUE)
            );
            $validation = $this->login_security->validate_settings($input);

            if( ! $validation['valid']){
                $this->delayed_redirect(
                    '保存失败：'.implode('；', $validation['errors']),
                    '/setting/security',
                    5
                );
                return;
            }

            $data = json_encode($validation['settings']);
            if($this->update->option('login_security', $data)){
                $this->delayed_redirect('登录安全设置已更新，3 秒后返回！', '/setting/security', 3);
            }
            else{
                $this->delayed_redirect('保存失败，请检查数据库写入权限。', '/setting/security', 5);
            }
        }
        //删除单张图片,需传入图片ID，及文件路径
        public function del_img(){
            $imgid = $this->input->post('imgid');
            if (!is_string($imgid) || !preg_match('/^[a-f0-9]{16}$/i', $imgid)) {
                show_error('无效的图片ID。', 400);
                return;
            }
            $this->load->model('query');
            $this->load->model('delete');
            $image = $this->query->onepic($imgid);
            if (!$image) { show_404(); }
            $ok = $this->delete->remove_image($image);
            $this->output->set_content_type('application/json')->set_output(json_encode([
                'code' => $ok ? 200 : 0, 'msg' => $ok ? '删除成功！' : '删除失败。'
            ]));
        }
        //操作成功返回json
        protected function suc_msg($msg){
            $arr = array(
                "code"      =>  200,
                "msg"       =>  $msg
            );
            $info = json_encode($arr);
            echo $info;
        }
        //手动跳转
        protected function delayed_redirect($msg, $url, $seconds = 3){
            $msg = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
            $url = $url ?: '/admin/';

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
