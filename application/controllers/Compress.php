<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Compress extends CI_Controller {
    public function __construct()
    {
        parent::__construct();
        $this->load->library('basic');
        $this->basic->is_login(true);
        require_post();
    }

    public function img($id = '')
    {
        $this->load->model('query', '', true);
        $image = ctype_digit((string)$id) ? $this->query->img((int)$id) : null;
        if (!$image) { show_404(); }
        $key = $this->api_key();
        if (!$key) { show_error('图片压缩未配置。', 503); }
        $path = safe_image_path($image->path);
        if (!$path || $image->compression || !image_details($path)) { show_error('图片不存在、格式不支持或已经压缩。', 409); }
        if (filesize($path) > upload_max_bytes()) { show_error('图片超过压缩大小限制。', 413); }
        $originalHash = hash_file('sha256', $path);
        $originalMode = fileperms($path) & 0777;
        $temp = false;
        $backup = false;
        $replaced = false;
        $debug = $this->db->db_debug;
        try {
            $content = $this->compressed_content($path, $key);
            $temp = tempnam(dirname($path), '.compress-');
            if ($temp === false || strlen($content) > upload_max_bytes() || file_put_contents($temp, $content) === false
                || !valid_raster_image($temp) || image_details($temp)[2] !== image_details($path)[2]) {
                throw new RuntimeException('Invalid compressed image');
            }
            $lock = image_operation_lock();
            $current = $this->query->img((int)$id);
            if (!$current || $current->compression || safe_image_path($current->path) !== $path
                || hash_file('sha256', $path) !== $originalHash) { throw new RuntimeException('Image changed during compression'); }
            $directory = FCPATH.'data/temp';
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new RuntimeException('Cannot create backup directory'); }
            $backup = tempnam($directory, 'compress-backup-');
            if ($backup === false || !copy($path, $backup)) { throw new RuntimeException('Cannot back up image'); }
            chmod($temp, $originalMode);
            chmod($backup, $originalMode);
            $this->db->db_debug = false;
            if (!$this->db->trans_begin() || !rename($temp, $path)) { throw new RuntimeException('Cannot replace image'); }
            $replaced = true;
            $this->load->model('update');
            if (!$this->update->compress($id) || !$this->db->trans_status() || !$this->db->trans_commit()) { throw new RuntimeException('Cannot save compression status'); }
            $this->output->set_content_type('application/json')->set_output(json_encode(['code' => 200, 'msg' => '压缩成功。']));
        } catch (Throwable $error) {
            $this->db->trans_rollback();
            $restored = !$replaced || ($backup && rename($backup, $path));
            if (!$restored) { log_message('error', 'Original image recovery required from '.$backup); }
            log_message('error', 'Compression failed: '.$error->getMessage());
            // show_error exits; perform cleanup before calling it as finally cannot run after exit.
            if ($temp && is_file($temp)) { @unlink($temp); }
            if ($restored && $backup && is_file($backup)) { @unlink($backup); }
            show_error($restored ? '压缩失败，原图已保留，请检查配置或稍后重试。' : '压缩失败，原图备份已保留，请联系管理员恢复。', 502);
        } finally {
            $this->db->db_debug = $debug;
            if ($temp && is_file($temp)) { @unlink($temp); }
            if ($backup && is_file($backup)) { @unlink($backup); }
        }
    }

    protected function api_key(): string
    {
        return getenv('IMGURL_TINIFY_KEY') ?: '';
    }

    /** Network boundary is separate from the atomic file/database update for offline testing. */
    protected function compressed_content(string $path, string $key): string
    {
        $curl = curl_init('https://api.tinify.com/shrink');
        $response = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => file_get_contents($path),
            CURLOPT_USERPWD => 'api:'.$key,
            (defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS) => defined('CURLOPT_PROTOCOLS_STR') ? 'HTTPS' : CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 65536) { return 0; }
                $response .= $chunk;
                return strlen($chunk);
            }
        ]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $data = json_decode($response, true);
        $url = $data['output']['url'] ?? null;
        if ($ok === false || $status !== 201 || !is_string($url)
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'api.tinify.com') {
            throw new RuntimeException('Compression service failed');
        }
        $this->load->library('remote_image');
        return $this->remote_image->download($url, upload_max_bytes());
    }
}
