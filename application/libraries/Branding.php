<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Select only registered local uploads; keep independent copies for site assets. */
class Branding {
    public const FIELDS = ['background', 'logo', 'favicon'];

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
        if (filesize($path) > 10 * 1024 * 1024) {
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
        if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0755, true))) {
            throw new RuntimeException('无法创建站点外观图片目录。');
        }
        $temp = tempnam($directory, '.asset-');
        if ($temp === false) { throw new RuntimeException('无法写入站点外观图片。'); }
        try {
            if ($field === 'favicon') {
                $source = imagecreatefromstring(file_get_contents($info['path']));
                $icon = imagecreatetruecolor(256, 256);
                imagealphablending($icon, false);
                imagesavealpha($icon, true);
                imagefill($icon, 0, 0, imagecolorallocatealpha($icon, 0, 0, 0, 127));
                if (!imagecopyresampled($icon, $source, 0, 0, 0, 0, 256, 256, $info['width'], $info['height'])
                    || !imagepng($icon, $temp)) { throw new RuntimeException('无法生成网站图标。'); }
                $extension = '.png';
            } else {
                if (!copy($info['path'], $temp)) { throw new RuntimeException('无法复制站点外观图片。'); }
                $extension = image_type_to_extension($info['type']);
            }
            $url = '/imgs/branding/'.hash_file('sha256', $temp).$extension;
            if (!chmod($temp, 0644) || !rename($temp, FCPATH.ltrim($url, '/'))) {
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
