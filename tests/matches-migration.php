<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Matches/MatchService.php';
$dsn=getenv('HORIZON_MATCHES_TEST_DSN');
if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1013_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Only explicit own task1013 loopback DB allowed');
$path=$dsn?null:tempnam(sys_get_temp_dir(),'horizon-task1013-legacy-');$settings=tempnam(sys_get_temp_dir(),'horizon-task1013-config-');$pdo=null;
try{
    $pdo=new PDO($dsn?:'sqlite:'.$path,$dsn?getenv('HORIZON_MATCHES_TEST_USER'):null,$dsn?getenv('HORIZON_MATCHES_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);if(!$dsn)$pdo->exec('PRAGMA foreign_keys=ON');
    $driver=$dsn?'mysql':'sqlite';$sql=file_get_contents(__DIR__.'/../migrations/'.$driver.'.sql');$cut=strpos($sql,'-- Matches');if($cut===false)throw new RuntimeException('Missing additive Matches migration boundary');$pdo->exec(substr($sql,0,$cut));
    $id=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at) VALUES(?,?,?,?)')->execute([$id,'Legacy match owner',Database::uuid(),'2026-10-16 12:00:00']);$before=$pdo->query('SELECT id,display_name,anonymous_token,created_at FROM users')->fetchAll();
    file_put_contents($settings,'DB_DRIVER='.$driver."\n");Config::load($settings);$property=(new ReflectionClass(Database::class))->getProperty('pdo');if(PHP_VERSION_ID<80100)$property->setAccessible(true);$property->setValue(null,$pdo);Database::migrate();Database::migrate();
    if($before!==$pdo->query('SELECT id,display_name,anonymous_token,created_at FROM users')->fetchAll())throw new RuntimeException('Legacy identity changed');
    $s=new MatchService($pdo);if($s->mine($id)['revision']!==0||$s->config()['config']['revision']!==0||(int)$pdo->query('SELECT COUNT(*) FROM player_matches')->fetchColumn()!==0||(int)$pdo->query('SELECT COUNT(*) FROM match_config')->fetchColumn()!==0)throw new RuntimeException('Migration/read inserted player/config state');
    $indexes=$dsn?$pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='matches_state'")->fetchAll(PDO::FETCH_COLUMN):array_column($pdo->query('PRAGMA index_list(matches_state)')->fetchAll(),'name');foreach(['idx_matches_open','idx_matches_status','idx_matches_due']as$index)if(!in_array($index,$indexes,true))throw new RuntimeException('Missing index '.$index);
    echo'PASS: Matches legacy '.($dsn?'MySQL':'SQLite')." bootstrap preserves identity and prior feature tables, adds paired indexed storage and keeps lazy player/config reads\n";
}finally{if(isset($property))$property->setValue(null,null);$pdo=null;@unlink($settings);if($path!==null)foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
