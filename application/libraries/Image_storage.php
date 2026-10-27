<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Image_storage_error extends RuntimeException {
    public function __construct(int $status, public string $error_code, string $message)
    {
        parent::__construct($message, $status);
    }
}

/** One validation, deduplication and transactional publication path for all uploads. */
class Image_storage {
    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->helper('basic');
        $this->CI->load->model('query', '', true);
        $this->CI->load->model('insert');
        $this->CI->load->library('image');
    }

    /** Owns the temporary file, including cleanup on every failure or duplicate. */
    public function store(string $temporary, string $clientName, string $user): array
    {
        $published = [];
        $committed = false;
        try {
            if (!in_array($user, ['admin', 'visitor'], true)) { throw new RuntimeException('Invalid upload role'); }
            if (!is_file($temporary)) { throw new Image_storage_error(400, 'missing_file', '上传文件不存在。'); }
            $bytes = @filesize($temporary);
            if ($bytes === false) { throw new RuntimeException('Cannot read upload'); }
            if ($bytes > upload_max_bytes()) {
                throw new Image_storage_error(413, 'payload_too_large', '图片超过站点上传大小限制。');
            }
            $info = image_details($temporary);
            if (!$info || !valid_raster_image($temporary) || !mime($temporary)) {
                throw new Image_storage_error(422, 'invalid_image', '图片类型、内容或像素数量不符合限制。');
            }
            $lock = image_operation_lock();
            if ($user === 'visitor' && !$this->CI->query->uplimit(get_ip())) {
                throw new Image_storage_error(429, 'upload_limit', '上传达到上限！');
            }
            $digest = @md5_file($temporary);
            if ($digest === false) { throw new RuntimeException('Cannot hash upload'); }
            $imgid = substr($digest, 8, 16);
            $existing = $this->CI->query->repeat($imgid);
            if ($existing) {
                $source = safe_image_path($existing->path);
                if (!$source) { throw new RuntimeException('Stored duplicate is unavailable'); }
                $storedHash = @hash_file('sha256', $source);
                $uploadHash = @hash_file('sha256', $temporary);
                if ($storedHash === false || $uploadHash === false) { throw new RuntimeException('Cannot verify duplicate'); }
                // Compression intentionally changes bytes while preserving the original image ID.
                if (!(int)$existing->compression && !hash_equals($storedHash, $uploadHash)) {
                    throw new Image_storage_error(409, 'image_conflict', '图片标识冲突，无法保存。');
                }
                $storedInfo = image_details($source);
                $storedBytes = @filesize($source);
                if (!$storedInfo || $storedBytes === false) { throw new RuntimeException('Stored duplicate is invalid'); }
                $thumbnail = safe_image_path($existing->thumb_path) ? $existing->thumb_path : $existing->path;
                return ['created' => false, 'restricted' => $existing->level === 'adult',
                    'payload' => $this->payload($existing->id, $imgid, $existing->path, $thumbnail, $storedInfo, $storedBytes)];
            }

            $root = realpath(FCPATH.'imgs');
            $relativeDirectory = '/imgs/'.date('Y/m').'/';
            $directory = FCPATH.ltrim($relativeDirectory, '/');
            if ($root === false || (!is_dir($directory) && !@mkdir($directory, 0755, true))) {
                throw new RuntimeException('Upload directory unavailable');
            }
            $resolved = realpath($directory);
            if ($resolved === false || !str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Upload directory escapes storage');
            }
            $extension = ext($temporary);
            if ($extension === false) { throw new Image_storage_error(422, 'invalid_image', '无法识别图片类型。'); }
            $path = $relativeDirectory.$imgid.$extension;
            $full = $directory.$imgid.$extension;
            if (file_exists($full) || is_link($full)) { throw new RuntimeException('Unregistered image file already exists'); }
            // Mark the destination before copying, so even a partial copy is rolled back.
            $published[] = $full;
            if (!@rename($temporary, $full) && !@copy($temporary, $full)) { throw new RuntimeException('Cannot publish image'); }
            if (!@chmod($full, 0644)) { throw new RuntimeException('Cannot set image permissions'); }
            $thumbnail = $relativeDirectory.$imgid.'_thumb'.$extension;
            $thumbnailFull = FCPATH.ltrim($thumbnail, '/');
            if (file_exists($thumbnailFull) || is_link($thumbnailFull)) { throw new RuntimeException('Unregistered thumbnail already exists'); }
            $published[] = $thumbnailFull;
            if (!@$this->CI->image->thumbnail($full, 290, 175)) {
                if (is_file($thumbnailFull) && !@unlink($thumbnailFull)) { throw new RuntimeException('Cannot clean failed thumbnail'); }
                $thumbnail = $path;
            }
            $token = bin2hex(random_bytes(8));
            $image = [
                'imgid' => $imgid, 'path' => $path, 'thumb_path' => $thumbnail, 'storage' => 'localhost',
                'ip' => get_ip(), 'ua' => get_ua(), 'date' => date('Y-m-d H:i'),
                'user' => $user, 'level' => 'unknown', 'token' => $token
            ];
            $metadata = [
                'imgid' => $imgid, 'mime' => $info['mime'], 'width' => $info[0], 'height' => $info[1],
                'ext' => $extension, 'client_name' => $clientName
            ];
            // Resolve response URLs before committing, so response preparation cannot orphan a row.
            $payload = $this->payload(0, $imgid, $path, $thumbnail, $info, $bytes);
            $payload['delete'] = rtrim((string)$this->CI->query->get_domain(), '/').'/delete/'.$token;
            $id = $this->CI->insert->createImageWithInfo($image, $metadata);
            if ($id === false) { throw new RuntimeException('数据库写入失败！'); }
            $committed = true;
            $payload['id'] = $id;
            return ['created' => true, 'restricted' => false, 'payload' => $payload];
        } finally {
            if (!$committed) { foreach ($published as $file) { if (is_file($file)) { @unlink($file); } } }
            if (is_file($temporary)) { @unlink($temporary); }
            if (isset($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function payload($id, string $imgid, string $path, string $thumbnail, array $info, int $bytes): array
    {
        $domain = rtrim((string)$this->CI->query->domain('localhost'), '/');
        return [
            'code' => 200, 'id' => $id, 'imgid' => $imgid, 'relative_path' => $path,
            'url' => $domain.$path, 'thumbnail_url' => $domain.$thumbnail,
            'width' => (int)$info[0], 'height' => (int)$info[1], 'mime' => $info['mime'], 'size_bytes' => $bytes
        ];
    }
}
