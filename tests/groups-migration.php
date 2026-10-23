<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Groups/GroupService.php';
require_once __DIR__ . '/../src/Groups/GroupMigration.php';

$dsn = getenv('HORIZON_GROUPS_TEST_DSN');
$parts = [];
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=(task1010_[a-f0-9]+);charset=utf8mb4$/D', $dsn, $parts))
    throw new RuntimeException('Only an explicit loopback task1010_<hex> migration DB is permitted');
$path = tempnam(sys_get_temp_dir(), 'horizon-task1010-migration-');
$env = $path . '.env'; $pdo = null;
try {
    $pdo = new PDO($dsn ?: 'sqlite:' . $path, $dsn ? getenv('HORIZON_GROUPS_TEST_USER') : null,
        $dsn ? getenv('HORIZON_GROUPS_TEST_PASSWORD') : null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Real pre-feature schema: neither activity column nor any Group table exists.
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
        . "\nDB_USER=" . getenv('HORIZON_GROUPS_TEST_USER') . "\nDB_PASS=" . getenv('HORIZON_GROUPS_TEST_PASSWORD') . "\n"
        : "DB_DRIVER=sqlite\nDB_PATH=" . $path . "\n";
    file_put_contents($env, $config); chmod($env, 0600); Config::load($env);
    Database::migrate(); $pdo = Database::connect();
    $row = $pdo->query('SELECT * FROM users')->fetch();
    if ($row['anonymous_token'] !== 'legacy-token' || $row['session_token'] !== 'legacy-session'
        || $row['last_login_millis'] !== null || $row['created_at_millis'] !== null)
        throw new RuntimeException('Legacy history changed or activity fabricated');
    $service = new GroupService($pdo);
    if ($service->get($id)['revision'] !== 0) throw new RuntimeException('Migration cannot prebook group state');
    Database::migrate();
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 1
        || (int)$pdo->query('SELECT COUNT(*) FROM player_groups')->fetchColumn() !== 0)
        throw new RuntimeException('Repeated migration reset or created player state');
    $indexes = $pdo->query($dsn ? 'SHOW INDEX FROM player_groups' : "PRAGMA index_list('player_groups')")->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('idx_groups_deleted', array_column($indexes, $dsn ? 'Key_name' : 'name'), true))
        throw new RuntimeException('Missing indexed deletion anchor');
    // Actual previous Group schema: no direction column or migration fence, with meaningful data.
    $pdo->exec('DROP TABLE group_pending_references');$pdo->exec('DROP TABLE group_storage');
    $pdo->exec($dsn?'CREATE TABLE group_pending_references(user_id CHAR(36) NOT NULL,group_id CHAR(36) NOT NULL,PRIMARY KEY(user_id,group_id),FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'CREATE TABLE group_pending_references(user_id TEXT NOT NULL,group_id TEXT NOT NULL,PRIMARY KEY(user_id,group_id),FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE)');
    $groupId=Database::uuid();$oldGroup=GroupRules::json(['id'=>$groupId,'revision'=>3,'name'=>'Old','description'=>'Original','joinMode'=>'open','dataJson'=>'{"kept":{}}','leaderId'=>$id,'memberCount'=>1,'invitations'=>[],'joinRequests'=>[],'createdAt'=>'2026-01-01T00:00:00.000Z','updatedAt'=>'2026-01-02T00:00:00.000Z']);
    $oldPlayer=GroupRules::json(['groupIds'=>[$groupId],'incomingInvites'=>[],'outgoingRequests'=>[]]);
    $pdo->prepare('INSERT INTO groups_state(id,revision,data) VALUES(?,3,?)')->execute([$groupId,$oldGroup]);$pdo->prepare('INSERT INTO group_members(group_id,user_id,role,joined_millis) VALUES(?,?,?,?)')->execute([$groupId,$id,'leader',1767225600000]);$pdo->prepare('INSERT INTO player_groups(user_id,active_user_id,revision,data) VALUES(?,?,9,?)')->execute([$id,$id,$oldPlayer]);
    Database::migrate();
    $get=$pdo->prepare('SELECT data FROM groups_state WHERE id=?');$get->execute([$groupId]);if($get->fetchColumn()!==$oldGroup)throw new RuntimeException('Bootstrap rewrote original Group');
    $get=$pdo->prepare('SELECT data FROM player_groups WHERE user_id=?');$get->execute([$id]);if($get->fetchColumn()!==$oldPlayer||(int)$pdo->query('SELECT ready FROM group_storage WHERE id=1')->fetchColumn()!==0)throw new RuntimeException('Bootstrap compacted old player or falsely marked sources ready');
    $service=new GroupService($pdo);if(count($service->get($id)['groups'])!==1)throw new RuntimeException('Legacy read blocked before explicit conversion');
    try{$service->create($id,9,'No','','open',(object)[]);throw new RuntimeException('Write escaped incomplete source-index fence');}catch(GroupFailure $e){if($e->errorCode!=='GROUP_STORAGE_CONFLICT')throw $e;}
    $migration=new GroupMigration($pdo);if(!$migration->indexPage(10,true)['ready'])throw new RuntimeException('Bounded source index did not finish');$migration->players(null,10,true);
    $get->execute([$id]);if($get->fetchColumn()!=='{"s":1,"m":1}'||$service->get($id)['revision']!==9)throw new RuntimeException('Verified old membership did not compact without revision reset');
    echo 'PASS: Group legacy ' . ($dsn ? 'MySQL' : 'SQLite') . " bootstrap preserves identity/history, lazy state and cleanup index\n";
} finally {
    $pdo = null;
    foreach ([$path, $path . '-wal', $path . '-shm', $env] as $file) if (file_exists($file)) unlink($file);
}
