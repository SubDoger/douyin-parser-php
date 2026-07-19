<?php

declare(strict_types=1);

namespace App;

use App\Exception\ExtractionException;
use App\Exception\InvalidUrlException;
use App\Http\HttpClientInterface;
use DOMDocument;
use DOMElement;
use JsonException;
use Throwable;

final class DouyinParser
{
    private const ALLOWED_DOMAINS = ['douyin.com', 'iesdouyin.com'];

    public function __construct(private HttpClientInterface $http)
    {
    }

    /** @return array<string, mixed> */
    public function parse(string $rawInput, ?callable $progress = null): array
    {
        $this->emitProgress($progress, 'validate', 3, '正在校验分享链接');
        $sourceUrl = $this->extractShareUrl($rawInput);
        $this->emitProgress($progress, 'resolve', 12, '正在定位作品 ID');
        $resolvedUrl = null;
        $videoId = $this->resolveVideoId($sourceUrl, $progress, $resolvedUrl);
        $this->emitProgress($progress, 'resolve', 28, '已定位作品 ' . $videoId);

        $hint = $this->contentHintFromUrl($resolvedUrl ?? $sourceUrl);
        $strategies = $this->strategyUrls($videoId, $hint);
        $errors = [];
        $video = null;

        foreach ($strategies as $index => $strategy) {
            $progressPercent = min(70, 38 + ($index * 12));
            try {
                $this->emitProgress(
                    $progress,
                    'fetch',
                    $progressPercent,
                    '正在尝试解析策略：' . $strategy['name'],
                );
                $response = $this->http->get($strategy['url']);
                if ($response->status < 200 || $response->status >= 300) {
                    throw new ExtractionException('HTTP ' . $response->status);
                }
                $dataSets = $this->extractStructuredData($response->body);
                $aweme = null;
                foreach ($dataSets as $data) {
                    $aweme = $this->findAweme($data, $videoId);
                    if ($aweme !== null) {
                        break;
                    }
                }
                if ($aweme === null) {
                    throw new ExtractionException('页面未返回目标作品数据');
                }
                $video = $this->mapAweme($aweme, $videoId, $strategy['name']);
                break;
            } catch (Throwable $exception) {
                $errors[] = $strategy['name'] . ': ' . $exception->getMessage();
            }
        }

        if ($video === null) {
            throw new ExtractionException('所有解析策略均失败：' . implode(' | ', $errors));
        }

        $this->emitProgress($progress, 'decode', 78, '正在提取作品资源');
        $this->emitProgress($progress, 'select', 90, '正在整理清晰度与编码');
        $this->emitProgress($progress, 'complete', 100, '解析完成');
        return $video;
    }

    public function extractShareUrl(string $rawInput): string
    {
        $rawInput = trim($rawInput);
        if ($rawInput === '' || strlen($rawInput) > 4096) {
            throw new InvalidUrlException('请输入有效的抖音分享链接');
        }

        if (preg_match('~https?://[^\s<>"\']+~iu', $rawInput, $matches) !== 1) {
            throw new InvalidUrlException('未找到有效的 HTTP(S) 链接');
        }

        $url = preg_replace(
            '~[).,;!?\]}\x{ff0c}\x{3002}\x{ff1b}\x{ff01}\x{ff1f}\x{3011}\x{300b}\x{201d}\x{2019}]+$~u',
            '',
            $matches[0],
        );
        if (!is_string($url)) {
            throw new InvalidUrlException('分享链接格式无效');
        }

        return $this->validateDouyinUrl($url);
    }

    private function validateDouyinUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidUrlException('分享链接格式无效');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidUrlException('仅支持 HTTP(S) 链接');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidUrlException('链接不能包含用户信息');
        }
        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            throw new InvalidUrlException('链接端口不在允许范围内');
        }

        foreach (self::ALLOWED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return $url;
            }
        }

        throw new InvalidUrlException('仅支持 douyin.com 或 iesdouyin.com 链接');
    }

    private function resolveVideoId(
        string $sourceUrl,
        ?callable $progress = null,
        ?string &$resolvedUrl = null,
    ): string
    {
        $directId = $this->videoIdFromUrl($sourceUrl);
        if ($directId !== null) {
            $resolvedUrl = $sourceUrl;
            return $directId;
        }

        $currentUrl = $sourceUrl;
        for ($redirects = 0; $redirects < 6; $redirects++) {
            try {
                $this->emitProgress(
                    $progress,
                    'resolve',
                    min(24, 14 + ($redirects * 2)),
                    $redirects === 0 ? '正在展开抖音短链' : '正在跟随短链重定向',
                );
                $response = $this->http->get($currentUrl);
            } catch (Throwable $exception) {
                throw new ExtractionException('展开抖音短链失败：' . $exception->getMessage(), 0, $exception);
            }

            if ($response->isRedirect()) {
                $location = $response->header('location');
                if ($location === null || $location === '') {
                    break;
                }
                $currentUrl = $this->validateDouyinUrl($this->resolveUrl($currentUrl, $location));
                $videoId = $this->videoIdFromUrl($currentUrl);
                if ($videoId !== null) {
                    $resolvedUrl = $currentUrl;
                    return $videoId;
                }
                continue;
            }

            if ($response->status >= 400) {
                throw new ExtractionException('展开抖音短链时返回 HTTP ' . $response->status);
            }

            $canonical = $this->extractCanonicalUrl($response->body);
            if ($canonical !== null) {
                $canonical = $this->validateDouyinUrl($this->resolveUrl($response->url, $canonical));
                $videoId = $this->videoIdFromUrl($canonical);
                if ($videoId !== null) {
                    $resolvedUrl = $canonical;
                    return $videoId;
                }
            }
            break;
        }

        throw new ExtractionException('无法从分享链接定位视频 ID');
    }

    private function videoIdFromUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '');
        if (preg_match('~/(?:share/)?(?:video|note|slides)/(\d{10,})~i', $path, $matches) === 1) {
            return $matches[1];
        }

        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach (['modal_id', 'aweme_id', 'item_ids'] as $key) {
            $value = $query[$key] ?? null;
            if (is_scalar($value) && preg_match('~^\d{10,}$~', (string) $value) === 1) {
                return (string) $value;
            }
        }
        return null;
    }

    private function contentHintFromUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('~/(?:share/)?(?:note|slides)/~i', $path) === 1) {
            return 'album';
        }
        if (preg_match('~/(?:share/)?video/~i', $path) === 1) {
            return 'video';
        }
        return 'unknown';
    }

    /** @return list<array{name: string, url: string}> */
    private function strategyUrls(string $videoId, string $hint): array
    {
        if ($hint === 'album') {
            return [
                ['name' => 'slides-share', 'url' => 'https://www.iesdouyin.com/share/slides/' . $videoId . '/'],
                ['name' => 'note-share', 'url' => 'https://www.iesdouyin.com/share/note/' . $videoId . '/'],
                ['name' => 'note-web', 'url' => 'https://www.douyin.com/note/' . $videoId],
            ];
        }
        if ($hint === 'video') {
            return [
                ['name' => 'video-share', 'url' => 'https://www.iesdouyin.com/share/video/' . $videoId . '/'],
                ['name' => 'video-web', 'url' => 'https://www.douyin.com/video/' . $videoId],
            ];
        }
        return [
            ['name' => 'video-share', 'url' => 'https://www.iesdouyin.com/share/video/' . $videoId . '/'],
            ['name' => 'slides-share', 'url' => 'https://www.iesdouyin.com/share/slides/' . $videoId . '/'],
            ['name' => 'video-web', 'url' => 'https://www.douyin.com/video/' . $videoId],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function extractStructuredData(string $html): array
    {
        $dom = $this->loadHtml($html);
        $dataSets = [];
        $markers = ['window._ROUTER_DATA =', 'window._SSR_DATA ='];

        foreach ($dom->getElementsByTagName('script') as $script) {
            $content = trim($script->textContent);
            $payload = null;
            $decodeUrl = false;

            foreach ($markers as $marker) {
                if (str_starts_with($content, $marker)) {
                    $payload = rtrim(trim(substr($content, strlen($marker))), ';');
                    break;
                }
            }

            if ($script instanceof DOMElement) {
                $scriptId = $script->getAttribute('id');
                if ($scriptId === 'RENDER_DATA') {
                    $payload = $content;
                    $decodeUrl = true;
                } elseif ($scriptId === '__UNIVERSAL_DATA_FOR_REHYDRATION__') {
                    $payload = $content;
                }
            }

            if ($payload === null || $payload === '') {
                continue;
            }
            if ($decodeUrl) {
                $payload = rawurldecode($payload);
            }
            try {
                $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (is_array($data)) {
                $dataSets[] = $data;
            }
        }

        if ($dataSets === []) {
            throw new ExtractionException('页面未包含可识别的结构化数据');
        }
        return $dataSets;
    }

    private function extractCanonicalUrl(string $html): ?string
    {
        $dom = $this->loadHtml($html);
        foreach ($dom->getElementsByTagName('link') as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            if (strtolower($link->getAttribute('rel')) === 'canonical') {
                return $link->getAttribute('href') ?: null;
            }
        }
        return null;
    }

    private function loadHtml(string $html): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new ExtractionException('PHP dom extension is required');
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new ExtractionException('抖音页面 HTML 解析失败');
        }
        return $dom;
    }

    /** @param mixed $node
     *  @return array<string, mixed>|null
     */
    private function findAweme(mixed $node, string $videoId): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        $hasVideo = is_array($node['video'] ?? null);
        $hasImages = (is_array($node['images'] ?? null) && $node['images'] !== [])
            || (is_array($node['image_infos'] ?? null) && $node['image_infos'] !== [])
            || is_array($node['image_post_info'] ?? null);
        if ((string) ($node['aweme_id'] ?? '') === $videoId && ($hasVideo || $hasImages)) {
            return $node;
        }
        foreach ($node as $value) {
            $result = $this->findAweme($value, $videoId);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $item
     *  @return array<string, mixed>
     */
    private function mapAweme(array $item, string $videoId, string $strategyName): array
    {
        $author = is_array($item['author'] ?? null) ? $item['author'] : [];
        $video = is_array($item['video'] ?? null) ? $item['video'] : [];
        $candidates = $this->playCandidates($video);
        $images = $this->imageResources($item);
        $isAlbum = $images !== [];
        if (!$isAlbum && $candidates === []) {
            throw new ExtractionException('分享页中没有可用的视频或图片资源');
        }

        usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $formats = [];
        foreach ($candidates as $index => $candidate) {
            unset($candidate['score']);
            $candidate['is_default'] = $index === 0;
            $formats[] = $candidate;
        }
        $selected = $formats[0] ?? null;

        $durationMs = is_numeric($video['duration'] ?? null) ? (float) $video['duration'] : null;
        $authorId = $author['sec_uid'] ?? $author['uid'] ?? $author['unique_id'] ?? null;
        $firstImage = $images[0] ?? null;

        return [
            'platform' => 'douyin',
            'content_type' => $isAlbum ? 'album' : 'video',
            'id' => $videoId,
            'title' => (string) ($item['desc'] ?? ('douyin-' . $videoId)),
            'author' => [
                'id' => $authorId !== null ? (string) $authorId : null,
                'name' => isset($author['nickname']) ? (string) $author['nickname'] : null,
                'avatar_url' => $this->firstUrl($author['avatar_thumb'] ?? null),
            ],
            'duration' => !$isAlbum && $durationMs !== null ? $durationMs / 1000 : null,
            'width' => $isAlbum
                ? ($firstImage['width'] ?? null)
                : (is_numeric($video['width'] ?? null) ? (int) $video['width'] : null),
            'height' => $isAlbum
                ? ($firstImage['height'] ?? null)
                : (is_numeric($video['height'] ?? null) ? (int) $video['height'] : null),
            'cover_url' => $isAlbum
                ? ($firstImage['url'] ?? null)
                : ($this->firstUrl($video['cover'] ?? null)
                    ?? $this->firstUrl($video['origin_cover'] ?? null)),
            'media_url' => $selected['media_url'] ?? null,
            'webpage_url' => 'https://www.douyin.com/' . ($isAlbum ? 'note/' : 'video/') . $videoId,
            'ext' => $isAlbum ? 'zip' : 'mp4',
            'format_id' => $selected['id'] ?? null,
            'quality' => $selected['quality'] ?? null,
            'codec' => $selected['codec'] ?? null,
            'filesize' => $selected['filesize'] ?? null,
            'formats' => $formats,
            'images' => $images,
            'resource_count' => $isAlbum ? count($images) : count($formats),
            'parser_strategy' => $strategyName,
        ];
    }

    /** @param array<string, mixed> $video
     *  @return list<array<string, mixed>>
     */
    private function playCandidates(array $video): array
    {
        $rawCandidates = [];
        $bitRates = is_array($video['bit_rate'] ?? null) ? $video['bit_rate'] : [];
        foreach ($bitRates as $index => $stream) {
            if (!is_array($stream)) {
                continue;
            }
            $url = $this->firstUrl($stream['play_addr'] ?? null);
            if ($url === null) {
                continue;
            }
            $playAddress = is_array($stream['play_addr'] ?? null) ? $stream['play_addr'] : [];
            $width = $this->integerValue($playAddress['width'] ?? $stream['width'] ?? $video['width'] ?? null);
            $height = $this->integerValue($playAddress['height'] ?? $stream['height'] ?? $video['height'] ?? null);
            $bitrate = $this->integerValue($stream['bit_rate'] ?? 0) ?? 0;
            $filesize = $this->integerValue($playAddress['data_size'] ?? $stream['data_size'] ?? null);
            $codec = $this->detectCodec($stream, '');
            $name = (string) ($stream['gear_name'] ?? $stream['quality_type'] ?? ('bitrate-' . $index));
            $rawCandidates[] = compact(
                'name',
                'url',
                'width',
                'height',
                'bitrate',
                'filesize',
                'codec',
            );
        }

        foreach (['play_addr_h264', 'play_addr_265', 'play_addr'] as $key) {
            $playAddress = is_array($video[$key] ?? null) ? $video[$key] : [];
            $url = $this->firstUrl($playAddress);
            if ($url !== null) {
                $rawCandidates[] = [
                    'name' => $key,
                    'url' => $url,
                    'width' => $this->integerValue($playAddress['width'] ?? $video['width'] ?? null),
                    'height' => $this->integerValue($playAddress['height'] ?? $video['height'] ?? null),
                    'bitrate' => $this->integerValue($playAddress['bit_rate'] ?? null) ?? 0,
                    'filesize' => $this->integerValue($playAddress['data_size'] ?? null),
                    'codec' => $this->detectCodec($playAddress, $key),
                ];
            }
        }

        $candidates = [];
        $seenUrls = [];
        $usedIds = [];
        foreach ($rawCandidates as $candidate) {
            $mediaUrl = $this->sourcePlayUrl((string) $candidate['url']);
            if (isset($seenUrls[$mediaUrl])) {
                continue;
            }
            $seenUrls[$mediaUrl] = true;

            $width = (int) ($candidate['width'] ?? 0);
            $height = (int) ($candidate['height'] ?? 0);
            $bitrate = (int) ($candidate['bitrate'] ?? 0);
            $codec = (string) ($candidate['codec'] ?? 'H.264');
            $qualityPixels = $width > 0 && $height > 0 ? min($width, $height) : 0;
            $quality = $qualityPixels > 0 ? $qualityPixels . 'P' : '源画质';
            $bitrateText = $bitrate > 0 ? ' · ' . number_format($bitrate / 1000000, 1) . ' Mbps' : '';
            $label = $quality . ' · ' . $codec . $bitrateText;
            $baseId = strtolower((string) preg_replace(
                '~[^0-9a-z]+~i',
                '-',
                (string) $candidate['name'] . '-' . $codec . '-' . $width . 'x' . $height . '-' . $bitrate,
            ));
            $baseId = trim($baseId, '-') ?: 'source';
            $formatId = $baseId;
            $duplicate = 2;
            while (isset($usedIds[$formatId])) {
                $formatId = $baseId . '-' . $duplicate++;
            }
            $usedIds[$formatId] = true;

            $score = ($qualityPixels * 10000000)
                + $bitrate
                + ($codec === 'H.264' ? 1000 : 0);
            $candidates[] = [
                'id' => $formatId,
                'label' => $label,
                'quality' => $quality,
                'codec' => $codec,
                'width' => $width ?: null,
                'height' => $height ?: null,
                'bitrate' => $bitrate ?: null,
                'filesize' => $candidate['filesize'] ?: null,
                'media_url' => $mediaUrl,
                'score' => $score,
            ];
        }
        return $candidates;
    }

    /** @param array<string, mixed> $stream */
    private function detectCodec(array $stream, string $name): string
    {
        if (($stream['is_h265'] ?? false) === true || (int) ($stream['is_h265'] ?? 0) === 1) {
            return 'H.265';
        }
        $signals = strtolower($name . ' '
            . (string) ($stream['codec_type'] ?? '') . ' '
            . (string) ($stream['video_format'] ?? '') . ' '
            . (string) ($stream['format'] ?? '') . ' '
            . (string) ($stream['gear_name'] ?? ''));
        if (str_contains($signals, 'av1')) {
            return 'AV1';
        }
        if (str_contains($signals, '265') || str_contains($signals, 'hevc')) {
            return 'H.265';
        }
        return 'H.264';
    }

    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /** @param array<string, mixed> $item
     *  @return list<array<string, mixed>>
     */
    private function imageResources(array $item): array
    {
        $containers = [];
        foreach (['images', 'image_infos'] as $key) {
            if (is_array($item[$key] ?? null)) {
                $containers[] = $item[$key];
            }
        }
        foreach (['image_post_info', 'note'] as $parentKey) {
            $parent = is_array($item[$parentKey] ?? null) ? $item[$parentKey] : [];
            if (is_array($parent['images'] ?? null)) {
                $containers[] = $parent['images'];
            }
        }

        $resources = [];
        $seenUrls = [];
        foreach ($containers as $container) {
            foreach ($container as $image) {
                if (!is_array($image)) {
                    continue;
                }
                $url = $this->firstUrl(
                    $image['download_url_list']
                    ?? $image['url_list']
                    ?? $image['display_image']
                    ?? $image['original_image']
                    ?? $image,
                );
                if ($url === null || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    $extension = 'jpg';
                }
                $resources[] = [
                    'id' => 'image-' . str_pad((string) (count($resources) + 1), 3, '0', STR_PAD_LEFT),
                    'url' => $url,
                    'width' => $this->integerValue($image['width'] ?? null),
                    'height' => $this->integerValue($image['height'] ?? null),
                    'ext' => $extension === 'jpeg' ? 'jpg' : $extension,
                ];
            }
        }
        return $resources;
    }

    private function firstUrl(mixed $value): ?string
    {
        if (is_string($value) && preg_match('~^https?://~i', $value) === 1) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach (['url_list', 'url', 'src'] as $key) {
            if (array_key_exists($key, $value)) {
                $result = $this->firstUrl($value[$key]);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        foreach ($value as $item) {
            $result = $this->firstUrl($item);
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    private function sourcePlayUrl(string $url): string
    {
        return (string) preg_replace('~/playwm/~', '/play/', $url, 1);
    }

    private function resolveUrl(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }
        $baseParts = parse_url($base);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            throw new InvalidUrlException('重定向基础链接无效');
        }
        $origin = $baseParts['scheme'] . '://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $origin .= ':' . $baseParts['port'];
        }
        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($baseParts['path'] ?? '/');
        return $origin . rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . $location;
    }

    private function emitProgress(
        ?callable $progress,
        string $phase,
        int $percent,
        string $message,
    ): void {
        if ($progress === null) {
            return;
        }
        $progress([
            'phase' => $phase,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
