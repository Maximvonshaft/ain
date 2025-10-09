<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Middlewares\CsrfMiddleware;
use Core\Config;
use Core\Request;
use RuntimeException;

use function App\Memo\Legacy\run as runLegacyMemo;

final class MemoController
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

        runLegacyMemo($this->config, $token, $request);
    }
}
