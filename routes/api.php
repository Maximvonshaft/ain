<?php

use App\Http\Controllers\MemoController;
use App\Http\Controllers\MindmapApiController;
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

$router->add('GET', '/api/v1/memos/{memo}/mindmaps', function (Request $request, array $params) {
    $controller = new MindmapApiController();
    return $controller->indexForMemo($request, $params);
});

$router->add('POST', '/api/v1/memos/{memo}/mindmaps', function (Request $request, array $params) {
    $controller = new MindmapApiController();
    return $controller->storeForMemo($request, $params);
});

$router->add('GET', '/api/v1/mindmaps/{mindmap}', function (Request $request, array $params) {
    $controller = new MindmapApiController();
    return $controller->show($request, $params);
});

$router->add('PATCH', '/api/v1/mindmaps/{mindmap}', function (Request $request, array $params) {
    $controller = new MindmapApiController();
    return $controller->update($request, $params);
});

$router->add('PATCH', '/api/v1/mindmaps/{mindmap}/nodes', function (Request $request, array $params) {
    $controller = new MindmapApiController();
    return $controller->syncNodes($request, $params);
});

$router->add('PATCH', '/api/v1/mindmaps/{mindmap}/edges', function (Request $request, array $params) {
    $controller = new MindmapApiController();
    return $controller->syncEdges($request, $params);
});

$router->add('DELETE', '/api/v1/mindmaps/{mindmap}', function (Request $request, array $params) {
    $controller = new MindmapApiController();
    return $controller->destroy($request, $params);
});

