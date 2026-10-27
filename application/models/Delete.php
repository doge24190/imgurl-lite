<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Delete extends CI_Model {
    public function remove_image($image): bool
    {
        if (!$image || !is_string($image->imgid ?? null)) { return false; }
        $expectedToken = is_string($image->token ?? null) ? $image->token : null;
        return $this->remove_by_id($image->imgid, $expectedToken) === true;
    }

    /** null = absent, false = storage failure. Read paths only after taking the shared lock. */
    public function remove_by_id(string $imgid, ?string $expectedToken = null): ?bool
    {
        if (!preg_match('/^[a-f0-9]{16}$/iD', $imgid)) { return false; }
        $staged = [];
        $temp = FCPATH.'data/temp';
        $debug = $this->db->db_debug;
        $this->db->db_debug = false;
        $transaction = false;
        try {
            $lock = image_operation_lock();
            $query = $this->db->where('imgid', strtolower($imgid))->get('images');
            if (!$query) { throw new RuntimeException('Cannot read image'); }
            $image = $query->row();
            if (!$image) { return null; }
            // A public delete link authorized before the lock cannot target a later re-upload.
            if ($expectedToken !== null && (!is_string($image->token) || !hash_equals($image->token, $expectedToken))) { return null; }
            if (!is_dir($temp) && !@mkdir($temp, 0700, true) && !is_dir($temp)) { throw new RuntimeException('Cannot create deletion staging directory'); }
            foreach (array_unique([$image->path, $image->thumb_path]) as $path) {
                if (!is_string($path) || !str_starts_with($path, '/imgs/') || str_contains($path, "\0")
                    || str_contains($path, '\\') || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
                    throw new RuntimeException('Invalid image path');
                }
                $full = safe_image_path($path);
                if ($full === false) {
                    if (file_exists(FCPATH.ltrim($path, '/')) || is_link(FCPATH.ltrim($path, '/'))) {
                        throw new RuntimeException('Image path escapes upload directory');
                    }
                    continue;
                }
                if (isset($staged[$full])) { continue; }
                $target = @tempnam($temp, 'delete-');
                if ($target === false) { throw new RuntimeException('Cannot stage deletion'); }
                if (!@rename($full, $target)) { @unlink($target); throw new RuntimeException('Cannot stage deletion'); }
                $staged[$full] = $target;
            }
            if (!$this->db->trans_begin()) { throw new RuntimeException('Cannot begin deletion'); }
            $transaction = true;
            $this->db->where('imgid', $image->imgid)->delete('imginfo');
            $this->db->where('imgid', $image->imgid)->delete('images');
            if (!$this->db->trans_status() || !$this->db->trans_commit()) { throw new RuntimeException('Database deletion failed'); }
            $transaction = false;
            foreach ($staged as $target) { @unlink($target); }
            return true;
        } catch (Throwable $error) {
            if ($transaction) { $this->db->trans_rollback(); }
            foreach ($staged as $full => $target) { @rename($target, $full); }
            log_message('error', 'Image deletion failed: '.$error->getMessage());
            return false;
        } finally {
            $this->db->db_debug = $debug;
            if (isset($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
