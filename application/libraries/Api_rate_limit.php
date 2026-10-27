<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Api_rate_limit {
    /** Atomic, fixed-minute window; callers use IPs or verified token IDs, never raw secrets. */
    public function consume(string $key, int $limit): int
    {
        $directory = FCPATH.'data/.api_rate';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('API 限速存储不可用。');
        }
        $path = $directory.'/'.hash('sha256', $key).'.json';
        $handle = @fopen($path, 'c+');
        if ($handle === false) { throw new RuntimeException('API 限速存储不可用。'); }
        try {
            if (!flock($handle, LOCK_EX)) { throw new RuntimeException('无法锁定 API 限速记录。'); }
            $window = (int)floor(time() / 60);
            $state = json_decode(stream_get_contents($handle, 2048), true);
            $count = is_array($state) && ($state['window'] ?? null) === $window ? (int)($state['count'] ?? 0) : 0;
            if ($count >= $limit) { return 60 - time() % 60; }
            $content = json_encode(['window' => $window, 'count' => $count + 1]);
            rewind($handle);
            if (!@ftruncate($handle, 0) || @fwrite($handle, $content) !== strlen($content) || !@fflush($handle)) {
                throw new RuntimeException('无法保存 API 限速记录。');
            }
            return 0;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
