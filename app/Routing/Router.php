<?php

namespace App\Routing;

use App\Http\Request;
use App\Http\Response;
use Closure;

class Router
{
    /** @var array<string, array<int, array{pattern:string, parameters:array<int,string>, handler:Closure}>> */
    private array $routes = [];

    public function add(string $method, string $path, Closure $handler): void
    {
        $method = strtoupper($method);
        [$pattern, $parameters] = $this->compilePath($path);
        $this->routes[$method][] = [
            'pattern' => $pattern,
            'parameters' => $parameters,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $method = strtoupper($request->method());
        $path = $request->path();

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = [];
                foreach ($route['parameters'] as $index => $name) {
                    $params[$name] = $matches[$index + 1] ?? null;
                }
                $handler = $route['handler'];
                return $handler($request, $params);
            }
        }

        return Response::json(['message' => 'Not Found'], 404);
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function compilePath(string $path): array
    {
        $segments = explode('/', trim($path, '/'));
        $parameters = [];
        $regex = '#^';
        if ($segments === ['']) {
            $regex .= '\/';
        } else {
            foreach ($segments as $segment) {
                $regex .= '\/';
                if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                    $name = trim($segment, '{}');
                    $parameters[] = $name;
                    $regex .= '([^\/]+)';
                } else {
                    $regex .= preg_quote($segment, '#');
                }
            }
        }
        $regex .= '$#';

        return [$regex, $parameters];
    }
}

