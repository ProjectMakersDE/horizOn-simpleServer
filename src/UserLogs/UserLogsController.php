<?php

declare(strict_types=1);

class UserLogsController
{
    private const VALID_TYPES = ['INFO', 'WARN', 'ERROR'];

    public static function create(Request $request): void
    {
        $message = $request->body('message', '');
        $type = $request->body('type', '');
        $userId = $request->body('userId', '');

        if ($message === '' || $type === '' || $userId === '') {
            Response::badRequest('message, type, and userId are required');
            return;
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            Response::badRequest('type must be one of: INFO, WARN, ERROR');
            return;
        }

        if (strlen($message) > 1000) {
            Response::badRequest('message must be max 1000 characters');
            return;
        }

        $errorCode = $request->body('errorCode');

        $pdo = Database::connect();
        $id = Database::uuid();
        $now = Database::now();

        $stmt = $pdo->prepare(
            'INSERT INTO user_logs (id, user_id, message, type, error_code, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $userId, $message, $type, $errorCode, $now]);

        Response::created([
            'id' => $id,
            'createdAt' => $now,
        ]);
    }
}
