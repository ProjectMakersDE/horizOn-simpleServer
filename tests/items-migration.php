<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Items/ItemService.php';

$dsn = getenv('HORIZON_ITEMS_TEST_DSN');
$parts = [];
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=(task1011_[a-f0-9]+);charset=utf8mb4$/D', $dsn, $parts))
    throw new RuntimeException('Only an explicit loopback task1011_<hex> migration DB is permitted');
$path = tempnam(sys_get_temp_dir(), 'horizon-task1011-migration-');
$env = $path . '.env'; $pdo = null;
try {
    $pdo = new PDO($dsn ?: 'sqlite:' . $path, $dsn ? getenv('HORIZON_ITEMS_TEST_USER') : null,
        $dsn ? getenv('HORIZON_ITEMS_TEST_PASSWORD') : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Real pre-feature schema: neither activity column nor any Item table exists.
    $pdo->exec($dsn
        ? 'CREATE TABLE users(id CHAR(36) PRIMARY KEY,display_name VARCHAR(30) NOT NULL,anonymous_token VARCHAR(64) UNIQUE NOT NULL,
            session_token VARCHAR(256),session_expires_at DATETIME,email VARCHAR(254),apple_user_id VARCHAR(255),
            is_private_relay_email TINYINT(1) NOT NULL DEFAULT 0,avatar_id VARCHAR(32),frame_id VARCHAR(32),badges TEXT,unlocks TEXT,
            created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        : 'CREATE TABLE users(id TEXT PRIMARY KEY,display_name TEXT NOT NULL,anonymous_token TEXT UNIQUE NOT NULL,
            session_token TEXT,session_expires_at TEXT,email TEXT,apple_user_id TEXT,is_private_relay_email INTEGER NOT NULL DEFAULT 0,
            avatar_id TEXT,frame_id TEXT,badges TEXT,unlocks TEXT,created_at TEXT NOT NULL)');
    $id = Database::uuid();
    $pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,session_token,session_expires_at,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$id, 'Legacy Player', 'legacy-token', 'legacy-session', '2099-01-01 00:00:00', '2026-01-01 00:00:00']);
    $pdo = null;
    $config = $dsn ? "DB_DRIVER=mysql\nDB_HOST=127.0.0.1\nDB_PORT=" . $parts[1] . "\nDB_NAME=" . $parts[2]
        . "\nDB_USER=" . getenv('HORIZON_ITEMS_TEST_USER') . "\nDB_PASS=" . getenv('HORIZON_ITEMS_TEST_PASSWORD') . "\n"
        : "DB_DRIVER=sqlite\nDB_PATH=" . $path . "\n";
    file_put_contents($env, $config); chmod($env, 0600); Config::load($env);
    Database::migrate(); $pdo = Database::connect();
    $row = $pdo->query('SELECT * FROM users')->fetch();
    if ($row['anonymous_token'] !== 'legacy-token' || $row['session_token'] !== 'legacy-session'
        || $row['last_login_millis'] !== null || $row['created_at_millis'] !== null)
        throw new RuntimeException('Legacy history changed or activity fabricated');
    $service = new ItemService($pdo);
    if ($service->get($id)['revision'] !== 0) throw new RuntimeException('Migration cannot prebook group state');
    Database::migrate();
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 1
        || (int)$pdo->query('SELECT COUNT(*) FROM player_items')->fetchColumn() !== 0)
        throw new RuntimeException('Repeated migration reset or created player state');
    foreach (['item_catalog','item_templates','player_items'] as $table) if ((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn() !== 0) throw new RuntimeException('Migration cannot prebook Items catalog/state');
    $indexes = $pdo->query($dsn ? 'SHOW INDEX FROM item_templates' : "PRAGMA index_list('item_templates')")->fetchAll(PDO::FETCH_ASSOC);
    if (!$indexes) throw new RuntimeException('Missing catalog key index');
    echo 'PASS: Items legacy ' . ($dsn ? 'MySQL' : 'SQLite') . " bootstrap preserves identity/history, lazy state and indexed catalog\n";
} finally {
    $pdo = null;
    foreach ([$path, $path . '-wal', $path . '-shm', $env] as $file) if (file_exists($file)) unlink($file);
}
