<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/SharedObjects/SharedObjectService.php';
$dsn=getenv('HORIZON_SHARED_OBJECTS_TEST_DSN');if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1012_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Only explicit own task1012 loopback test DB permitted');
$path=$dsn?null:tempnam(sys_get_temp_dir(),'horizon-task1012-legacy-');$settings=tempnam(sys_get_temp_dir(),'horizon-task1012-config-');$pdo=null;
try{
    $pdo=new PDO($dsn?:'sqlite:'.$path,$dsn?getenv('HORIZON_SHARED_OBJECTS_TEST_USER'):null,$dsn?getenv('HORIZON_SHARED_OBJECTS_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);if(!$dsn)$pdo->exec('PRAGMA foreign_keys=ON');
    $driver=$dsn?'mysql':'sqlite';$sql=file_get_contents(__DIR__.'/../migrations/'.$driver.'.sql');$cut=strpos($sql,'-- Shared Objects:');if($cut===false)throw new RuntimeException('Missing additive migration boundary');$pdo->exec(substr($sql,0,$cut));
    $user=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at) VALUES(?,?,?,?)')->execute([$user,'Legacy Shared Owner',Database::uuid(),'2026-10-16 12:00:00']);$before=$pdo->query('SELECT id,display_name,anonymous_token,created_at FROM users')->fetchAll(PDO::FETCH_ASSOC);
    // Real bootstrap uses only this explicit fixture PDO; it never reads application credentials.
    file_put_contents($settings,'DB_DRIVER='.$driver."\n");Config::load($settings);$property=(new ReflectionClass(Database::class))->getProperty('pdo');if(PHP_VERSION_ID<80100)$property->setAccessible(true);$property->setValue(null,$pdo);
    Database::migrate();Database::migrate();if($before!==$pdo->query('SELECT id,display_name,anonymous_token,created_at FROM users')->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Legacy identities changed');
    $service=new SharedObjectService($pdo);if($service->collections(null)['revision']!==0||$service->limits()['chargedBytes']!==0||(int)$pdo->query('SELECT COUNT(*) FROM shared_object_usage')->fetchColumn()!==0)throw new RuntimeException('Upgrade/read inserted owner state');
    if($dsn){$q=$pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shared_objects'");$indexes=$q->fetchAll(PDO::FETCH_COLUMN);}else $indexes=array_column($pdo->query('PRAGMA index_list(shared_objects)')->fetchAll(PDO::FETCH_ASSOC),'name');
    foreach(['idx_shared_slot0_page','idx_shared_position_x_page','idx_shared_owner_page','idx_shared_expiry','idx_shared_deleted_owner'] as $index)if(!in_array($index,$indexes,true))throw new RuntimeException('Missing index '.$index);
    echo 'PASS: Shared Objects legacy '.($dsn?'MySQL':'SQLite')." bootstrap preserves identity, adds paired metadata/payload/indexes and keeps lazy quota/usage\n";
}finally{$property=$property??null;if($property!==null)$property->setValue(null,null);$pdo=null;@unlink($settings);if($path!==null)foreach([$path,$path.'-wal',$path.'-shm'] as $file)if(is_file($file))unlink($file);}
