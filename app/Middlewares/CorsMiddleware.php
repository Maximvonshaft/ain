<?php

namespace App\Middlewares;

use Core\Request;

class CorsMiddleware
{
    public function __construct(private array $options = [])
    {
    }

    public function handle(Request $request): void
    {
        $origin = $this->options['allow_origin'] ?? '*';
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Headers: ' . ($this->options['allow_headers'] ?? 'Content-Type, X-Requested-With'));
        header('Access-Control-Allow-Methods: ' . ($this->options['allow_methods'] ?? 'GET, POST, PUT, PATCH, DELETE, OPTIONS'));
        header('Access-Control-Allow-Credentials: ' . (($this->options['allow_credentials'] ?? false) ? 'true' : 'false'));
        if ($request->method() === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
