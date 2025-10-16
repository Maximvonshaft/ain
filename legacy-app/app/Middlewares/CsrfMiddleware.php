<?php

namespace App\Middlewares;

use Core\Request;
use RuntimeException;
use Throwable;

class CsrfMiddleware
{
    public function __construct(private string $sessionKey = '_csrf_token')
    {
    }

    public function token(Request $request): string
    {
        $session = &$this->session($request);
        $token = $session[$this->sessionKey] ?? null;
        if (!is_string($token) || $token === '') {
            try {
                $session[$this->sessionKey] = bin2hex(random_bytes(16));
            } catch (Throwable $exception) {
                throw new RuntimeException('Unable to generate CSRF token', 0, $exception);
            }
            $token = $session[$this->sessionKey];
        }

        return $token;
    }

    public function verify(Request $request): void
    {
        $session = &$this->session($request);
        $expected = $session[$this->sessionKey] ?? null;
        $provided = $this->providedToken($request);

        if (!is_string($expected) || $expected === '' || $provided === null) {
            throw new RuntimeException('CSRF validation failed');
        }

        if (!hash_equals($expected, $provided)) {
            throw new RuntimeException('CSRF validation failed');
        }
    }

    private function &session(Request $request): array
    {
        $session =& $request->sessionRef();
        return $session;
    }

    private function providedToken(Request $request): ?string
    {
        $token = $request->input('_csrf');
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $header = $request->server('HTTP_X_CSRF_TOKEN');
        if (is_string($header)) {
            $trimmed = trim($header);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
