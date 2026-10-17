<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/GiftCodes/GiftCodesService.php';
$dsn=getenv('HORIZON_GIFT_TEST_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1007_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Explicit isolated MySQL RR fixture required');
function currentCatalogConnection(string $dsn):PDO{return new PDO($dsn,getenv('HORIZON_GIFT_TEST_USER'),getenv('HORIZON_GIFT_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
function currentCatalogCheck($expected,$actual,string $label):void{global$passed;if($expected!==$actual)throw new RuntimeException($label.': '.json_encode([$expected,$actual]));$passed++;}
$passed=0;$p=currentCatalogConnection($dsn);$other=currentCatalogConnection($dsn);foreach([$p,$other]as$c)$c->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$p->exec(file_get_contents(__DIR__.'/../migrations/mysql.sql'));Database::ensurePlayerValuesConfig($p);Database::ensureGiftCodeValues($p);
$clock=static function(){return 1792230000000;};$scope=new SqlTransaction($p);$values=new PlayerValuesService($p,$clock,$scope);$otherValues=new PlayerValuesService($other,$clock);$gift=new GiftCodesService($p,$clock,$scope);$user=Database::uuid();$p->prepare('INSERT INTO users(id,display_name,anonymous_token) VALUES(?,?,?)')->execute([$user,'CurrentCatalog',Database::uuid()]);
$values->configure(['definitions'=>[['key'=>'gold','name'=>'Gold','maxBalance'=>1000]],'offers'=>[]],0);$gift->create(['code'=>'CURRENTLIMIT','values'=>[['key'=>'gold','amount'=>7]]]);$intent=$gift->prepare('CURRENTLIMIT',$user);$error=null;
try{$scope->run(static function()use($p,$otherValues,$gift,$user,$intent){
    $p->query('SELECT revision FROM player_values_config WHERE id=1')->fetchColumn(); // Establish a genuine old RR snapshot, with no lock.
    $otherValues->configure(['definitions'=>[['key'=>'gold','name'=>'Gold','maxBalance'=>5]],'offers'=>[]],1);
    $gift->redeemPrepared('CURRENTLIMIT',$user,$intent);
});}catch(PlayerValuesFailure $e){$error=$e->errorCode;}
currentCatalogCheck('MAX_BALANCE_REACHED',$error,'source grant must enforce the newly committed catalogue');currentCatalogCheck(0,(int)$p->query("SELECT current_redemptions FROM gift_codes WHERE code='CURRENTLIMIT'")->fetchColumn(),'catalogue rejection rolls back campaign');currentCatalogCheck('0',$gift->prepare('CURRENTLIMIT',$user)['expectedCount'],'catalogue rejection rolls back pair');
$gift->create(['code'=>'CURRENTSTATE']);$original=$gift->prepare('CURRENTSTATE',$user);$gift->redeemPrepared('CURRENTSTATE',$user,$original);$raw=null;
$replay=$scope->run(static function()use($p,$other,$otherValues,$gift,$user,$original,&$raw){
    $p->query('SELECT revision FROM player_values_config WHERE id=1')->fetchColumn();
    $otherValues->configure(['definitions'=>[['key'=>'gold','name'=>'Gold','maxBalance'=>5],['key'=>'silver','name'=>'Silver','maxBalance'=>10,'initialBalance'=>3]],'offers'=>[]],2);
    $otherValues->get($user);$q=$other->prepare('SELECT data FROM player_values WHERE user_id=?');$q->execute([$user]);$raw=$q->fetchColumn();
    return$gift->redeemPrepared('CURRENTSTATE',$user,$original);
});
currentCatalogCheck(true,$replay['alreadyApplied'],'original replay reads authoritative current catalogue');$balances=array_column($replay['playerValues']['values'],'balance','key');currentCatalogCheck(3,$balances['silver'],'current added slot decodes with current catalogue');currentCatalogCheck([],$replay['grantedValues'],'current replay keeps no historical delta');$q=$p->prepare('SELECT data FROM player_values WHERE user_id=?');$q->execute([$user]);currentCatalogCheck($raw,$q->fetchColumn(),'replay leaves current authoritative bytes unchanged');
echo 'PASS: '.$passed." real two-PDO MySQL RR catalogue assertions\n";
