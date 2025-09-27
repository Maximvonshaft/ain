<?php

use App\Support\Config;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = realpath(__DIR__ . '/..');
        $base = dirname($base);
        return $path ? $base . '/' . ltrim($path, '/') : $base;
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        $storage = base_path('storage');
        return $path ? $storage . '/' . ltrim($path, '/') : $storage;
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::getInstance()->get($key, $default);
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('app_base_path')) {
    function app_base_path(): string
    {
        static $cached;
        if ($cached !== null) {
            return $cached;
        }

        $configured = trim((string) config('app.base_path', ''), '/');
        if ($configured !== '') {
            $cached = '/' . $configured;
        } else {
            $url = (string) config('app.url', '');
            $parts = $url ? parse_url($url) : [];
            $path = is_array($parts) ? ($parts['path'] ?? '') : '';
            $path = trim((string) $path, '/');
            $cached = $path !== '' ? '/' . $path : '';
        }

        if ($cached === '/') {
            $cached = '';
        }

        return $cached;
    }
}

