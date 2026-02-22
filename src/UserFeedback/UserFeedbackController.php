<?php

declare(strict_types=1);

class UserFeedbackController
{
    public static function submit(Request $request): void
    {
        $title = $request->body('title', '');
        $message = $request->body('message', '');
        $userId = $request->body('userId', '');

        if ($title === '' || $message === '' || $userId === '') {
            Response::badRequest('title, message, and userId are required');
            return;
        }

        if (strlen($title) > 100) {
            Response::badRequest('title must be max 100 characters');
            return;
        }

        if (strlen($message) > 2048) {
            Response::badRequest('message must be max 2048 characters');
            return;
        }

        $email = $request->body('email');
        $category = $request->body('category');
        $deviceInfo = $request->body('deviceInfo');

        $pdo = Database::connect();
        $now = Database::now();

        $stmt = $pdo->prepare(
            'INSERT INTO user_feedback (id, user_id, title, message, category, email, device_info, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Database::uuid(), $userId, $title, $message,
            $category, $email, $deviceInfo, $now,
        ]);

        // The real server returns just "ok" as a string
        Response::json('ok');
    }
}
