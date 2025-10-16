<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Legacy Application Settings
    |--------------------------------------------------------------------------
    |
    | 在迁移过程中保留遗留应用所需的运行时配置，确保新的 Laravel 项目能够
    | 与旧有的数据库与上传目录保持一致。这些键值与原先单体应用的 config/app.php
    | 中的设置相对应，方便后续按模块逐步迁移。
    |
    */

    'legacy' => [
        'database' => [
            'path' => value(function () {
                $path = env('LEGACY_DATABASE_PATH', base_path('legacy-app/memo.sqlite'));

                if ($path === ':memory:') {
                    return $path;
                }

                $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
                    || str_starts_with($path, '\\\\')
                    || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');

                return $isAbsolute ? $path : base_path($path);
            }),
        ],
        'uploads' => [
            'path' => value(function () {
                $path = env('LEGACY_UPLOAD_PATH', base_path('legacy-app/storage/uploads'));

                $isAbsolute = str_starts_with($path, DIRECTORY_SEPARATOR)
                    || str_starts_with($path, '\\\\')
                    || (strlen($path) > 1 && ctype_alpha($path[0]) && $path[1] === ':');

                return $isAbsolute ? $path : base_path($path);
            }),
            'max_bytes' => (int) env('LEGACY_UPLOAD_MAX_BYTES', 15 * 1024 * 1024),
            'allowed_mimes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('LEGACY_UPLOAD_ALLOWED_MIMES', ''))
            ))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
