<?php

use App\Http\Controllers\MemoController;
use App\Http\Controllers\MindmapController;
use App\Http\Request;

$router->add('GET', '/', function (Request $request) {
    $controller = new MemoController();
    return $controller->index($request);
});

$router->add('GET', '/mindmaps/{mindmap}', function (Request $request, array $params) {
    $controller = new MindmapController();
    return $controller->show($request, $params);
});

