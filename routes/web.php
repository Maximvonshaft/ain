<?php

use App\Controllers\MemoController;
use App\Middlewares\CsrfMiddleware;
use Core\Request;
use Core\Router;

$csrf = new CsrfMiddleware();
$memo = new MemoController($config, $csrf);

/** @var Router $router */
foreach (['/', '/index.php'] as $path) {
    $router->get($path, function (Request $request) use ($memo) {
        $memo->handle($request);
    });

    $router->post($path, function (Request $request) use ($memo) {
        $memo->handle($request);
    });
}
