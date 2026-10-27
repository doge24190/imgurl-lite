# ImgURL API v1

API v1 用于部署健康检查、图片查询和外观设置。原有 `/api/upload`、`/api/parse`、`/api/found` 接口保持兼容；它们不使用这里的管理令牌权限。API v1 不提供上传、删除图片、修改账号密码或创建令牌的接口。

## 创建令牌

登录后台 → 系统设置 → API 令牌，填写名称、有效天数并选择权限。完整令牌仅在创建响应中显示一次，请立即保存到密码管理器或服务器环境变量。数据库仅存储摘要。最多保留 50 个令牌，有效期为 1–365 天，默认 30 天；到期或不再使用的令牌可在后台撤销。修改后台账号或密码后，现有令牌会失效。

| 权限 | 允许操作 |
| --- | --- |
| `health:read` | 查看健康检查的详细结果 |
| `images:read` | 查询所有未标记为 adult 的图片，包含管理员上传的图片 |
| `appearance:read` | 查看当前外观和尺寸要求 |
| `appearance:write` | 修改首页背景、Logo、图标 |

生产环境使用 HTTPS。受保护接口只接受 `Authorization: Bearer <令牌>`，不会使用后台登录 Cookie，也不会从 URL 查询参数或 Cookie 读取令牌。权限相互独立；例如 `appearance:write` 不授予外观 GET 接口权限。PATCH 成功响应会返回更新后的外观。

以下示例中，将站点地址设为自己的域名，并在当前终端准备 `IMGURL_API_TOKEN` 环境变量。令牌不要写入 URL、前端代码、Git 仓库或公开日志。

```sh
export IMGURL_BASE_URL='https://images.example.com'
# 将在后台创建的令牌保存到 IMGURL_API_TOKEN 环境变量。
```

## 健康检查

```sh
curl --fail-with-body "$IMGURL_BASE_URL/api/v1/health"

curl --fail-with-body \
  -H "Authorization: Bearer $IMGURL_API_TOKEN" \
  "$IMGURL_BASE_URL/api/v1/health"
```

匿名请求只返回汇总状态：

```json
{"ok":true,"data":{"status":"ok","api_version":"v1"}}
```

携带 `health:read` 令牌时，额外返回 `checks`（`installed`、`database`、`storage`、`image_processing`）和 `application_version`。不会公开服务器路径、PHP 配置或账号信息。未安装、数据库不可用或目录不可写时返回 HTTP 503。健康检查使用只读数据库查询，不写入测试图片；限速计数会正常更新。

## 查询图片

```sh
curl --fail-with-body --get \
  -H "Authorization: Bearer $IMGURL_API_TOKEN" \
  --data-urlencode 'search=照片' \
  --data-urlencode 'page=1' \
  --data-urlencode 'per_page=24' \
  --data-urlencode 'usage=background' \
  "$IMGURL_BASE_URL/api/v1/images"
```

需要 `images:read`。参数均可省略：

- `search`：按文件名或 16 位图片 ID 包含匹配，最多 200 字节，区分大小写。
- `page`：页码，默认 1，范围 1–100000。
- `per_page`：每页数量，默认 24，范围 1–50。
- `usage`：`background`、`logo`、`favicon`；提供时返回尺寸是否合适及原因。不会只筛选合适图片。

返回 `data.items`、`data.page`、`data.per_page`、`data.has_more`。每项包含 `imgid`、`name`、`created_at`、`available`、`url`、`thumbnail_url`、`width`、`height`、`mime`、`size_bytes`；`usage` 对应 `eligible`、`reason`。图片尺寸和格式来自实际文件，不直接信任数据库尺寸。原文件缺失或无效时 `available` 为 false，图片地址和尺寸为 null。地址为相对站点根路径；`created_at` 沿用原站点的上传日期格式及服务器时区。

此接口不返回上传者 IP、删除密钥或删除链接。`eligible` 表示格式、文件大小和尺寸适用，保存时还会再次校验图片内容可解码性。

查询单张图片：

```sh
curl --fail-with-body \
  -H "Authorization: Bearer $IMGURL_API_TOKEN" \
  "$IMGURL_BASE_URL/api/v1/images/0123456789abcdef"
```

单张接口的 `data` 直接为图片对象。图片不存在或标记为 adult 时返回 404。

## 查看与修改外观

```sh
curl --fail-with-body \
  -H "Authorization: Bearer $IMGURL_API_TOKEN" \
  "$IMGURL_BASE_URL/api/v1/settings/appearance"
```

GET 需要 `appearance:read`，只返回 `background`、`logo`、`favicon` 以及 `requirements`。默认背景的值为 null，默认 Logo 和图标为原站点静态文件地址。不会返回统计代码、账号或其他站点配置。

```sh
curl --fail-with-body -X PATCH \
  -H "Authorization: Bearer $IMGURL_API_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"background":"0123456789abcdef","favicon":"fedcba9876543210"}' \
  "$IMGURL_BASE_URL/api/v1/settings/appearance"
```

PATCH 需要 `appearance:write`，正文必须为非空 JSON 对象，最多 16 KB。仅支持 `background`、`logo`、`favicon`，值为已上传图片的 16 位 `imgid` 或字符串 `default`。未提供的字段保持原设置，网站标题、描述、统计代码等配置均保留。

```sh
curl --fail-with-body -X PATCH \
  -H "Authorization: Bearer $IMGURL_API_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"background":"default","logo":"default","favicon":"default"}' \
  "$IMGURL_BASE_URL/api/v1/settings/appearance"
```

保存使用与后台表单相同的校验、共享文件锁及独立副本机制。尺寸不符、图片已删除、文件损坏或数据库保存失败时，保留原配置并清理本次新增副本。图标输出 256 × 256 PNG。

## 返回值与限速

成功为 `{"ok":true,"data":...}`，错误为：

```json
{"ok":false,"error":{"code":"insufficient_scope","message":"该令牌没有所需权限。"}}
```

| HTTP 状态 | 含义 |
| --- | --- |
| 400 | 参数、JSON 格式或字段错误 |
| 401 | 缺少、无效、过期、已撤销或因账号变更失效的令牌 |
| 403 | 令牌权限不足 |
| 404 | 图片或接口不存在 |
| 405 | 方法不支持，`Allow` 响应头列出支持的方法 |
| 413 | 正文超过 16 KB |
| 415 | PATCH 正文不是 `application/json` |
| 422 | 所选图片不符合外观要求 |
| 429 | 超出请求限额，按 `Retry-After` 秒数稍后重试 |
| 503 | 站点未就绪、数据库或存储暂时不可用 |

限速按固定自然分钟计数：每个客户端 IP 每分钟 300 次，每个有效令牌每分钟 120 次。并发请求使用文件锁计数，返回值不包含令牌。限速状态保存在受保护的 `data/.api_rate/`。所有 v1 响应使用 `Cache-Control: no-store`，没有开放跨域管理请求。

## 部署与验证

现有站点直接更新代码，无需重新安装或修改数据库结构。令牌摘要保存在现有 SQLite 数据库的 `api_tokens` 配置项中；请保留 `data/imgurl.db3`、上传图片及外观副本。

Nginx + PHP-FPM 环境请确保入口脚本的 FastCGI 配置转发 Authorization：

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

保留原有 `/data/` 禁止访问规则，确保 PHP 可以写入 `data/`、`imgs/` 以及数据库文件。若已有可信反向代理，沿用 `proxy_ips` 配置，以便限速识别真实客户端 IP。Apache 通过仓库 `.htaccess` 转发 Authorization。

更新后，先使用健康检查及只读令牌验证生产环境。上传、外观修改及失败回滚测试在独立测试站点执行：

```sh
python3 tests/security_smoke.py --php php
node tests/csrf.test.js
```

部署脚本也可使用只读检查工具：

```sh
python3 scripts/api_check.py --base-url "$IMGURL_BASE_URL"
# IMGURL_API_TOKEN 需要 health:read、images:read 和 appearance:read
python3 scripts/api_check.py --base-url "$IMGURL_BASE_URL" --images --appearance
```

OpenAPI 3.0 文档位于 `/static/openapi.json`，可导入支持 OpenAPI 的客户端。
