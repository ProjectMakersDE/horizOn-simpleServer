<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Inbox/InboxService.php';
/** Inject a lost acknowledgement only after the database really committed both rows. */
class InboxLostCommitPDO extends PDO {
    public bool $loseCommit = false;
    private function acknowledged(): void { if ($this->loseCommit) { $this->loseCommit=false; throw new RuntimeException('Injected lost commit acknowledgement'); } }
    #[\ReturnTypeWillChange]
    public function exec($statement) { $result=parent::exec($statement); if(strtoupper(trim($statement))==='COMMIT')$this->acknowledged(); return $result; }
    #[\ReturnTypeWillChange]
    public function commit() { $result=parent::commit();$this->acknowledged();return $result; }
}
function inboxConnection(string $target): PDO {
    $mysql = strpos($target, 'mysql:') === 0;
    $pdo = new InboxLostCommitPDO($mysql ? $target : 'sqlite:' . $target, $mysql ? getenv('HORIZON_INBOX_TEST_USER') : null, $mysql ? getenv('HORIZON_INBOX_TEST_PASSWORD') : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (!$mysql) { $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA journal_mode=WAL'); }
    return $pdo;
}
if (($argv[1] ?? '') === '--claim' || ($argv[1] ?? '') === '--publish') {
    try {
        $service = new InboxService(inboxConnection($argv[2]), static function () use ($argv) { return (int)$argv[5]; });
        $result = $argv[1] === '--claim' ? $service->claim($argv[3], $argv[4])
            : $service->publish(['type'=>'parallel','textKey'=>'parallel','expiresAt'=>InboxRules::instant((int)$argv[5]+60000)], $argv[4], (int)$argv[3]);
        echo InboxRules::json($result);
    } catch (InboxFailure $failure) { echo json_encode(['code'=>$failure->errorCode]); }
    exit;
}
require_once __DIR__.'/../src/Inbox/InboxMigration.php';
require_once __DIR__.'/../src/SharedObjects/TrustedNoticeService.php';
$passed=0;
function check($expected,$actual,string $why):void{global $passed;if($expected!==$actual)throw new RuntimeException($why.': '.json_encode([$expected,$actual]));$passed++;}
function reject(string $code,callable $work):void{try{$work();}catch(InboxFailure $e){check($code,$e->errorCode,'Inbox rejection');return;}catch(PlayerValuesFailure $e){check($code,$e->errorCode,'value rejection');return;}catch(SharedObjectFailure $e){check($code,$e->errorCode,'trusted rejection');return;}throw new RuntimeException('Expected '.$code);}
function balance(array $v):int{foreach($v['values']as$r)if($r['key']==='coins')return $r['balance'];throw new RuntimeException('No coins');}
$dsn=getenv('HORIZON_INBOX_TEST_DSN');
if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1006_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Only explicit loopback task1006_<hex> database allowed');
$target=$dsn?:tempnam(sys_get_temp_dir(),'horizon-task1006-');$settings=tempnam(sys_get_temp_dir(),'horizon-inbox-config-');$pdo=null;
try{
    $pdo=inboxConnection($target);$pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));
    Database::ensurePlayerValuesConfig($pdo);Database::ensureInboxConfig($pdo);Database::ensureInboxColumns($pdo);
    $now=1792152000000;$clock=static function()use(&$now){return $now;};$service=new InboxService($pdo,$clock);$values=new PlayerValuesService($pdo,$clock);$users=[];
    $user=static function()use($pdo,&$users,&$now){$id=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at,created_at_millis) VALUES(?,?,?,?,?)')->execute([$id,'Inbox',Database::uuid(),'2026-10-16 12:00:00',$now-1000]);$users[]=$id;return $id;};
    $a=$user();$b=$user();$c=$user();
    $load=static function(string $u)use($pdo){$q=$pdo->prepare('SELECT data FROM inbox_state WHERE user_id=?');$q->execute([$u]);return $q->fetchColumn();};
    $save=static function(string $u,array $state)use($pdo){$sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT INTO inbox_state(user_id,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)':'INSERT INTO inbox_state(user_id,data) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data';$pdo->prepare($sql)->execute([$u,$state['_compact']??false?InboxStorage::encodeState($state):InboxRules::json($state)]);};
    $limits=static function(array $values)use($settings){$text='';foreach($values as$k=>$v)$text.=$k.'='.$v."\n";file_put_contents($settings,$text);Config::load($settings);};$limits([]);
    $values->configure(['definitions'=>[['key'=>'coins','name'=>'Coins','maxBalance'=>1000,'dailyCap'=>10]],'offers'=>[]],0);
    $empty=$service->get($a);check([],$empty['entries'],'empty');check(true,$service->get($a,$empty['cursor'])['unchanged'],'conditional');check(2,$empty['limits']['freeTexts'],'configured default');check(false,$load($a),'read never creates row');
    // Typed plain text uses exactly the Kotlin UUIDv3 identity and original revision before policy checks.
    $operation=Database::uuid();$issued=$now;$expected=0;$text=str_repeat('ä',128);$project=$service->broadcasts()['apiKeyId'];
    $notice=new TrustedNoticeService($pdo,$clock);$input=['apiKeyId'=>$project,'userId'=>$a,'operationId'=>$operation,'issuedAt'=>InboxRules::instant($issued),'expiresAt'=>InboxRules::instant($now+60000),'freeText'=>$text,'expectedRevision'=>$expected];
    $delivered=$notice->send($input);$id='message:'.InboxStorage::namedUuid($project.':'.$a.':'.$operation.':0:'.$issued);check($id,$delivered['entryId'],'cross-runtime deterministic ID');
    check($text,$service->get($a)['entries'][0]['parameters']->text,'plain UTF8 original');check(true,$notice->send($input)['replayed'],'exact replay');
    $limits(['INBOX_FREE_TEXT_BYTES'=>1,'INBOX_FREE_TEXT_RETENTION_DAYS'=>1]);check(true,$notice->send($input)['replayed'],'retained replay precedes lowered policy');
    $changed=$input;$changed['freeText']='changed';reject('OPERATION_CONFLICT',static function()use($notice,$changed){$notice->send($changed);});
    $read=$service->markRead($a,$id);check(true,$read['entries'][0]['read'],'read');check($read['revision'],$service->markRead($a,$id)['revision'],'idempotent read');
    reject('NO_ATTACHMENT',static function()use($service,$a,$id){$service->claim($a,$id);});$service->remove($a,$id);reject('STATE_CONFLICT',static function()use($notice,$input){$notice->send($input);});
    $floor=$service->get($a)['revision'];$limits([]);$now-=1000;$next=$service->freeText($a,Database::uuid(),$floor,$issued,$issued+60000,'clock reversal');check(true,$next['entryId']!==$id,'new generation ID');check(true,InboxRules::millis($service->get($a)['entries'][0]['createdAt'])>$floor,'monotone generation despite clock reversal');
    $now=$issued+60001;$service->cleanup();$cleared=InboxStorage::state($load($a));check([],$cleared['freeTextEntries'],'physical expiry');check(true,$cleared['revision']>$floor,'empty mailbox keeps floor');check(null,$pdo->query("SELECT next_expiry FROM inbox_state WHERE user_id='$a'")->fetchColumn(),'empty expiry cleared');
    reject('STATE_CONFLICT',static function()use($notice,$input){$notice->send($input);});
    // Source visibility and generation survive read/remove/prune with frozen/backwards clocks.
    $request=Database::uuid();$peer=Database::uuid();$incoming=[['userId'=>$peer,'requestId'=>$request,'createdAt'=>InboxRules::instant($now)]];
    $pdo->prepare('INSERT INTO friends_state(user_id,active_user_id,revision,friends,incoming,outgoing,blocked) VALUES(?,?,1,?,?,?,?)')->execute([$b,$b,'[]',InboxRules::json($incoming),'[]','[]']);
    $content=['type'=>'friend_request','reference'=>$request,'textKey'=>'horizon.friends.request','parameters'=>['userId'=>$peer],'expiresAt'=>InboxRules::instant($now+60000)];
    $originalIssued=$now;$first=$service->deliver($b,$content,'friends:request:'.$request,$originalIssued,'friends:request:'.$request);$firstView=$service->get($b);check($request,$firstView['entries'][0]['reference'],'resolved pending source');
    $service->markRead($b,$first['entryId']);$prior=$service->get($b);$pdo->prepare("UPDATE friends_state SET incoming='[]',revision=2 WHERE user_id=?")->execute([$b]);$invisible=$service->get($b,$prior['cursor']);check(false,$invisible['unchanged'],'source visibility changes cursor');check($prior['revision'],$invisible['revision'],'source visibility needs no recipient write');check([],$invisible['entries'],'resolved pending disappears');
    $service->remove($b,$first['entryId']);$now+=60001;$service->cleanup();$pdo->prepare('UPDATE friends_state SET incoming=?,revision=3 WHERE user_id=?')->execute([InboxRules::json($incoming),$b]);$now=$originalIssued;$content['expiresAt']=InboxRules::instant($now+60000);
    $second=$service->deliver($b,$content,'friends:request:'.$request,$originalIssued,'friends:request:'.$request);check(true,$second['entryId']!==$first['entryId'],'recreated source cannot reuse read identity');reject('ENTRY_NOT_FOUND',static function()use($service,$b,$first){$service->markRead($b,$first['entryId']);});check(false,$service->get($b)['entries'][0]['read'],'old read never marks new generation');
    $row=json_decode($load($b),true);check(34,strlen(base64_decode($row['e'])),'one34B source');check(false,isset($row['q']),'no new source receipts');check(false,strpos($load($b),'textKey')!==false,'no payload snapshot');
    // Valuable catalogue order is the slot mapping; ordinary cleanup/insertion cannot move or reuse slots.
    $body=static function(?int $amount=null,int $duration=60000)use(&$now){return ['type'=>'notice','textKey'=>'inbox.notice','expiresAt'=>InboxRules::instant($now+$duration),'attachment'=>$amount===null?null:['values'=>[['key'=>'coins','amount'=>$amount]]]];};
    $publish=static function(array $content)use($service,&$now){$rev=$service->broadcasts()['revision'];return $service->publish($content,$now.':'.Database::uuid(),$rev);};
    $originalReward=$body(11);$reward=$publish($originalReward)['operation'];reject('UNCLAIMED_REWARD',static function()use($service,$a,$reward){$service->remove($a,$reward['entryId']);});$before=$load($a);reject('DAILY_LIMIT_REACHED',static function()use($service,$a,$reward){$service->claim($a,$reward['entryId']);});check($before,$load($a),'failed credit rolls back marker and revision');
    $values->configure(['definitions'=>[['key'=>'coins','name'=>'Coins','maxBalance'=>1000,'dailyCap'=>1000]],'offers'=>[]],1);$pdo->loseCommit=true;reject('STATE_CONFLICT',static function()use($service,$a,$reward){$service->claim($a,$reward['entryId']);});check(true,$service->claim($a,$reward['entryId'])['replayed'],'lost commit safely replays');check(11,balance($values->get($a)),'one original credit');
    $ordinary=$publish($body(null,1000))['operation']['entryId'];$reward2=$publish($body(2))['operation']['entryId'];$service->markRead($a,$ordinary);$raw=InboxStorage::state($load($a));check(17,strlen(base64_decode(json_decode($load($a),true)['o'])),'only one interacted ordinary17B');check(2,InboxStorage::status($raw['rewardStates'],0),'first reward claimed slot0');check(0,InboxStorage::status($raw['rewardStates'],1),'second unseen slot1');
    $now+=1001;$service->cleanup();$publish($body(null,60000));$cat=InboxStorage::catalog($pdo->query('SELECT data FROM inbox_catalog WHERE id=1')->fetchColumn());check(0,InboxStorage::rewardSlot($cat,$reward['entryId']),'ordinary expiry leaves slot0');check(1,InboxStorage::rewardSlot($cat,$reward2),'ordinary expiry leaves slot1');$raw=InboxStorage::state($load($a));check([],$raw['ordinaryMarkers'],'expired markers physically removed');check(2,InboxStorage::status($raw['rewardStates'],0),'reward state remains');
    $limits(['INBOX_BROADCASTS'=>1,'INBOX_TARGETED'=>1]);check(true,count($service->get($a)['entries'])>1,'lower quota does not hide retained reads');check(true,$service->publish($originalReward,$reward['operationId'],0)['operation']['replayed'],'original publication before current policy');reject('BROADCAST_LIMIT_REACHED',static function()use($publish,$body){$publish($body());});$limits([]);
    // Native cold migration retains original valuable payload, date, hash, receipts and eligible statuses.
    $legacy=$user();$legacyState=InboxRules::emptyState();$legacyId='message:'.Database::uuid();$legacyContent=InboxRules::content($body(3));$legacyOp='achievement:'.Database::uuid();$legacyHash=InboxRules::hash($legacyContent,$legacyOp,$now);
    $legacyState['entries'][]=InboxRules::message($legacyId,$legacyContent,$now,$legacyOp,$legacyHash);$legacyState=InboxRules::record($legacyState,$legacyOp,$legacyHash,$now,$legacyId);$save($legacy,$legacyState);$original=$load($legacy);
    $legacyVisible=array_values(array_filter($service->get($legacy)['entries'],static function($e)use($legacyId){return $e['id']===$legacyId;}));check(3,$legacyVisible[0]['attachment']['values'][0]['amount'],'legacy original readable');check($original,$load($legacy),'legacy read has no rewrite');reject('MIGRATION_REQUIRED',static function()use($service,$legacy,$legacyId){$service->claim($legacy,$legacyId);});
    $migration=new InboxMigration($pdo);check(true,$migration->player($legacy),'explicit conversion');$compact=InboxStorage::state($load($legacy));check(InboxRules::json($legacyState['entries']),InboxRules::json($compact['entries']),'full original JSON payload and provenance retained');check($legacyState['receipts'],$compact['receipts'],'finite old receipts retained');check(3,balance($service->claim($legacy,$legacyId)['playerValues']),'old value granted once');check(true,$service->claim($legacy,$legacyId)['replayed'],'old claim replays');
    $bad=$user();$broken=$legacyState;$broken['entries'][0]['content']['attachment']['values'][0]['futureValue']=7;$save($bad,$broken);$before=$load($bad);reject('MIGRATION_REQUIRED',static function()use($migration,$bad){$migration->player($bad);});check($before,$load($bad),'unknown reward remains byte-identical');check(0,(int)$pdo->query("SELECT COUNT(*) FROM player_values WHERE user_id='$bad'")->fetchColumn(),'migration failure never credits');
    $unknownCompact=$user();$unsafe=InboxStorage::emptyState();$unsafe['entries']=$broken['entries'];$save($unknownCompact,$unsafe);$unchanged=$load($unknownCompact);
    $unsafeId=$unsafe['entries'][0]['id'];reject('MIGRATION_REQUIRED',static function()use($service,$unknownCompact,$unsafeId){$service->claim($unknownCompact,$unsafeId);});reject('MIGRATION_REQUIRED',static function()use($service,$unknownCompact,$unsafeId){$service->remove($unknownCompact,$unsafeId);});check($unchanged,$load($unknownCompact),'unknown current retained reward not consumed/discarded');
    // Independent native claim/publication races, no emulated in-memory guards.
    $race=$publish($body(1))['operation']['entryId'];$jobs=[];for($i=0;$i<4;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--claim',$target,$c,$race,(string)$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$jobs[]=[$process,$pipes];}
    $wins=0;foreach($jobs as[$process,$pipes]){$reply=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(0,proc_close($process),'native claim worker');if($err!=='')throw new RuntimeException($err);$result=json_decode($reply,true,512,JSON_THROW_ON_ERROR);check(false,isset($result['code']),'claim succeeds/replays');if(!$result['replayed'])$wins++;}check(1,$wins,'four claimers one credit');check(1,balance($values->get($c)),'native race exact credit');
    $revision=$service->broadcasts()['revision'];$jobs=[];for($i=0;$i<4;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--publish',$target,(string)$revision,$now.':'.Database::uuid(),(string)$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$jobs[]=[$process,$pipes];}$wins=0;foreach($jobs as[$process,$pipes]){$reply=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(0,proc_close($process),'native publication worker');if($err!=='')throw new RuntimeException($err);$result=json_decode($reply,true,512,JSON_THROW_ON_ERROR);if(isset($result['code']))check('REVISION_CONFLICT',$result['code'],'CAS loser');else $wins++;}check(1,$wins,'one catalogue CAS winner');
    $explain=$pdo->prepare(($dsn?'EXPLAIN ':'EXPLAIN QUERY PLAN ').'SELECT user_id FROM inbox_state WHERE next_expiry<=? ORDER BY next_expiry,user_id LIMIT 100');$explain->execute([$now]);$plan=$explain->fetchAll();check(true,strpos(json_encode($plan),'idx_inbox_expiry')!==false,'physical cleanup uses expiry index');
    $profile=['emptyState'=>InboxStorage::encodeState(InboxStorage::emptyState()),'emptyStateBytes'=>strlen(InboxStorage::encodeState(InboxStorage::emptyState())),'emptyCatalogBytes'=>strlen(InboxStorage::encodeCatalog(InboxStorage::emptyCatalog($project))),'sourceTupleBytes'=>34,'ordinaryMarkerBytes'=>17,'interactedRewardBytes'=>strlen($raw['rewardStates']),'oneSourceSqlJson'=>$load($b)];echo 'INBOX_SQL_PROFILES='.InboxRules::json($profile)."\n";
    echo 'PASS: '.$passed.' Inbox '.($dsn?'MySQL':'SQLite')." assertions; source-only/clock/slots/migration/atomic credit/native races\n";
}finally{
    if($pdo&&isset($users)){if($pdo->inTransaction())$pdo->rollBack();foreach($users as$u)$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$u]);}$pdo=null;
    if(!$dsn)foreach([$target,$target.'-wal',$target.'-shm']as$file)if(file_exists($file))unlink($file);if(file_exists($settings))unlink($settings);
}
