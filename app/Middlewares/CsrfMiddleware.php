<?php

namespace App\Middlewares;

use Core\Request;
use RuntimeException;

class CsrfMiddleware
{
    public function __construct(private string $sessionKey = '_csrf_token')
    {
    }

    public function token(Request $request): string
    {
        $session =& $_SESSION;
        if (!isset($session[$this->sessionKey])) {
            $session[$this->sessionKey] = bin2hex(random_bytes(16));
        }
        return $session[$this->sessionKey];
    }

    public function verify(Request $request): void
    {
        $session =& $_SESSION;
        $expected = $session[$this->sessionKey] ?? null;
        $provided = $request->input('_csrf') ?? $request->server('HTTP_X_CSRF_TOKEN');
        if (!$expected || !$provided || !hash_equals((string)$expected, (string)$provided)) {
            throw new RuntimeException('CSRF validation failed');
        }
    }
}
