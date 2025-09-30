<?php

use App\Controllers\LegacyMemoController;
use Core\Request;
use Core\Router;

$legacy = new LegacyMemoController();

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
