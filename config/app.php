<?php

use App\Memo\Config\AllowedMimes;

return [
    'name' => 'Memo',
    'env' => getenv('APP_ENV') ?: 'production',
    'base_url' => getenv('APP_URL') ?: '',
    'timezone' => 'Asia/Shanghai',
    'database' => [
        'path' => __DIR__ . '/../memo.sqlite',
    ],
    'uploads' => [
        'path' => __DIR__ . '/../storage/uploads',
        'max_bytes' => 15 * 1024 * 1024,
        'allowed_mimes' => array_merge(
            AllowedMimes::defaults(),
            [
                // 在此添加自定义 MIME => 扩展名 映射，例如：
                // 'application/x-example' => 'ex',
            ],
        ),
    ],
    'imports' => [
        'max_bytes' => 1024 * 1024,
        'mime_types' => [
            'application/json',
            'text/json',
            'text/plain',
        ],
    ],
];
