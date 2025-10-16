<?php

declare(strict_types=1);

use App\Controllers\MemoController;
use App\Memo\Legacy\LegacyMemoRunner;
use App\Middlewares\CsrfMiddleware;
use App\Support\SessionConfigurator;
use Core\Request;

$config = require __DIR__ . '/../bootstrap.php';

SessionConfigurator::configure($config);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$baseUrl = (string)($config->get('app.base_url', '') ?? '');
$basePath = '';
if ($baseUrl !== '') {
    $parsedPath = parse_url($baseUrl, PHP_URL_PATH);
    if (is_string($parsedPath)) {
        $basePath = $parsedPath;
    }
}

$request = Request::fromGlobals($basePath);
$csrf = new CsrfMiddleware();
$runner = new LegacyMemoRunner($config);
$controller = new MemoController($runner, $csrf);

if ($request->isPost()) {
    $controller->store($request);
} else {
    $controller->index($request);
}
