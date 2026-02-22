<?php

declare(strict_types=1);

class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, string $code, int $status = 400): void
    {
        self::json([
            'error' => true,
            'message' => $message,
            'code' => $code,
        ], $status);
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::error($message, 'NOT_FOUND', 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 'UNAUTHORIZED', 401);
    }

    public static function tooManyRequests(string $message = 'Rate limit exceeded'): void
    {
        self::error($message, 'RATE_LIMITED', 429);
    }

    public static function badRequest(string $message): void
    {
        self::error($message, 'BAD_REQUEST', 400);
    }

    public static function serverError(string $message = 'Internal server error'): void
    {
        self::error($message, 'INTERNAL_ERROR', 500);
    }

    public static function created($data): void
    {
        self::json($data, 201);
    }
}
