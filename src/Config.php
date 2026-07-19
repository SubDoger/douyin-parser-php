<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

final class Config
{
    public function __construct(
        public string $downloadDir,
        public ?string $cookiesFile,
        public ?string $proxy,
        public int $timeoutSeconds,
        public int $mediaTimeoutSeconds,
        public int $maxFileSizeMb,
        public int $maxStorageMb,
        public bool $allowRemote,
    ) {
    }

    public static function fromEnvironment(?string $projectRoot = null): self
    {
        $projectRoot ??= dirname(__DIR__);
        $downloadDir = getenv('DOUYIN_DOWNLOAD_DIR') ?: $projectRoot . '/downloads';
        $cookiesFile = getenv('DOUYIN_COOKIES_FILE') ?: null;
        $proxy = getenv('DOUYIN_PROXY') ?: null;

        return new self(
            self::absolutePath($downloadDir, $projectRoot),
            $cookiesFile ? self::absolutePath($cookiesFile, $projectRoot) : null,
            $proxy,
            self::positiveInteger('DOUYIN_TIMEOUT', 20),
            self::positiveInteger('DOUYIN_MEDIA_TIMEOUT', 900),
            self::positiveInteger('DOUYIN_MAX_FILESIZE_MB', 500),
            self::positiveInteger('DOUYIN_MAX_STORAGE_MB', 5000),
            self::boolean('APP_ALLOW_REMOTE', false),
        );
    }

    private static function boolean(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        $normalized = strtolower($value);
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        throw new InvalidArgumentException($name . ' must be a boolean');
    }

    private static function positiveInteger(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException($name . ' must be a positive integer');
        }
        return (int) $value;
    }

    private static function absolutePath(string $path, string $projectRoot): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }
        return rtrim($projectRoot, '/') . '/' . ltrim($path, '/');
    }
}
