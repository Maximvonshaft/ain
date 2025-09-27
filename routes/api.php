<?php

use App\Http\Controllers\MemoController;
use App\Http\Request;

$router->add('POST', '/api/v1/memos', function (Request $request) {
    $controller = new MemoController();
    return $controller->store($request);
});

$router->add('PATCH', '/api/v1/memos/{memo}', function (Request $request, array $params) {
    $controller = new MemoController();
    return $controller->update($request, $params);
});

$router->add('PATCH', '/api/v1/memos/{memo}/toggle', function (Request $request, array $params) {
    $controller = new MemoController();
    return $controller->toggle($request, $params);
});

$router->add('POST', '/api/v1/memos/{memo}/subtasks', function (Request $request, array $params) {
    $controller = new MemoController();
    return $controller->addSubtask($request, $params);
});

$router->add('PATCH', '/api/v1/subtasks/{subtask}/toggle', function (Request $request, array $params) {
    $controller = new MemoController();
    return $controller->toggleSubtask($request, $params);
});

