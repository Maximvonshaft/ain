<?php

require __DIR__ . '/autoload.php';

$app = new App\Application();

$router = $app->router();

require base_path('routes/web.php');
require base_path('routes/api.php');

return $app;

