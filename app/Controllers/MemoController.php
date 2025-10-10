<?php

namespace App\Controllers;

use App\Memo\Legacy\LegacyMemoRunner;
use App\Middlewares\CsrfMiddleware;
use Core\Request;
use RuntimeException;

final class MemoController
{
    public function __construct(
        private LegacyMemoRunner $runner,
        private CsrfMiddleware $csrf
    ) {
    }

    public function index(Request $request): void
    {
        $token = $this->csrf->token($request);
        $this->runner->handle($request, $token);
    }

    public function store(Request $request): void
    {
        try {
            $this->csrf->verify($request);
        } catch (RuntimeException $exception) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => 0,
                'error' => 'CSRF validation failed',
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $token = $this->csrf->token($request);
        $this->runner->handle($request, $token);
    }
}
