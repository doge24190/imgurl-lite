# PHP 8.2 升级与部署

此版本保留 CodeIgniter 3 和 SQLite，框架更新至官方 3.1.13，并补充 PHP 8.2 动态属性兼容处理。现有图片路径、16 字符图片 ID 和旧删除链接格式保持兼容，不需要自动重建数据库。

## 环境

- PHP 8.2 最新安全补丁版本；CLI 和 PHP-FPM 均需检查。
- 扩展：PDO、pdo_sqlite、GD、fileinfo、curl、mbstring、ctype、filter、session。GD 的 WebP 支持取决于发行版构建。
- 建议 `memory_limit=256M`；统一配置 Nginx `client_max_body_size`、PHP `post_max_size`、`upload_max_filesize` 和站点上传大小。
- 图片最多 2000 万像素；文件大小以站点后台设置为准，默认 10 MB。普通上传、粘贴、URL 下载均执行限制。
- `data`、`imgs`、`application/logs` 对 PHP-FPM 用户可写；SQLite 数据库文件也需要可写。程序代码应只读。
- 所有图片目录须允许 Web 服务器读取；临时文件、登录会话和验证码状态位于受保护的 data 目录。

## 兼容性变化

1. **升级后需要重新登录。** 旧 user/token Cookie 不再用于鉴权，改用随机、服务端可撤销且有有效期的会话。旧 MD5 密码在正确登录后自动转存安全哈希，无需提前重设密码。长于 72 字节的旧密码迁移时使用 SHA-384 预哈希，避免 bcrypt 截断。新的安装/重置密码为 8 至 72 字节。
2. **管理写操作必须 POST，并携带 CSRF token。** 内置表单及 jQuery AJAX 已接入。自定义客户端可先获取页面，从 `csrf-name`/`csrf-token` meta 标签读取 token，并保存响应 Cookie。管理批量删除已改为 POST。
3. **公开上传 API 保留无需 CSRF 的兼容入口。** `/api/upload`、`/api/upload/{json,url,html,markdown,bbcode}`、`/api/parse`、`/api/upload/parse` 以及对应的普通/粘贴上传入口可继续使用。URL 上传仍需管理员登录及 CSRF。
4. **删除链接 GET 只显示确认页。** 实际删除需在确认表单 POST，防止链接扫描或预取直接删除图片。退出登录也采用 GET 确认、POST 执行。
5. **URL 下载只支持公开 HTTP/HTTPS 地址的 80/443 端口，不跟随重定向。** 内网、回环、保留地址及危险协议被拒绝；DNS 结果固定到连接，TLS 证书必须有效。发生跳转时，请填写最终图片地址。
6. **图片压缩默认关闭。** 仅管理员 POST 可调用；需要在 PHP-FPM 可见的环境中设置 `IMGURL_TINIFY_KEY`。旧源码公开过的密钥应在服务商处撤销/轮换，不能继续复用。

## HTTPS、代理与 PHP-FPM

HTTPS 终止在反向代理时，给 PHP-FPM 设置 `IMGURL_COOKIE_SECURE=1`，并且只允许 HTTPS 访问站点。`application/config/config.php` 的 `proxy_ips` 只填写实际可信代理，访客上传计数和登录限速统一使用 CI 的 IP 判断。

PHP-FPM 默认可能清理进程环境，单纯在交互终端 export 不一定对 FPM 生效。可在对应池配置中显式设置：

```ini
env[IMGURL_COOKIE_SECURE] = 1
; 按需启用压缩，在服务器上填入自己的密钥：
; env[IMGURL_TINIFY_KEY] = your-own-key
```

不要将真实密钥提交到 Git。正确设置数据库里的站点根 URL，并根据站点需求明确 PHP 时区。

## Nginx 关键保护

Nginx 不读取 `.htaccess`。除路由、TLS 和上传限制外，站点 server 中至少应包含以下规则，并结合已有配置检查优先级：

```nginx
location ^~ /data/ { return 403; }
location ^~ /application/ { return 403; }
location ^~ /system/ { return 403; }
location ^~ /tests/ { return 403; }
location ^~ /docs/ { return 403; }
location ~ /\.(?!well-known).* { return 403; }
location ~* /(composer\.(json|lock)|README|LICENSE) { return 403; }

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    # 按生产环境调整：
    fastcgi_pass unix:/run/php-fpm/php-fpm.sock;
}
location ~ \.php(?:/|$) { return 404; }
```

不要给 imgs 添加绕过上述 PHP 限制的执行规则。生产服务器也不应暴露开发用 PHP 内置服务器。

## 升级步骤

1. 暂停写入，备份代码、imgs、data 中的配置和数据库。使用 SQLite Backup API 获取一致备份；若启用了 WAL，不要只复制主数据库文件。
2. 在独立路径和测试域名验证本版本。保留现有 `data/imgurl.db3`、`data/install.lock`、配置、图片和验证码密钥，不运行重新安装。
3. 确认目录权限、FPM 环境、Nginx 规则和扩展；生产使用 `CI_ENV=production`。生产记录 E_ALL 错误，但不向访客展示错误。
4. 切换代码，重新登录，检查上传、粘贴、后台筛选、删除和错误日志。
5. 回滚时恢复匹配的代码与数据库备份。首次新版本登录会升级密码摘要，旧版本无法验证该摘要，所以不能仅回滚代码。

## 回归测试

GitHub Actions 自动检查 PHP 8.2 和 8.5。测试只复制源码和模板数据库到随机临时目录，监听回环地址，不连接现有站点或调用真实压缩服务。需要 Python 3 和 PHP 8.2，以及环境列表中的 PHP 扩展。

```sh
python3 tests/security_smoke.py --php /path/to/php
node tests/csrf.test.js
```

覆盖安装、真实验证码校验路径、旧密码升级、服务端会话、CSRF、五种图片格式、损坏/大像素图片、上传限制、重复返回、并发去重、XSS 编码、筛选参数、下载目标限制、数据库失败回滚、压缩服务模拟成功/失败、删除确认、退出与会话过期，以及全部 PHP 文件语法检查。测试验证码签名只使用测试临时目录中的密钥；生产没有加入验证码绕过接口。

真实 Tinify 服务、生产 TLS/FPM 配置、完整浏览器交互和高负载表现仍需在部署环境验收。当前通过 SQLite 加共享文件锁保证单机图片操作一致性，不宣称支持多节点存储。
