<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Del extends CI_Controller {
    public function __construct()
    {
        parent::__construct();
        $this->load->model('query', '', true);
        $this->load->model('delete');
        $this->load->library('basic');
    }

    public function id($id = '')
    {
        $this->basic->is_login(true);
        require_post();
        if (!ctype_digit((string)$id) || (int)$id < 1) { show_error('无效的图片ID。', 400); }
        $image = $this->query->img((int)$id);
        if (!$image) { show_404(); }
        $ok = $this->delete->remove_image($image);
        $this->output->set_content_type('application/json')->set_output(json_encode([
            'code' => $ok ? 200 : 0, 'id' => (int)$id, 'msg' => $ok ? '删除成功！' : '删除失败。'
        ]));
    }

    public function token($value = '')
    {
        if (!preg_match('/^[a-f0-9]{16}$/i', $value)) { show_404(); }
        $image = $this->query->get_token($value);
        if (!$image) { show_404(); }
        if ($this->input->method() !== 'post') {
            echo '<!doctype html><meta charset="utf-8"><title>确认删除图片</title>';
            echo '<form method="post">'.csrf_field().'<button>确认永久删除这张图片</button></form>';
            return;
        }
        if (!$this->delete->remove_image($image)) { show_error('删除失败，请稍后重试。', 500); }
        echo '图片已删除！';
    }
}
