<?php

declare(strict_types=1);

use App\Middlewares\CsrfMiddleware;
use Core\Request;
use Core\Router;

require __DIR__ . '/bootstrap.php';

final class Assert
{
    private int $count = 0;

    public function true(bool $condition, string $message = 'Assertion failed'): void
    {
        $this->count++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public function same(mixed $expected, mixed $actual, string $message = 'Values are not identical'): void
    {
        $this->count++;
        if ($expected !== $actual) {
            throw new \RuntimeException($message . sprintf('\nExpected: %s\nActual: %s', var_export($expected, true), var_export($actual, true)));
        }
    }

    public function count(): int
    {
        return $this->count;
    }
}

$assert = new Assert();

testRouter($assert);
testCsrfMiddleware($assert);
testRequestBasePathDetection($assert);

echo 'OK (' . $assert->count() . " assertions)\n";

function testRouter(Assert $assert): void
{
    $router = new Router();
    $handlerCalls = [];
    $fileCalls = [];

    $router->get('/notes', function (Request $request) use (&$handlerCalls) {
        $handlerCalls[] = $request->method();
        return 'handled';
    });

    $router->get('/files/{name}.json', function (Request $request, string $name) use (&$fileCalls) {
        $fileCalls[] = $name;
        return 'file:' . $name;
    });

    $session = [];
    $request = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/notes',
    ], [], [], $session);
    $assert->same('handled', $router->dispatch($request), 'GET route should return handler result');
    $assert->same(['GET'], $handlerCalls, 'GET request should invoke handler once');

    $session = [];
    $requestHead = new Request([], [], [
        'REQUEST_METHOD' => 'HEAD',
        'REQUEST_URI' => '/notes',
    ], [], [], $session);
    $buffer = capture(fn () => $router->dispatch($requestHead));
    $assert->same('', $buffer->output, 'HEAD fallback should not emit a body');
    $assert->same('handled', $buffer->result, 'HEAD fallback should reuse GET handler');
    $assert->same(['GET', 'HEAD'], $handlerCalls, 'HEAD fallback should invoke handler with HEAD method');

    http_response_code(200);
    $session = [];
    $requestPost = new Request([], [], [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/notes',
    ], [], [], $session);
    $buffer = capture(fn () => $router->dispatch($requestPost));
    $assert->same('Method Not Allowed', $buffer->output, 'POST should respond with 405 when route exists for other methods');
    $assert->same(405, http_response_code(), 'POST should set HTTP 405 status');

    http_response_code(200);
    $session = [];
    $requestMissing = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/missing',
    ], [], [], $session);
    $buffer = capture(fn () => $router->dispatch($requestMissing));
    $assert->same('Not Found', $buffer->output, 'Missing routes should respond with 404 body');
    $assert->same(404, http_response_code(), 'Missing routes should set HTTP 404 status');

    http_response_code(200);
    $session = [];
    $requestFile = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/files/report.json',
    ], [], [], $session);
    $assert->same('file:report', $router->dispatch($requestFile), 'Dynamic routes should capture parameters');
    $assert->same(['report'], $fileCalls, 'Dynamic route handler should receive captured parameter');

    http_response_code(200);
    $session = [];
    $requestMismatch = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/files/reportXjson',
    ], [], [], $session);
    $buffer = capture(fn () => $router->dispatch($requestMismatch));
    $assert->same('Not Found', $buffer->output, 'Literal segments after parameters must match exactly');
    $assert->same(404, http_response_code(), 'Literal mismatch should produce 404 status');
}

function testCsrfMiddleware(Assert $assert): void
{
    $middleware = new CsrfMiddleware();
    $session = [];
    $request = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/',
    ], [], [], $session);

    $token = $middleware->token($request);
    $assert->true(is_string($token) && $token !== '', 'Token generation should return a non-empty string');
    $assert->same($token, $middleware->token($request), 'Token generation should be idempotent per session');

    $postRequest = new Request([], ['_csrf' => $token], [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/',
    ], [], [], $session);
    $middleware->verify($postRequest);
    $assert->true(true, 'Valid POST token should pass verification');

    $headerRequest = new Request([], [], [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/',
        'HTTP_X_CSRF_TOKEN' => $token,
    ], [], [], $session);
    $middleware->verify($headerRequest);
    $assert->true(true, 'Valid header token should pass verification');

    $invalidRequest = new Request([], ['_csrf' => 'invalid'], [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/',
    ], [], [], $session);

    $thrown = false;
    try {
        $middleware->verify($invalidRequest);
    } catch (\RuntimeException) {
        $thrown = true;
    }
    $assert->true($thrown, 'Invalid token should trigger RuntimeException');
}

function testRequestBasePathDetection(Assert $assert): void
{
    $session = [];
    $request = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/app/memo',
        'SCRIPT_NAME' => '/app/index.php',
    ], [], [], $session);

    $assert->same('/app', $request->basePath(), 'SCRIPT_NAME should define base path when present');
    $assert->same('/memo', $request->path(), 'Request path should strip base path correctly');

    $session = [];
    $requestWithPhpSelf = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/alias/memo',
        'SCRIPT_NAME' => '/index.php',
        'PHP_SELF' => '/alias/index.php',
    ], [], [], $session);

    $assert->same('/alias', $requestWithPhpSelf->basePath(), 'PHP_SELF should be used when SCRIPT_NAME lacks prefix');
    $assert->same('/memo', $requestWithPhpSelf->path(), 'Path should use detected base path from PHP_SELF');

    $session = [];
    $requestWithForwarded = new Request([], [], [
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/memo',
        'HTTP_X_FORWARDED_PREFIX' => '/tenant',
    ], [], [], $session);

    $assert->same('/tenant', $requestWithForwarded->basePath(), 'Forwarded prefix header should set base path');
    $assert->same('/memo', $requestWithForwarded->path(), 'Forwarded prefix should not affect normalized path');
}

/**
 * @param callable():mixed $callback
 * @return object{result:mixed,output:string}
 */
function capture(callable $callback): object
{
    $level = ob_get_level();
    ob_start();
    try {
        $result = $callback();
    } finally {
        $buffers = [];
        while (ob_get_level() > $level) {
            $buffers[] = ob_get_clean();
        }
        $output = implode('', array_reverse($buffers));
    }

    return (object) [
        'result' => $result,
        'output' => $output,
    ];
}
