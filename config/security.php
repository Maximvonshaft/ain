<?php
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
        'directives' => [
            'default-src' => "'self' cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.gstatic.com",
            'img-src' => "'self' data: blob:",
            'style-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            'font-src' => "'self' data: https://fonts.gstatic.com",
            'script-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
        ],
    ],
];
