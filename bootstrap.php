<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    throw new RuntimeException('PHP 8.1 or newer is required');
}

define('PROJECT_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = PROJECT_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Shanghai');
