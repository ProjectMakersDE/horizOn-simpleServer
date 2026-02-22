<?php

declare(strict_types=1);

class RemoteConfigController
{
    public static function get(Request $request): void
    {
        $key = $request->param('key');

        if ($key === null || $key === '') {
            Response::badRequest('Config key is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT config_value FROM remote_configs WHERE config_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        Response::json([
            'configKey' => $key,
            'configValue' => $row !== false ? $row['config_value'] : null,
            'found' => $row !== false,
        ]);
    }

    public static function all(Request $request): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('SELECT config_key, config_value FROM remote_configs');
        $rows = $stmt->fetchAll();

        $configs = [];
        foreach ($rows as $row) {
            $configs[$row['config_key']] = $row['config_value'];
        }

        Response::json([
            'configs' => $configs,
            'total' => count($configs),
        ]);
    }
}
