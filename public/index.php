<?php

declare(strict_types=1);

use Core\Request;
use Core\Router;
use Core\SecurityHeaders;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** @var \Core\Config $config */
$config = require __DIR__ . '/../bootstrap.php';

$baseUrl = (string)($config->get('app.base_url', '') ?? '');
$basePath = '';
if ($baseUrl !== '') {
    $parsedPath = parse_url($baseUrl, PHP_URL_PATH);
    if (is_string($parsedPath)) {
        $basePath = $parsedPath;
    }
}

$request = Request::fromGlobals($basePath);
$securityHeaders = new SecurityHeaders($config);
$securityHeaders->apply($request);
$router = new Router();

require __DIR__ . '/../routes/web.php';

$router->dispatch($request);
