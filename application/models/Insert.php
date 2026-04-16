<?php
class Insert extends CI_Model {

    public function __construct()
    {
        parent::__construct();
    }

    public function createImageWithInfo(array $imageRow, array $imgInfoRow)
    {
        $this->db->trans_start();

        $this->db->insert('images', $imageRow);
        $id = $this->db->insert_id();

        $this->db->insert('imginfo', $imgInfoRow);

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE) {
            return false;
        }

        return $id;
    }

    // 旧方法先保留，等 Upload.php 全部切过去再删
    public function images($datas){
        if($this->db->insert('images', $datas)){
            return $this->db->insert_id();
        }
        return false;
    }

    public function imginfo($datas){
        if($this->db->insert('imginfo', $datas)){
            return $this->db->insert_id();
        }
        return false;
    }
}