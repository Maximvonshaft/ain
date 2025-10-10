<?php

declare(strict_types=1);

namespace App\Support;

use Core\Config;

final class SecurityHeaders
{
    public static function apply(Config $config): void
    {
        if (headers_sent()) {
            return;
        }

        $settings = $config->get('security', []);
        if (!is_array($settings)) {
            return;
        }

        $headers = $settings['headers']['set'] ?? [];
        $remove = $settings['headers']['remove'] ?? [];

        if (is_array($remove)) {
            foreach ($remove as $name) {
                if (is_string($name) && $name !== '') {
                    header_remove($name);
                }
            }
        }

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if ($name === '' || $value === null) {
                    continue;
                }
                header($name . ': ' . $value);
            }
        }

        $hsts = $settings['hsts'] ?? null;
        if (is_array($hsts) && !empty($hsts['enable'])) {
            if (self::isHttps($_SERVER)) {
                $maxAge = (int)($hsts['max_age'] ?? 0);
                $directives = ['max-age=' . max(0, $maxAge)];
                if (!empty($hsts['include_subdomains'])) {
                    $directives[] = 'includeSubDomains';
                }
                if (!empty($hsts['preload'])) {
                    $directives[] = 'preload';
                }
                header('Strict-Transport-Security: ' . implode('; ', $directives));
            }
        }

        $csp = $settings['csp'] ?? null;
        if (is_array($csp)) {
            $directives = [];
            $rawDirectives = $csp['directives'] ?? [];
            if (is_array($rawDirectives)) {
                foreach ($rawDirectives as $directive => $value) {
                    $valueString = trim((string)$value);
                    if ($directive === '' || $valueString === '') {
                        continue;
                    }
                    $directives[] = $directive . ' ' . $valueString;
                }
            }
            if ($directives) {
                $headerName = (isset($csp['mode']) && $csp['mode'] === 'report-only')
                    ? 'Content-Security-Policy-Report-Only'
                    : 'Content-Security-Policy';
                header($headerName . ': ' . implode('; ', $directives));
            }
        }

        $GLOBALS['_legacy_security_headers_applied'] = true;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function isHttps(array $server): bool
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
