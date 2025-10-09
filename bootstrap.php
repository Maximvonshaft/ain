<?php

declare(strict_types=1);

use App\Support\SecurityHeaders;
use Core\Config;
use Core\DB;
use App\Memo\Legacy\Runtime as LegacyRuntime;

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'App\\' => __DIR__ . '/app/',
        'Core\\' => __DIR__ . '/core/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

require_once __DIR__ . '/app/Support/helpers.php';
require_once __DIR__ . '/app/Memo/Legacy/Application.php';

$config = new Config(__DIR__ . '/config');

SecurityHeaders::apply($config);
if (class_exists(LegacyRuntime::class)) {
    LegacyRuntime::markSecurityHeadersApplied();
}

$timezone = $config->get('app.timezone', 'UTC');
if ($timezone) {
    date_default_timezone_set($timezone);
}

$dbPath = $config->get('app.database.path');
if (!is_string($dbPath)) {
    throw new \RuntimeException('Database path not configured');
}

DB::connect($dbPath);

$uploadDir = $config->get('app.uploads.path');
if (is_string($uploadDir) && !is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

return $config;
