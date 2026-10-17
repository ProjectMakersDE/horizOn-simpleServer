<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Items/ItemMigration.php';
require_once __DIR__.'/../src/PlayerValues/PlayerValuesService.php';
require_once __DIR__.'/../src/Marketplace/MarketStore.php';
$count=0;
function same($expected,$actual,string $why):void{global $count;if($expected!==$actual)throw new RuntimeException($why.': '.json_encode([$expected,$actual]));$count++;}
function fails(string $code,callable $work):void{try{$work();}catch(PlayerValuesFailure $e){same($code,$e->errorCode,'Failure');return;}throw new RuntimeException('Expected '.$code);}
$dsn=getenv('HORIZON_ITEMS_TEST_DSN');
if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1011_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Explicit task1011 loopback DB required');
$path=$dsn?:tempnam(sys_get_temp_dir(),'horizon-itemcompact-');$pdo=null;
try{
 $pdo=new PDO($dsn?:'sqlite:'.$path,$dsn?getenv('HORIZON_ITEMS_TEST_USER'):null,$dsn?getenv('HORIZON_ITEMS_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 if(!$dsn)$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));Database::ensurePlayerValuesConfig($pdo);
 $now=1792152000000;$clock=static function()use(&$now){return $now;};$scope=new SqlTransaction($pdo);$items=new ItemService($pdo,$clock,$scope);$store=new ItemStore($pdo);$migration=new ItemMigration($pdo);$pv=new PlayerValuesService($pdo,$clock,$scope);
 $user=static function()use($pdo){$id=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at) VALUES(?,?,?,?)')->execute([$id,'Compact',Database::uuid(),'2026-10-16 12:00:00']);return $id;};
 $raw=static function(string $id)use($pdo){$q=$pdo->prepare('SELECT data FROM player_items WHERE user_id=?');$q->execute([$id]);return $q->fetchColumn();};
 $template=['name'=>'Sword','type'=>'weapon','rarity'=>'common','active'=>true,'baseProperties'=>(object)['power'=>10,'durability'=>100,'label'=>'shared'], 'propertyRules'=>[['key'=>'power','min'=>-ItemRules::MAX,'max'=>ItemRules::MAX,'maxChangePerCall'=>ItemRules::MAX],['key'=>'durability','min'=>0,'max'=>100,'maxChangePerCall'=>100]]];
 $items->put('sword',0,$template);$pv->configure(['definitions'=>[['key'=>'coins','name'=>'Coins','maxBalance'=>1000,'initialBalance'=>100]],'offers'=>[['key'=>'sword','name'=>'Sword','active'=>true,'costs'=>[['key'=>'coins','amount'=>1]],'rewards'=>[],'itemRewards'=>[['itemKey'=>'sword','quantity'=>1]]]]],0);
 $u=$user();$op=$now.':'.Database::uuid();$purchase=$pv->purchase($u,'sword',$op);$id=$purchase['operation']['items'][0]['instanceId'];$state=json_decode($raw($u),true);$freshStoredBytes=strlen($raw($u));
 same(['s','i'],array_keys($state),'No inventory purchase receipt or properties');same(34,strlen(base64_decode($state['i'])),'Exact fresh instance34B');same(false,strpos($raw($u),'shared')!==false,'No shared string copy');same(false,isset($items->get($u)['instances'][0]['_basis']),'No internal metadata on wire');
 $items->adjust($u,$id,1,[['key'=>'power','amount'=>-12],['key'=>'durability','amount'=>-1]]);$state=json_decode($raw($u),true);$deltaStoredBytes=strlen($raw($u));same(52,strlen(base64_decode($state['i'])),'Exact two deltas52B');same(-2,$items->get($u)['instances'][0]['properties']->power,'Signed LE integer');
 $firstBase=$store->inventory($u)['instances'][0]['_basis']['version'];$changed=$template;$changed['baseProperties']=(object)['power'=>20,'durability'=>50,'label'=>'changed'];$items->put('sword',1,$changed);
 same('shared',$items->get($u)['instances'][0]['properties']->label,'Old immutable string retained');same(-2,$items->get($u)['instances'][0]['properties']->power,'Old numeric delta retained');same(false,$migration->collect($firstBase),'Referenced old basis retained');
 $items->adjust($u,$id,2,[['key'=>'power','amount'=>12],['key'=>'durability','amount'=>1]]);same(34,strlen(base64_decode(json_decode($raw($u),true)['i'])),'Return to original base removes deltas');
 $items->discard($u,$id,3);same(true,$migration->collect($firstBase),'Unused noncurrent basis removed');same(true,$pv->purchase($u,'sword',$op)['operation']['replayed'],'Source guards discarded purchase');same([],$items->get($u)['instances'],'Replay does not recreate');
 try{$items->grantFromSource($u,[['itemKey'=>'sword','quantity'=>1]],$op,$now,'offer:sword');throw new RuntimeException('Missing scope accepted');}catch(LogicException $e){same(true,true,'Explicit source scope required');}
 // Real raised inventory cap, no hidden150 clamp, lowered read/removal remains valid.
 $wide=new ItemService($pdo,$clock,$scope,6000,160,60);same(6000,$wide->limits()['catalog'],'Catalog default is not a maximum');same(60,$wide->limits()['receipts'],'Legacy receipt count configurable');$w=$user();
 for($i=0;$i<38;$i++)$scope->run(static function()use($wide,$w,$now){$wide->grantFromSource($w,[['itemKey'=>'sword','quantity'=>4]],$now.':'.Database::uuid(),$now,'fixture');});
 $low=new ItemService($pdo,$clock,$scope,1,1,1);$snapshot=$low->get($w);same(152,count($snapshot['instances']),'Raised cap really stores152 items');$low->discard($w,$snapshot['instances'][0]['instanceId'],$snapshot['revision']);same(151,count($low->get($w)['instances']),'Lowered cap does not block removal');
 fails('INVENTORY_FULL',static function()use($low,$scope,$w,$now){$scope->run(static function()use($low,$w,$now){$low->grantFromSource($w,[['itemKey'=>'sword','quantity'=>1]],$now.':'.Database::uuid(),$now,'fixture');});});
 // Original historical values, even when the current template changed its immutable shape.
 $old=$user();$legacy=['instanceId'=>Database::uuid(),'itemKey'=>'sword','properties'=>(object)['power'=>-7,'durability'=>95,'label'=>'old original'],'equipped'=>true,'createdAt'=>PlayerValuesLedger::instant($now-123)];
 $json=json_encode(['instances'=>[$legacy],'receipts'=>[],'evictedThroughMillis'=>0],JSON_THROW_ON_ERROR);$pdo->prepare('INSERT INTO player_items(user_id,revision,data) VALUES(?,?,?)')->execute([$old,7,$json]);
 same($json,$raw($old),'Legacy source original');same('old original',$items->get($old)['instances'][0]['properties']->label,'Legacy read');same($json,$raw($old),'Legacy read never writes');
 fails('MIGRATION_REQUIRED',static function()use($items,$old,$legacy){$items->equip($old,$legacy['instanceId'],7,false);});
 same(0,$migration->page('inventory',null,50,false)['converted'],'Inspect no writes');same($json,$raw($old),'Inspect leaves original');
 same(true,$migration->one('inventory',$old),'Explicit original conversion');same(false,$migration->one('inventory',$old),'Repeated conversion no write');same(ItemRules::canonical((object)$legacy),ItemRules::canonical((object)$items->get($old)['instances'][0]),'Exact historical wire after conversion');same(8,$items->get($old)['revision'],'Conversion advances CAS');
 $unknown=$user();$bad=json_decode($json,true);$bad['unknown']=['valuable'=>'keep'];$badJson=json_encode($bad);$pdo->prepare('INSERT INTO player_items(user_id,revision,data) VALUES(?,?,?)')->execute([$unknown,1,$badJson]);
 fails('MIGRATION_REQUIRED',static function()use($migration,$unknown){$migration->one('inventory',$unknown);});same($badJson,$raw($unknown),'Unknown historical fields preserved');
 // Original JSON type is part of the legacy contract, including empty object versus array.
 foreach([null,'lost',7,[],[1]]as $badProperties){
  $badUser=$user();$badState=json_decode($json);$badState->instances[0]->properties=$badProperties;$badText=json_encode($badState);
  $pdo->prepare('INSERT INTO player_items(user_id,revision,data) VALUES(?,?,?)')->execute([$badUser,1,$badText]);
  fails('MIGRATION_REQUIRED',static function()use($migration,$badUser){$migration->one('inventory',$badUser);});
  same($badText,$raw($badUser),'Unknown property type is never cast to a new object');
  fails('MIGRATION_REQUIRED',static function()use($items,$badUser){$items->get($badUser);});
 }
 // Escrow conversion keeps the actual item and all surrounding listing metadata.
 $market=new MarketStore($pdo);
 $escrow=static function($item)use($pdo,$now,$u){$id=Database::uuid();$text=json_encode(['item'=>$item,'originalListingField'=>['keep'=>true]]);$pdo->prepare('INSERT INTO market_listings(id,revision,status,item_key,price,created_millis,due_kind,due_millis,return_owner,data) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,1,'active','sword',1,$now,0,$now+1000,$u,$text]);return [$id,$text];};
 $escrowRaw=static function(string $id)use($pdo){$q=$pdo->prepare('SELECT data FROM market_listings WHERE id=?');$q->execute([$id]);return $q->fetchColumn();};
 [$validEscrow,$originalEscrow]=$escrow($legacy);same(true,$migration->one('escrow',$validEscrow),'Original escrow compacted');
 same(ItemRules::canonical((object)$legacy),ItemRules::canonical((object)ItemStorage::wire($market->get($validEscrow)['item'])),'Escrow exact original after conversion');
 same(['keep'=>true],json_decode($escrowRaw($validEscrow),true)['originalListingField'],'Unrelated listing metadata retained');
 foreach([null,'lost',7,[],[1]]as $badProperties){
  $badItem=$legacy;$badItem['properties']=$badProperties;[$badEscrow,$originalEscrow]=$escrow($badItem);
  fails('MIGRATION_REQUIRED',static function()use($migration,$badEscrow){$migration->one('escrow',$badEscrow);});
  fails('MIGRATION_REQUIRED',static function()use($market,$badEscrow){$market->get($badEscrow);});
  same($originalEscrow,$escrowRaw($badEscrow),'Malformed escrow original unchanged');
 }
 // A successful conversion inside a failing outer SQL transaction leaves no partial representation.
 $rollback=$user();$pdo->prepare('INSERT INTO player_items(user_id,revision,data) VALUES(?,?,?)')->execute([$rollback,1,$json]);$pdo->beginTransaction();$migration->one('inventory',$rollback);$pdo->rollBack();same($json,$raw($rollback),'Outer rollback restores legacy record and basis counts');
 // Every malformed packed shape fails closed rather than granting defaults.
 $basis=$store->inventory($w)['instances'][0];$packed=ItemStorage::pack([$basis]);
 foreach([substr($packed,0,-1),substr_replace($packed,chr(2),32,1),substr_replace($packed,chr(17),33,1),$packed.$packed]as $bad)fails('STATE_CONFLICT',static function()use($bad){ItemStorage::unpack($bad);});
 fails('STATE_CONFLICT',static function(){ItemStorage::binary('AA');});
 $sample=$basis;$sample['properties']=clone $basis['properties'];$sample['properties']->power=-ItemRules::MAX;$bytes=ItemStorage::pack([$sample]);same(-ItemRules::MAX,ItemStorage::hydrate(ItemStorage::unpack($bytes)[0],$basis['_basis'])['properties']->power,'Safe signed minimum roundtrip');
 echo 'ITEM_COMPACT_BYTES='.json_encode(['rawFresh'=>34,'rawTwoDeltas'=>52,'base64Fresh'=>strlen(base64_encode($packed)),'freshSqlJson'=>$freshStoredBytes,'twoDeltaSqlJson'=>$deltaStoredBytes])."\n";
 echo 'PASS: '.$count.' compact Items '.($dsn?'MySQL':'SQLite')." assertions\n";
}finally{$pdo=null;if(!$dsn)foreach([$path,$path.'-wal',$path.'-shm']as $file)if(file_exists($file))unlink($file);}
