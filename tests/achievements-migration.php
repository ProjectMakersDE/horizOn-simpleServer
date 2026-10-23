<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Achievements/AchievementService.php';

// Exercise the real bootstrap against a pre-Achievements installation, including a second run.
$directory = sys_get_temp_dir() . '/horizon-task1008-upgrade-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create own migration fixture');
$path = $directory . '/legacy.sqlite';
$env = $directory . '/test.env';
try {
    $legacy = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacy->exec('CREATE TABLE users(id TEXT PRIMARY KEY, display_name TEXT NOT NULL, anonymous_token TEXT UNIQUE NOT NULL,
        session_token TEXT, session_expires_at TEXT, email TEXT, apple_user_id TEXT, is_private_relay_email INTEGER NOT NULL DEFAULT 0,
        avatar_id TEXT, frame_id TEXT, badges TEXT, unlocks TEXT, created_at TEXT NOT NULL)');
    $user = Database::uuid();
    $legacy->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at) VALUES(?,?,?,?)')
        ->execute([$user, 'Preserved player', Database::uuid(), '2026-10-15 10:11:12']);
    $legacy = null;
    file_put_contents($env, 'DB_DRIVER=sqlite' . "\nDB_PATH=" . $path . "\n");
    chmod($env, 0600);
    Config::load($env);
    Database::migrate();
    $pdo = Database::connect();
    $service = new AchievementService($pdo);
    $service->configure([['key'=>'existing','name'=>'Existing achievement','mode'=>'manual']],0);
    $service->unlock($user,'existing');
    $catalog = $pdo->query('SELECT data FROM achievement_catalog WHERE id=1')->fetchColumn();
    $progress = $pdo->query('SELECT data FROM achievement_state')->fetchColumn();
    Database::migrate();
    $row = $pdo->query('SELECT * FROM users')->fetch();
    if ($row['id'] !== $user || $row['display_name'] !== 'Preserved player' || $row['created_at'] !== '2026-10-15 10:11:12'
        || !array_key_exists('created_at_millis', $row) || $row['created_at_millis'] !== null
        || $pdo->query('SELECT data FROM achievement_catalog WHERE id=1')->fetchColumn() !== $catalog
        || $pdo->query('SELECT data FROM achievement_state')->fetchColumn() !== $progress
        || (int)$pdo->query('SELECT COUNT(*) FROM achievement_state')->fetchColumn() !== 1) {
        throw new RuntimeException('Legacy upgrade lost player data, reset the catalog or lost permanent progress');
    }
    echo "PASS: real legacy SQLite upgrade preserves registration, catalogue identity and permanent unlocks on repeated bootstrap\n";
} finally {
    $pdo = null;
    foreach ([$path, $path . '-wal', $path . '-shm', $env] as $file) if (file_exists($file)) unlink($file);
    rmdir($directory);
}
