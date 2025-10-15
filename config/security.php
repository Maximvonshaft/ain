<?php

use App\Support\MemoCspDefaults;

return [
    'session' => [
        'cookie_secure' => (($value = getenv('SESSION_COOKIE_SECURE')) !== false) ? $value : 'auto',
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_name' => 'memo_session',
    ],
    'headers' => [
        'remove' => ['X-Powered-By'],
        'set' => [
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
        ],
    ],
    'hsts' => [
        'enable' => true,
        'max_age' => 31536000,
        'include_subdomains' => false,
    ],
    'csp' => [
        'mode' => 'enforce',
        'directives' => MemoCspDefaults::directives(),
    ],
];
