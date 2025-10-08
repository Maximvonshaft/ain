<?php

declare(strict_types=1);

use App\Repositories\AttachmentRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ItemRepository;
use App\Repositories\StepRepository;
use App\Services\MemoService;
use Core\Request;
use Core\Router;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/** @var \Core\Config $config */
$config = require __DIR__ . '/../bootstrap.php';

$baseUrl = (string)($config->get('app.base_url', '') ?? '');
$basePath = '';
if ($baseUrl !== '') {
    $parsedPath = parse_url($baseUrl, PHP_URL_PATH);
    if (is_string($parsedPath)) {
        $basePath = $parsedPath;
    }
}

$request = Request::fromGlobals($basePath);
$router = new Router();

$uploadConfig = (array)$config->get('app.uploads', []);
$uploadDir = (string)($uploadConfig['path'] ?? (__DIR__ . '/../storage/uploads'));
$maxBytes = (int)($uploadConfig['max_bytes'] ?? (15 * 1024 * 1024));
$allowedMimes = is_array($uploadConfig['allowed_mimes'] ?? null) ? $uploadConfig['allowed_mimes'] : [];

$memoService = new MemoService(
    new CategoryRepository(),
    new ItemRepository(),
    new StepRepository(),
    new AttachmentRepository($uploadDir),
    $maxBytes,
    $allowedMimes
);

require __DIR__ . '/../routes/api.php';
require __DIR__ . '/../routes/web.php';

$router->dispatch($request);
