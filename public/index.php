<?php

declare(strict_types=1);

use Core\Request;
use Core\Router;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/../bootstrap.php';

$request = Request::fromGlobals();
$router = new Router();

require __DIR__ . '/../routes/web.php';

$router->dispatch($request);
