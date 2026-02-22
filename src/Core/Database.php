<?php

declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'sqlite') {
            $dbPath = Config::get('DB_PATH', './data/horizon.db');
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO("sqlite:{$dbPath}");
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
        } elseif ($driver === 'mysql') {
            $host = Config::get('DB_HOST', 'localhost');
            $port = Config::get('DB_PORT', '3306');
            $name = Config::get('DB_NAME', 'horizon');
            $user = Config::get('DB_USER', 'root');
            $pass = Config::get('DB_PASS', '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $user, $pass);
        } else {
            throw new RuntimeException("Unsupported DB_DRIVER: {$driver}");
        }

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return self::$pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::connect();
        $driver = Config::get('DB_DRIVER', 'sqlite');
        $file = __DIR__ . "/../../migrations/{$driver}.sql";

        if (!file_exists($file)) {
            throw new RuntimeException("Migration file not found: {$file}");
        }

        $sql = file_get_contents($file);
        $pdo->exec($sql);
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s');
    }
}
