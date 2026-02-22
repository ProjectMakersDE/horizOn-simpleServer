<?php

declare(strict_types=1);

class CloudSaveController
{
    public static function save(Request $request): void
    {
        $userId = $request->body('userId', '');
        $saveData = $request->body('saveData');

        if ($userId === '') {
            Response::badRequest('userId is required');
            return;
        }

        if ($saveData === null) {
            Response::badRequest('saveData is required');
            return;
        }

        $dataStr = is_string($saveData) ? $saveData : json_encode($saveData);

        if (strlen($dataStr) > 300000) {
            Response::badRequest('saveData exceeds maximum size of 300,000 characters');
            return;
        }

        $pdo = Database::connect();
        $now = Database::now();
        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'INSERT INTO cloud_saves (user_id, data, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO cloud_saves (user_id, data, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT(user_id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at'
            );
        }

        $stmt->execute([$userId, $dataStr, $now]);

        Response::json([
            'success' => true,
            'dataSizeBytes' => strlen($dataStr),
        ]);
    }

    public static function load(Request $request): void
    {
        $userId = $request->body('userId', '');

        if ($userId === '') {
            Response::badRequest('userId is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT data FROM cloud_saves WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::json([
                'found' => false,
                'saveData' => null,
            ]);
            return;
        }

        Response::json([
            'found' => true,
            'saveData' => $row['data'],
        ]);
    }
}
