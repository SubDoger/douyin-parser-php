<?php

declare(strict_types=1);

namespace App\Http;

final class HttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $url,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }
}
