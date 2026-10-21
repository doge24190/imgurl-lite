<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Server-side, expiring sessions. Legacy deterministic login cookies are ignored. */
class Auth {
    private const LIFETIME = 604800;

    private function start(bool $create = false): bool
    {
        if (!$create && empty($_COOKIE['imgurl_session'])) {
            return false;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        $directory = FCPATH.'data/.sessions';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            show_error('无法创建登录会话目录。', 503);
        }
        session_name('imgurl_session');
        session_save_path($directory);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)self::LIFETIME);
        session_set_cookie_params([
            'lifetime' => self::LIFETIME, 'path' => '/',
            'secure' => (bool)config_item('cookie_secure') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Lax'
        ]);
        if (!session_start()) {
            show_error('无法启动登录会话。', 503);
        }
        return true;
    }

    public function login(string $username, string $passwordHash): void
    {
        $this->start(true);
        session_regenerate_id(true);
        $_SESSION = [
            'username' => $username,
            'credential' => hash('sha256', $username."\0".$passwordHash),
            'expires' => time() + self::LIFETIME
        ];
        session_write_close();
    }

    public function check(string $username, string $passwordHash): bool
    {
        if (!$this->start()) {
            return false;
        }
        $valid = isset($_SESSION['username'], $_SESSION['credential'], $_SESSION['expires'])
            && is_string($_SESSION['credential'])
            && $_SESSION['username'] === $username && $_SESSION['expires'] > time()
            && hash_equals(hash('sha256', $username."\0".$passwordHash), $_SESSION['credential']);
        session_write_close();
        return $valid;
    }

    public function username(): string
    {
        if (!$this->start()) {
            return '';
        }
        $username = $_SESSION['username'] ?? '';
        session_write_close();
        return is_string($username) ? $username : '';
    }

    public function logout(): void
    {
        if ($this->start()) {
            $_SESSION = [];
            $params = session_get_cookie_params();
            session_destroy();
            setcookie('imgurl_session', '', [
                'expires' => time() - 3600, 'path' => '/',
                'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax'
            ]);
        }
        unset($_COOKIE['imgurl_session']);
    }
}
