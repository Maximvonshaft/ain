<?php

use App\Controllers\LegacyMemoController;
use App\Middlewares\CsrfMiddleware;
use Core\Request;
use Core\Router;

$legacy = new LegacyMemoController();
$csrf = new CsrfMiddleware();

/** @var Router $router */
$router->get('/', function (Request $request) use ($legacy, $csrf) {
    $csrf->token($request);
    $legacy->handle($request);
});

$router->post('/', function (Request $request) use ($legacy, $csrf) {
    $csrf->verify($request);
    $legacy->handle($request);
});

$router->get('/index.php', function (Request $request) use ($legacy, $csrf) {
    $csrf->token($request);
    $legacy->handle($request);
});

$router->post('/index.php', function (Request $request) use ($legacy, $csrf) {
    $csrf->verify($request);
    $legacy->handle($request);
});
