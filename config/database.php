<?php

return [
    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env_value('DB_DATABASE', base_path('database/database.sqlite')),
        ],
    ],

    'migrations' => [
        'path' => base_path('database/migrations'),
    ],
];

