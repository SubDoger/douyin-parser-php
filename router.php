<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicRoot = realpath(__DIR__ . '/public');
$candidate = realpath(__DIR__ . '/public' . $path);

if ($path !== '/'
    && $publicRoot !== false
    && $candidate !== false
    && str_starts_with($candidate, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($candidate)) {
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $contentTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    header('Content-Type: ' . ($contentTypes[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($candidate));
    header('Cache-Control: public, max-age=3600');
    readfile($candidate);
    exit;
}

require __DIR__ . '/public/index.php';
