<?php

declare(strict_types=1);

class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->routes[] = ['PUT', $pattern, $handler];
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->routes[] = ['DELETE', $pattern, $handler];
    }

    public function dispatch(Request $request): void
    {
        // Handle CORS preflight
        if ($request->method() === 'OPTIONS') {
            http_response_code(204);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
            header('Access-Control-Max-Age: 86400');
            exit;
        }

        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method()) {
                continue;
            }

            $params = $this->match($pattern, $request->path());
            if ($params !== null) {
                $request->setParams($params);
                $handler($request);
                return;
            }
        }

        Response::notFound('Endpoint not found');
    }

    private function match(string $pattern, string $path): ?array
    {
        // Convert pattern like /api/v1/app/remote-config/{key} to regex
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return null;
    }
}
