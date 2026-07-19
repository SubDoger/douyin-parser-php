<?php

declare(strict_types=1);

use App\DouyinParser;
use App\DownloadService;
use App\Exception\InvalidUrlException;
use App\Http\HttpClientInterface;
use App\Http\HttpResponse;

require dirname(__DIR__, 2) . '/bootstrap.php';

final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<string> */
    public array $requests = [];

    /** @param Closure(string): HttpResponse $handler */
    public function __construct(private Closure $handler)
    {
    }

    public function get(string $url): HttpResponse
    {
        $this->requests[] = $url;
        return ($this->handler)($url);
    }
}

function fixtureHtml(string $videoId = '7350000000000000000'): string
{
    $routerData = [
        'loaderData' => [
            'video_(id)/page' => [
                'videoInfoRes' => [
                    'item_list' => [[
                        'aweme_id' => $videoId,
                        'desc' => '测试视频',
                        'author' => [
                            'sec_uid' => 'sec-user-1',
                            'nickname' => '测试作者',
                            'avatar_thumb' => [
                                'url_list' => ['https://img.example.test/avatar.jpg'],
                            ],
                        ],
                        'video' => [
                            'duration' => 12500,
                            'width' => 1080,
                            'height' => 1920,
                            'cover' => [
                                'url_list' => ['https://img.example.test/cover.jpg'],
                            ],
                            'play_addr' => [
                                'url_list' => [
                                    'https://aweme.snssdk.com/aweme/v1/playwm/?video_id=fixture',
                                ],
                            ],
                        ],
                    ]],
                ],
            ],
        ],
    ];

    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<link rel="canonical" href="https://www.douyin.com/video/' . $videoId . '">'
        . '</head><body><script>window._ROUTER_DATA = '
        . json_encode($routerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . ';</script></body></html>';
}

/** @param array<string, mixed> $item */
function itemHtml(array $item, string $scriptType = 'router'): string
{
    $data = ['loaderData' => ['page' => ['videoInfoRes' => ['item_list' => [$item]]]]];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $script = $scriptType === 'render'
        ? '<script id="RENDER_DATA">' . rawurlencode($json) . '</script>'
        : '<script>window._ROUTER_DATA = ' . $json . ';</script>';
    return '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $script . '</body></html>';
}

function formatFixtureHtml(string $videoId = '7350000000000000000'): string
{
    $streams = [
        [
            'gear_name' => 'normal_1080_h264',
            'bit_rate' => 4000000,
            'is_h265' => 0,
            'play_addr' => [
                'width' => 1080,
                'height' => 1920,
                'data_size' => 8000000,
                'url_list' => ['https://aweme.snssdk.com/aweme/v1/play/?video_id=h264-1080'],
            ],
        ],
        [
            'gear_name' => 'normal_1080_h265',
            'bit_rate' => 3000000,
            'is_h265' => 1,
            'play_addr' => [
                'width' => 1080,
                'height' => 1920,
                'data_size' => 6000000,
                'url_list' => ['https://aweme.snssdk.com/aweme/v1/play/?video_id=h265-1080'],
            ],
        ],
        [
            'gear_name' => 'normal_720_h264',
            'bit_rate' => 1800000,
            'is_h265' => 0,
            'play_addr' => [
                'width' => 720,
                'height' => 1280,
                'data_size' => 3500000,
                'url_list' => ['https://aweme.snssdk.com/aweme/v1/play/?video_id=h264-720'],
            ],
        ],
    ];
    return itemHtml([
        'aweme_id' => $videoId,
        'desc' => '多格式视频',
        'author' => ['nickname' => '格式作者'],
        'video' => [
            'duration' => 30000,
            'width' => 1080,
            'height' => 1920,
            'bit_rate' => $streams,
            'play_addr' => $streams[0]['play_addr'],
        ],
    ]);
}

function albumFixtureHtml(string $videoId = '7360000000000000000'): string
{
    return itemHtml([
        'aweme_id' => $videoId,
        'aweme_type' => 68,
        'desc' => '三张图文',
        'author' => ['nickname' => '图文作者'],
        'images' => [
            ['width' => 1080, 'height' => 1440, 'url_list' => ['https://p3.douyinpic.com/album/1.jpg']],
            ['width' => 1080, 'height' => 1440, 'url_list' => ['https://p3.douyinpic.com/album/2.webp']],
            ['width' => 1080, 'height' => 1440, 'url_list' => ['https://p3.douyinpic.com/album/3.png']],
        ],
    ]);
}

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param class-string<Throwable> $exceptionClass */
function assertThrows(string $exceptionClass, Closure $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException('Unexpected exception: ' . $exception::class . ' ' . $exception->getMessage());
    }
    throw new RuntimeException('Expected exception ' . $exceptionClass . ' was not thrown');
}

/** @return DouyinParser */
function fixtureParser(?FakeHttpClient &$client = null): DouyinParser
{
    $client = new FakeHttpClient(static fn (string $url): HttpResponse => new HttpResponse(
        200,
        ['content-type' => 'text/html; charset=utf-8'],
        fixtureHtml(),
        $url,
    ));
    return new DouyinParser($client);
}

$tests = [];

$tests['extracts URL from complete share text'] = static function (): void {
    $parser = fixtureParser();
    $url = $parser->extractShareUrl(
        '3.14 abc:/ 复制打开抖音 https://v.douyin.com/AbC123/ 看视频',
    );
    assertSameValue('https://v.douyin.com/AbC123/', $url);
};

$tests['rejects non-Douyin and lookalike domains'] = static function (): void {
    $parser = fixtureParser();
    foreach (['https://example.com/video/123', 'https://douyin.com.evil.test/video/123'] as $url) {
        assertThrows(InvalidUrlException::class, static fn (): string => $parser->extractShareUrl($url));
    }
};

$tests['parses server-rendered video data'] = static function (): void {
    $parser = fixtureParser($client);
    $video = $parser->parse('https://www.douyin.com/video/7350000000000000000');

    assertSameValue('7350000000000000000', $video['id']);
    assertSameValue('测试视频', $video['title']);
    assertSameValue('测试作者', $video['author']['name']);
    assertSameValue(12.5, $video['duration']);
    assertSameValue(1080, $video['width']);
    assertSameValue('video', $video['content_type']);
    assertTrueValue(count($video['formats']) >= 1, 'video formats are missing');
    assertTrueValue(str_contains($video['media_url'], '/play/'), 'source play path was not selected');
    assertTrueValue(!str_contains($video['media_url'], '/playwm/'), 'watermark play path remains');
    assertSameValue(1, count($client->requests));
};

$tests['returns selectable quality and codec formats'] = static function (): void {
    $client = new FakeHttpClient(static fn (string $url): HttpResponse => new HttpResponse(
        200,
        [],
        formatFixtureHtml(),
        $url,
    ));
    $video = (new DouyinParser($client))->parse(
        'https://www.douyin.com/video/7350000000000000000',
    );

    assertSameValue(3, count($video['formats']));
    assertSameValue('1080P', $video['formats'][0]['quality']);
    assertSameValue('H.264', $video['formats'][0]['codec']);
    assertTrueValue($video['formats'][0]['is_default'], 'best compatible format is not default');
    assertTrueValue(
        in_array('H.265', array_column($video['formats'], 'codec'), true),
        'H.265 format is missing',
    );
    assertSameValue($video['formats'][0]['id'], $video['format_id']);
};

$tests['parses an album without a video object'] = static function (): void {
    $client = new FakeHttpClient(static fn (string $url): HttpResponse => new HttpResponse(
        200,
        [],
        albumFixtureHtml(),
        $url,
    ));
    $album = (new DouyinParser($client))->parse(
        'https://www.douyin.com/note/7360000000000000000',
    );

    assertSameValue('album', $album['content_type']);
    assertSameValue(3, count($album['images']));
    assertSameValue([], $album['formats']);
    assertSameValue('zip', $album['ext']);
    assertSameValue('https://p3.douyinpic.com/album/1.jpg', $album['cover_url']);
    assertSameValue('slides-share', $album['parser_strategy']);
};

$tests['falls back to legacy RENDER_DATA'] = static function (): void {
    $requests = 0;
    $client = new FakeHttpClient(static function (string $url) use (&$requests): HttpResponse {
        $requests++;
        if ($requests === 1) {
            return new HttpResponse(200, [], '<html><body>no hydration data</body></html>', $url);
        }
        $item = [
            'aweme_id' => '7350000000000000000',
            'desc' => '回退解析',
            'video' => [
                'width' => 720,
                'height' => 1280,
                'play_addr' => ['url_list' => ['https://aweme.snssdk.com/aweme/v1/play/?video_id=fallback']],
            ],
        ];
        return new HttpResponse(200, [], itemHtml($item, 'render'), $url);
    });
    $video = (new DouyinParser($client))->parse(
        'https://www.douyin.com/video/7350000000000000000',
    );

    assertSameValue('回退解析', $video['title']);
    assertSameValue('video-web', $video['parser_strategy']);
    assertSameValue(2, $requests);
};

$tests['falls back when a strategy returns unusable resources'] = static function (): void {
    $requests = 0;
    $client = new FakeHttpClient(static function (string $url) use (&$requests): HttpResponse {
        $requests++;
        $item = [
            'aweme_id' => '7350000000000000000',
            'desc' => $requests === 1 ? '资源不完整' : '资源可用',
            'video' => $requests === 1
                ? ['width' => 1080, 'height' => 1920]
                : [
                    'width' => 720,
                    'height' => 1280,
                    'play_addr' => [
                        'url_list' => ['https://aweme.snssdk.com/aweme/v1/play/?video_id=usable'],
                    ],
                ],
        ];
        return new HttpResponse(200, [], itemHtml($item), $url);
    });
    $video = (new DouyinParser($client))->parse(
        'https://www.douyin.com/video/7350000000000000000',
    );

    assertSameValue('资源可用', $video['title']);
    assertSameValue('video-web', $video['parser_strategy']);
    assertSameValue(2, $requests);
};

$tests['emits ordered parsing progress events'] = static function (): void {
    $parser = fixtureParser();
    $events = [];
    $parser->parse(
        'https://www.douyin.com/video/7350000000000000000',
        static function (array $event) use (&$events): void {
            $events[] = $event;
        },
    );

    assertTrueValue(count($events) >= 7, 'too few parsing progress events');
    $previous = -1;
    foreach ($events as $event) {
        $percent = (int) ($event['percent'] ?? -1);
        assertTrueValue($percent >= $previous, 'parsing progress moved backwards');
        assertTrueValue(isset($event['phase'], $event['message']), 'progress event is incomplete');
        $previous = $percent;
    }
    assertSameValue(100, $previous, 'parsing progress did not complete');
};

$tests['expands short-link redirect before parsing'] = static function (): void {
    $client = new FakeHttpClient(static function (string $url): HttpResponse {
        if ($url === 'https://v.douyin.com/short-code/') {
            return new HttpResponse(
                302,
                ['location' => 'https://www.douyin.com/video/7350000000000000000?previous_page=web_code_link'],
                '',
                $url,
            );
        }
        return new HttpResponse(200, [], fixtureHtml(), $url);
    });
    $video = (new DouyinParser($client))->parse('https://v.douyin.com/short-code/');

    assertSameValue('7350000000000000000', $video['id']);
    assertSameValue(2, count($client->requests));
};

$tests['uses canonical URL when a short page does not redirect'] = static function (): void {
    $client = new FakeHttpClient(static function (string $url): HttpResponse {
        if ($url === 'https://v.douyin.com/canonical/') {
            $html = '<html><head><link rel="canonical" '
                . 'href="https://www.douyin.com/video/7350000000000000000"></head></html>';
            return new HttpResponse(200, [], $html, $url);
        }
        return new HttpResponse(200, [], fixtureHtml(), $url);
    });
    $video = (new DouyinParser($client))->parse('https://v.douyin.com/canonical/');

    assertSameValue('7350000000000000000', $video['id']);
    assertSameValue(2, count($client->requests));
};

$tests['sanitizes requested filenames'] = static function (): void {
    assertSameValue('我的视频_id', DownloadService::sanitizeFilename('../../我的视频; $(id)'));
    assertSameValue('demo', DownloadService::sanitizeFilename('demo.mp4'));
    assertThrows(InvalidUrlException::class, static fn (): string => DownloadService::sanitizeFilename('...'));
    assertTrueValue(
        strlen(DownloadService::sanitizeFilename(str_repeat('视频', 100))) <= 180,
        'sanitized filename exceeds its byte limit',
    );
};

$tests['allows only known public media CDN hosts'] = static function (): void {
    assertTrueValue(
        DownloadService::isAllowedMediaHost('v96-hcc.douyinvod.com'),
        'known Douyin media CDN was rejected',
    );
    foreach (['127.1', '2130706433', 'localhost', 'douyinvod.com.evil.test'] as $host) {
        assertTrueValue(!DownloadService::isAllowedMediaHost($host), 'unsafe media host was allowed: ' . $host);
    }
};

$tests['renders a valid visual workspace with unique IDs'] = static function (): void {
    ob_start();
    require dirname(__DIR__, 2) . '/views/home.php';
    $html = ob_get_clean();
    assertTrueValue(is_string($html) && str_contains($html, '<form id="parse-form"'), 'parse form is missing');

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    assertTrueValue($loaded, 'visual template HTML did not parse');

    $ids = [];
    foreach ($dom->getElementsByTagName('*') as $element) {
        if (!$element instanceof DOMElement || !$element->hasAttribute('id')) {
            continue;
        }
        $id = $element->getAttribute('id');
        assertTrueValue(!isset($ids[$id]), 'duplicate template ID: ' . $id);
        $ids[$id] = true;
    }
    foreach ([
        'parse-form',
        'share-input',
        'parse-progress-track',
        'result-panel',
        'format-select',
        'album-preview',
        'filename-extension',
        'download-button',
        'download-progress-track',
        'files-body',
    ] as $requiredId) {
        assertTrueValue(isset($ids[$requiredId]), 'required template ID is missing: ' . $requiredId);
    }
    foreach (['app.css', 'app.js', 'favicon.svg'] as $asset) {
        assertTrueValue(is_file(dirname(__DIR__, 2) . '/public/assets/' . $asset), 'asset is missing: ' . $asset);
    }
};

$passed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $exception) {
        fwrite(STDERR, "FAIL  {$name}\n  {$exception->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "\n{$passed} tests passed\n");
