<?php
$assetVersion = max(
    (int) filemtime(dirname(__DIR__) . '/public/assets/app.css'),
    (int) filemtime(dirname(__DIR__) . '/public/assets/app.js'),
    (int) filemtime(dirname(__DIR__) . '/public/assets/favicon.svg'),
);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#ffffff">
    <title>抖音视频解析</title>
    <link rel="icon" href="/assets/favicon.svg?v=<?= $assetVersion ?>" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css?v=<?= $assetVersion ?>">
    <script src="/assets/app.js?v=<?= $assetVersion ?>" defer></script>
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M15 4v10.25a4.25 4.25 0 1 1-3-4.06V7.5c3.2 2.4 5.7 2.75 7 2.75V7.4C17.1 7.2 16 6 15 4Z"/></svg>
            </span>
            <div>
                <h1>抖音视频解析</h1>
                <div class="runtime-status"><span></span> PHP 8.1+</div>
            </div>
        </div>
        <a class="header-action" href="#local-files">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h5l2 2h11v10H3Z"/><path d="M3 7V5h6l2 2"/></svg>
            本地文件
        </a>
    </header>

    <main class="main-content">
        <div class="workspace">
            <section class="tool-panel" aria-labelledby="parser-heading">
                <div class="panel-heading">
                    <div>
                        <span class="section-index">01</span>
                        <h2 id="parser-heading">解析任务</h2>
                    </div>
                    <span id="api-status" class="api-status"><span></span> 检查中</span>
                </div>

                <form id="parse-form" novalidate>
                    <label class="field-label" for="share-input">分享链接或口令</label>
                    <textarea
                        id="share-input"
                        name="url"
                        rows="6"
                        maxlength="4096"
                        required
                        spellcheck="false"
                        placeholder="https://v.douyin.com/..."
                    ></textarea>

                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="filename-input">文件名 <span>可选</span></label>
                            <div class="input-suffix">
                                <input id="filename-input" name="filename" maxlength="120" placeholder="自动命名">
                                <span id="filename-extension">.mp4</span>
                            </div>
                        </div>
                        <label class="toggle-row" for="overwrite-input">
                            <span>覆盖同名文件</span>
                            <input id="overwrite-input" name="overwrite" type="checkbox">
                            <span class="toggle" aria-hidden="true"></span>
                        </label>
                    </div>

                    <button id="parse-button" class="button button-primary" type="submit">
                        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                        <span class="button-label">解析视频</span>
                    </button>

                    <div id="parse-progress" class="stream-progress" hidden>
                        <div class="progress-heading">
                            <span id="parse-progress-label">准备解析</span>
                            <strong id="parse-progress-percent">0%</strong>
                        </div>
                        <div
                            id="parse-progress-track"
                            class="progress-track"
                            role="progressbar"
                            aria-label="解析进度"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="0"
                        ><span id="parse-progress-bar" class="progress-bar"></span></div>
                        <div id="parse-progress-detail" class="progress-detail">等待服务器响应</div>
                    </div>
                </form>
            </section>

            <section id="result-panel" class="result-panel" aria-labelledby="result-heading" aria-live="polite">
                <div class="panel-heading">
                    <div>
                        <span class="section-index">02</span>
                        <h2 id="result-heading">解析结果</h2>
                    </div>
                    <span id="result-state" class="result-state">待处理</span>
                </div>

                <div id="result-empty" class="result-empty">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    <strong>等待解析结果</strong>
                    <span>尚未加载视频</span>
                </div>

                <div id="result-content" class="result-content" hidden>
                    <div class="cover-frame">
                        <img id="video-cover" alt="视频封面" referrerpolicy="no-referrer">
                        <span id="video-duration" class="duration-label"></span>
                    </div>

                    <div class="video-details">
                        <h3 id="video-title"></h3>
                        <div class="author-line">
                            <img id="author-avatar" alt="" referrerpolicy="no-referrer">
                            <span id="author-name"></span>
                        </div>
                        <dl class="metadata-grid">
                            <div><dt>作品 ID</dt><dd id="video-id"></dd></div>
                            <div><dt>分辨率</dt><dd id="video-resolution"></dd></div>
                            <div><dt>容器</dt><dd id="video-format"></dd></div>
                        </dl>
                        <div id="format-selector-group" class="format-selector" hidden>
                            <label for="format-select">清晰度与编码</label>
                            <select id="format-select"></select>
                            <div id="format-details" class="format-details"></div>
                        </div>
                    </div>

                    <div id="album-preview" class="album-preview" hidden>
                        <div class="album-preview-heading">
                            <span>图片预览</span>
                            <strong id="album-count"></strong>
                        </div>
                        <div id="album-thumbnails" class="album-thumbnails"></div>
                    </div>

                    <div class="result-actions">
                        <button id="download-button" class="button button-primary" type="button">
                            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
                            <span class="button-label">下载 MP4</span>
                        </button>
                        <button id="copy-button" class="button button-secondary" type="button">
                            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="1"/><path d="M16 8V4H4v12h4"/></svg>
                            <span id="copy-button-label">复制直链</span>
                        </button>
                        <a id="webpage-link" class="icon-button" target="_blank" rel="noopener noreferrer" title="打开抖音原页">
                            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 3h6v6"/><path d="m10 14 11-11"/><path d="M18 13v7H4V6h7"/></svg>
                            <span class="sr-only">打开抖音原页</span>
                        </a>
                    </div>
                    <div id="download-progress" class="stream-progress stream-progress-download" hidden>
                        <div class="progress-heading">
                            <span id="download-progress-label">准备下载</span>
                            <strong id="download-progress-percent">0%</strong>
                        </div>
                        <div
                            id="download-progress-track"
                            class="progress-track"
                            role="progressbar"
                            aria-label="下载进度"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow="0"
                        ><span id="download-progress-bar" class="progress-bar"></span></div>
                        <div id="download-progress-detail" class="progress-detail">等待媒体连接</div>
                    </div>
                    <div id="download-status" class="download-status" hidden></div>
                </div>
            </section>
        </div>

        <section id="local-files" class="files-section" aria-labelledby="files-heading">
            <div class="files-header">
                <div>
                    <span class="section-index">03</span>
                    <h2 id="files-heading">本地文件</h2>
                    <span id="file-count" class="file-count">0 个文件</span>
                </div>
                <button id="refresh-files" class="icon-button" type="button" title="刷新文件列表">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M18.5 9A7 7 0 0 0 6 6.5L4 11"/><path d="M5.5 15A7 7 0 0 0 18 17.5l2-4.5"/></svg>
                    <span class="sr-only">刷新文件列表</span>
                </button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>文件名</th><th>大小</th><th>更新时间</th><th><span class="sr-only">操作</span></th></tr></thead>
                    <tbody id="files-body"></tbody>
                </table>
                <div id="files-empty" class="files-empty">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h5l2 2h11v10H3Z"/><path d="M3 7V5h6l2 2"/></svg>
                    <span>暂无本地视频</span>
                </div>
            </div>
        </section>
    </main>

    <footer class="app-footer">
        <span>存储目录</span>
        <code>downloads/</code>
    </footer>
</div>

<div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
</body>
</html>
