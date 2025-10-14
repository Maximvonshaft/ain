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
        $value = $this->server['HTTP_X_REQUESTED_WITH'] ?? null;
        if (!is_string($value)) {
            return false;
        }

        return strcasecmp(trim($value), 'XMLHttpRequest') === 0;
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
        $forwarded = $this->normalizeForwardedPrefix($server['HTTP_X_FORWARDED_PREFIX'] ?? null);
        if ($forwarded !== '') {
            return $forwarded;
        }

        $prefixCandidates = [
            $server['BASE_URI'] ?? null,
            $server['CONTEXT_PREFIX'] ?? null,
        ];

        foreach ($prefixCandidates as $candidate) {
            $normalized = $this->normalizePrefixCandidate($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        $scriptCandidates = [
            $server['SCRIPT_NAME'] ?? null,
            $server['PHP_SELF'] ?? null,
            $server['ORIG_SCRIPT_NAME'] ?? null,
        ];

        foreach ($scriptCandidates as $candidate) {
            $normalized = $this->normalizeScriptCandidate($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeForwardedPrefix(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $parts = array_map(static fn (string $part): string => trim($part), explode(',', $trimmed));
        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return '';
        }

        return $this->normalizePrefixCandidate($parts[0]);
    }

    private function normalizePrefixCandidate(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '/') {
            return '';
        }

        $path = parse_url($trimmed, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return '';
        }

        return '/' . trim($path, '/');
    }

    private function normalizeScriptCandidate(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $path = parse_url($trimmed, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        $directory = str_replace('\\', '/', dirname($path));
        if ($directory === '/' || $directory === '.' || $directory === '') {
            return '';
        }

        return $directory;
    }
}
