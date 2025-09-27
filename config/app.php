<?php

return [
    'name' => env_value('APP_NAME', 'Memo Suite'),
    'env' => env_value('APP_ENV', 'local'),
    'debug' => env_value('APP_DEBUG', 'true') === 'true',
    'url' => env_value('APP_URL', 'http://localhost'),
    'timezone' => env_value('APP_TIMEZONE', 'Asia/Shanghai'),
    'features' => [
        'auth' => env_value('FEATURES_AUTH', 'false') === 'true',
        'mindmap' => env_value('FEATURES_MINDMAP', 'false') === 'true',
        'search' => env_value('FEATURES_SEARCH', 'false') === 'true',
    ],
];

