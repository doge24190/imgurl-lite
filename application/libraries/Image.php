<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Image {
    /** Resize raster images with GD, including BMP and WebP when supported. */
    public function thumbnail($source, $width, $height): bool
    {
        $info = image_details($source);
        if (!$info || ($info[0] <= $width && $info[1] <= $height)) { return false; }
        $decoders = [IMAGETYPE_GIF => 'imagecreatefromgif', IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_BMP => 'imagecreatefrombmp', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        $encoders = [IMAGETYPE_GIF => 'imagegif', IMAGETYPE_JPEG => 'imagejpeg',
            IMAGETYPE_PNG => 'imagepng', IMAGETYPE_BMP => 'imagebmp', IMAGETYPE_WEBP => 'imagewebp'];
        $decode = $decoders[$info[2]];
        $encode = $encoders[$info[2]];
        if (!function_exists($decode) || !function_exists($encode)) { return false; }
        $image = null;
        $thumb = null;
        try {
            $image = @$decode($source);
            if (!$image) { return false; }
            $scale = min($width / $info[0], $height / $info[1]);
            $w = max(1, (int)round($info[0] * $scale));
            $h = max(1, (int)round($info[1] * $scale));
            $thumb = imagecreatetruecolor($w, $h);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
            imagecopyresampled($thumb, $image, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
            $path = pathinfo($source);
            $target = $path['dirname'].'/'.$path['filename'].'_thumb.'.$path['extension'];
            return $encode($thumb, $target) && is_file($target);
        } catch (Throwable $error) {
            log_message('error', 'Thumbnail failed: '.$error->getMessage());
            return false;
        } finally {
            unset($image, $thumb);
        }
    }
}
