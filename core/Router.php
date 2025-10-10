<?php

namespace Core;

class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    public function dispatch(Request $request): mixed
    {
        $method = $request->method();
        $normalized = $this->normalize($request->path());

        $resolved = $this->resolve($method, $normalized);
        if ($resolved !== null) {
            [$handler, $params] = $resolved;
            return $handler($request, ...$params);
        }

        if ($method === 'HEAD') {
            $resolved = $this->resolve('GET', $normalized);
            if ($resolved !== null) {
                [$handler, $params] = $resolved;
                $level = ob_get_level();
                ob_start();
                try {
                    $result = $handler($request, ...$params);
                } finally {
                    while (ob_get_level() > $level) {
                        ob_end_clean();
                    }
                }

                return $result;
            }
        }

        $allowed = $this->allowedMethodsFor($normalized);
        if ($allowed !== []) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowed));
            echo 'Method Not Allowed';
            return null;
        }

        http_response_code(404);
        echo 'Not Found';
        return null;
    }

    /**
     * @return array{0: callable, 1: array}|null
     */
    private function resolve(string $method, string $normalized): ?array
    {
        if (empty($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route => $handler) {
            $params = $this->match($route, $normalized);
            if ($params !== null) {
                return [$handler, $params];
            }
        }

        return null;
    }

    private function allowedMethodsFor(string $normalized): array
    {
        $allowed = [];
        foreach ($this->routes as $method => $handlers) {
            foreach ($handlers as $route => $handler) {
                if ($this->match($route, $normalized) !== null) {
                    $allowed[] = $method;
                    break;
                }
            }
        }

        if ($allowed === []) {
            return [];
        }

        $allowed = array_values(array_unique($allowed));
        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        sort($allowed);

        return $allowed;
    }

    private function normalize(string $path): string
    {
        return '/' . trim($path, '/');
    }

    private function match(string $route, string $path): ?array
    {
        if ($route === $path) {
            return [];
        }

        $pattern = preg_replace('#\{([^/]+)\}#', '(?P<$1>[^/]+)', $route);
        if ($pattern === null) {
            return null;
        }
        $pattern = '#^' . $pattern . '$#';
        if (preg_match($pattern, $path, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (!is_int($key)) {
                    $params[] = $value;
                }
            }
            return $params;
        }
        return null;
    }
}
