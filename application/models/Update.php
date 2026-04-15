<?php
    class Update extends CI_Model {

        public function __construct()
        {
            parent::__construct();
            // Your own constructor code
        }
        //浏览次数+1
        public function views($imgid){
            $sql = "update img_imginfo set views=views+1 where `imgid` = '$imgid'";

            $query = $this->db->query($sql);
            if($query){
                return true;
            }
            else{
                return false;
            }
        }
        //更新站点信息
        public function site($name,$data){
            $name = strip_tags($name);
            
            $sql = "UPDATE img_options SET `values` = '$data' WHERE `name` = '{$name}'";
            $query = $this->db->query($sql);
            if($query){
                return TRUE;
            }
            else{
                return FALSE;
            }
        }
        //更新密码
        public function password($values){
            $sql = "UPDATE img_options SET `values` = '{$values}' WHERE `name` = 'userinfo'";
            $query = $this->db->query($sql);
            if($query){
                return TRUE;
            }
            else{
                return FALSE;
            }
        }
        
    }
?>