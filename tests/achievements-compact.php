<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Achievements/AchievementMigration.php';
require_once __DIR__.'/../src/Inbox/InboxService.php';
require_once __DIR__.'/inbox-fixtures.php';
$checks=0;
function same($want,$got,string $why):void{global $checks;if($want!==$got)throw new RuntimeException($why.': '.json_encode([$want,$got]));$checks++;}
function fails(string $code,callable $work):void{try{$work();}catch(AchievementFailure $e){same($code,$e->errorCode,'achievement failure');return;}catch(InboxFailure $e){same($code,$e->errorCode,'inbox failure');return;}catch(PlayerValuesFailure $e){same($code,$e->errorCode,'value failure');return;}throw new RuntimeException('Expected '.$code);}
$dsn=getenv('HORIZON_ACHIEVEMENTS_TEST_DSN');
if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1008_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Dedicated loopback task1008 DB required');
$path=$dsn?:tempnam(sys_get_temp_dir(),'horizon-achcompact-');$pdo=null;
try{
 $pdo=new PDO($dsn?:'sqlite:'.$path,$dsn?getenv('HORIZON_ACHIEVEMENTS_TEST_USER'):null,$dsn?getenv('HORIZON_ACHIEVEMENTS_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 if(!$dsn)$pdo->exec('PRAGMA foreign_keys=ON');
 $pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));
 Database::ensurePlayerValuesConfig($pdo);Database::ensureStatisticsConfig($pdo);Database::ensureInboxConfig($pdo);Database::ensureAchievementsConfig($pdo);
 $now=1792152000000;$clock=static function()use(&$now){return $now;};
 $service=new AchievementService($pdo,$clock);$migration=new AchievementMigration($pdo,$clock);$values=new PlayerValuesService($pdo,$clock);$inbox=new InboxService($pdo,$clock);
 $values->configure(['definitions'=>[['key'=>'coins','name'=>'Coins','maxBalance'=>1000]],'offers'=>[]],0);
 $make=static function(string $key,int $amount=2){return ['key'=>$key,'name'=>$key,'mode'=>'manual','rewards'=>['values'=>[['key'=>'coins','amount'=>$amount]]]];};
 $bad=$make('bad');$bad['rewards']['values']=null;fails('INVALID_REQUEST',static function()use($service,$bad){$service->configure([$bad],0);});
 $service->configure([$make('old',99),$make('second'),$make('new')],0);
 $user=static function()use($pdo,$now){$id=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at,created_at_millis) VALUES(?,?,?,?,?)')->execute([$id,'Fixture',Database::uuid(),'2026-10-16 12:00:00',$now]);return $id;};
 $raw=static function(string $id)use($pdo){$q=$pdo->prepare('SELECT data FROM achievement_state WHERE user_id=?');$q->execute([$id]);return $q->fetchColumn();};
 $old=static function(string $key,string $kind='pending',int $amount=3)use($now){return ['key'=>$key,'issuedAt'=>$now,'sourceId'=>Database::uuid(),'rewardState'=>$kind,'pending'=>$kind==='pending'?AchievementRules::content(['key'=>$key,'rewards'=>['values'=>[['key'=>'coins','amount'=>$amount]]]],$now):null,'inboxEntryId'=>null];};
 $seed=static function(string $id,array $records)use($pdo){$state=['revision'=>7,'catalogRevision'=>1,'unlocks'=>$records];$pdo->prepare('INSERT INTO achievement_state(user_id,revision,data) VALUES(?,?,?)')->execute([$id,7,InboxRules::json($state)]);};
 $fresh=$user();$service->unlock($fresh,'second');$wire=json_decode($raw($fresh),true);
 same(['s','r','c','b'],array_keys($wire),'only compact fields');same('Ag==',$wire['b'],'stable bit1');same(1,strlen(base64_decode($wire['b'])),'one actual bitmap byte');
 same(2,$values->get($fresh)['values'][0]['balance'],'atomic reward');same([],$inbox->get($fresh)['entries'],'no duplicated message');
 same(null,$service->get($fresh)['progress'][0]['unlockedAt'],'no timestamp');
 $q=$pdo->prepare('SELECT data FROM player_values WHERE user_id=?');$q->execute([$fresh]);$pv=json_decode($q->fetchColumn(),true);same('',$pv['q'],'no duplicate replay guard');
 $legacy=$user();$record=$old('old');$seed($legacy,[$record]);$before=$raw($legacy);
 same('pending',$service->get($legacy)['progress'][0]['rewardState'],'legacy read preserves pending');same($before,$raw($legacy),'read does not migrate');
 same(true,$service->unlock($legacy,'old')['replayed'],'legacy source replay');same('MIGRATION_REQUIRED',$service->deliver($legacy,['old'])['results'][0]['code'],'no hidden drain');
 fails('MIGRATION_REQUIRED',static function()use($service,$legacy){$service->unlock($legacy,'new');});
 same(0,$migration->page(null,50,false)['converted'],'inspect has no writes');same($before,$raw($legacy),'inspect preserves original');
 same(true,$migration->player($legacy),'pending migration');same(false,$migration->player($legacy),'repeated migration');same(3,$values->get($legacy)['values'][0]['balance'],'original reward not changed catalogue99');
 same(null,$service->get($legacy)['progress'][0]['unlockedAt'],'legacy date removed only after settlement');same([],$inbox->get($legacy)['entries'],'no new Inbox migration copy');
 // Authentic old pending-to-delivered handoff. Original message and source identify the same reward.
 $delivered=static function(string $id,bool $claim=false)use($old,$inbox,$seed,$now,$pdo){$r=$old('old');$source='achievement:'.$r['sourceId'];$r['inboxEntryId']=legacyInboxFixture($pdo,$id,$r['pending'],$source,$now,$claim);$r['pending']=null;$r['rewardState']='delivered';$seed($id,[$r]);if($claim)$inbox->claim($id,$r['inboxEntryId']);return $r;};
 foreach([false,true] as $claimed){$id=$user();$r=$delivered($id,$claimed);same(true,$migration->player($id),'delivered settlement');same(3,$values->get($id)['values'][0]['balance'],'claimed/unclaimed credit once');same(true,$inbox->get($id)['entries'][0]['claimed'],'original source now claimed');$inbox->claim($id,$r['inboxEntryId']);same(3,$values->get($id)['values'][0]['balance'],'original claim retry no duplicate');}
 // A genuinely old claimed source still carries a duplicate legacy PV receipt: move only that guard.
 $oldClaim=$user();$r=$old('old');$source='achievement:'.$r['sourceId'];
 $r['inboxEntryId']=legacyInboxFixture($pdo,$oldClaim,$r['pending'],$source,$now,false);$operation='inbox:'.$r['inboxEntryId'];$amounts=$r['pending']['attachment']['values'];
 $values->grant($oldClaim,$amounts,$operation,$now,$operation);
 $q=$pdo->prepare('SELECT data FROM inbox_state WHERE user_id=?');$q->execute([$oldClaim]);$mail=json_decode($q->fetchColumn(),true);$mail['entries'][0]['claimed']=true;$mail['entries'][0]['read']=true;
 $pdo->prepare('UPDATE inbox_state SET data=? WHERE user_id=?')->execute([InboxRules::json($mail),$oldClaim]);$r['pending']=null;$r['rewardState']='delivered';$seed($oldClaim,[$r]);
 $config=$values->config(false);$q=$pdo->prepare('SELECT data FROM player_values WHERE user_id=?');$q->execute([$oldClaim]);$beforePv=PlayerValuesStorage::decode($q->fetchColumn(),$config['knownKeys']);
 same(1,count($beforePv['receipts']),'authentic old retained duplicate guard');same(true,$migration->player($oldClaim),'claimed original guard migration');
 $q->execute([$oldClaim]);$afterPv=PlayerValuesStorage::decode($q->fetchColumn(),$config['knownKeys']);
 same([],$afterPv['receipts'],'only proven receipt removed');same($now,$afterPv['evictedThroughMillis'],'old acceptance floor closes');same($beforePv['revision']+1,$afterPv['revision'],'guard migration advances values revision');
 foreach(['values','day','today','adjustedToday','initializedKeys']as$field)same($beforePv[$field],$afterPv[$field],'source guard migration preserves '.$field);
 fails('OPERATION_EXPIRED',static function()use($values,$oldClaim,$amounts,$operation,$now){$values->grant($oldClaim,$amounts,$operation,$now,$operation);});same(3,$values->get($oldClaim)['values'][0]['balance'],'old standalone receipt cannot reopen');
 $missing=$user();$r=$old('old','delivered');$r['inboxEntryId']='message:'.Database::uuid();$seed($missing,[$r]);$before=$raw($missing);
 fails('MIGRATION_REQUIRED',static function()use($migration,$missing){$migration->player($missing);});same($before,$raw($missing),'missing proof preserves source');
 $unknown=$user();$r=$old('old');$r['unexplained']='preserve';$seed($unknown,[$r]);$before=$raw($unknown);
 fails('MIGRATION_REQUIRED',static function()use($migration,$unknown){$migration->player($unknown);});same($before,$raw($unknown),'unknown fields never discarded');
 foreach(['attachment','value','content'] as $kind){
  $id=$user();$r=$old('old');
  if($kind==='attachment')$r['pending']['attachment']['otherReward']=['item'=>'preserve'];
  elseif($kind==='value')$r['pending']['attachment']['values'][0]['otherReward']='preserve';
  else $r['pending']['otherReward']='preserve';
  $seed($id,[$r]);$before=$raw($id);
  fails('MIGRATION_REQUIRED',static function()use($migration,$id){$migration->player($id);});same($before,$raw($id),'unknown original shape retained');
  $q=$pdo->prepare('SELECT COUNT(*) FROM player_values WHERE user_id=?');$q->execute([$id]);same(0,(int)$q->fetchColumn(),'unknown reward never partially credited');
 }
 // A valid source UUID alone must not bind a message for a different achievement key.
 $wrongKey=$user();$r=$old('second');$source='achievement:'.$r['sourceId'];
 $r['inboxEntryId']=legacyInboxFixture($pdo,$wrongKey,$r['pending'],$source,$now);$r['key']='old';$r['pending']=null;$r['rewardState']='delivered';$seed($wrongKey,[$r]);$before=$raw($wrongKey);
 fails('MIGRATION_REQUIRED',static function()use($migration,$wrongKey){$migration->player($wrongKey);});same($before,$raw($wrongKey),'wrong content identity preserved');
 // Legacy value guard claiming a credit while the Inbox flag says unclaimed is contradictory.
 $ambiguous=$user();$r=$delivered($ambiguous);$operation='inbox:'.$r['inboxEntryId'];$values->grant($ambiguous,[['key'=>'coins','amount'=>3]],$operation,$now,$operation);$before=$raw($ambiguous);
 fails('MIGRATION_REQUIRED',static function()use($migration,$ambiguous){$migration->player($ambiguous);});same($before,$raw($ambiguous),'contradiction preserves source');same(3,$values->get($ambiguous)['values'][0]['balance'],'contradiction never credits twice');
 // Proven claimed historical guard may be removed after its identity and original amount match.
 $proven=$user();$r=$delivered($proven);$operation='inbox:'.$r['inboxEntryId'];$values->grant($proven,[['key'=>'coins','amount'=>3]],$operation,$now,$operation);
 $q=$pdo->prepare('SELECT data FROM inbox_state WHERE user_id=?');$q->execute([$proven]);$state=json_decode($q->fetchColumn(),true);$state['entries'][0]['claimed']=true;
 $pdo->prepare('UPDATE inbox_state SET data=? WHERE user_id=?')->execute([InboxRules::json($state),$proven]);
 same(true,$migration->player($proven),'proven guard converted');same(3,$values->get($proven)['values'][0]['balance'],'proven original not reissued');
 $q=$pdo->prepare('SELECT data FROM player_values WHERE user_id=?');$q->execute([$proven]);same('',json_decode($q->fetchColumn(),true)['q'],'only proven guard removed');
 // Any later balance failure rolls back earlier Inbox settlement and every source bit.
 $fail=$user();$r=$old('old');$source='achievement:'.$r['sourceId'];$r['inboxEntryId']=legacyInboxFixture($pdo,$fail,$r['pending'],$source,$now);$r['pending']=null;$r['rewardState']='delivered';
 $seed($fail,[$r,$old('second','pending',1000)]);$before=$raw($fail);
 fails('MAX_BALANCE_REACHED',static function()use($migration,$fail){$migration->player($fail);});same($before,$raw($fail),'failed settlement source unchanged');same(false,$inbox->get($fail)['entries'][0]['claimed'],'earlier Inbox change rolled back');same(0,$values->get($fail)['values'][0]['balance'],'all credits rolled back');
 // Administrable counts grow past former hardcoded250/500, then lower without truncating reads.
 $raised=new AchievementService($pdo,$clock,null,['maxDefinitions'=>400,'maxLifetimeKeys'=>1000]);$defs=[];for($i=0;$i<300;$i++)$defs[]=$make('key'.$i,1);
 $raised->configure($defs,1);$wide=$user();$raised->unlock($wide,'key299');$low=new AchievementService($pdo,$clock,null,['maxDefinitions'=>1,'maxLifetimeKeys'=>1]);
 same(300,count($low->definitions()['definitions']),'lowered limit preserves catalogue reads');same('key299',$low->get($wide)['progress'][0]['key'],'lowered limit preserves high slot');
 $low->configure($defs,2);fails('ACHIEVEMENT_LIFETIME_LIMIT_REACHED',static function()use($low,$make){$low->configure([$make('extra')],2);});
 $raised->configure([],2);$defs=[];for($i=300;$i<600;$i++)$defs[]=$make('key'.$i,1);$raised->configure($defs,3);$raised->unlock($wide,'key599');
 same(2,count($low->get($wide)['progress']),'lifetime beyond500 retained');same(76,strlen(base64_decode(json_decode($raw($wide),true)['b'])),'actual bitmap603slots76bytes');
 $bad=json_decode($raw($wide),true);$bad['b']=base64_encode(str_repeat("\xff",77));$pdo->prepare('UPDATE achievement_state SET data=? WHERE user_id=?')->execute([InboxRules::json($bad),$wide]);
 fails('MIGRATION_REQUIRED',static function()use($low,$wide){$low->get($wide);});
 echo 'PASS: '.$checks.' compact achievements '.($dsn?'MySQL':'SQLite')." assertions\n";
}finally{if($pdo&&$pdo->inTransaction())$pdo->rollBack();$pdo=null;if(!$dsn)foreach([$path,$path.'-wal',$path.'-shm']as $f)if(file_exists($f))unlink($f);}
