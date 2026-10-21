<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Manage extends CI_Controller {
    public function __construct()
    {
        parent::__construct();
        $this->load->library('basic');
        $this->basic->is_login(true);
        $this->load->model('query', '', true);
    }

    public function images($type = 'all', $page = 0)
    {
        if (!in_array($type, ['all', 'admin', 'visitor', 'dubious', 'id', 'imgid', 'ip'], true)
            || !ctype_digit((string)$page) || (int)$page > 1000000) { show_404(); }
        $value = $this->input->get('value');
        $date = $this->input->get('date');
        if (($value !== null && !is_string($value)) || ($date !== null && !is_string($date))) {
            show_error('查询参数格式错误。', 400);
        }
        $this->db->from('images AS a')->join('imginfo AS b', 'a.imgid=b.imgid');
        if ($type === 'admin' || $type === 'visitor') { $this->db->where('a.user', $type); }
        if ($type === 'dubious') { $this->db->where('a.level', 'adult'); }
        if ($type === 'id') {
            if (!$value || !ctype_digit($value) || (int)$value < 1) { show_error('图片 ID 无效。', 400); }
            $this->db->where('a.id', (int)$value);
        }
        if ($type === 'imgid') {
            if (!is_string($value) || !preg_match('/^[a-f0-9]{16}$/i', $value)) { show_error('ImgID 无效。', 400); }
            $this->db->where('a.imgid', $value);
        }
        if ($type === 'ip') {
            if (!filter_var($value, FILTER_VALIDATE_IP)) { show_error('IP 地址无效。', 400); }
            $this->db->where('a.ip', $value);
        }
        if ($date !== null && $date !== '') {
            $dates = explode('|', $date);
            if (count($dates) !== 2) { show_error('日期范围无效。', 400); }
            foreach ($dates as $part) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $part);
                if (!$parsed || $parsed->format('Y-m-d') !== $part) { show_error('日期范围无效。', 400); }
            }
            if ($dates[0] > $dates[1]) { show_error('日期顺序无效。', 400); }
            $this->db->where('date(a.date) >=', $dates[0])->where('date(a.date) <=', $dates[1]);
        }
        $num = $this->db->count_all_results('', false);
        $data['imgs'] = $this->db->select('a.id,a.imgid,a.path,a.thumb_path,a.date,a.compression,a.level,b.mime,b.width,b.height,b.views,b.ext,b.client_name')
            ->order_by('a.id', 'DESC')->limit(16, (int)$page)->get()->result_array();
        $data['admin_title'] = '图片管理';
        $data['domain'] = $this->query->domain('localhost');
        $this->load->library('pagination');
        $this->pagination->initialize([
            'base_url' => '/manage/images/'.$type.'/', 'total_rows' => $num, 'per_page' => 16,
            'reuse_query_string' => true, 'first_link' => '首页', 'last_link' => '尾页',
            'next_link' => '下一页', 'prev_link' => '上一页', 'attributes' => ['class' => 'paging']
        ]);
        $data['page'] = $this->pagination->create_links();
        $this->load->view('admin/header', $data);
        $this->load->view('admin/left');
        $this->load->view('admin/images', $data);
        $this->load->view('admin/footer');
    }

    public function imginfo($imgid = '')
    {
        if (!preg_match('/^[a-f0-9]{16}$/i', $imgid)) { show_404(); }
        $row = $this->query->picinfo($imgid);
        if (!$row) { show_404(); }
        $row->size = file_size(FCPATH.ltrim($row->path, '/'));
        $this->load->view('admin/imginfo', $row);
    }
}
