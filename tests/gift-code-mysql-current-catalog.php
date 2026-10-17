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
// Offer pages and point reads must also bypass the old snapshot, not only the catalogue header.
$defs=[['key'=>'gold','name'=>'Gold','maxBalance'=>5],['key'=>'silver','name'=>'Silver','maxBalance'=>10,'initialBalance'=>3]];
$offer=['key'=>'current_offer','name'=>'Current offer','costs'=>[['key'=>'gold','amount'=>1]],'rewards'=>[['key'=>'silver','amount'=>1]]];$values->configure(['definitions'=>$defs,'offers'=>[$offer]],3);$values->correct($user,[['key'=>'gold','balance'=>5]],'RR price fixture',$clock().':'.Database::uuid());
$purchase=$scope->run(static function()use($p,$otherValues,$values,$user,$defs,$offer,$clock){
    $p->query('SELECT data FROM player_value_offers')->fetchAll();$offer['costs'][0]['amount']=4;$otherValues->configure(['definitions'=>$defs,'offers'=>[$offer]],4);
    $catalog=$values->offers($user);currentCatalogCheck(4,$catalog['offers'][0]['costs'][0]['amount'],'offer page uses current price');
    return$values->purchase($user,'current_offer',$clock().':'.Database::uuid());
});$balances=array_column($purchase['values'],'balance','key');currentCatalogCheck(1,$balances['gold'],'purchase uses newly committed price');currentCatalogCheck(4,$balances['silver'],'purchase rewards once');
// Two simultaneously active reader transactions share the lock; an actual third writer waits/fails safely.
$readerScope=new SqlTransaction($other);$readerValues=new PlayerValuesService($other,$clock,$readerScope);$third=currentCatalogConnection($dsn);$third->exec('SET SESSION innodb_lock_wait_timeout=1');$other->exec('SET SESSION innodb_lock_wait_timeout=1');$writer=new PlayerValuesService($third,$clock);$newDefs=$defs;$newDefs[0]['maxBalance']=6;
$scope->run(static function()use($values,$readerScope,$readerValues,$writer,$newDefs){
    currentCatalogCheck(5,$values->config(false)['revision'],'first provider holds current shared catalogue');
    $readerScope->run(static function()use($readerValues,$writer,$newDefs){
        currentCatalogCheck(5,$readerValues->config(false)['revision'],'second provider reads concurrently without exclusive header serialization');$code=null;
        try{$writer->configure(['definitions'=>$newDefs,'offers'=>[]],5);}catch(PlayerValuesFailure $e){$code=$e->errorCode;}
        currentCatalogCheck('STATE_CONFLICT',$code,'configuration writer cannot pass active readers');currentCatalogCheck(5,$readerValues->config(false)['revision'],'blocked writer changed no catalogue');
    });
});currentCatalogCheck(6,$writer->configure(['definitions'=>$newDefs,'offers'=>[]],5)['revision'],'configuration writer proceeds after both readers release');
echo 'PASS: '.$passed." real two-PDO MySQL RR catalogue assertions\n";
