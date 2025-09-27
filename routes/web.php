<?php

use App\Http\Controllers\MemoController;
use App\Http\Request;

$router->add('GET', '/', function (Request $request) {
    $controller = new MemoController();
    return $controller->index($request);
});

