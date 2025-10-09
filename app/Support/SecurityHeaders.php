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
            $https = $_SERVER['HTTPS'] ?? '';
            if ($https !== '' && strtolower((string)$https) !== 'off') {
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
}
