<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class CurlHttpClient implements HttpClientInterface
{
    public const USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) '
        . 'AppleWebKit/605.1.15 Mobile/15E148';

    public function __construct(
        private int $timeoutSeconds = 20,
        private ?string $proxy = null,
        private ?string $cookiesFile = null,
        private int $maxBodyBytes = 6291456,
    ) {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP curl extension is required');
        }
    }

    public function get(string $url): HttpResponse
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        $status = 0;
        $headers = [];
        $body = '';
        $tooLarge = false;

        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9',
            ],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$status, &$headers): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $matches) === 1) {
                    $status = (int) $matches[1];
                    $headers = [];
                } elseif (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxBodyBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);

        if ($this->proxy !== null) {
            curl_setopt($curl, CURLOPT_PROXY, $this->proxy);
        }
        if ($this->cookiesFile !== null) {
            if (!is_file($this->cookiesFile)) {
                curl_close($curl);
                throw new RuntimeException('DOUYIN_COOKIES_FILE does not exist');
            }
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->cookiesFile);
        }

        $success = curl_exec($curl);
        $error = curl_error($curl);
        $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
        if ($status === 0) {
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        }
        curl_close($curl);

        if ($success === false) {
            if ($tooLarge) {
                throw new RuntimeException('Upstream response is too large');
            }
            throw new RuntimeException('Upstream request failed: ' . $error);
        }

        return new HttpResponse($status, $headers, $body, $effectiveUrl ?: $url);
    }
}
