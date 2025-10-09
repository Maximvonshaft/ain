<?php
return [
    'session' => [
        'cookie_secure' => true,
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
            'default-src' => "'self' cdn.jsdelivr.net",
            'img-src' => "'self' data: blob:",
            'style-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com",
            'font-src' => "fonts.gstatic.com",
            'script-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
        ],
    ],
];
