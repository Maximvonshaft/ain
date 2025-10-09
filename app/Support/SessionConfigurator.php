<?php

declare(strict_types=1);

namespace App\Support;

use Core\Config;

final class SessionConfigurator
{
    public static function apply(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $settings = $config->get('security.session');
        if (!is_array($settings) || $settings === []) {
            return;
        }

        $cookieParams = session_get_cookie_params();
        $options = [
            'lifetime' => $cookieParams['lifetime'] ?? 0,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => (bool)($settings['cookie_secure'] ?? ($cookieParams['secure'] ?? false)),
            'httponly' => (bool)($settings['cookie_httponly'] ?? ($cookieParams['httponly'] ?? true)),
            'samesite' => (string)($settings['cookie_samesite'] ?? ($cookieParams['samesite'] ?? 'Lax')),
        ];

        if (isset($settings['cookie_name']) && is_string($settings['cookie_name']) && $settings['cookie_name'] !== '') {
            session_name($settings['cookie_name']);
        }

        session_set_cookie_params($options);
    }
}
