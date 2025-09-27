<?php

$app = require __DIR__ . '/../bootstrap/app.php';

$request = new App\Http\Request();
$response = $app->handle($request);
$response->send();

