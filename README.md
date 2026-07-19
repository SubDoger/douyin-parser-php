# PHP 抖音无水印作品解析

面向 PHP 8.1 的无框架本地工具，支持粘贴抖音短链、视频/图文页链接或完整分享口令。视频可按清晰度和编码选择无水印 MP4，图文作品可将全部图片打包为 ZIP 下载到本地。

## 环境

- PHP 8.1+
- PHP 扩展：`curl`、`dom`、`json`、`mbstring`、`fileinfo`、`openssl`、`zip`
- 不需要 Composer、Python 或 Node.js

检查环境：

```bash
php -v
php -m | grep -E 'curl|dom|json|mbstring|fileinfo|openssl|zip'
```

## 启动

```bash
php -S 127.0.0.1:8000 router.php
```

打开 [http://127.0.0.1:8000](http://127.0.0.1:8000) 即可使用可视化解析页面。

## API

### 解析

```bash
curl http://127.0.0.1:8000/api/parse \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://v.douyin.com/SHARE_CODE/"}'
```

视频结果的 `formats` 数组列出可用清晰度和编码。每项包含稳定的 `id`、显示名称 `label`、`quality`、`codec`、分辨率、码率、预计大小及 `media_url`；顶层 `format_id` 是默认格式。图文结果的 `content_type` 为 `album`，原图资源位于 `images`。

解析器会根据作品链接依次尝试对应的视频、图文分享页及网页回退策略，并兼容 `_ROUTER_DATA`、`_SSR_DATA`、`RENDER_DATA` 和 universal hydration 数据；实际命中的策略由 `parser_strategy` 返回。

流式解析端点会逐行返回 NDJSON 进度事件：

```bash
curl -N http://127.0.0.1:8000/api/parse/stream \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://v.douyin.com/SHARE_CODE/"}'
```

### 下载

```bash
curl http://127.0.0.1:8000/api/download \
  -H 'Content-Type: application/json' \
  -d '{
    "url":"https://v.douyin.com/SHARE_CODE/",
    "filename":"my-video",
    "format_id":"FORMAT_ID_FROM_PARSE",
    "overwrite":false
  }'
```

`format_id` 应取自同一作品解析结果的 `formats[].id`；省略时使用默认格式。服务端下载前会重新解析并匹配该格式，不接受客户端传入媒体直链。

图文作品无需传 `format_id`，服务端会下载全部图片并打包为 ZIP。文件默认保存到 `downloads/`，响应中的 `file_url` 可直接下载已保存的 MP4 或 ZIP。

可视化页面使用 `/api/download/stream` 实时接收下载字节数、总大小和百分比；该端点的请求字段与 `/api/download` 相同。

### 其他端点

| 端点 | 方法 | 用途 |
| --- | --- | --- |
| `/api/health` | `GET` | 运行状态和 PHP 版本 |
| `/api/parse/stream` | `POST` | NDJSON 流式解析进度 |
| `/api/download/stream` | `POST` | NDJSON 流式下载进度 |
| `/api/files` | `GET` | 本地 MP4/ZIP 文件列表 |
| `/files/{filename}` | `GET` | 下载本地文件 |

## 配置

| 环境变量 | 默认值 | 用途 |
| --- | --- | --- |
| `DOUYIN_DOWNLOAD_DIR` | `./downloads` | MP4/ZIP 保存目录 |
| `DOUYIN_COOKIES_FILE` | 空 | Netscape 格式 Cookie 文件 |
| `DOUYIN_PROXY` | 空 | HTTP/SOCKS 代理地址 |
| `DOUYIN_TIMEOUT` | `20` | 上游请求超时秒数 |
| `DOUYIN_MEDIA_TIMEOUT` | `900` | 媒体下载总超时秒数 |
| `DOUYIN_MAX_FILESIZE_MB` | `500` | 单个作品下载最大大小 |
| `DOUYIN_MAX_STORAGE_MB` | `5000` | 下载目录 MP4/ZIP 总容量上限 |
| `APP_ALLOW_REMOTE` | `false` | 是否允许非回环地址访问 |
| `APP_TIMEZONE` | `Asia/Shanghai` | 文件时间时区 |

示例：

```bash
export DOUYIN_DOWNLOAD_DIR="$PWD/downloads"
export DOUYIN_MAX_FILESIZE_MB=300
php -S 127.0.0.1:8000 router.php
```

应用默认拒绝非本机请求。仅在前置反向代理已提供身份验证和限速时，才应设置 `APP_ALLOW_REMOTE=true`。

## 测试

```bash
php tests/php/run.php
find . -path './.venv' -prune -o -name '*.php' -type f -print0 | xargs -0 -n1 php -l
```

解析器会验证输入域名、限制重定向，并在原子落盘前检查响应类型和文件大小；视频额外校验 MP4 `ftyp` 文件头，图文压缩包校验 ZIP 内容及图片数量。

## 开源协议

本项目采用 [MIT License](LICENSE)。发布表单所需的项目介绍和建仓说明见 [PUBLISHING.md](PUBLISHING.md)。
