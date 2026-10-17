<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/GiftCodes/GiftCodeCounterStore.php';
$dsn=getenv('HORIZON_GIFT_TEST_DSN');if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1007_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Own gift storage fixture required');
$p=new PDO($dsn?:'sqlite::memory:',$dsn?getenv('HORIZON_GIFT_TEST_USER'):null,$dsn?getenv('HORIZON_GIFT_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$p->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));Database::ensureGiftCodeValues($p);$scope=new SqlTransaction($p);$store=new GiftCodeCounterStore($p,$scope);$campaign=Database::uuid();$user=Database::uuid();$measure=[];
if(!$dsn){
    // Actual production table, not an estimated JSON/BSON representation.
    $measure['empty']=$p->query("SELECT SUM(ncell) records,SUM(payload) recordPayload,SUM(pgsize) allocatedBytes,SUM(unused) unusedBytes FROM dbstat WHERE name='gift_code_counts'")->fetch();
    foreach([1,128,9223372036854775807]as$count){$p->exec('DELETE FROM gift_code_counts');$scope->run(static function()use($store,$campaign,$user,$count){$store->install($campaign,$user,$count);});$row=$p->query("SELECT SUM(ncell) records,SUM(payload) recordPayload,SUM(pgsize) allocatedBytes,SUM(unused) unusedBytes FROM dbstat WHERE name='gift_code_counts'")->fetch();if((int)$row['records']!==1||!in_array((int)$row['recordPayload'],[36,38,44],true))throw new RuntimeException('Unexpected actual SQLite record payload');$measure[(string)$count]=$row;}
    $measure['aliasIndex']=$p->query("SELECT SUM(ncell) records,SUM(payload) recordPayload,SUM(pgsize) allocatedBytes FROM dbstat WHERE name='idx_gift_v2_identity'")->fetch();
}else{
    $scope->run(static function()use($store,$campaign,$user){$store->install($campaign,$user,PHP_INT_MAX);});$row=$p->query('SELECT OCTET_LENGTH(campaign_id) campaignBytes,OCTET_LENGTH(player_id) playerBytes,count FROM gift_code_counts')->fetch();if((int)$row['campaignBytes']!==16||(int)$row['playerBytes']!==16||(string)$row['count']!==GiftCodeProtocol::MAX)throw new RuntimeException('MySQL binary widths or Int64 changed');
    $measure['columns']=$p->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gift_code_counts' ORDER BY ORDINAL_POSITION")->fetchAll();
    if(array_column($measure['columns'],'COLUMN_TYPE')!==['binary(16)','binary(16)','bigint'])throw new RuntimeException('MySQL physical counter types changed');
    $p->query('ANALYZE TABLE gift_code_counts')->fetchAll();$measure['allocated']=$p->query("SELECT ENGINE,ROW_FORMAT,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gift_code_counts'")->fetch();
    $measure['indexes']=$p->query("SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gift_code_counts' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll();
}
echo 'PASS: actual '.($dsn?'MySQL':'SQLite').' Gift storage '.json_encode($measure,JSON_THROW_ON_ERROR)."\n";
