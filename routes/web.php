<?php

use App\Controllers\MemoController;
use App\Memo\Legacy\LegacyMemoRunner;
use App\Middlewares\CsrfMiddleware;
use Core\Router;

$csrf = new CsrfMiddleware();
$runner = new LegacyMemoRunner($config);
$controller = new MemoController($runner, $csrf);

/** @var Router $router */
$router->get('/', [$controller, 'index']);
$router->post('/', [$controller, 'store']);
$router->get('/index.php', [$controller, 'index']);
$router->post('/index.php', [$controller, 'store']);
