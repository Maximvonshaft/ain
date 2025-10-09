<?php

declare(strict_types=1);

use App\Middlewares\CsrfMiddleware;
use Core\Config;

function view_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    try {
        return Config::instance()->get($key, $default);
    } catch (\RuntimeException) {
        return $default;
    }
}

function now(): int
{
    return time();
}

function bytes_h(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $index = 0;
    $value = (float)$bytes;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return sprintf(($value >= 10 || $index === 0) ? '%.0f %s' : '%.1f %s', $value, $units[$index]);
}

function format_datetime(int $timestamp): string
{
    return date('Y-m-d H:i', $timestamp);
}

function allowed_upload_mime_map(): array
{
    $mimes = config('app.uploads.allowed_mimes', []);

    return is_array($mimes) ? $mimes : [];
}

function csrf_token(): string
{
    static $middleware;

    if (!$middleware instanceof CsrfMiddleware) {
        $middleware = new CsrfMiddleware();
    }

    return $middleware->token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . view_escape(csrf_token()) . '">';
}
