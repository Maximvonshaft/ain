<?php

declare(strict_types=1);

function view_escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return view_escape($value);
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

if (!function_exists('dt')) {
    function dt(int $timestamp): string
    {
        return format_datetime($timestamp);
    }
}

if (!function_exists('ensure_directory')) {
    function ensure_directory(string $path, int $mode = 0o775): void
    {
        $path = rtrim($path);
        if ($path === '') {
            throw new RuntimeException('Directory path cannot be empty.');
        }

        if (is_dir($path)) {
            if (!is_writable($path)) {
                error_log(sprintf('Directory is not writable: %s', $path));
                throw new RuntimeException('目录不可写: ' . $path);
            }

            return;
        }

        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            error_log(sprintf('Failed to create directory: %s', $path));
            throw new RuntimeException('无法创建目录: ' . $path);
        }

        if (!is_writable($path)) {
            error_log(sprintf('Directory created but not writable: %s', $path));
            throw new RuntimeException('目录不可写: ' . $path);
        }
    }
}
