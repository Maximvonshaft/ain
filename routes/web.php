<?php

use Core\Request;
use Core\Response;

/** @var \Core\Router $router */

$router->get('/', function (Request $request): void {
    Response::json([
        'message' => 'Memo API 服务已就绪。请使用前端 SPA 通过 /api/* 访问数据接口。',
    ]);
});
