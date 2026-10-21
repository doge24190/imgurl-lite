<?php
class Insert extends CI_Model {

    public function __construct()
    {
        parent::__construct();
    }

    public function createImageWithInfo(array $imageRow, array $imgInfoRow)
    {
        $debug = $this->db->db_debug;
        $this->db->db_debug = false;
        try {
            if (!$this->db->trans_begin() || !$this->db->insert('images', $imageRow)) {
                throw new RuntimeException('Cannot insert image');
            }
            $id = $this->db->insert_id();
            if (!$this->db->insert('imginfo', $imgInfoRow) || !$this->db->trans_status() || !$this->db->trans_commit()) {
                throw new RuntimeException('Cannot insert image metadata');
            }
            return $id;
        } catch (Throwable $error) {
            $this->db->trans_rollback();
            log_message('error', 'Image insert failed: '.$error->getMessage());
            return false;
        } finally {
            $this->db->db_debug = $debug;
        }
    }

    // 旧方法
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