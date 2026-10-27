# ImgURL
ImgURL是一款简单、纯粹的图床程序，使用PHP + SQLite 3开发。

![](https://i.bmp.ovh/imgs/2018/12/06cf0ac3b7625b6b.png)

![](https://i.bmp.ovh/imgs/2018/12/f0b565e2e0ffa166.png)

![](https://i.bmp.ovh/imgs/2018/12/017c5e66b53db4d1.png)

### 主要功能
- [x] 支持拽拖上传、多图上传、Ctrl + V粘贴上传、URL上传
- [x] 支持图片裁剪，自动生成缩略图
- [x] 限制访客上传数量
- [x] API支持
- [ ] 在线更新
- [ ] 外部存储

### 环境要求
* PHP >= 8.2
* PDO_SQLite
* GD2
* cURL、mbstring
* ImageMagick（可选；缩略图使用 GD）
* fileinfo


### PHP 8.2 升级

请先阅读 [升级与部署说明](docs/php82-upgrade.md)。旧版本升级后需要重新登录；旧密码会在成功登录时自动升级。后台写操作已增加 CSRF 校验，公开上传 API 保持兼容。

### 后台登录安全

后台登录默认启用一次性图片验证码，并按客户端 IP 限制失败尝试：15 分钟内失败 5 次后暂停登录 15 分钟。验证码错误和密码错误都会计入限制。管理员可在“系统设置 → 登录安全”中调整参数，代码中的默认值位于 `application/config/login_security.php`。

限速状态和验证码签名密钥保存在可写的 `data/.login_security` 目录中。若站点位于 Nginx、CDN 等反向代理后，请在 `application/config/config.php` 的 `proxy_ips` 中仅配置可信代理地址，否则应用无法可靠识别真实客户端 IP。


### 网站外观个性化

登录后台，进入“系统设置 → 站点设置 → 网站外观”，即可从已上传的图片中选择首页背景、网站 Logo 和网站图标（favicon）。图片库支持按文件名或图片 ID 搜索、分页、预览和尺寸提示。新照片请先从首页上传，再回到设置选择；最后点击“保存”生效。每项均可恢复默认。

| 用途 | 尺寸要求 | 建议 |
| --- | --- | --- |
| 首页背景 | 至少 1280 × 720 像素 | 1920 × 1080 或更大的横向照片；手机端居中裁切，仅首页应用 |
| 网站 Logo | 宽 64–4096、高 32–4096 像素，宽高比 1:4 至 8:1 | 透明 PNG；在导航栏内保持比例显示 |
| 网站图标 | 64–2048 像素的正方形 | 256 × 256；自动生成保留透明度的 256 × 256 PNG |

支持 PNG、JPG、WebP、GIF，单张不超过 10 MB，并沿用上传图片的 2000 万像素上限。保存时根据原文件重新检查格式、真实尺寸和可解码性，不能通过修改浏览器参数绕过检查。GIF 用作图标时取静态帧。

外观副本存放在 `imgs/branding/`，不依赖图片库中的原图；删除原图后仍可正常显示。更换或恢复默认会清理不再使用的外观副本。请将此目录随站点上传文件一同备份，并确保 PHP 对 `imgs/` 目录有写权限。现有站点无需修改数据库结构，旧 Logo 设置保留；图标使用内容指纹地址，更换后自动更新缓存。

### API v1 与访问令牌

后台“系统设置 → API 令牌”可创建有有效期、按权限限制且可撤销的 Bearer Token。API v1 提供健康检查、图片搜索 / 分页 / 详情，以及首页背景、Logo、图标的查询和修改。现有公开上传 API 保持兼容。

详见 [API 使用说明](data/pages/api.md)，安装后也可访问 `/page/api`；机器可读文档为 [OpenAPI 3.0](static/openapi.json)。部署只读验收可运行 `python3 scripts/api_check.py --base-url https://你的域名`。Nginx + PHP-FPM 请确认转发 `Authorization` 请求头，并保留 `data/` 目录访问保护。无需更改现有数据库结构。

### 鸣谢

ImgURL Lite的诞生离不开以下项目，在此表示感谢。

* [ImgURL]
* [LayUI](https://github.com/sentsin/layui)
* [CodeIgniter](https://github.com/bcit-ci/CodeIgniter)
* [clipBoard.js](https://github.com/baixuexiyang/clipBoard.js)
* [Parsedown](https://github.com/erusev/parsedown)
* [jQuery](https://github.com/jquery/jquery)
* [tinypng](https://tinypng.com/)
* [ModerateContent](https://www.moderatecontent.com/)
