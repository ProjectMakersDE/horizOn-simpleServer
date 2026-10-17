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
        self::ensurePlayerValuesConfig($pdo);
        self::ensureStatisticsConfig($pdo);
        self::ensureInboxConfig($pdo);
        self::ensureGiftCodeValues($pdo);
        self::ensureAchievementsConfig($pdo);
        self::ensureMarketOperations($pdo);
        // Millisecond registration cutoff prevents a post-publication signup in the same second qualifying.
        self::ensureUserActivityColumns($pdo);

        // Add email column to users table if it doesn't exist (for Email Sending)
        self::addColumnIfNotExists($pdo, $driver, 'users', 'email', 'TEXT', 'VARCHAR(254)');

        // Apple Sign-In columns (added incrementally so existing deployments upgrade cleanly).
        self::addColumnIfNotExists($pdo, $driver, 'users', 'apple_user_id', 'TEXT', 'VARCHAR(255)');
        self::addColumnIfNotExists($pdo, $driver, 'users', 'is_private_relay_email', 'INTEGER DEFAULT 0', 'TINYINT(1) NOT NULL DEFAULT 0');
        self::createIndexIfNotExists($pdo, $driver, 'idx_users_apple_user_id', 'users', 'apple_user_id');

        // Player profile columns (TASK-881): selected avatar, frame, badges (JSON array)
        // and server-written unlocks (JSON array). NULL while empty.
        self::addColumnIfNotExists($pdo, $driver, 'users', 'avatar_id', 'TEXT', 'VARCHAR(32) NULL');
        self::addColumnIfNotExists($pdo, $driver, 'users', 'frame_id', 'TEXT', 'VARCHAR(32) NULL');
        self::addColumnIfNotExists($pdo, $driver, 'users', 'badges', 'TEXT', 'TEXT NULL');
        self::addColumnIfNotExists($pdo, $driver, 'users', 'unlocks', 'TEXT', 'TEXT NULL');
    }

    /** Existing installations must not take a SQLite writer lock on every HTTP request. */
    public static function ensurePlayerValuesConfig(PDO $pdo): void
    {
        if ($pdo->query('SELECT id FROM player_values_config WHERE id = 1')->fetch() !== false) return;
        $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $pdo->exec($insert . " INTO player_values_config(id, revision, definitions, known_keys) VALUES (1, 0, '[]', '[]')");
    }

    /** Read-only bootstrap when the singleton catalog already exists. */
    public static function ensureStatisticsConfig(PDO $pdo): void
    {
        if ($pdo->query('SELECT id FROM statistics_catalog WHERE id = 1')->fetch() !== false) return;
        $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $pdo->exec($insert . " INTO statistics_catalog(id, version, definitions) VALUES (1, 0, '[]')");
    }

    public static function ensureInboxConfig(PDO $pdo): void
    {
        if ($pdo->query('SELECT id FROM inbox_catalog WHERE id = 1')->fetch() !== false) return;
        $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $state = ['apiKeyId' => self::uuid(), 'revision' => 0, 'entries' => [], 'receipts' => [], 'evictedThroughMillis' => 0];
        $pdo->prepare($insert . ' INTO inbox_catalog(id,data) VALUES (1,?)')->execute([json_encode($state, JSON_THROW_ON_ERROR)]);
    }

    /** One existing bootstrap metadata query checks both timestamps; no extra login read. */
    public static function ensureUserActivityColumns(PDO $pdo): void
    {
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $columns = $mysql
            ? $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchAll(PDO::FETCH_COLUMN)
            : array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        foreach (['created_at_millis', 'last_login_millis'] as $column) if (!in_array($column, $columns, true))
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ($mysql ? ' BIGINT NULL' : ' INTEGER'));
    }

    public static function nowMillis(): int { return (int)floor(microtime(true) * 1000); }

    /** Add indexed listing projections only. Legacy listing/player JSON is not scanned or rewritten. */
    public static function ensureMarketOperations(PDO $pdo): void
    {
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $columns=$driver==='mysql'
            ?$pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='market_listings'")->fetchAll(PDO::FETCH_COLUMN)
            :array_column($pdo->query('PRAGMA table_info(market_listings)')->fetchAll(),'name');
        foreach(['create_actor','create_operation','close_actor','close_operation']as$column)if(!in_array($column,$columns,true))
            $pdo->exec('ALTER TABLE market_listings ADD COLUMN '.$column.($driver==='mysql'?' CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL':' TEXT COLLATE BINARY'));
        foreach(['create','close']as$slot)self::createIndexIfNotExists($pdo,$driver,'idx_market_'.$slot.'_operation','market_listings',$slot.'_actor,'.$slot.'_operation',true,true);
    }

    public static function ensureAchievementsConfig(PDO $pdo): void
    {
        if ($pdo->query('SELECT id FROM achievement_catalog WHERE id=1')->fetch() !== false) return;
        $insert = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $catalog = ['projectId'=>self::uuid(),'revision'=>0,'definitions'=>[],'lifetimeKeys'=>[]];
        $pdo->prepare($insert.' INTO achievement_catalog(id,data) VALUES(1,?)')->execute([json_encode($catalog,JSON_THROW_ON_ERROR)]);
    }

    /** Additive legacy upgrade; inspect each affected table once, never reset counters/history. */
    public static function ensureGiftCodeValues(PDO $pdo): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $definitions = [
            'gift_codes' => ['values_json' => 'TEXT', 'v2_id' => $driver === 'mysql' ? 'BINARY(16) NULL' : 'BLOB',
                // Existing campaigns are protected V1; only explicit creation starts V2.
                'protocol_version' => $driver === 'mysql' ? 'TINYINT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1',
                'config_revision' => $driver === 'mysql' ? 'BIGINT NOT NULL DEFAULT 1' : 'INTEGER NOT NULL DEFAULT 1',
                'max_per_user' => $driver === 'mysql' ? 'INT DEFAULT 1' : 'INTEGER DEFAULT 1',
                'unlimited_total' => $driver === 'mysql' ? 'TINYINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
                'migration_hash' => $driver === 'mysql' ? 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL' : 'TEXT'],
            'gift_code_redemptions' => ['idempotency_id' => $driver === 'mysql'
                ? 'VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL' : 'TEXT',
                'gift_data' => 'TEXT', 'granted_values' => 'TEXT', 'value_granted_at' => $driver === 'mysql' ? 'BIGINT NULL' : 'INTEGER'],
        ];
        foreach ($definitions as $table => $columns) {
            if ($driver === 'mysql') {
                $query = $pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
                $query->execute([$table]); $metadata = $query->fetchAll(PDO::FETCH_ASSOC); $existing = array_column($metadata, 'COLUMN_NAME');
                if ($table === 'gift_codes') foreach ($metadata as $column) if ($column['COLUMN_NAME'] === 'current_redemptions' && $column['DATA_TYPE'] !== 'bigint')
                    $pdo->exec('ALTER TABLE gift_codes MODIFY current_redemptions BIGINT NOT NULL DEFAULT 0');
            } else $existing = array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
            foreach ($columns as $column => $type) if (!in_array($column, $existing, true)) $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
        }
        self::createIndexIfNotExists($pdo, $driver, 'idx_gift_v2_identity', 'gift_codes', 'v2_id', true, true);
        $pdo->exec('CREATE TABLE IF NOT EXISTS gift_code_identity_lock (id ' . ($driver === 'mysql' ? 'TINYINT PRIMARY KEY) ENGINE=InnoDB' : 'INTEGER PRIMARY KEY CHECK(id=1))'));
        if ($pdo->query('SELECT id FROM gift_code_identity_lock WHERE id=1')->fetch() === false)
            $pdo->exec(($driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE') . ' INTO gift_code_identity_lock(id) VALUES(1)');
        self::createIndexIfNotExists($pdo, $driver, 'idx_gift_idempotency', 'gift_code_redemptions', 'idempotency_id', true, true);
        self::createIndexIfNotExists($pdo, $driver, 'idx_gift_retention', 'gift_code_redemptions', 'value_granted_at, redeemed_at', false, true);
    }

    private static function createIndexIfNotExists(PDO $pdo, string $driver, string $indexName, string $table, string $column, bool $unique = false, bool $required = false): void
    {
        try {
            if ($driver === 'sqlite') {
                $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX IF NOT EXISTS {$indexName} ON {$table}({$column})";
                $pdo->exec($sql);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) as cnt FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
                );
                $stmt->execute([$table, $indexName]);
                if ((int)$stmt->fetch()['cnt'] === 0) {
                    $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . "INDEX {$indexName} ON {$table}({$column})";
                    $pdo->exec($sql);
                }
            }
        } catch (\Throwable $e) {
            if ($required) throw $e;
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
