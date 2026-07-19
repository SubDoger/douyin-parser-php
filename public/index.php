<?php

declare(strict_types=1);

use App\Config;
use App\DouyinParser;
use App\DownloadService;
use App\Exception\ConflictException;
use App\Exception\DownloadException;
use App\Exception\ExtractionException;
use App\Exception\InvalidUrlException;
use App\Http\CurlHttpClient;
require dirname(__DIR__) . '/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
    . "img-src 'self' https: data:; media-src 'self' https:; connect-src 'self'; "
    . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

/** @param array<string, mixed> $data */
function jsonResponse(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function beginJsonStream(): void
{
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache, no-store, no-transform');
    header('X-Accel-Buffering: no');
    ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }
    while (ob_get_level() > 0) {
        if (!@ob_end_flush()) {
            break;
        }
    }
    streamEvent(['type' => 'ready']);
}

/** @param array<string, mixed> $event */
function streamEvent(array $event): void
{
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function streamFailure(Throwable $exception): void
{
    $status = match (true) {
        $exception instanceof InvalidUrlException => 422,
        $exception instanceof ConflictException => 409,
        $exception instanceof ExtractionException,
        $exception instanceof DownloadException => 502,
        default => 500,
    };
    if ($status === 500) {
        error_log((string) $exception);
    }
    streamEvent([
        'type' => 'error',
        'status' => $status,
        'detail' => $status === 500 ? '服务器内部错误' : $exception->getMessage(),
    ]);
}

/** @return array<string, mixed> */
function readJsonBody(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        throw new InvalidUrlException('请使用 application/json 请求');
    }
    $inputStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
    $body = file_get_contents($inputStream, false, null, 0, 32769);
    if (!is_string($body) || $body === '' || strlen($body) > 32768) {
        throw new InvalidUrlException('请求体为空或过大');
    }
    try {
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
        throw new InvalidUrlException('JSON 请求体无效', 0, $exception);
    }
    if (!is_array($payload)) {
        throw new InvalidUrlException('JSON 请求体必须是对象');
    }
    return $payload;
}

function requireMethod(string $expected): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $expected) {
        header('Allow: ' . $expected);
        jsonResponse(['detail' => '请求方法不允许'], 405);
    }
}

function isLoopbackAddress(string $address): bool
{
    $address = strtolower(trim($address, '[]'));
    if ($address === '::1') {
        return true;
    }
    if (str_starts_with($address, '::ffff:')) {
        $address = substr($address, 7);
    }
    return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && str_starts_with($address, '127.');
}

$path = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    $config = Config::fromEnvironment(dirname(__DIR__));
    $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!$config->allowRemote
        && is_string($remoteAddress)
        && !isLoopbackAddress($remoteAddress)) {
        jsonResponse(['detail' => '默认仅允许本机访问'], 403);
    }
    $http = new CurlHttpClient(
        $config->timeoutSeconds,
        $config->proxy,
        $config->cookiesFile,
    );
    $parser = new DouyinParser($http);
    $downloads = new DownloadService($config, $parser);

    if ($path === '/' && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        require dirname(__DIR__) . '/views/home.php';
        exit;
    }

    if ($path === '/api/health') {
        requireMethod('GET');
        jsonResponse([
            'status' => 'ok',
            'runtime' => 'PHP ' . PHP_VERSION,
            'minimum' => 'PHP 8.1',
            'cookies_configured' => $config->cookiesFile !== null && is_file($config->cookiesFile),
            'capabilities' => ['video_formats', 'album_images', 'parser_fallbacks'],
        ]);
    }

    if ($path === '/api/parse') {
        requireMethod('POST');
        $payload = readJsonBody();
        $url = $payload['url'] ?? null;
        if (!is_string($url)) {
            throw new InvalidUrlException('url 必须是字符串');
        }
        jsonResponse($parser->parse($url));
    }

    if ($path === '/api/parse/stream') {
        requireMethod('POST');
        $payload = readJsonBody();
        $url = $payload['url'] ?? null;
        if (!is_string($url)) {
            throw new InvalidUrlException('url 必须是字符串');
        }
        beginJsonStream();
        try {
            $video = $parser->parse(
                $url,
                static function (array $event): void {
                    streamEvent(array_merge(['type' => 'progress'], $event));
                },
            );
            streamEvent(['type' => 'result', 'data' => $video]);
        } catch (Throwable $exception) {
            streamFailure($exception);
        }
        exit;
    }

    if ($path === '/api/download') {
        requireMethod('POST');
        $payload = readJsonBody();
        $url = $payload['url'] ?? null;
        $filename = $payload['filename'] ?? null;
        $formatId = $payload['format_id'] ?? null;
        if (!is_string($url)) {
            throw new InvalidUrlException('url 必须是字符串');
        }
        if ($filename !== null && !is_string($filename)) {
            throw new InvalidUrlException('filename 必须是字符串');
        }
        if ($formatId !== null && !is_string($formatId)) {
            throw new InvalidUrlException('format_id 必须是字符串');
        }
        jsonResponse($downloads->download(
            $url,
            $filename,
            ($payload['overwrite'] ?? false) === true,
            null,
            $formatId,
        ));
    }

    if ($path === '/api/download/stream') {
        requireMethod('POST');
        $payload = readJsonBody();
        $url = $payload['url'] ?? null;
        $filename = $payload['filename'] ?? null;
        $formatId = $payload['format_id'] ?? null;
        if (!is_string($url)) {
            throw new InvalidUrlException('url 必须是字符串');
        }
        if ($filename !== null && !is_string($filename)) {
            throw new InvalidUrlException('filename 必须是字符串');
        }
        if ($formatId !== null && !is_string($formatId)) {
            throw new InvalidUrlException('format_id 必须是字符串');
        }
        beginJsonStream();
        try {
            $result = $downloads->download(
                $url,
                $filename,
                ($payload['overwrite'] ?? false) === true,
                static function (array $event): void {
                    streamEvent(array_merge(['type' => 'progress'], $event));
                },
                $formatId,
            );
            streamEvent(['type' => 'result', 'data' => $result]);
        } catch (Throwable $exception) {
            streamFailure($exception);
        }
        exit;
    }

    if ($path === '/api/files') {
        requireMethod('GET');
        jsonResponse(['files' => $downloads->listFiles()]);
    }

    if ($method === 'GET' && preg_match('~^/files/(.+)$~u', $path, $matches) === 1) {
        $file = $downloads->locateFile($matches[1]);
        if ($file === null) {
            jsonResponse(['detail' => '文件不存在'], 404);
        }
        $filename = basename($file);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($extension === 'zip' ? 'application/zip' : 'video/mp4'));
        header('Content-Length: ' . filesize($file));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
        header('Cache-Control: private, no-cache');
        readfile($file);
        exit;
    }

    jsonResponse(['detail' => '路由不存在'], 404);
} catch (InvalidUrlException $exception) {
    jsonResponse(['detail' => $exception->getMessage()], 422);
} catch (ConflictException $exception) {
    jsonResponse(['detail' => $exception->getMessage()], 409);
} catch (ExtractionException|DownloadException $exception) {
    jsonResponse(['detail' => $exception->getMessage()], 502);
} catch (Throwable $exception) {
    error_log((string) $exception);
    jsonResponse(['detail' => '服务器内部错误'], 500);
}
