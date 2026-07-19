# 开源项目发布资料

## 表单字段

| 字段 | 填写内容 |
| --- | --- |
| 项目名称 | PHP 抖音无水印作品解析器 |
| 系统类型 | Web |
| 行业分类 | 项目任务、开发工具 |
| 授权协议 | MIT License |
| 开源组织 | 个人开源项目（该项可留空） |

## 功能介绍

本项目是一款基于 PHP 8.1+ 的可视化抖音作品解析与本地下载工具，支持抖音短链、视频页、图文页及完整分享口令。解析过程通过 NDJSON 流式展示实时进度，可识别多种页面数据并自动切换回退策略。视频结果支持按清晰度、分辨率以及 H.264、H.265、AV1 编码选择无水印 MP4；图文作品支持原图预览，并将全部图片打包为 ZIP。下载阶段实时显示字节数、总大小和百分比，同时提供文件名设置、同名覆盖控制和本地文件管理。项目无框架、无需 Composer，部署简单，适合接口联调、开发学习及本地媒体整理。

字数：256 个中文及标点字符，满足表单 80-800 字要求。

## 开源地址

GitHub 公开仓库：

`https://github.com/SubDoger/douyin-parser-php`

## 建仓命令

首次在本地关联并推送该仓库时执行：

```bash
git init
git add README.md LICENSE PUBLISHING.md .gitignore bootstrap.php router.php public src views tests
git status --short
git commit -m "Initial open source release"
git branch -M main
git remote add origin https://github.com/SubDoger/douyin-parser-php.git
git push -u origin main
```

上面的显式文件列表只提交 PHP 应用，避免把本地下载文件、虚拟环境或其他工作目录一并上传。提交前检查 `git status --short` 的输出。

推送后先在未登录窗口打开仓库地址，确认 README、源码和 `LICENSE` 均可访问，再填写发布表单。

## 示例图片建议

1. 首页与流式解析进度界面。
2. 解析结果、清晰度/编码选择及下载完成界面。

上传前避免让截图包含本地绝对路径、Cookie、代理地址或其他私密配置。
