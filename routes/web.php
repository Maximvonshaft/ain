<?php

use App\Controllers\LegacyMemoController;
use App\Middlewares\CsrfMiddleware;
use Core\Request;
use Core\Router;

$csrf = new CsrfMiddleware();
$legacy = new LegacyMemoController($config, $csrf);

/** @var Router $router */
$router->get('/', function (Request $request) use ($legacy) {
    $legacy->handle($request);
});

$router->post('/', function (Request $request) use ($legacy) {
    $legacy->handle($request);
});

$router->get('/index.php', function (Request $request) use ($legacy) {
    $legacy->handle($request);
});

$router->post('/index.php', function (Request $request) use ($legacy) {
    $legacy->handle($request);
});
