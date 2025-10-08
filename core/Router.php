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

    public function put(string $path, callable $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function options(string $path, callable $handler): void
    {
        $this->addRoute('OPTIONS', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$this->normalize($path)] = $handler;
    }

    public function dispatch(Request $request): mixed
    {
        $method = $request->method();
        $normalized = $this->normalize($request->path());

        if (!empty($this->routes[$method])) {
            foreach ($this->routes[$method] as $route => $handler) {
                $params = $this->match($route, $normalized);
                if ($params !== null) {
                    return $handler($request, ...$params);
                }
            }
        }

        http_response_code(404);
        echo 'Not Found';
        return null;
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
