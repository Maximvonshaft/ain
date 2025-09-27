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

        $cached = '';

        $configured = trim((string) config('app.base_path', ''), '/');
        if ($configured !== '') {
            $cached = '/' . $configured;
        }

        if ($cached === '') {
            $url = (string) config('app.url', '');
            if ($url !== '') {
                $parts = parse_url($url) ?: [];
                $path = is_array($parts) ? ($parts['path'] ?? '') : '';
                $path = trim((string) $path, '/');
                if ($path !== '') {
                    $cached = '/' . $path;
                }
            }
        }

        if ($cached === '' && PHP_SAPI !== 'cli') {
            $candidates = [];
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            $phpSelf = $_SERVER['PHP_SELF'] ?? '';
            if ($scriptName !== '') {
                $candidates[] = $scriptName;
            }
            if ($phpSelf !== '' && $phpSelf !== $scriptName) {
                $candidates[] = $phpSelf;
            }

            foreach ($candidates as $candidate) {
                $candidate = str_replace('\\', '/', (string) $candidate);
                if ($candidate === '') {
                    continue;
                }

                $dir = rtrim(dirname($candidate), '/');
                if ($dir === '.' || $dir === '/' || $dir === '') {
                    continue;
                }

                if (str_ends_with($dir, '/public')) {
                    $dir = rtrim(substr($dir, 0, -strlen('/public')), '/');
                }

                if ($dir === '') {
                    continue;
                }

                $cached = '/' . ltrim($dir, '/');
                break;
            }
        }

        if ($cached === '/' || $cached === null) {
            $cached = '';
        }

        return $cached;
    }
}

