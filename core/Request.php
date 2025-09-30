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
        private array &$session
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE, $_SESSION);
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

    public function session(): array
    {
        return $this->session;
    }

    public function &sessionRef(): array
    {
        return $this->session;
    }
}
