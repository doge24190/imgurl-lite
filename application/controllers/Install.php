<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Install extends CI_Controller {
    public function index()
    {
        if (is_file(FCPATH.'data/install.lock')) { header('Location: /'); return; }
        $step = $this->input->get('setup');
        $step = is_string($step) && ctype_digit($step) ? (int)$step : 1;
        $data = ['title' => 'ImgURL安装向导', 'logo' => '/static/images/logo.png', 'env' => $this->check()];
        $data['sum'] = !in_array('<span style="color:red">未通过！</span>', array_column($data['env'], 'result'), true);
        if ($step === 3) {
            require_post();
            if (!$data['sum']) { show_error('请先满足所有环境要求。', 503); }
            $info = [];
            foreach (['domain', 'user', 'pass1', 'pass2'] as $field) {
                $info[$field] = $this->input->post($field);
                if (!is_string($info[$field])) { show_error('安装参数格式错误。', 400); }
            }
            $parts = parse_url($info['domain']);
            if (!filter_var($info['domain'], FILTER_VALIDATE_URL) || !isset($parts['scheme'], $parts['host'])
                || !in_array($parts['scheme'], ['http', 'https'], true)
                || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment'])
                || !in_array($parts['path'] ?? '', ['', '/'], true)) { show_error('请填写 HTTP/HTTPS 站点根地址。', 400); }
            if (!preg_match('/^[a-zA-Z0-9]{1,64}$/', $info['user'])) { show_error('用户名格式错误。', 400); }
            if (strlen($info['pass1']) < 8 || strlen($info['pass1']) > 72 || $info['pass1'] !== $info['pass2']) {
                show_error('密码需要为 8 至 72 字节，且两次输入一致。', 400);
            }
            $this->setup($info);
        } elseif ($step === 2) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $data['domain'] = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || config_item('cookie_secure') ? 'https://' : 'http://').$host;
        } else { $step = 1; }
        $this->load->view('user/header', $data);
        $this->load->view('user/install'.$step, $data);
        $this->load->view('user/footer');
    }

    private function check(): array
    {
        $checks = ['php' => ['PHP', 'PHP >= 8.2', PHP_VERSION, PHP_VERSION_ID >= 80200]];
        foreach (['pdo_sqlite', 'gd', 'fileinfo', 'curl', 'mbstring', 'ctype', 'filter', 'session'] as $extension) {
            $checks[$extension] = [$extension, '必须支持', extension_loaded($extension) ? 'Yes' : 'No', extension_loaded($extension)];
        }
        foreach (['data', 'imgs'] as $directory) {
            $checks[$directory] = ['/'.$directory, '可写', is_writable(FCPATH.$directory) ? 'Yes' : 'No', is_writable(FCPATH.$directory)];
        }
        $result = [];
        foreach ($checks as $key => [$name, $required, $info, $passed]) {
            $result[$key] = ['name' => $name, 'requir' => $required, 'info' => $info,
                'result' => $passed ? '<span style="color:green">通过！</span>' : '<span style="color:red">未通过！</span>'];
        }
        return $result;
    }

    private function setup(array $info): void
    {
        $lock = fopen(FCPATH.'data/.install.mutex', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { show_error('另一安装正在进行中。', 409); }
        $database = FCPATH.'data/imgurl.db3';
        if (file_exists($database) || file_exists(FCPATH.'data/install.lock')) { show_error('数据库已存在，禁止覆盖安装。', 409); }
        $temp = tempnam(FCPATH.'data', '.install-');
        $created = false;
        try {
            if ($temp === false || !copy(FCPATH.'data/imgurl-simple.db3', $temp)) { throw new RuntimeException('Cannot copy database'); }
            $pdo = new PDO('sqlite:'.$temp, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->beginTransaction();
            $statement = $pdo->prepare('INSERT INTO img_options(name, "values") VALUES (?, ?)');
            $statement->execute(['userinfo', json_encode(['username' => $info['user'], 'password' => password_hash($info['pass1'], PASSWORD_DEFAULT)])]);
            $statement->execute(['site_url', rtrim($info['domain'], '/')]);
            $pdo->prepare('INSERT INTO img_storage(engine, domains, switch) VALUES (?, ?, ?)')->execute(['localhost', rtrim($info['domain'], '/'), 'ON']);
            $pdo->commit();
            $pdo = null;
            if (!rename($temp, $database)) { throw new RuntimeException('Cannot save database'); }
            $created = true;
            if (file_put_contents(FCPATH.'data/install.lock', 'ImgURL', LOCK_EX) === false) { throw new RuntimeException('Cannot lock installation'); }
        } catch (Throwable $error) {
            if ($temp && is_file($temp)) { @unlink($temp); }
            if ($created) { @unlink($database); }
            log_message('error', 'Installation failed: '.$error->getMessage());
            show_error('安装失败，请检查目录权限。', 500);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
