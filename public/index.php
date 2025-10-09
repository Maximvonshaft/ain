<?php

declare(strict_types=1);

use App\Support\SecurityHeaders;
use Core\Request;
use Core\Router;

/** @var \Core\Config $config */
$config = require __DIR__ . '/../bootstrap.php';

$sessionConfig = $config->get('security.session', []);
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (is_array($sessionConfig)) {
        $cookieParams = [
            'secure' => (bool)($sessionConfig['cookie_secure'] ?? false),
            'httponly' => (bool)($sessionConfig['cookie_httponly'] ?? true),
            'samesite' => $sessionConfig['cookie_samesite'] ?? 'Lax',
        ];
        if (isset($sessionConfig['cookie_lifetime'])) {
            $cookieParams['lifetime'] = (int)$sessionConfig['cookie_lifetime'];
        }
        session_set_cookie_params($cookieParams);
        if (!empty($sessionConfig['cookie_name']) && is_string($sessionConfig['cookie_name'])) {
            session_name($sessionConfig['cookie_name']);
        }
    }

    session_start();
}

(new SecurityHeaders($config))->apply();

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

require __DIR__ . '/../routes/web.php';

$router->dispatch($request);
