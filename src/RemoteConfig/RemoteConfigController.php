<?php

declare(strict_types=1);

class RemoteConfigController
{
    private const RESERVED_KEYS = ['smtp_config'];

    public static function get(Request $request): void
    {
        $key = $request->param('key');

        if ($key === null || $key === '') {
            Response::badRequest('Config key is required');
            return;
        }

        if (self::isReservedKey($key)) {
            Response::json([
                'configKey' => $key,
                'configValue' => null,
                'found' => false,
            ]);
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT config_key, config_value FROM remote_configs WHERE config_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        // MySQL can resolve a non-reserved spelling to the internal SMTP row.
        if ($row !== false && self::isReservedKey($row['config_key'])) {
            $row = false;
        }

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
            if (self::isReservedKey($row['config_key'])) {
                continue;
            }
            $configs[$row['config_key']] = $row['config_value'];
        }

        Response::json([
            'configs' => $configs,
            'total' => count($configs),
        ]);
    }

    public static function filter(Request $request): void
    {
        $pattern = trim((string)$request->query('pattern', ''));
        $filter = self::parseFilterPattern($pattern);

        $pdo = Database::connect();
        $rows = $pdo->query('SELECT config_key, config_value FROM remote_configs')->fetchAll();

        $configs = [];
        foreach ($rows as $row) {
            $key = $row['config_key'];
            if (self::isReservedKey($key)) {
                continue;
            }
            if (preg_match($filter['regex'], $key) === 1) {
                $configs[$key] = $row['config_value'];
            }
        }

        Response::json([
            'configs' => $configs,
            'total' => count($configs),
            'pattern' => $filter['pattern'],
            'matchType' => $filter['matchType'],
        ]);
    }

    private static function parseFilterPattern(string $pattern): array
    {
        if ($pattern === '') {
            Response::badRequest('Filter pattern must not be blank');
        }
        if (strlen($pattern) > 102) {
            Response::badRequest('Filter pattern must not exceed 102 characters');
        }
        if (strpos($pattern, '**') !== false) {
            Response::badRequest('Filter pattern must not contain consecutive wildcards');
        }

        $wildcardCount = substr_count($pattern, '*');
        if ($wildcardCount > 10) {
            Response::badRequest('Filter pattern must not contain more than 10 wildcards');
        }

        $literal = str_replace('*', '', $pattern);
        if ($literal === '') {
            Response::badRequest('Filter pattern must contain config key text');
        }
        if (strlen($literal) > 100) {
            Response::badRequest('Config key text must not exceed 100 characters');
        }
        if (preg_match('/\A[a-zA-Z0-9_.-]+\z/', $literal) !== 1) {
            Response::badRequest('Filter pattern can only contain letters, numbers, underscore, dot, hyphen, and wildcards');
        }

        $hasLeadingWildcard = $pattern[0] === '*';
        $hasTrailingWildcard = substr($pattern, -1) === '*';
        if ($wildcardCount === 0 || ($wildcardCount === 1 && $hasTrailingWildcard)) {
            $matchType = 'PREFIX';
        } elseif ($wildcardCount === 1 && $hasLeadingWildcard) {
            $matchType = 'SUFFIX';
        } elseif ($wildcardCount === 2 && $hasLeadingWildcard && $hasTrailingWildcard) {
            $matchType = 'CONTAINS';
        } else {
            $matchType = 'GLOB';
        }

        $parts = explode('*', $pattern);
        $regex = $hasLeadingWildcard ? '' : '^';
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $regex .= '.*';
            }
            if ($part !== '') {
                $regex .= preg_quote($part, '/');
            }
        }
        if ($wildcardCount > 0 && !$hasTrailingWildcard) {
            $regex .= '$';
        }

        return [
            'pattern' => $pattern,
            'matchType' => $matchType,
            'regex' => '/' . $regex . '/u',
        ];
    }

    private static function isReservedKey(string $key): bool
    {
        $normalizedKey = trim($key);
        foreach (self::RESERVED_KEYS as $reservedKey) {
            if (strcasecmp($normalizedKey, $reservedKey) === 0) {
                return true;
            }
        }

        return false;
    }
}
