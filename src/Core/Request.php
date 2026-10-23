<?php

declare(strict_types=1);

class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $headers;
    private ?array $body;
    private string $rawBody = '';
    private array $params = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Parse path from REQUEST_URI, strip query string
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        $this->path = $pos !== false ? substr($uri, 0, $pos) : $uri;

        // Remove trailing slash (except root)
        if ($this->path !== '/' && substr($this->path, -1) === '/') {
            $this->path = rtrim($this->path, '/');
        }

        $this->query = $_GET;

        // Parse headers
        $this->headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $this->headers[$header] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $this->headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        // Apache with CGI/FastCGI hides the Authorization header; .htaccess passes it on
        // as REDIRECT_HTTP_AUTHORIZATION after the rewrite to index.php.
        if (!isset($this->headers['authorization']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $this->headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        // Parse JSON body
        $this->body = null;
        $rawBody = file_get_contents('php://input');
        $this->rawBody = $rawBody === false ? '' : $rawBody;
        if ($rawBody !== '' && $rawBody !== false) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->body = $decoded;
            }
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $this->query) || !is_scalar($this->query[$key])) {
            return $default;
        }
        return (string)$this->query[$key];
    }

    public function queryInt(string $key, int $default = 0): int
    {
        if (!isset($this->query[$key]) || !is_scalar($this->query[$key])) {
            return $default;
        }
        return (int)$this->query[$key];
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function body(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function rawBody(): string { return $this->rawBody; }
    public function hasQuery(string $key): bool { return array_key_exists($key, $this->query); }

    /** A feature may need JSON object identity: {} must not turn into [] through associative decode. */
    public function objectBody(string $key)
    {
        $root = json_decode($this->rawBody);
        return $root instanceof stdClass && property_exists($root, $key) ? $root->{$key} : null;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function clientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }
}
