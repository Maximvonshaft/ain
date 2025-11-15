<?php

declare(strict_types=1);

namespace App\Support;

use Core\Config;

final class SessionConfigurator
{
    public static function configure(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $session = $config->get('security.session');
        if (!is_array($session) || $session === []) {
            return;
        }

        $cookieParams = session_get_cookie_params();

        if (array_key_exists('cookie_secure', $session)) {
            $cookieParams['secure'] = self::resolveCookieSecure($session['cookie_secure']);
        }

        if (array_key_exists('cookie_httponly', $session)) {
            $cookieParams['httponly'] = (bool) $session['cookie_httponly'];
        }

        if (array_key_exists('cookie_path', $session) && is_string($session['cookie_path'])) {
            $cookieParams['path'] = $session['cookie_path'];
        }

        if (array_key_exists('cookie_domain', $session) && is_string($session['cookie_domain'])) {
            $cookieParams['domain'] = $session['cookie_domain'];
        }

        if (array_key_exists('cookie_samesite', $session) && is_string($session['cookie_samesite'])) {
            $cookieParams['samesite'] = $session['cookie_samesite'];
        }

        session_set_cookie_params([
            'lifetime' => (int) ($cookieParams['lifetime'] ?? 0),
            'path' => (string) ($cookieParams['path'] ?? '/'),
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => (bool) ($cookieParams['secure'] ?? false),
            'httponly' => (bool) ($cookieParams['httponly'] ?? false),
            'samesite' => $cookieParams['samesite'] ?? 'Lax',
        ]);

        if (isset($session['cookie_name']) && is_string($session['cookie_name']) && $session['cookie_name'] !== '') {
            session_name($session['cookie_name']);
        }
    }

    private static function resolveCookieSecure(mixed $value): bool
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'auto') {
                return HttpSchemeDetector::isHttps($_SERVER);
            }

            if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        if ($value === null) {
            return false;
        }

        return (bool) $value;
    }
}
