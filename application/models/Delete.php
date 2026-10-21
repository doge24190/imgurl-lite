<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Delete extends CI_Model {
    /** Stage files so a database failure can restore them. Paths only come from a database row. */
    public function remove_image($image): bool
    {
        if (!$image || !preg_match('/^[a-f0-9]{16}$/i', $image->imgid)) { return false; }
        $staged = [];
        $temp = FCPATH.'data/temp';
        if (!is_dir($temp) && !mkdir($temp, 0700, true) && !is_dir($temp)) { return false; }
        $debug = $this->db->db_debug;
        $this->db->db_debug = false;
        try {
            $lock = image_operation_lock();
            foreach (array_unique([$image->path, $image->thumb_path]) as $path) {
                if (!is_string($path) || !str_starts_with($path, '/imgs/')) { throw new RuntimeException('Invalid image path'); }
                $full = safe_image_path($path);
                if ($full === false) {
                    if (file_exists(FCPATH.ltrim($path, '/')) || is_link(FCPATH.ltrim($path, '/'))) {
                        throw new RuntimeException('Image path escapes upload directory');
                    }
                    continue;
                }
                if (isset($staged[$full])) { continue; }
                $target = tempnam($temp, 'delete-');
                if ($target === false) { throw new RuntimeException('Cannot stage deletion'); }
                if (!rename($full, $target)) { @unlink($target); throw new RuntimeException('Cannot stage deletion'); }
                $staged[$full] = $target;
            }
            $this->db->trans_begin();
            $this->db->where('imgid', $image->imgid)->delete('imginfo');
            $this->db->where('imgid', $image->imgid)->delete('images');
            if (!$this->db->trans_status() || !$this->db->trans_commit()) { throw new RuntimeException('Database deletion failed'); }
        } catch (Throwable $error) {
            $this->db->trans_rollback();
            foreach ($staged as $full => $target) { @rename($target, $full); }
            log_message('error', 'Image deletion failed: '.$error->getMessage());
            return false;
        } finally {
            $this->db->db_debug = $debug;
        }
        foreach ($staged as $target) { @unlink($target); }
        return true;
    }
}
