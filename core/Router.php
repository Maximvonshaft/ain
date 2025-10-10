<?php

namespace Core;

class Router
{
    /**
     * @var array<string, array<string, callable>>
     */
    private array $staticRoutes = [];

    /**
     * @var array<string, array<string, callable>>
     */
    private array $dynamicRoutes = [];

    /**
     * @var array<string, array{0: string, 1: array<int, string>}|null>
     */
    private array $compiledRoutes = [];

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
        $normalized = $this->normalize($path);

        if (!array_key_exists($normalized, $this->compiledRoutes)) {
            $this->compiledRoutes[$normalized] = $this->compileRoute($normalized);
        }

        $compiled = $this->compiledRoutes[$normalized] ?? null;

        if ($compiled === null) {
            $this->staticRoutes[$method][$normalized] = $handler;
            return;
        }

        $this->dynamicRoutes[$method][$normalized] = $handler;
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
        if (isset($this->staticRoutes[$method][$normalized])) {
            return [$this->staticRoutes[$method][$normalized], []];
        }

        if (empty($this->dynamicRoutes[$method])) {
            return null;
        }

        foreach ($this->dynamicRoutes[$method] as $route => $handler) {
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
        foreach ($this->staticRoutes as $method => $routes) {
            if (isset($routes[$normalized])) {
                $allowed[] = $method;
            }
        }

        foreach ($this->dynamicRoutes as $method => $handlers) {
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

        if (!array_key_exists($route, $this->compiledRoutes)) {
            $this->compiledRoutes[$route] = $this->compileRoute($route);
        }

        $compiled = $this->compiledRoutes[$route];
        if ($compiled === null) {
            return null;
        }

        [$pattern, $paramNames] = $compiled;
        if (!preg_match($pattern, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($paramNames as $name) {
            if (array_key_exists($name, $matches)) {
                $params[] = $matches[$name];
            }
        }

        return $params;
    }

    /**
     * @return array{0: string, 1: array<int, string>}|null
     */
    private function compileRoute(string $route): ?array
    {
        if (strpos($route, '{') === false) {
            return null;
        }

        $paramNames = [];
        $pattern = '#^';
        $offset = 0;
        $length = strlen($route);

        while (($start = strpos($route, '{', $offset)) !== false) {
            $pattern .= preg_quote(substr($route, $offset, $start - $offset), '#');

            $end = strpos($route, '}', $start);
            if ($end === false) {
                return null;
            }

            $paramName = substr($route, $start + 1, $end - $start - 1);
            if ($paramName === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $paramName)) {
                return null;
            }

            $paramNames[] = $paramName;
            $pattern .= '(?P<' . $paramName . '>[^/]+)';
            $offset = $end + 1;
        }

        if ($offset < $length) {
            $pattern .= preg_quote(substr($route, $offset), '#');
        }

        $pattern .= '$#';

        return [$pattern, $paramNames];
    }
}
