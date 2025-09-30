<?php

declare(strict_types=1);

use Core\Request;
use Core\Router;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$config = require __DIR__ . '/../bootstrap.php';

$baseUrl = (string)($config->get('app.base_url') ?? '');
$basePath = '';

if ($baseUrl !== '') {
    $parsedPath = parse_url($baseUrl, PHP_URL_PATH);
    if (is_string($parsedPath)) {
        $basePath = $parsedPath;
    }
}

if ($basePath === '') {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($scriptName !== '') {
        $scriptDir = str_replace('\\', '/', dirname($scriptName));
        if ($scriptDir !== '/' && $scriptDir !== '\\' && $scriptDir !== '.') {
            $basePath = $scriptDir;
        }
    }
}

$request = Request::fromGlobals();
$router = new Router($basePath);

require __DIR__ . '/../routes/web.php';

$router->dispatch($request);
