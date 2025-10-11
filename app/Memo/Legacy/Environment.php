<?php

namespace App\Memo\Legacy;

use App\Memo\Config\RuntimeConfig;
use Core\Config;

final class Environment
{
    private static ?Config $config = null;
    private static ?RuntimeConfig $runtimeConfig = null;
    private static ?string $csrfToken = null;
    private static bool $securityHeadersApplied = false;
    private static string $basePath = '';

    public static function bootstrap(
        Config $config,
        string $csrfToken,
        RuntimeConfig $runtimeConfig,
        string $basePath = ''
    ): void
    {
        self::$config = $config;
        self::$csrfToken = $csrfToken;
        self::$runtimeConfig = $runtimeConfig;
        self::$securityHeadersApplied = false;
        self::$basePath = self::normalizeBasePath($basePath);
    }

    public static function config(): ?Config
    {
        return self::$config;
    }

    public static function runtimeConfig(): RuntimeConfig
    {
        if (!self::$runtimeConfig) {
            throw new \RuntimeException('Memo runtime configuration has not been initialised.');
        }

        return self::$runtimeConfig;
    }

    public static function csrfToken(): ?string
    {
        return self::$csrfToken;
    }

    public static function securityHeadersApplied(): bool
    {
        return self::$securityHeadersApplied;
    }

    public static function markSecurityHeadersApplied(): void
    {
        self::$securityHeadersApplied = true;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    private static function normalizeBasePath(string $basePath): string
    {
        if ($basePath === '' || $basePath === '/') {
            return '';
        }

        $normalized = '/' . ltrim($basePath, '/');

        return rtrim($normalized, '/');
    }
}
