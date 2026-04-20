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

        // Add email column to users table if it doesn't exist (for Email Sending)
        self::addColumnIfNotExists($pdo, $driver, 'users', 'email', 'TEXT', 'VARCHAR(254)');

        // Apple Sign-In columns (added incrementally so existing deployments upgrade cleanly).
        self::addColumnIfNotExists($pdo, $driver, 'users', 'apple_user_id', 'TEXT', 'VARCHAR(255)');
        self::addColumnIfNotExists($pdo, $driver, 'users', 'is_private_relay_email', 'INTEGER DEFAULT 0', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::createIndexIfNotExists($pdo, $driver, 'idx_users_apple_user_id', 'users', 'apple_user_id');
    }

    private static function createIndexIfNotExists(PDO $pdo, string $driver, string $indexName, string $table, string $column): void
    {
        try {
            if ($driver === 'sqlite') {
                $sql = "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table}({$column})";
                $pdo->exec($sql);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) as cnt FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
                );
                $stmt->execute([$table, $indexName]);
                if ((int)$stmt->fetch()['cnt'] === 0) {
                    $sql = "CREATE INDEX {$indexName} ON {$table}({$column})";
                    $pdo->exec($sql);
                }
            }
        } catch (\Throwable $e) {
            error_log('horizOn migration notice (index): ' . $e->getMessage());
        }
    }

    private static function addColumnIfNotExists(PDO $pdo, string $driver, string $table, string $column, string $sqliteType, string $mysqlType): void
    {
        try {
            if ($driver === 'sqlite') {
                $cols = $pdo->query("PRAGMA table_info({$table})")->fetchAll();
                foreach ($cols as $col) {
                    if ($col['name'] === $column) {
                        return;
                    }
                }
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$sqliteType}");
            } else {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) as cnt FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                );
                $stmt->execute([$table, $column]);
                if ((int)$stmt->fetch()['cnt'] === 0) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$mysqlType}");
                }
            }
        } catch (\Throwable $e) {
            error_log("horizOn migration notice: " . $e->getMessage());
        }
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
