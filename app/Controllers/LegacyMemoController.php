<?php

namespace App\Controllers;

use App\Middlewares\CsrfMiddleware;
use Core\Config;
use Core\Request;
use RuntimeException;

class LegacyMemoController
{
    public function __construct(
        private Config $config,
        private CsrfMiddleware $csrf
    ) {
    }

    public function handle(Request $request): void
    {
        try {
            if ($request->method() === 'POST') {
                $this->csrf->verify($request);
            }
        } catch (RuntimeException $e) {
            http_response_code(419);
            echo 'CSRF validation failed';
            return;
        }

        $token = $this->csrf->token($request);

        $GLOBALS['_legacy_config'] = $this->config;
        $GLOBALS['_legacy_csrf_token'] = $token;

        require __DIR__ . '/../../memo.php';
    }
}
