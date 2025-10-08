<?php

namespace Core;

class Request
{
    public function __construct(
        private array $get,
        private array $post,
        private array $server,
        private array $files,
        private array $cookies,
        private array &$session,
        private string $basePath = ''
    ) {
        $this->basePath = $this->normalizeBasePath($basePath ?: $this->detectBasePath($server));
    }

    private ?array $jsonBody = null;
    private ?string $rawBody = null;

    public static function fromGlobals(string $basePath = ''): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE, $_SESSION, $basePath);
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->get;
        }
        return $this->get[$key] ?? $default;
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }
        return $this->post[$key] ?? $default;
    }

    public function files(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->files;
        }
        return $this->files[$key] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function ajax(): bool
    {
        return !empty($this->server['HTTP_X_REQUESTED_WITH']);
    }

    public function body(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }

        $this->rawBody = file_get_contents('php://input') ?: '';
        return $this->rawBody;
    }

    public function json(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $contentType = strtolower((string)$this->server('CONTENT_TYPE', ''));
        if (!str_contains($contentType, 'application/json')) {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $raw = $this->body();
        if ($raw === '') {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->jsonBody = [];
            return $this->jsonBody;
        }

        $this->jsonBody = $decoded;
        return $this->jsonBody;
    }

    public function session(): array
    {
        return $this->session;
    }

    public function &sessionRef(): array
    {
        return $this->session;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $relative = $this->stripBasePath($path);

        if ($relative === '' || $relative === null) {
            return '/';
        }

        return '/' . ltrim($relative, '/');
    }

    private function normalizeBasePath(string $basePath): string
    {
        if ($basePath === '' || $basePath === '/') {
            return '';
        }

        $basePath = trim($basePath);
        if ($basePath === '') {
            return '';
        }

        $parsed = parse_url($basePath, PHP_URL_PATH);
        if (is_string($parsed)) {
            $basePath = $parsed;
        }

        $basePath = '/' . trim($basePath, '/');

        return $basePath === '/' ? '' : $basePath;
    }

    private function stripBasePath(string $path): string
    {
        if ($this->basePath === '') {
            return $path;
        }

        if ($path === $this->basePath) {
            return '/';
        }

        $prefixed = $this->basePath . '/';
        if (str_starts_with($path, $prefixed)) {
            return substr($path, strlen($this->basePath));
        }

        return $path;
    }

    private function detectBasePath(array $server): string
    {
        $scriptName = $server['SCRIPT_NAME'] ?? '';
        if ($scriptName === '') {
            return '';
        }

        $directory = str_replace('\\', '/', dirname($scriptName));
        if ($directory === '/' || $directory === '.' || $directory === '') {
            return '';
        }

        return $directory;
    }
}
