# 更新日志（寒阳图床 · 相对上游 LightPicture 的改动）

> 本项目 Fork 自 [osuuu/LightPicture](https://github.com/osuuu/LightPicture)，
> 上游版权归原作者所有。以下列出本分支的全部改动。

## 2026-11-26 — 首个二开版本

### 运行环境适配（PHP 8.5）

上游仅标称支持 PHP 7.2~7.4，本分支适配 PHP 8.5.13 并线上验证通过：

- `public/install/index.php`：移除安装器 `phpversion() > '7.6'` 的版本上限硬检查；
  `mysqli_connect_errno($conn)` 改为无参调用（PHP 8.5 Fatal 修复）。
- `vendor/topthink/framework/src/think/initializer/Error.php`：
  `error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED)`，
  避免 ThinkPHP 把 PHP 8 的 Deprecated 强制转成异常。
- `app/services/AuthToken.php`：`createToken()` 中 `$token = []` 初始化，
  修复「Trying to access array offset on null」。
- `app/BaseController.php`：`city()` 增加 `is_string()` / `is_array()` 类型保护，
  修复 curl 失败返回 false 时的类型错误。
- 环境依赖：补装 PHP fileinfo 扩展。

### 功能增强

- **上传自动转 WebP**（`app/services/UploadCLass.php::toWebp()` + `app/controller/Api.php`）
  - jpg/png 按真实 mime 判定并压缩为 WebP（质量 82、保留 PNG 透明通道）；
  - GIF / 已是 WebP / 超过 24MP 跳过；转换后变大或失败自动回退原图；
  - 转换产物覆盖回原临时文件，规避 `move_uploaded_file` 对非上传文件的拒绝；
  - 一处入口生效，本地 / OSS / COS / 七牛 / 又拍 / 华为 / S3 全部覆盖。
  - 实测：jpg 1.9MB→275KB（-86%），png 612KB→45KB（-93%）。

- **AWS S3 兼容协议存储**（`app/services/S3Client.php` 新增，零第三方依赖）
  - 手写 AWS Signature V4 签名（原生 curl + hash_hmac）；
  - 兼容 AWS S3 / Cloudflare R2 / MinIO / Backblaze B2；
  - 仅填 API 端点 / Access Key / Secret Key 三项，bucket 与 region 从端点自动解析；
  - `UploadCLass` 增加 s3 上传/删除分支；`Storage::type()` 增加选项；
  - 前端存储表单对 s3 只显示三字段。

### 问题修复

- `app/validate/User.php` + `app/controller/Member.php`：
  新增 `edit` 验证场景，编辑成员时 email 只做必填校验，
  修复安装器把根管理员 email 设为 `admin`（非邮箱格式）导致编辑报「邮箱规则错误」。
- `app/controller/Member.php`：
  新增/恢复成员时写入真实 `create_time`（原代码未写，时间为 0）。
- `public/static/js/chunk-b8669f6c.*.js`：
  团队成员「已用容量」从写死 GB 改为自适应单位（B/KB/MB/GB/TB），
  修复小容量显示 `0.00 G` 的问题。

### 品牌

- 产品名改为「寒阳图床」，替换品牌图标（favicon / apple-touch-icon / logo.png）。
- 前端显示文字品牌化；**保留上游 GPL 致谢、原作者邮箱与仓库链接**。
- 上传目录常量 `FOLDER`（`LightPicture/`）保持不变，以确保存量图片链接不失效。

### 合规

- 新增 `README.md` 顶部 Fork 声明，明确上游作者与版权。
- 新增 `CHANGELOG.md`（本文件）。
- `LICENSE` 采用 GPL-3.0（与上游 README 声明一致）；
  上游框架自带的 `LICENSE.upstream-thinkphp.txt`（Apache 2.0）保留备查。
- 仓库不含 `.env`、数据库密码、运行日志、用户上传图片等敏感信息；
  提供 `.env.example` 配置模板。
