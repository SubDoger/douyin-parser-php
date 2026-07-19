(() => {
    'use strict';

    const state = {
        source: '',
        video: null,
        selectedFormat: null,
        toastTimer: null,
    };

    const elements = {
        form: document.querySelector('#parse-form'),
        shareInput: document.querySelector('#share-input'),
        filenameInput: document.querySelector('#filename-input'),
        overwriteInput: document.querySelector('#overwrite-input'),
        filenameExtension: document.querySelector('#filename-extension'),
        parseButton: document.querySelector('#parse-button'),
        parseProgress: document.querySelector('#parse-progress'),
        parseProgressLabel: document.querySelector('#parse-progress-label'),
        parseProgressPercent: document.querySelector('#parse-progress-percent'),
        parseProgressTrack: document.querySelector('#parse-progress-track'),
        parseProgressBar: document.querySelector('#parse-progress-bar'),
        parseProgressDetail: document.querySelector('#parse-progress-detail'),
        resultPanel: document.querySelector('#result-panel'),
        resultState: document.querySelector('#result-state'),
        resultEmpty: document.querySelector('#result-empty'),
        resultContent: document.querySelector('#result-content'),
        cover: document.querySelector('#video-cover'),
        duration: document.querySelector('#video-duration'),
        title: document.querySelector('#video-title'),
        authorAvatar: document.querySelector('#author-avatar'),
        authorName: document.querySelector('#author-name'),
        videoId: document.querySelector('#video-id'),
        resolution: document.querySelector('#video-resolution'),
        format: document.querySelector('#video-format'),
        formatSelectorGroup: document.querySelector('#format-selector-group'),
        formatSelect: document.querySelector('#format-select'),
        formatDetails: document.querySelector('#format-details'),
        albumPreview: document.querySelector('#album-preview'),
        albumCount: document.querySelector('#album-count'),
        albumThumbnails: document.querySelector('#album-thumbnails'),
        webpageLink: document.querySelector('#webpage-link'),
        copyButton: document.querySelector('#copy-button'),
        copyButtonLabel: document.querySelector('#copy-button-label'),
        downloadButton: document.querySelector('#download-button'),
        downloadProgress: document.querySelector('#download-progress'),
        downloadProgressLabel: document.querySelector('#download-progress-label'),
        downloadProgressPercent: document.querySelector('#download-progress-percent'),
        downloadProgressTrack: document.querySelector('#download-progress-track'),
        downloadProgressBar: document.querySelector('#download-progress-bar'),
        downloadProgressDetail: document.querySelector('#download-progress-detail'),
        downloadStatus: document.querySelector('#download-status'),
        apiStatus: document.querySelector('#api-status'),
        refreshFiles: document.querySelector('#refresh-files'),
        filesBody: document.querySelector('#files-body'),
        filesEmpty: document.querySelector('#files-empty'),
        fileCount: document.querySelector('#file-count'),
        toast: document.querySelector('#toast'),
    };

    async function request(path, options = {}) {
        const response = await fetch(path, options);
        const contentType = response.headers.get('content-type') || '';
        let payload = null;
        if (contentType.includes('application/json')) {
            payload = await response.json();
        }
        if (!response.ok) {
            throw new Error(payload?.detail || `HTTP ${response.status}`);
        }
        return payload;
    }

    async function streamRequest(path, payload, onProgress) {
        const response = await fetch(path, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
        });
        if (!response.ok) {
            const errorPayload = await response.json().catch(() => null);
            throw new Error(errorPayload?.detail || `HTTP ${response.status}`);
        }
        if (!response.body) {
            throw new Error('当前浏览器不支持流式响应');
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let result = null;

        const processLine = (line) => {
            const trimmed = line.trim();
            if (!trimmed) return;
            let event;
            try {
                event = JSON.parse(trimmed);
            } catch {
                throw new Error('服务器返回了无效的进度流');
            }
            if (event.type === 'progress') {
                onProgress(event);
            } else if (event.type === 'error') {
                throw new Error(event.detail || '流式任务失败');
            } else if (event.type === 'result') {
                result = event.data;
            }
        };

        try {
            while (true) {
                const {done, value} = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, {stream: true});
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';
                lines.forEach(processLine);
            }
            buffer += decoder.decode();
            processLine(buffer);
        } finally {
            if (result === null) {
                await reader.cancel().catch(() => {});
            }
            reader.releaseLock();
        }

        if (result === null) {
            throw new Error('进度流结束但未返回结果');
        }
        return result;
    }

    function setLoading(button, loading, loadingLabel, normalLabel) {
        const label = button.querySelector('.button-label');
        button.disabled = loading;
        button.classList.toggle('is-loading', loading);
        if (label) {
            label.textContent = loading ? loadingLabel : normalLabel;
        }
    }

    function showToast(message, error = false) {
        clearTimeout(state.toastTimer);
        elements.toast.textContent = message;
        elements.toast.classList.toggle('is-error', error);
        elements.toast.hidden = false;
        state.toastTimer = window.setTimeout(() => {
            elements.toast.hidden = true;
        }, 4200);
    }

    function formatDuration(value) {
        if (value === null || value === undefined || !Number.isFinite(Number(value))) {
            return null;
        }
        const total = Math.max(0, Math.round(Number(value)));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = total % 60;
        if (hours > 0) {
            return `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }
        return `${minutes}:${String(seconds).padStart(2, '0')}`;
    }

    function formatBytes(value) {
        const bytes = Number(value) || 0;
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
        if (bytes < 1024 ** 3) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
        return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
    }

    function progressElements(kind) {
        if (kind === 'parse') {
            return {
                container: elements.parseProgress,
                label: elements.parseProgressLabel,
                percent: elements.parseProgressPercent,
                track: elements.parseProgressTrack,
                bar: elements.parseProgressBar,
                detail: elements.parseProgressDetail,
            };
        }
        return {
            container: elements.downloadProgress,
            label: elements.downloadProgressLabel,
            percent: elements.downloadProgressPercent,
            track: elements.downloadProgressTrack,
            bar: elements.downloadProgressBar,
            detail: elements.downloadProgressDetail,
        };
    }

    function resetProgress(kind, message) {
        const ui = progressElements(kind);
        ui.container.hidden = false;
        ui.container.classList.remove('is-complete', 'is-error');
        ui.track.classList.remove('is-indeterminate');
        ui.track.setAttribute('aria-valuenow', '0');
        ui.bar.style.width = '0%';
        ui.label.textContent = message;
        ui.percent.textContent = '0%';
        ui.detail.textContent = '正在建立流式连接';
    }

    function updateProgress(kind, event) {
        const ui = progressElements(kind);
        ui.container.hidden = false;
        ui.label.textContent = event.message || '任务处理中';

        const hasPercent = event.percent !== null
            && event.percent !== undefined
            && Number.isFinite(Number(event.percent));
        if (hasPercent) {
            const percent = Math.max(0, Math.min(100, Math.round(Number(event.percent))));
            ui.track.classList.remove('is-indeterminate');
            ui.track.setAttribute('aria-valuenow', String(percent));
            ui.bar.style.width = `${percent}%`;
            ui.percent.textContent = `${percent}%`;
            if (percent === 100) {
                ui.container.classList.add('is-complete');
            }
        } else {
            ui.track.classList.add('is-indeterminate');
            ui.track.removeAttribute('aria-valuenow');
            ui.percent.textContent = '实时';
        }

        const hasBytes = event.bytes !== null
            && event.bytes !== undefined
            && Number.isFinite(Number(event.bytes));
        const hasTotal = event.total_bytes !== null
            && event.total_bytes !== undefined
            && Number(event.total_bytes) > 0;
        const itemPrefix = event.item_index && event.item_count
            ? `第 ${event.item_index} / ${event.item_count} 张 · `
            : '';
        if (hasBytes && hasTotal) {
            ui.detail.textContent = `${itemPrefix}${formatBytes(event.bytes)} / ${formatBytes(event.total_bytes)}`;
        } else if (hasBytes) {
            ui.detail.textContent = `${itemPrefix}已接收 ${formatBytes(event.bytes)}`;
        } else {
            ui.detail.textContent = `当前阶段：${event.phase || 'processing'}`;
        }
    }

    function failProgress(kind, message) {
        const ui = progressElements(kind);
        ui.container.hidden = false;
        ui.container.classList.remove('is-complete');
        ui.container.classList.add('is-error');
        ui.track.classList.remove('is-indeterminate');
        ui.label.textContent = '任务失败';
        ui.percent.textContent = '错误';
        ui.detail.textContent = message;
    }

    function applySelectedFormat(formatId) {
        const formats = Array.isArray(state.video?.formats) ? state.video.formats : [];
        const selected = formats.find((format) => format.id === formatId)
            || formats.find((format) => format.is_default)
            || formats[0]
            || null;
        state.selectedFormat = selected;
        if (!selected) {
            elements.resolution.textContent = state.video?.width && state.video?.height
                ? `${state.video.width} × ${state.video.height}`
                : '-';
            elements.format.textContent = 'MP4';
            elements.formatDetails.textContent = '';
            return;
        }

        elements.formatSelect.value = selected.id;
        elements.resolution.textContent = selected.width && selected.height
            ? `${selected.width} × ${selected.height}`
            : '-';
        elements.format.textContent = `MP4 · ${selected.codec || 'H.264'}`;
        const details = [];
        if (selected.bitrate) details.push(`${(selected.bitrate / 1000000).toFixed(1)} Mbps`);
        if (selected.filesize) details.push(`预计 ${formatBytes(selected.filesize)}`);
        elements.formatDetails.textContent = details.join(' · ') || selected.quality || '源画质';
    }

    function renderFormatOptions(video) {
        const formats = Array.isArray(video.formats) ? video.formats : [];
        elements.formatSelect.replaceChildren(...formats.map((format) => {
            const option = document.createElement('option');
            option.value = format.id;
            option.textContent = format.label || `${format.quality || '源画质'} · ${format.codec || 'H.264'}`;
            return option;
        }));
        elements.formatSelectorGroup.hidden = formats.length === 0;
        applySelectedFormat((formats.find((format) => format.is_default) || formats[0] || {}).id);
    }

    function renderAlbumPreview(video) {
        const images = Array.isArray(video.images) ? video.images : [];
        state.selectedFormat = null;
        elements.albumCount.textContent = `${images.length} 张`;
        const thumbnails = images.map((image, index) => {
            const button = document.createElement('button');
            const preview = document.createElement('img');
            const indexLabel = document.createElement('span');
            button.type = 'button';
            button.className = 'album-thumbnail';
            button.title = `预览第 ${index + 1} 张图片`;
            preview.src = image.url;
            preview.alt = `第 ${index + 1} 张图片`;
            preview.referrerPolicy = 'no-referrer';
            indexLabel.textContent = String(index + 1);
            button.append(preview, indexLabel);
            button.addEventListener('click', () => {
                elements.cover.hidden = false;
                elements.cover.src = image.url;
            });
            return button;
        });
        elements.albumThumbnails.replaceChildren(...thumbnails);
        elements.albumPreview.hidden = images.length === 0;
    }

    function renderVideo(video) {
        const isAlbum = video.content_type === 'album';
        elements.resultEmpty.hidden = true;
        elements.resultContent.hidden = false;
        elements.resultState.textContent = isAlbum ? `图文 · ${video.images?.length || 0} 张` : '视频已就绪';
        elements.resultState.classList.add('is-ready');
        elements.resultPanel.setAttribute('aria-busy', 'false');

        elements.title.textContent = video.title || '未命名视频';
        elements.videoId.textContent = video.id || '-';
        elements.authorName.textContent = video.author?.name || '未知作者';
        elements.webpageLink.href = video.webpage_url || '#';

        if (isAlbum) {
            elements.duration.hidden = true;
            elements.duration.textContent = '';
            elements.resolution.textContent = video.width && video.height
                ? `${video.width} × ${video.height}`
                : '-';
            elements.format.textContent = `ZIP · ${video.images?.length || 0} 张图片`;
            elements.formatSelectorGroup.hidden = true;
            elements.formatSelect.replaceChildren();
            elements.formatDetails.textContent = '';
            elements.filenameExtension.textContent = '.zip';
            elements.downloadButton.querySelector('.button-label').textContent = '下载全部图片 ZIP';
            elements.copyButtonLabel.textContent = '复制首图链接';
            renderAlbumPreview(video);
        } else {
            const duration = formatDuration(video.duration);
            elements.duration.hidden = duration === null;
            elements.duration.textContent = duration || '';
            elements.albumPreview.hidden = true;
            elements.albumThumbnails.replaceChildren();
            elements.filenameExtension.textContent = '.mp4';
            elements.downloadButton.querySelector('.button-label').textContent = '下载 MP4';
            elements.copyButtonLabel.textContent = '复制直链';
            renderFormatOptions(video);
        }

        if (video.cover_url) {
            elements.cover.hidden = false;
            elements.cover.src = video.cover_url;
        } else {
            elements.cover.hidden = true;
            elements.cover.removeAttribute('src');
        }
        if (video.author?.avatar_url) {
            elements.authorAvatar.hidden = false;
            elements.authorAvatar.src = video.author.avatar_url;
        } else {
            elements.authorAvatar.hidden = true;
            elements.authorAvatar.removeAttribute('src');
        }
        elements.downloadStatus.hidden = true;
        elements.downloadProgress.hidden = true;
    }

    async function parseVideo(event) {
        event.preventDefault();
        const source = elements.shareInput.value.trim();
        if (!source) {
            showToast('请输入抖音分享链接或口令', true);
            elements.shareInput.focus();
            return;
        }

        elements.resultState.textContent = '解析中';
        elements.resultState.classList.remove('is-ready');
        elements.resultPanel.setAttribute('aria-busy', 'true');
        resetProgress('parse', '准备解析');
        setLoading(elements.parseButton, true, '正在解析', '解析视频');

        try {
            const video = await streamRequest(
                '/api/parse/stream',
                {url: source},
                (event) => updateProgress('parse', event),
            );
            state.source = source;
            state.video = video;
            renderVideo(video);
            showToast('视频解析完成');
        } catch (error) {
            elements.resultState.textContent = state.video ? '上次结果' : '解析失败';
            elements.resultState.classList.toggle('is-ready', Boolean(state.video));
            elements.resultPanel.setAttribute('aria-busy', 'false');
            failProgress('parse', error.message || '解析失败');
            showToast(error.message || '解析失败', true);
        } finally {
            setLoading(elements.parseButton, false, '正在解析', '解析视频');
        }
    }

    async function downloadVideo() {
        if (!state.video || !state.source) {
            return;
        }
        const normalLabel = state.video.content_type === 'album' ? '下载全部图片 ZIP' : '下载 MP4';
        setLoading(elements.downloadButton, true, '下载中', normalLabel);
        elements.downloadStatus.hidden = true;
        resetProgress('download', '准备下载');

        try {
            const result = await streamRequest(
                '/api/download/stream',
                {
                    url: state.source,
                    filename: elements.filenameInput.value.trim() || null,
                    overwrite: elements.overwriteInput.checked,
                    format_id: state.video.content_type === 'video' ? state.selectedFormat?.id || null : null,
                },
                (event) => updateProgress('download', event),
            );

            elements.downloadStatus.textContent = `已保存 ${result.filename} · ${formatBytes(result.size_bytes)}`;
            elements.downloadStatus.hidden = false;
            await loadFiles();
            showToast(state.video.content_type === 'album' ? '图文 ZIP 已下载到本地' : '视频已下载到本地');

            const link = document.createElement('a');
            link.href = result.file_url;
            link.download = result.filename;
            link.hidden = true;
            document.body.appendChild(link);
            link.click();
            link.remove();
        } catch (error) {
            failProgress('download', error.message || '下载失败');
            showToast(error.message || '下载失败', true);
        } finally {
            setLoading(elements.downloadButton, false, '下载中', normalLabel);
        }
    }

    async function copyDirectUrl() {
        const isAlbum = state.video?.content_type === 'album';
        const value = isAlbum
            ? state.video?.images?.[0]?.url
            : state.selectedFormat?.media_url || state.video?.media_url;
        if (!value) return;
        let copied = false;
        try {
            await navigator.clipboard.writeText(value);
            copied = true;
        } catch {
            const input = document.createElement('textarea');
            input.value = value;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            copied = document.execCommand('copy');
            input.remove();
        }
        const successMessage = isAlbum ? '首图链接已复制' : '无水印直链已复制';
        showToast(copied ? successMessage : '复制失败，请重试', !copied);
    }

    function createFileRow(file) {
        const row = document.createElement('tr');
        const name = document.createElement('td');
        const size = document.createElement('td');
        const modified = document.createElement('td');
        const action = document.createElement('td');
        const link = document.createElement('a');

        name.textContent = file.filename;
        size.textContent = formatBytes(file.size_bytes);
        modified.textContent = new Intl.DateTimeFormat('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(file.modified_at));
        link.className = 'file-download';
        link.href = file.file_url;
        link.download = file.filename;
        link.textContent = '下载';

        action.appendChild(link);
        row.append(name, size, modified, action);
        return row;
    }

    async function loadFiles() {
        try {
            const result = await request('/api/files');
            const files = Array.isArray(result?.files) ? result.files : [];
            elements.filesBody.replaceChildren(...files.map(createFileRow));
            elements.filesEmpty.hidden = files.length > 0;
            elements.fileCount.textContent = `${files.length} 个文件`;
        } catch (error) {
            showToast(error.message || '无法读取本地文件', true);
        }
    }

    async function checkHealth() {
        try {
            const health = await request('/api/health');
            elements.apiStatus.replaceChildren(
                document.createElement('span'),
                document.createTextNode(' 服务正常'),
            );
            elements.apiStatus.title = health?.runtime || '';
            elements.apiStatus.classList.remove('is-error');
        } catch {
            elements.apiStatus.replaceChildren(
                document.createElement('span'),
                document.createTextNode(' 服务异常'),
            );
            elements.apiStatus.classList.add('is-error');
        }
    }

    elements.cover.addEventListener('error', () => {
        elements.cover.hidden = true;
    });
    elements.authorAvatar.addEventListener('error', () => {
        elements.authorAvatar.hidden = true;
    });
    elements.form.addEventListener('submit', parseVideo);
    elements.downloadButton.addEventListener('click', downloadVideo);
    elements.formatSelect.addEventListener('change', () => {
        applySelectedFormat(elements.formatSelect.value);
    });
    elements.copyButton.addEventListener('click', copyDirectUrl);
    elements.refreshFiles.addEventListener('click', loadFiles);

    checkHealth();
    loadFiles();
})();
