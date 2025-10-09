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

if (!function_exists('now')) {
    function now(): int
    {
        return time();
    }
}

if (!function_exists('bytes_h')) {
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
}

if (!function_exists('format_datetime')) {
    function format_datetime(int $timestamp): string
    {
        return date('Y-m-d H:i', $timestamp);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return (string)($_SESSION['_csrf_token'] ?? '');
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $token = csrf_token();
        return '<input type="hidden" name="_csrf" value="' . view_escape($token) . '">';
    }
}
