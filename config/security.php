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
            'default-src' => "'self' cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            'img-src' => "'self' data: blob: https://tile.openstreetmap.org https://*.basemaps.cartocdn.com",
            'style-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            'font-src' => "'self' data: https://fonts.gstatic.com",
            'script-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            'connect-src' => "'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://tile.openstreetmap.org https://*.basemaps.cartocdn.com",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
        ],
    ],
];
