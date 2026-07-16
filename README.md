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
* PHP >= 5.6
* PDO_SQLite
* GD2
* ImageMagick
* fileinfo
* pathinfo

### 后台登录安全

后台登录默认启用一次性图片验证码，并按客户端 IP 限制失败尝试：15 分钟内失败 5 次后暂停登录 15 分钟。验证码错误和密码错误都会计入限制，相关参数可在 `application/config/login_security.php` 中调整。

限速状态和验证码签名密钥保存在可写的 `data/.login_security` 目录中。若站点位于 Nginx、CDN 等反向代理后，请在 `application/config/config.php` 的 `proxy_ips` 中仅配置可信代理地址，否则应用无法可靠识别真实客户端 IP。


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
