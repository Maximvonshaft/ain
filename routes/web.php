<?php

use App\Controllers\MemoController;
use App\Controllers\PortalController;
use App\Memo\Legacy\LegacyMemoRunner;
use App\Middlewares\CsrfMiddleware;
use Core\Router;

$csrf = new CsrfMiddleware();
$runner = new LegacyMemoRunner($config);
$controller = new MemoController($runner, $csrf);
$portal = new PortalController($csrf);

/** @var Router $router */
$router->get('/', [$portal, 'index']);
$router->get('/index.php', [$portal, 'index']);
$router->post('/portal/directives', [$portal, 'storeDirective']);
$router->get('/memo', [$controller, 'index']);
$router->post('/memo', [$controller, 'store']);
$router->get('/memo/index.php', [$controller, 'index']);
$router->post('/memo/index.php', [$controller, 'store']);
