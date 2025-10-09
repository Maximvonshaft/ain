<?php
return [
    'session' => [
        'cookie_secure' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_name' => 'memo_session',
    ],
    'hsts' => [
        'enable' => true,
        'max_age' => 31536000,
        'include_subdomains' => false,
    ],
    'csp' => [
        'mode' => 'report-only',
        'directives' => [
            'default-src' => "'self' cdn.jsdelivr.net",
            'img-src' => "'self' data: blob:",
            'style-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net",
            'font-src' => "cdn.jsdelivr.net",
            'script-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
        ],
    ],
];
