<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Select only registered local uploads; keep independent copies for site assets. */
class Branding {
    public const FIELDS = ['background', 'logo', 'favicon'];

    /** Shared, atomic save path for the admin form and the token API. */
    public function save(array $fields, array $selections, $legacyLogo = null): array
    {
        $CI =& get_instance();
        $CI->load->model('query', '', true);
        $CI->load->model('update');
        $previous = $data = [];
        $saved = false;
        $debug = $CI->db->db_debug;
        $CI->db->db_debug = false;
        try {
            $lock = image_operation_lock();
            $row = $CI->query->site_setting();
            if (!$row) { throw new RuntimeException('站点设置不存在。'); }
            $previous = json_decode($row->values, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($previous)) { throw new RuntimeException('站点设置格式错误。'); }
            $data = array_merge($previous, $fields);
            if ($legacyLogo !== null && $legacyLogo !== ($previous['logo'] ?? '')
                && $legacyLogo !== '/static/images/logo.png') {
                throw new InvalidArgumentException('请从已上传图片中选择 Logo。');
            }
            foreach (self::FIELDS as $field) {
                $selection = $selections[$field] ?? null;
                if ($selection === null || $selection === 'keep') { continue; }
                if ($selection === 'default') {
                    $data[$field] = $field === 'logo' ? '/static/images/logo.png' : '';
                    continue;
                }
                if (!is_string($selection) || !preg_match('/^[a-f0-9]{16}$/iD', $selection)) {
                    throw new InvalidArgumentException('图片选择参数错误。');
                }
                $data[$field] = $this->publish($CI->query->onepic($selection), $field);
            }
            if (!$CI->update->site('site_setting', json_encode($data, JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('无法保存站点设置。');
            }
            $saved = true;
            $this->cleanup($previous, $data);
            return $data;
        } finally {
            if (!$saved) { $this->cleanup($data, $previous); }
            $CI->db->db_debug = $debug;
            if (isset($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    public function presentation(array $settings): array
    {
        return [
            'background' => branding_url($settings['background'] ?? null, '') ?: null,
            'logo' => branding_url($settings['logo'] ?? null, '/static/images/logo.png'),
            'favicon' => branding_url($settings['favicon'] ?? null, '/favicon.ico'),
            'requirements' => [
                'max_bytes' => 10 * 1024 * 1024, 'max_pixels' => 20000000,
                'formats' => ['png', 'jpeg', 'webp', 'gif'],
                'background' => ['min_width' => 1280, 'min_height' => 720],
                'logo' => ['min_width' => 64, 'min_height' => 32, 'max_width' => 4096, 'max_height' => 4096, 'min_ratio' => 0.25, 'max_ratio' => 8],
                'favicon' => ['square' => true, 'min_width' => 64, 'max_width' => 2048, 'output_width' => 256, 'output_format' => 'png']
            ]
        ];
    }

    public function inspect($image, string $field): array
    {
        if (!in_array($field, self::FIELDS, true)) { throw new InvalidArgumentException('无效的图片用途。'); }
        if (!$image || ($image->level ?? '') === 'adult') {
            throw new InvalidArgumentException('图片不存在或不可用于站点外观。');
        }
        $path = safe_image_path($image->path);
        $info = $path ? image_details($path) : false;
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new InvalidArgumentException('请选择有效的 PNG、JPG、WebP 或 GIF 图片。');
        }
        $size = @filesize($path);
        if ($size === false) { throw new InvalidArgumentException('图片文件不可读取。'); }
        if ($size > 10 * 1024 * 1024) {
            throw new InvalidArgumentException('外观图片不能超过 10 MB。');
        }
        [$width, $height] = $info;
        if ($field === 'background' && ($width < 1280 || $height < 720)) {
            throw new InvalidArgumentException('首页背景至少需要 1280 × 720 像素，推荐 1920 × 1080 或更大的横向照片。');
        }
        if ($field === 'logo' && ($width < 64 || $height < 32 || $width > 4096 || $height > 4096
            || $width / $height < 0.25 || $width / $height > 8)) {
            throw new InvalidArgumentException('Logo 需要宽 64–4096、高 32–4096 像素，宽高比在 1:4 至 8:1 之间；推荐透明 PNG。');
        }
        if ($field === 'favicon' && ($width !== $height || $width < 64 || $width > 2048)) {
            throw new InvalidArgumentException('网站图标需要 64–2048 像素的正方形图片，推荐 256 × 256；保存时自动生成 PNG 图标。');
        }
        return ['path' => $path, 'width' => $width, 'height' => $height, 'type' => $info[2]];
    }

    /** Caller holds image_operation_lock(), shared with upload and deletion. */
    public function publish($image, string $field): string
    {
        $info = $this->inspect($image, $field);
        if (!valid_raster_image($info['path'])) { throw new InvalidArgumentException('图片内容损坏，请重新上传。'); }
        $directory = FCPATH.'imgs/branding';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0755, true))) {
            throw new RuntimeException('无法创建站点外观图片目录。');
        }
        $temp = @tempnam($directory, '.asset-');
        if ($temp === false) { throw new RuntimeException('无法写入站点外观图片。'); }
        try {
            if ($field === 'favicon') {
                $source = imagecreatefromstring(file_get_contents($info['path']));
                $icon = imagecreatetruecolor(256, 256);
                imagealphablending($icon, false);
                imagesavealpha($icon, true);
                imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
                if (!imagecopyresampled($icon, $source, 0, 0, 0, 0, 256, 256, $info['width'], $info['height'])
                    || !@imagepng($icon, $temp)) { throw new RuntimeException('无法生成网站图标。'); }
                $extension = '.png';
            } else {
                if (!@copy($info['path'], $temp)) { throw new RuntimeException('无法复制站点外观图片。'); }
                $extension = image_type_to_extension($info['type']);
            }
            $url = '/imgs/branding/'.hash_file('sha256', $temp).$extension;
            if (!@chmod($temp, 0644) || !@rename($temp, FCPATH.ltrim($url, '/'))) {
                throw new RuntimeException('无法保存站点外观图片。');
            }
            return $url;
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
    }

    /** Remove only generated files no longer referenced by any branding field. */
    public function cleanup(array $previous, array $current): void
    {
        $keep = array_intersect_key($current, array_flip(self::FIELDS));
        foreach (array_intersect_key($previous, array_flip(self::FIELDS)) as $url) {
            if (is_string($url) && preg_match('~^/imgs/branding/[a-f0-9]{64}\.(png|jpeg|webp|gif)$~D', $url)
                && !in_array($url, $keep, true)) {
                $path = safe_image_path($url);
                if ($path) { @unlink($path); }
            }
        }
    }
}
