<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Api_v1_error extends RuntimeException {
    public function __construct(int $status, public string $error_code, string $message)
    {
        parent::__construct($message, $status);
    }
}

/** Versioned endpoints. Browser sessions never grant access to protected API operations. */
class Api_v1 extends CI_Controller {
    public function _remap($method, $parameters = [])
    {
        $this->load->helper('basic');
        $this->load->library(['api_tokens', 'api_rate_limit', 'branding']);
        $path = implode('/', array_merge([$method], $parameters));
        $verb = strtoupper($this->input->method());
        try {
            $retry = $this->api_rate_limit->consume('ip:'.$this->input->ip_address(), 300);
            if ($retry > 0) { $this->limited($retry); }
            if ($path === 'health') {
                $this->method($verb, ['GET']);
                $authorization = $this->authorization();
                if ($authorization !== '') { $this->authorize('health:read', $authorization); }
                $this->health($authorization !== '');
                return;
            }
            if ($path === 'images' || preg_match('~^images/[^/]+$~D', $path)) {
                $this->method($verb, ['GET']);
                $this->authorize('images:read', $this->authorization());
                if ($path === 'images') { $this->images(); }
                else { $this->image(substr($path, 7)); }
                return;
            }
            if ($path === 'settings/appearance') {
                $this->method($verb, ['GET', 'PATCH']);
                $this->authorize($verb === 'GET' ? 'appearance:read' : 'appearance:write', $this->authorization());
                if ($verb === 'PATCH') {
                    $selections = $this->json_body();
                    foreach ($selections as $field => $value) {
                        if (!in_array($field, Branding::FIELDS, true) || !is_string($value)
                            || ($value !== 'default' && !preg_match('/^[a-f0-9]{16}$/iD', $value))) {
                            throw new Api_v1_error(400, 'invalid_parameter', '外观字段必须为 background、logo 或 favicon，值应为图片 ID 或 default。');
                        }
                    }
                    foreach ($selections as $field => $value) { $selections[$field] = strtolower($value); }
                    // Use the configured database with HTML SQL diagnostics disabled for API errors.
                    require APPPATH.'config/database.php';
                    $configuration = $db[$active_group];
                    $configuration['db_debug'] = false;
                    $this->load->database($configuration);
                    if (empty($this->db->conn_id)) { throw new RuntimeException('Database unavailable'); }
                    $settings = $this->branding->save([], $selections);
                } else { $settings = $this->api_tokens->option('site_setting'); }
                $this->respond(200, ['ok' => true, 'data' => $this->branding->presentation($settings)]);
                return;
            }
            throw new Api_v1_error(404, 'not_found', 'API 接口不存在。');
        } catch (Api_v1_error $error) {
            $this->respond($error->getCode(), ['ok' => false, 'error' => ['code' => $error->error_code, 'message' => $error->getMessage()]]);
        } catch (InvalidArgumentException $error) {
            $this->respond(422, ['ok' => false, 'error' => ['code' => 'invalid_image', 'message' => $error->getMessage()]]);
        } catch (Throwable $error) {
            // No request headers, tokens, paths, or SQL are included in the public response.
            log_message('error', 'API v1 operation failed ('.get_class($error).').');
            $this->respond(503, ['ok' => false, 'error' => ['code' => 'service_unavailable', 'message' => '服务暂不可用，请检查服务器日志、数据库及目录权限。']]);
        }
    }

    private function method(string $actual, array $allowed): void
    {
        if (!in_array($actual, $allowed, true)) {
            $this->output->set_header('Allow: '.implode(', ', $allowed));
            throw new Api_v1_error(405, 'method_not_allowed', '此接口不支持该请求方法。');
        }
    }

    private function authorization(): string
    {
        $value = $this->input->get_request_header('Authorization');
        if ($value === null) { $value = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''; }
        return is_string($value) ? $value : '';
    }

    private function authorize(string $scope, string $authorization): void
    {
        $token = $authorization !== '' ? $this->api_tokens->authenticate($authorization) : null;
        if (!$token) {
            $this->output->set_header('WWW-Authenticate: Bearer realm="ImgURL API"');
            throw new Api_v1_error(401, 'unauthorized', '请提供有效且未过期的 Bearer Token。');
        }
        $retry = $this->api_rate_limit->consume('token:'.$token['id'], 120);
        if ($retry > 0) { $this->limited($retry); }
        if (!in_array($scope, $token['scopes'], true)) {
            throw new Api_v1_error(403, 'insufficient_scope', '该令牌没有所需权限：'.$scope.'。');
        }
    }

    private function limited(int $retry): void
    {
        $this->output->set_header('Retry-After: '.$retry);
        throw new Api_v1_error(429, 'rate_limited', '请求过于频繁，请稍后重试。');
    }

    private function health(bool $detailed): void
    {
        $checks = [
            'installed' => is_file(FCPATH.'data/install.lock'), 'database' => false,
            'storage' => is_writable(FCPATH.'imgs') && is_writable(FCPATH.'data')
                && is_writable(FCPATH.'data/imgurl.db3') && !is_link(FCPATH.'imgs/branding')
                && (!is_dir(FCPATH.'imgs/branding') || is_writable(FCPATH.'imgs/branding')),
            'image_processing' => extension_loaded('gd') && extension_loaded('fileinfo')
        ];
        try {
            $settings = $this->api_tokens->option('site_setting');
            $checks['database'] = isset($settings['title']);
        } catch (Throwable $error) { /* Health still responds when installation or SQLite is unavailable. */ }
        $ready = !in_array(false, $checks, true);
        $data = ['status' => $ready ? 'ok' : 'degraded', 'api_version' => 'v1'];
        if ($detailed) {
            $data['checks'] = $checks;
            $data['application_version'] = trim((string)@file_get_contents(FCPATH.'data/version.txt'));
        }
        $response = ['ok' => $ready, 'data' => $data];
        if (!$ready) { $response['error'] = ['code' => 'service_unavailable', 'message' => '站点未就绪。']; }
        $this->respond($ready ? 200 : 503, $response);
    }

    private function integer(string $name, int $default, int $max): int
    {
        $value = $this->input->get($name);
        if ($value === null) { return $default; }
        if (!is_string($value) || !ctype_digit($value) || (int)$value < 1 || (int)$value > $max) {
            throw new Api_v1_error(400, 'invalid_parameter', $name.' 超出允许范围。');
        }
        return (int)$value;
    }

    private function images(): void
    {
        $page = $this->integer('page', 1, 100000);
        $perPage = $this->integer('per_page', 24, 50);
        $search = $this->input->get('search') ?? '';
        $usage = $this->input->get('usage');
        if (!is_string($search) || strlen($search) > 200
            || ($usage !== null && (!is_string($usage) || !in_array($usage, Branding::FIELDS, true)))) {
            throw new Api_v1_error(400, 'invalid_parameter', '图片筛选参数错误。');
        }
        $where = "a.level != 'adult'";
        $values = [];
        if ($search !== '') {
            $where .= ' AND (instr(b.client_name, ?) > 0 OR instr(a.imgid, ?) > 0)';
            $values = [$search, $search];
        }
        $pdo = $this->api_tokens->database();
        $statement = $pdo->prepare('SELECT a.imgid,a.path,a.thumb_path,a.date,a.level,b.client_name FROM img_images a INNER JOIN img_imginfo b ON a.imgid=b.imgid WHERE '.$where.' ORDER BY a.id DESC LIMIT ? OFFSET ?');
        foreach ($values as $index => $value) { $statement->bindValue($index + 1, $value); }
        $statement->bindValue(count($values) + 1, $perPage + 1, PDO::PARAM_INT);
        $statement->bindValue(count($values) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $this->respond(200, ['ok' => true, 'data' => [
            'items' => array_map(fn($row) => $this->describe_image($row, $usage), array_slice($rows, 0, $perPage)),
            'page' => $page, 'per_page' => $perPage, 'has_more' => count($rows) > $perPage
        ]]);
    }

    private function image(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/iD', $id)) { throw new Api_v1_error(400, 'invalid_parameter', '图片 ID 无效。'); }
        $statement = $this->api_tokens->database()->prepare("SELECT a.imgid,a.path,a.thumb_path,a.date,a.level,b.client_name FROM img_images a INNER JOIN img_imginfo b ON a.imgid=b.imgid WHERE a.imgid=? AND a.level != 'adult' LIMIT 1");
        $statement->execute([strtolower($id)]);
        $row = $statement->fetch();
        if (!$row) { throw new Api_v1_error(404, 'not_found', '图片不存在。'); }
        $this->respond(200, ['ok' => true, 'data' => $this->describe_image($row, null)]);
    }

    private function describe_image(array $row, ?string $usage): array
    {
        $path = safe_image_path($row['path']);
        $info = $path ? image_details($path) : false;
        $size = $path ? @filesize($path) : false;
        $result = [
            'imgid' => $row['imgid'], 'name' => $row['client_name'], 'created_at' => $row['date'],
            'available' => (bool)$info, 'url' => $info ? $row['path'] : null,
            'thumbnail_url' => $info ? (safe_image_path($row['thumb_path']) ? $row['thumb_path'] : $row['path']) : null,
            'width' => $info ? $info[0] : null, 'height' => $info ? $info[1] : null,
            'mime' => $info ? $info['mime'] : null, 'size_bytes' => $size === false ? null : $size
        ];
        if ($usage !== null) {
            $result['eligible'] = false;
            $result['reason'] = null;
            try { $this->branding->inspect((object)$row, $usage); $result['eligible'] = true; }
            catch (InvalidArgumentException $error) { $result['reason'] = $error->getMessage(); }
        }
        return $result;
    }

    private function json_body(): array
    {
        $type = strtolower(trim(explode(';', $this->input->get_request_header('Content-Type') ?? '')[0]));
        if ($type !== 'application/json') { throw new Api_v1_error(415, 'unsupported_media_type', '请使用 application/json。'); }
        $body = file_get_contents('php://input', false, null, 0, 16385);
        if ($body === false) { throw new RuntimeException('Cannot read request body'); }
        if (strlen($body) > 16384) { throw new Api_v1_error(413, 'payload_too_large', '请求正文不能超过 16 KB。'); }
        try { $value = json_decode($body, false, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new Api_v1_error(400, 'invalid_json', 'JSON 格式错误。'); }
        if (!($value instanceof stdClass) || count((array)$value) === 0) {
            throw new Api_v1_error(400, 'invalid_parameter', '请提供至少一个外观字段。');
        }
        return (array)$value;
    }

    private function respond(int $status, array $body): void
    {
        $this->output->set_status_header($status)->set_content_type('application/json')
            ->set_header('Cache-Control: no-store')->set_header('X-Content-Type-Options: nosniff')
            ->set_output(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
