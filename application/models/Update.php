<?php
class Update extends CI_Model {

    public function __construct()
    {
        parent::__construct();
    }

    // 浏览次数 +1
    public function views($imgid){
        $this->db->set('views', 'views+1', FALSE);
        $this->db->where('imgid', $imgid);
        return $this->db->update('imginfo');
    }

    // 更新站点信息 / 上传限制
    public function site($name, $data){
        $this->db->where('name', $name);
        return $this->db->update('options', array(
            'values' => $data
        ));
    }

    // 更新密码
    public function password($values){
        $this->db->where('name', 'userinfo');
        return $this->db->update('options', array(
            'values' => $values
        ));
    }
}