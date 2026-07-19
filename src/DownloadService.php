<?php

declare(strict_types=1);

namespace App;

use App\Exception\ConflictException;
use App\Exception\DownloadException;
use App\Exception\InvalidUrlException;
use App\Http\CurlHttpClient;
use FilesystemIterator;
use Throwable;

final class DownloadService
{
    private const MEDIA_DOMAINS = [
        'snssdk.com',
        'amemv.com',
        'douyinvod.com',
        'idouyinvod.com',
        'douyincdn.com',
        'bytecdn.com',
        'bytecdn.cn',
        'bytefcdn.com',
        'bytefcdnrd.com',
        'byteimg.com',
        'douyinpic.com',
        'douyinstatic.com',
        'pstatp.com',
        'zijieimg.com',
        'volccdn.com',
        'zjcdn.com',
    ];
    private const LOCAL_EXTENSIONS = ['mp4', 'zip'];

    private string $downloadDir;

    public function __construct(
        private Config $config,
        private DouyinParser $parser,
    ) {
        if (!is_dir($config->downloadDir) && !mkdir($config->downloadDir, 0775, true) && !is_dir($config->downloadDir)) {
            throw new DownloadException('无法创建下载目录');
        }
        $realPath = realpath($config->downloadDir);
        if ($realPath === false || !is_writable($realPath)) {
            throw new DownloadException('下载目录不可写');
        }
        $this->downloadDir = $realPath;
    }

    /** @return array<string, mixed> */
    public function download(
        string $rawInput,
        ?string $requestedFilename = null,
        bool $overwrite = false,
        ?callable $progress = null,
        ?string $formatId = null,
    ): array {
        $video = $this->parser->parse(
            $rawInput,
            function (array $event) use ($progress): void {
                $parserPercent = (int) ($event['percent'] ?? 0);
                $this->emitProgress(
                    $progress,
                    'parse',
                    max(1, min(25, (int) round($parserPercent * 0.25))),
                    (string) ($event['message'] ?? '正在解析视频'),
                );
            },
        );
        $this->emitProgress($progress, 'prepare', 26, '作品信息已就绪');
        if (($video['content_type'] ?? 'video') === 'album') {
            if ($formatId !== null && $formatId !== '') {
                throw new InvalidUrlException('图文作品不支持视频格式选择');
            }
            return $this->downloadAlbum(
                $video,
                $requestedFilename,
                $overwrite,
                $progress,
            );
        }

        $video = $this->selectVideoFormat($video, $formatId);
        if ($requestedFilename !== null && trim($requestedFilename) !== '') {
            $stem = self::sanitizeFilename($requestedFilename);
        } else {
            try {
                $title = mb_substr(self::sanitizeFilename((string) $video['title']), 0, 80);
            } catch (InvalidUrlException) {
                $title = 'douyin';
            }
            $stem = $title . ' [' . $video['id'] . ']';
        }

        $filename = $stem . '.mp4';
        $target = $this->downloadDir . DIRECTORY_SEPARATOR . $filename;
        $this->emitProgress($progress, 'prepare', 28, '正在等待下载任务');
        $lockHandle = fopen($this->downloadDir . DIRECTORY_SEPARATOR . '.download.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            throw new DownloadException('无法获取下载锁');
        }

        $temporary = null;

        try {
            $this->emitProgress($progress, 'prepare', 30, '正在准备本地文件');
            $temporary = $this->downloadDir . DIRECTORY_SEPARATOR . '.' . $filename
                . '.' . bin2hex(random_bytes(8)) . '.part';
            if (is_file($target) && !$overwrite) {
                throw new ConflictException('文件已存在：' . $filename);
            }

            $existingSize = is_file($target) ? (int) filesize($target) : 0;
            $storageBytes = $this->storageBytes() - $existingSize;
            $storageLimit = $this->config->maxStorageMb * 1024 * 1024;
            $availableStorage = $storageLimit - $storageBytes;
            $downloadLimit = min(
                $this->config->maxFileSizeMb * 1024 * 1024,
                $availableStorage,
            );
            if ($downloadLimit < 12) {
                throw new DownloadException('下载目录已达总容量上限');
            }

            $sizeBytes = $this->streamAsset(
                (string) $video['media_url'],
                $temporary,
                $downloadLimit,
                $progress,
            );
            $this->emitProgress(
                $progress,
                'verify',
                96,
                '正在校验 MP4 文件',
                ['bytes' => $sizeBytes, 'total_bytes' => $sizeBytes],
            );
            if (!$this->isMp4($temporary)) {
                throw new DownloadException('上游返回的内容不是有效 MP4 文件');
            }

            $this->emitProgress($progress, 'save', 99, '正在保存本地文件');
            if (!rename($temporary, $target)) {
                throw new DownloadException('无法将临时文件移入下载目录');
            }

            $video['filesize'] = $sizeBytes;
            $result = [
                'video' => $video,
                'filename' => $filename,
                'file_url' => '/files/' . rawurlencode($filename),
                'size_bytes' => $sizeBytes,
            ];
            $this->emitProgress(
                $progress,
                'complete',
                100,
                '下载完成',
                ['bytes' => $sizeBytes, 'total_bytes' => $sizeBytes],
            );
            return $result;
        } catch (Throwable $exception) {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            if ($exception instanceof ConflictException || $exception instanceof DownloadException) {
                throw $exception;
            }
            throw new DownloadException('下载失败：' . $exception->getMessage(), 0, $exception);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return list<array<string, int|string>> */
    public function listFiles(): array
    {
        $files = [];
        $iterator = new FilesystemIterator($this->downloadDir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            $extension = strtolower($file->getExtension());
            if (!$file->isFile() || !in_array($extension, self::LOCAL_EXTENSIONS, true)) {
                continue;
            }
            $files[] = [
                'filename' => $file->getFilename(),
                'size_bytes' => $file->getSize(),
                'modified_at' => date(DATE_ATOM, $file->getMTime()),
                'file_url' => '/files/' . rawurlencode($file->getFilename()),
                'content_type' => $extension === 'zip' ? 'album' : 'video',
            ];
        }
        usort(
            $files,
            static fn (array $left, array $right): int => strcmp($right['modified_at'], $left['modified_at']),
        );
        return $files;
    }

    public function locateFile(string $filename): ?string
    {
        if ($filename === '' || basename(str_replace('\\', '/', $filename)) !== $filename) {
            return null;
        }
        if (!in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::LOCAL_EXTENSIONS, true)) {
            return null;
        }

        $candidate = realpath($this->downloadDir . DIRECTORY_SEPARATOR . $filename);
        if ($candidate === false || dirname($candidate) !== $this->downloadDir || !is_file($candidate)) {
            return null;
        }
        return $candidate;
    }

    public static function sanitizeFilename(string $value): string
    {
        $basename = basename(str_replace('\\', '/', trim($value)));
        if (str_ends_with(strtolower($basename), '.mp4')) {
            $basename = substr($basename, 0, -4);
        }
        $sanitized = preg_replace('~[^\p{Han}0-9A-Za-z._-]+~u', '_', $basename);
        if (!is_string($sanitized)) {
            throw new InvalidUrlException('文件名编码无效');
        }
        $sanitized = trim($sanitized, "._ \t\n\r\0\x0B");
        // Keep enough headroom for the video ID, extension and filesystem byte limits.
        $sanitized = mb_strcut($sanitized, 0, 180, 'UTF-8');
        $sanitized = trim($sanitized, "._ \t\n\r\0\x0B");
        if ($sanitized === '') {
            throw new InvalidUrlException('文件名不包含可用字符');
        }
        return $sanitized;
    }

    /** @param array<string, mixed> $video
     *  @return array<string, mixed>
     */
    private function selectVideoFormat(array $video, ?string $formatId): array
    {
        $formats = is_array($video['formats'] ?? null) ? $video['formats'] : [];
        if ($formats === []) {
            if (!is_string($video['media_url'] ?? null) || $video['media_url'] === '') {
                throw new DownloadException('解析结果中没有可用的视频格式');
            }
            return $video;
        }

        $selected = null;
        foreach ($formats as $format) {
            if (!is_array($format)) {
                continue;
            }
            if ($formatId !== null && $formatId !== '' && ($format['id'] ?? null) === $formatId) {
                $selected = $format;
                break;
            }
            if (($formatId === null || $formatId === '') && ($format['is_default'] ?? false) === true) {
                $selected = $format;
            }
        }
        if ($selected === null && ($formatId === null || $formatId === '')) {
            $selected = is_array($formats[0] ?? null) ? $formats[0] : null;
        }
        if ($selected === null) {
            throw new InvalidUrlException('选择的清晰度或编码已不可用，请重新解析');
        }
        if (!is_string($selected['media_url'] ?? null) || $selected['media_url'] === '') {
            throw new DownloadException('选择的视频格式缺少媒体地址');
        }

        $video['media_url'] = $selected['media_url'];
        $video['format_id'] = $selected['id'] ?? null;
        $video['quality'] = $selected['quality'] ?? null;
        $video['codec'] = $selected['codec'] ?? null;
        $video['width'] = $selected['width'] ?? $video['width'] ?? null;
        $video['height'] = $selected['height'] ?? $video['height'] ?? null;
        $video['filesize'] = $selected['filesize'] ?? null;
        return $video;
    }

    /** @param array<string, mixed> $work
     *  @return array<string, mixed>
     */
    private function downloadAlbum(
        array $work,
        ?string $requestedFilename,
        bool $overwrite,
        ?callable $progress,
    ): array {
        if (!class_exists(\ZipArchive::class)) {
            throw new DownloadException('图文打包需要 PHP zip 扩展');
        }
        $images = is_array($work['images'] ?? null) ? $work['images'] : [];
        if ($images === []) {
            throw new DownloadException('图文作品中没有可下载的图片');
        }

        if ($requestedFilename !== null && trim($requestedFilename) !== '') {
            $stem = self::sanitizeFilename($requestedFilename);
        } else {
            try {
                $title = mb_substr(self::sanitizeFilename((string) ($work['title'] ?? 'douyin')), 0, 80);
            } catch (InvalidUrlException) {
                $title = 'douyin';
            }
            $stem = $title . ' [' . $work['id'] . ']';
        }

        $filename = $stem . '.zip';
        $target = $this->downloadDir . DIRECTORY_SEPARATOR . $filename;
        $this->emitProgress($progress, 'prepare', 28, '正在等待图片打包任务');
        $lockHandle = fopen($this->downloadDir . DIRECTORY_SEPARATOR . '.download.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            throw new DownloadException('无法获取下载锁');
        }

        $temporary = null;
        $imageTemps = [];
        $archive = null;

        try {
            if (is_file($target) && !$overwrite) {
                throw new ConflictException('文件已存在：' . $filename);
            }
            $existingSize = is_file($target) ? (int) filesize($target) : 0;
            $storageBytes = $this->storageBytes() - $existingSize;
            $storageLimit = $this->config->maxStorageMb * 1024 * 1024;
            $downloadLimit = min(
                $this->config->maxFileSizeMb * 1024 * 1024,
                $storageLimit - $storageBytes,
            );
            if ($downloadLimit < 12) {
                throw new DownloadException('下载目录已达总容量上限');
            }

            $temporary = $this->downloadDir . DIRECTORY_SEPARATOR . '.' . $filename
                . '.' . bin2hex(random_bytes(8)) . '.part';
            $archive = new \ZipArchive();
            $openStatus = $archive->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($openStatus !== true) {
                throw new DownloadException('无法创建图片 ZIP 文件');
            }

            $totalRawBytes = 0;
            $imageCount = count($images);
            foreach ($images as $index => $image) {
                if (!is_array($image) || !is_string($image['url'] ?? null)) {
                    continue;
                }
                $remaining = $downloadLimit - $totalRawBytes;
                if ($remaining < 12) {
                    throw new DownloadException('图片合计大小超过下载上限');
                }
                $itemNumber = $index + 1;
                $rangeStart = 30 + (int) floor(($index / $imageCount) * 64);
                $rangeEnd = 30 + (int) floor((($index + 1) / $imageCount) * 64);
                $imageTemp = $this->downloadDir . DIRECTORY_SEPARATOR . '.' . $stem
                    . '.image-' . $itemNumber . '.' . bin2hex(random_bytes(4)) . '.part';
                $imageTemps[] = $imageTemp;
                $imageSize = $this->streamAsset(
                    $image['url'],
                    $imageTemp,
                    $remaining,
                    $progress,
                    $rangeStart,
                    $rangeEnd,
                    '正在下载第 ' . $itemNumber . ' / ' . $imageCount . ' 张图片',
                    ['item_index' => $itemNumber, 'item_count' => $imageCount],
                );
                $extension = $this->imageExtension($imageTemp);
                $entryName = str_pad((string) $itemNumber, 3, '0', STR_PAD_LEFT) . '.' . $extension;
                if (!$archive->addFile($imageTemp, $entryName)) {
                    throw new DownloadException('无法将图片写入 ZIP');
                }
                $totalRawBytes += $imageSize;
            }

            if (!$archive->close()) {
                throw new DownloadException('无法完成图片 ZIP 文件');
            }
            $archive = null;
            foreach ($imageTemps as $imageTemp) {
                if (is_file($imageTemp)) {
                    unlink($imageTemp);
                }
            }
            $imageTemps = [];

            $zipSize = is_file($temporary) ? (int) filesize($temporary) : 0;
            $this->emitProgress(
                $progress,
                'verify',
                96,
                '正在校验图片 ZIP',
                ['bytes' => $zipSize, 'total_bytes' => $zipSize],
            );
            if ($zipSize < 22 || !$this->isZip($temporary, $imageCount)) {
                throw new DownloadException('生成的图片 ZIP 无效');
            }
            $this->emitProgress($progress, 'save', 99, '正在保存图文压缩包');
            if (!rename($temporary, $target)) {
                throw new DownloadException('无法将 ZIP 移入下载目录');
            }

            $work['filesize'] = $zipSize;
            $result = [
                'video' => $work,
                'filename' => $filename,
                'file_url' => '/files/' . rawurlencode($filename),
                'size_bytes' => $zipSize,
                'asset_count' => $imageCount,
            ];
            $this->emitProgress(
                $progress,
                'complete',
                100,
                '图文打包完成',
                ['bytes' => $zipSize, 'total_bytes' => $zipSize],
            );
            return $result;
        } catch (Throwable $exception) {
            if ($archive instanceof \ZipArchive) {
                $archive->close();
            }
            foreach ($imageTemps as $imageTemp) {
                if (is_file($imageTemp)) {
                    unlink($imageTemp);
                }
            }
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
            if ($exception instanceof ConflictException
                || $exception instanceof DownloadException
                || $exception instanceof InvalidUrlException) {
                throw $exception;
            }
            throw new DownloadException('图文下载失败：' . $exception->getMessage(), 0, $exception);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function imageExtension(string $path): string
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->file($path);
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new DownloadException('上游返回的资源不是可识别图片'),
        };
    }

    private function isZip(string $path, int $expectedFiles): bool
    {
        $archive = new \ZipArchive();
        if ($archive->open($path) !== true) {
            return false;
        }
        $valid = $archive->numFiles === $expectedFiles;
        $archive->close();
        return $valid;
    }

    public static function isAllowedMediaHost(string $host): bool
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));
        foreach (self::MEDIA_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, int> $progressExtra */
    private function streamAsset(
        string $mediaUrl,
        string $outputPath,
        int $maximum,
        ?callable $progress = null,
        int $rangeStart = 31,
        int $rangeEnd = 94,
        string $progressMessage = '正在接收视频数据',
        array $progressExtra = [],
    ): int
    {
        $output = fopen($outputPath, 'x+b');
        if ($output === false) {
            throw new DownloadException('无法创建临时文件');
        }

        $currentUrl = $mediaUrl;
        $total = 0;
        $completed = false;
        $lastReportedBytes = 0;
        $lastReportedAt = microtime(true);

        try {
            for ($redirects = 0; $redirects < 6; $redirects++) {
                $this->emitProgress(
                    $progress,
                    'connect',
                    $rangeStart,
                    $redirects === 0 ? '正在连接资源 CDN' : '正在跟随媒体重定向',
                    $progressExtra,
                );
                $endpoint = $this->validatedMediaEndpoint($currentUrl);
                $curl = curl_init($currentUrl);
                if ($curl === false) {
                    throw new DownloadException('无法初始化媒体下载');
                }

                $status = 0;
                $headers = [];
                $tooLarge = false;
                $declaredSize = null;

                curl_setopt_array($curl, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT => min(10, $this->config->timeoutSeconds),
                    CURLOPT_TIMEOUT => $this->config->mediaTimeoutSeconds,
                    CURLOPT_LOW_SPEED_LIMIT => 1024,
                    CURLOPT_LOW_SPEED_TIME => 30,
                    CURLOPT_USERAGENT => CurlHttpClient::USER_AGENT,
                    CURLOPT_HTTPHEADER => ['Accept: */*'],
                    CURLOPT_ENCODING => '',
                    CURLOPT_RESOLVE => [$endpoint['resolve']],
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (
                        &$status,
                        &$headers,
                        &$declaredSize,
                    ): int {
                        $length = strlen($line);
                        $trimmed = trim($line);
                        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $matches) === 1) {
                            $status = (int) $matches[1];
                            $headers = [];
                            $declaredSize = null;
                        } elseif (str_contains($trimmed, ':')) {
                            [$name, $value] = explode(':', $trimmed, 2);
                            $normalizedName = strtolower(trim($name));
                            $normalizedValue = trim($value);
                            $headers[$normalizedName] = $normalizedValue;
                            if ($normalizedName === 'content-length' && ctype_digit($normalizedValue)) {
                                $declaredSize = (int) $normalizedValue;
                            }
                        }
                        return $length;
                    },
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
                        $output,
                        &$status,
                        &$tooLarge,
                        &$total,
                        &$declaredSize,
                        &$lastReportedBytes,
                        &$lastReportedAt,
                        $progress,
                        $maximum,
                        $rangeStart,
                        $rangeEnd,
                        $progressMessage,
                        $progressExtra,
                    ): int {
                        $length = strlen($chunk);
                        if ($status < 200 || $status >= 300) {
                            return $length;
                        }
                        if ($total + $length > $maximum) {
                            $tooLarge = true;
                            return 0;
                        }
                        $written = fwrite($output, $chunk);
                        if ($written !== $length) {
                            return 0;
                        }
                        $total += $written;
                        $now = microtime(true);
                        if ($progress !== null
                            && ($total - $lastReportedBytes >= 262144 || $now - $lastReportedAt >= 0.2)) {
                            $percent = $declaredSize !== null && $declaredSize > 0
                                ? $rangeStart + (int) round(
                                    min(1, $total / $declaredSize) * ($rangeEnd - $rangeStart),
                                )
                                : null;
                            $progress(array_merge([
                                'phase' => 'download',
                                'percent' => $percent,
                                'message' => $progressMessage,
                                'bytes' => $total,
                                'total_bytes' => $declaredSize,
                            ], $progressExtra));
                            $lastReportedBytes = $total;
                            $lastReportedAt = $now;
                        }
                        return $written;
                    },
                ]);

                if ($this->config->proxy !== null) {
                    curl_setopt($curl, CURLOPT_PROXY, $this->config->proxy);
                }
                if ($this->config->cookiesFile !== null && is_file($this->config->cookiesFile)) {
                    curl_setopt($curl, CURLOPT_COOKIEFILE, $this->config->cookiesFile);
                }

                $success = curl_exec($curl);
                $error = curl_error($curl);
                if ($status === 0) {
                    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                }
                $contentType = strtolower((string) (
                    $headers['content-type'] ?? curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?? ''
                ));
                curl_close($curl);

                if ($success === false) {
                    if ($tooLarge) {
                        throw new DownloadException('资源超过允许的最大文件大小');
                    }
                    throw new DownloadException('媒体请求失败：' . $error);
                }

                if ($status >= 300 && $status < 400) {
                    $location = $headers['location'] ?? null;
                    if (!is_string($location) || $location === '') {
                        throw new DownloadException('媒体重定向缺少 Location');
                    }
                    $currentUrl = $this->resolveUrl($currentUrl, $location);
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    throw new DownloadException('媒体服务返回 HTTP ' . $status);
                }
                if (str_starts_with($contentType, 'text/')
                    || str_contains($contentType, 'application/json')
                    || str_contains($contentType, 'application/xml')) {
                    throw new DownloadException('上游未返回可下载资源：' . $contentType);
                }
                if ($total < 12) {
                    throw new DownloadException('上游返回的资源内容为空');
                }
                $completed = true;
                $this->emitProgress(
                    $progress,
                    'download',
                    $rangeEnd,
                    '资源数据接收完成',
                    array_merge(['bytes' => $total, 'total_bytes' => $total], $progressExtra),
                );
                break;
            }
        } finally {
            fflush($output);
            fclose($output);
        }

        if (!$completed) {
            throw new DownloadException('媒体地址重定向次数过多');
        }
        return $total;
    }

    /** @return array{resolve: string} */
    private function validatedMediaEndpoint(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new DownloadException('媒体地址无效');
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new DownloadException('媒体地址协议无效');
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            throw new DownloadException('媒体地址端口无效');
        }

        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        if (!self::isAllowedMediaHost($host)) {
            throw new DownloadException('媒体地址不属于允许的 CDN');
        }

        $ipv4 = gethostbynamel($host);
        $ipv4 = is_array($ipv4) ? $ipv4 : [];
        $ipv6 = [];
        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            $records = dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ipv6[] = $record['ipv6'];
                    }
                }
            }
        }
        $addresses = array_values(array_unique(array_merge($ipv4, $ipv6)));
        if ($addresses === []) {
            throw new DownloadException('无法解析媒体 CDN 域名');
        }
        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new DownloadException('媒体 CDN 解析到非公网 IP');
            }
        }

        $pinAddresses = $ipv4 !== [] ? $ipv4 : $ipv6;
        $pinAddresses = array_map(
            static fn (string $address): string => str_contains($address, ':')
                ? '[' . $address . ']'
                : $address,
            $pinAddresses,
        );
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80);

        return [
            'resolve' => $host . ':' . $port . ':' . implode(',', $pinAddresses),
        ];
    }

    private function storageBytes(): int
    {
        $total = 0;
        $iterator = new FilesystemIterator($this->downloadDir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if ($file->isFile()
                && in_array(strtolower($file->getExtension()), self::LOCAL_EXTENSIONS, true)) {
                $total += $file->getSize();
            }
        }
        return $total;
    }

    private function isMp4(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 12);
        fclose($handle);
        return is_string($header) && strlen($header) >= 8 && substr($header, 4, 4) === 'ftyp';
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new DownloadException('媒体重定向基础地址无效');
        }
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
    }

    /** @param array<string, int|null> $extra */
    private function emitProgress(
        ?callable $progress,
        string $phase,
        ?int $percent,
        string $message,
        array $extra = [],
    ): void {
        if ($progress === null) {
            return;
        }
        $progress(array_merge([
            'phase' => $phase,
            'percent' => $percent,
            'message' => $message,
        ], $extra));
    }
}
