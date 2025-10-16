<?php

declare(strict_types=1);

namespace App\Support;

final class HttpSchemeDetector
{
    /**
     * @param array<string, mixed> $server
     */
    public static function isHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME'] as $header) {
            if (self::isForwardedProtoHttps($server[$header] ?? null)) {
                return true;
            }
        }

        foreach (['HTTP_X_FORWARDED_SSL', 'HTTP_FRONT_END_HTTPS'] as $header) {
            $value = $server[$header] ?? null;
            if (is_string($value) && strtolower(trim($value)) === 'on') {
                return true;
            }
        }

        $forwarded = $server['HTTP_FORWARDED'] ?? null;
        if (is_string($forwarded) && $forwarded !== '') {
            foreach (explode(',', $forwarded) as $part) {
                foreach (explode(';', $part) as $directive) {
                    $pair = explode('=', trim($directive), 2);
                    if (count($pair) !== 2) {
                        continue;
                    }
                    if (strtolower($pair[0]) === 'proto' && strtolower(trim($pair[1], " \"'")) === 'https') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function isForwardedProtoHttps(mixed $value): bool
    {
        if (!is_string($value) || $value === '') {
            return false;
        }

        $first = explode(',', $value)[0] ?? '';
        return strtolower(trim($first)) === 'https';
    }
}
