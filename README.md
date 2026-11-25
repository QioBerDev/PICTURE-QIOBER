<p align="center">
  <img src="logo.png" alt="寒阳图床" width="180" />
</p>

<h1 align="center">寒阳图床</h1>

<p align="center">企业 / 团队 / 个人图片资源管理系统 · PHP 8.5 可用分支</p>

---

> ## ⚠️ 上游声明（Fork Notice）
>
> **本项目 Fork 自 [LightPicture](https://github.com/osuuu/LightPicture)，原作者 osuuu（Team），版权归原作者所有。**
> 本分支在尊重原作的基础上做了二开适配与功能增强，界面与核心架构沿用上游。
> 如果你喜欢这个系统，请给原仓库一个 Star。
>
> 上游许可：GPL-3.0 · Copyright (c) 2022 LightPicture (http://picture.h234.cn)

---

## 本分支相对上游的改动（CHANGELOG）

| 类别 | 改动内容 |
| --- | --- |
| **运行环境** | 适配 **PHP 8.5.13**（上游仅标称支持 PHP ≤7.4）：修复安装器版本硬检查、ThinkPHP 错误处理器接管 Deprecated、AuthToken 数组未初始化、`city()` 类型错误；补装 fileinfo 扩展 |
| **上传优化** | 上传时 jpg/png **自动压缩为 WebP**（质量 82、保透明、变大/失败回退、GIF 与超大图跳过），一处生效覆盖全部存储类型 |
| **对象存储** | 新增 **AWS S3 兼容协议**存储（基于 AWS Signature V4，零第三方依赖，兼容 AWS S3 / Cloudflare R2 / MinIO / Backblaze B2），仅需 API 端点 / Access Key / Secret Key 三项 |
| **问题修复** | 修复编辑成员时非邮箱格式账号（如 `admin`）误报「邮箱规则错误」；新增成员写入真实创建时间；团队成员「已用容量」改自适应单位显示 |
| **品牌** | 更名为「寒阳图床」，替换品牌图标 |

详细技术实现见本仓库文档与提交记录。

---

## 程序功能（继承自上游）

- 支持第三方云储存：本地、阿里云 OSS、腾讯云 COS、七牛云 KODO、又拍云 USS、华为云 OBS、**S3 兼容协议**
- 支持多桶储存，可同时添加多个对象存储桶管理，适合团队多桶协作
- 多图上传、拖拽上传、粘贴上传、上传预览、全屏预览、一键复制图片外链
- **上传自动转 WebP**，节省存储与带宽
- 多用户管理、分组管理；不同分组用户控制不同的存储桶
- 完整的权限控制功能，不同用户组可分配不同的操作权限
- 完整的可视化日志功能，记录用户所有操作，方便事件溯源
- 全局配置用户初始剩余储存空间、设置指定用户剩余储存空间
- 支持接口上传、接口删除
- 原创 Geek 扁平化页面风格，高性能 / 精致 / 优雅 / 简洁而不简单

## 环境要求

- PHP >= 7.2（**推荐并已验证 PHP 8.5**；需 fileinfo / curl / PDO / ZipArchive 扩展，GD 支持 WebP）
- MySQL >= 5.5

## 安装教程

1. 下载本仓库，上传至 Web 运行环境并解压。
2. 设置运行目录为 `public`。
3. 配置网站默认文档：`index.html`、`index.php`。
4. 配置 Rewrite 规则为 thinkphp：

**Apache**
```apache
<IfModule mod_rewrite.c>
  Options +FollowSymlinks -Multiviews
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^(.*)$ index.php/$1 [QSA,PT,L]
</IfModule>
```

**Nginx**
```nginx
location / {
   if (!-e $request_filename) {
        rewrite  ^(.*)$  /index.php?s=/$1  last;
   }
}
```

5. 复制 `.env.example` 为 `.env`，按实际环境填写数据库配置。
6. 访问 `域名/install`，按页面提示安装。
7. 安装完成后默认账号 `admin`，密码 `123456`（**请立即修改**）。

注：若开启前台注册，请登录管理后台配置发信邮箱。

## S3 存储填写说明

在「存储管理 → 新建桶 → 存储源」选择「AWS S3」，填写三项：

- **API 端点**：`{服务地址}/{bucket}`，例如
  - AWS：`https://s3.us-east-1.amazonaws.com/mybucket`
  - Cloudflare R2：`https://<account-id>.r2.cloudflarestorage.com/mybucket`
  - MinIO：`https://minio.example.com:9000/mybucket`
  - Backblaze B2：`https://s3.us-west-004.backblazeb2.com/mybucket`
- **Access Key**
- **Secret Key**

Region 会从端点自动推断（R2 自动识别为 `auto`）。

## 鸣谢

- [LightPicture](https://github.com/osuuu/LightPicture) 及原作者 osuuu（上游项目）
- ThinkPHP、Vue、iviewUI、Iconfont、viewer.js

## 开源许可

本项目遵循 **GPL-3.0** 开源许可，详见 [LICENSE](LICENSE)。

```
Copyright (c) 2022 LightPicture (http://picture.h234.cn)   # 上游原作
Copyright (c) 2026 寒阳图床 (QioBerDev)                      # 本二开分支
```
