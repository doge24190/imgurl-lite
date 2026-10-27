<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Opaque tokens: only SHA-256 digests are persisted in the existing options table. */
class Api_tokens {
    public const SCOPES = [
        'health:read' => '查看详细健康状态',
        'images:read' => '查询图片（含管理员上传的图片）',
        'appearance:read' => '查看网站外观',
        'appearance:write' => '修改网站外观'
    ];
    private ?PDO $pdo = null;

    public function database(): PDO
    {
        if ($this->pdo === null) {
            if (!is_file(FCPATH.'data/install.lock') || !is_file(FCPATH.'data/imgurl.db3')) {
                throw new RuntimeException('站点尚未安装。');
            }
            $this->pdo = new PDO('sqlite:'.FCPATH.'data/imgurl.db3', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        }
        return $this->pdo;
    }

    public function option(string $name): array
    {
        $statement = $this->database()->prepare('SELECT "values" FROM img_options WHERE name=? LIMIT 1');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
        if ($value === false) { return []; }
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new RuntimeException('配置格式错误。'); }
        return $decoded;
    }

    private function credential(): string
    {
        $user = $this->option('userinfo');
        if (!is_string($user['username'] ?? null) || !is_string($user['password'] ?? null)) {
            throw new RuntimeException('账号配置不可用。');
        }
        return hash('sha256', $user['username']."\0".$user['password']);
    }

    public function listing(): array
    {
        $credential = $this->credential();
        $result = [];
        foreach ($this->option('api_tokens') as $record) {
            $record['active'] = $record['expires_at'] > time() && hash_equals($credential, $record['credential']);
            unset($record['hash'], $record['credential']);
            $result[] = $record;
        }
        return array_reverse($result);
    }

    public function create($name, $scopes, $days): array
    {
        if (!is_string($name) || !mb_check_encoding($name, 'UTF-8') || trim($name) === '' || mb_strlen(trim($name)) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new InvalidArgumentException('请填写 1–80 个字符的令牌名称。');
        }
        if (!is_array($scopes) || count($scopes) < 1 || count($scopes) > count(self::SCOPES)) {
            throw new InvalidArgumentException('请至少选择一项权限。');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !isset(self::SCOPES[$scope])) { throw new InvalidArgumentException('令牌权限无效。'); }
        }
        if (!is_string($days) || !ctype_digit($days) || (int)$days < 1 || (int)$days > 365) {
            throw new InvalidArgumentException('有效期应为 1–365 天。');
        }
        $secret = 'imgurl_'.bin2hex(random_bytes(32));
        $record = [
            'id' => bin2hex(random_bytes(8)), 'name' => trim($name), 'prefix' => substr($secret, 0, 15),
            'hash' => hash('sha256', $secret), 'credential' => $this->credential(),
            'scopes' => array_values(array_unique($scopes)), 'created_at' => time(),
            'expires_at' => time() + (int)$days * 86400
        ];
        $this->mutate(function (array $records) use ($record): array {
            if (count($records) >= 50) { throw new InvalidArgumentException('最多保留 50 个令牌，请先撤销不用的令牌。'); }
            $records[$record['id']] = $record;
            return $records;
        });
        return ['secret' => $secret, 'id' => $record['id']];
    }

    public function revoke($id): void
    {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{16}$/D', $id)) { throw new InvalidArgumentException('令牌 ID 无效。'); }
        $this->mutate(function (array $records) use ($id): array {
            if (!isset($records[$id])) { throw new InvalidArgumentException('令牌不存在或已撤销。'); }
            unset($records[$id]);
            return $records;
        });
    }

    private function mutate(callable $change): void
    {
        $pdo = $this->database();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $records = $change($this->option('api_tokens'));
            $value = json_encode($records, JSON_THROW_ON_ERROR);
            $exists = $pdo->query("SELECT count(*) FROM img_options WHERE name='api_tokens'")->fetchColumn();
            $statement = $exists
                ? $pdo->prepare('UPDATE img_options SET "values"=? WHERE name=\'api_tokens\'')
                : $pdo->prepare('INSERT INTO img_options("values",name) VALUES (?,\'api_tokens\')');
            $statement->execute([$value]);
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            $pdo->exec('ROLLBACK');
            throw $error;
        }
    }

    public function authenticate(string $authorization): ?array
    {
        if (!preg_match('/^Bearer (imgurl_[a-f0-9]{64})$/iD', $authorization, $match)) { return null; }
        $hash = hash('sha256', $match[1]);
        foreach ($this->option('api_tokens') as $record) {
            if (is_string($record['hash'] ?? null) && hash_equals($record['hash'], $hash)
                && $record['expires_at'] > time() && hash_equals($record['credential'], $this->credential())) {
                return $record;
            }
        }
        return null;
    }
}
