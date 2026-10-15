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
$passed = 0;
function check($expected, $actual, string $label): void { global $passed; if ($expected !== $actual) throw new RuntimeException($label . ': expected '.json_encode($expected).', got '.json_encode($actual)); $passed++; }
function reject(string $code, callable $work): void { try {$work();} catch (InboxFailure $error) {check($code,$error->errorCode,'inbox rejection');return;} catch(PlayerValuesFailure $error) {check($code,$error->errorCode,'grant rejection');return;} throw new RuntimeException('Expected '.$code); }
function balance(array $view): int { foreach($view['values'] as $row) if($row['key']==='coins') return $row['balance']; throw new RuntimeException('No coins'); }
$dsn = getenv('HORIZON_INBOX_TEST_DSN');
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1006_[a-f0-9]+;charset=utf8mb4$/D',$dsn)) throw new RuntimeException('Only explicit loopback task1006_<hex> database is allowed');
$target = $dsn ?: tempnam(sys_get_temp_dir(),'horizon-task1006-'); $pdo = null;
try {
    $pdo=inboxConnection($target); $pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));
    Database::ensurePlayerValuesConfig($pdo); Database::ensureStatisticsConfig($pdo); Database::ensureInboxConfig($pdo);
    $now=(int)(strtotime('2026-10-16T12:00:00Z')*1000); $clock=static function()use(&$now){return $now;};
    $service=new InboxService($pdo,$clock); $values=new PlayerValuesService($pdo,$clock);
    $user=Database::uuid(); $other=Database::uuid(); $late=Database::uuid();
    $insert=$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at,created_at_millis) VALUES(?,?,?,?,?)');
    foreach([$user,$other,$late] as $id) $insert->execute([$id,'Inbox',Database::uuid(),'2026-10-16 12:00:00',$id===$late?$now+1:$now-1000]);
    $config=['definitions'=>[['key'=>'coins','name'=>'Coins','maxBalance'=>1000,'dailyCap'=>10]],'offers'=>[]]; $values->configure($config,0);
    $empty=$service->get($user); check([], $empty['entries'],'empty inbox'); check(120,$empty['pollAfterSeconds'],'poll interval'); check(true,$service->get($user,$empty['cursor'])['unchanged'],'conditional unchanged'); check(true,is_object($empty['counts']),'counts are object');
    $content=static function(?int $amount=null,int $expiry=60000)use(&$now){return ['type'=>'notice','textKey'=>'inbox.notice','expiresAt'=>InboxRules::instant($now+$expiry),'attachment'=>$amount===null?null:['values'=>[['key'=>'coins','amount'=>$amount]]]];};
    $operation=static function(int $offset=0)use(&$now){return ($now+$offset).':'.Database::uuid();};
    $normal=$content(); $delivered=$service->deliver($user,$normal,'source-ordinary',$now,'fixture');
    check(false,$delivered['replayed'],'first delivery'); check($delivered['entryId'],$service->deliver($user,$normal,'source-ordinary',$now,'fixture')['entryId'],'retained delivery replay');
    check(1,count($service->get($user)['entries']),'delivery once');
    $changed=$normal; $changed['reference']='changed'; reject('OPERATION_CONFLICT',static function()use($service,$user,$changed,$now){$service->deliver($user,$changed,'source-ordinary',$now,'fixture');});
    $read=$service->markRead($user,$delivered['entryId']);check(true,$read['entries'][0]['read'],'mark read');check($read['revision'],$service->markRead($user,$delivered['entryId'])['revision'],'read idempotent');
    check(true,strpos(InboxRules::json($read),'"parameters":{}')!==false,'empty parameters stay JSON object');
    reject('NO_ATTACHMENT',static function()use($service,$user,$delivered){$service->claim($user,$delivered['entryId']);});
    $removed=$service->remove($user,$delivered['entryId']);check([],$removed['entries'],'ordinary remove');check($removed['revision'],$service->remove($user,$delivered['entryId'])['revision'],'remove idempotent');
    check(true,$service->deliver($user,$normal,'source-ordinary',$now,'fixture')['replayed'],'delivery receipt survives removal');check([],$service->get($user)['entries'],'receipt never redelivers removed entry');
    $reward=$service->deliver($user,$content(11),'source-reward',$now,'fixture');
    reject('UNCLAIMED_REWARD',static function()use($service,$user,$reward){$service->remove($user,$reward['entryId']);});
    $before=$pdo->query("SELECT data FROM inbox_state WHERE user_id='$user'")->fetch()['data'];
    reject('DAILY_LIMIT_REACHED',static function()use($service,$user,$reward){$service->claim($user,$reward['entryId']);});
    check($before,$pdo->query("SELECT data FROM inbox_state WHERE user_id='$user'")->fetch()['data'],'failed grant rolls inbox marker/revision back');
    check(0,(int)$pdo->query("SELECT COUNT(*) FROM player_values WHERE user_id='$user'")->fetchColumn(),'failed initialization/grant leaves no value row');
    $config['definitions'][0]['dailyCap']=1000;$values->configure($config,1);
    $pdo->loseCommit=true;reject('STATE_CONFLICT',static function()use($service,$user,$reward){$service->claim($user,$reward['entryId']);});
    $claimed=$service->claim($user,$reward['entryId']);check(true,$claimed['replayed'],'uncertain committed claim safely replays');check(11,balance($claimed['playerValues']),'exact grant');
    check(true,$service->claim($user,$reward['entryId'])['replayed'],'duplicate claim replay');check(11,balance($values->get($user)),'no duplicate grant');
    $service->remove($user,$reward['entryId']);reject('ENTRY_NOT_FOUND',static function()use($service,$user,$reward){$service->claim($user,$reward['entryId']);});
    // Publication eligibility has millisecond resolution, even within one legacy created_at second.
    $pub=$service->publish($content(2,1000),$operation(),0);$broadcast=$pub['operation']['entryId'];$original=$pub['operation']['operationId'];
    check(1,count($service->get($user)['entries']),'existing user eligible');check([],$service->get($late)['entries'],'same-second later signup excluded');
    check(true,$service->publish($content(2,1000),$original,0)['operation']['replayed'],'publication replay before stale If-Match');
    reject('REVISION_CONFLICT',static function()use($service,$content,$operation){$service->publish($content(),$operation(),0);});
    reject('UNCLAIMED_REWARD',static function()use($service,$broadcast){$service->removeBroadcast($broadcast,1);});
    $ordinary=$service->publish($content(null,1000),$operation(1),1);$oldView=$service->get($user);$oldRevision=$oldView['revision'];$oldBroadcast=$oldView['broadcastRevision'];
    $now+=2000;$overdue=$service->get($user,$oldView['cursor']);check(false,$overdue['unchanged'],'time transition invalidates cursor');check($oldRevision,$overdue['revision'],'expiry needs no player write');check($oldBroadcast,$overdue['broadcastRevision'],'expiry needs no catalog write');
    check(1,count($overdue['entries']),'ordinary expired/reward retained');check(true,$overdue['entries'][0]['overdue'],'valuable attachment overdue');
    check(1,$overdue['counts']->notice['claimable'],'claimable counters');
    $claimedBroadcast=$service->claim($user,$broadcast);check(13,balance($claimedBroadcast['playerValues']),'overdue broadcast claim');check([],$claimedBroadcast['inbox']['entries'],'claimed overdue broadcast hidden');
    check(true,$service->claim($user,$broadcast)['replayed'],'retained broadcast marker replay after expiry');
    check(true,$service->publish(['type'=>'notice','textKey'=>'inbox.notice','expiresAt'=>InboxRules::instant($now-1000),'attachment'=>['values'=>[['key'=>'coins','amount'=>2]]]],$original,0)['operation']['replayed'],'retained publication before expiry validation');
    // Internal pending source owns the same PDO transition. Old source cannot use normal replay window.
    $pdo->exec('CREATE TABLE inbox_pending_fixture(id VARCHAR(40) PRIMARY KEY,user_id VARCHAR(36),status VARCHAR(20),entry_id VARCHAR(80))');
    $pdo->prepare('INSERT INTO inbox_pending_fixture(id,user_id,status) VALUES(?,?,?)')->execute(['escrow',$user,'pending']);
    $pending=$content(3,-1000);$issued=$now-InboxRules::RETENTION-1;
    reject('OPERATION_EXPIRED',static function()use($service,$user,$pending,$issued){$service->deliver($user,$pending,'old-escrow',$issued,'escrow');});
    $complete=static function(PDO $connection,string $entry)use($pdo,$user){if($connection!==$pdo)throw new RuntimeException('Foreign PDO');$q=$connection->prepare('UPDATE inbox_pending_fixture SET status=?,entry_id=? WHERE id=? AND user_id=? AND status=?');$q->execute(['delivered',$entry,'escrow',$user,'pending']);return $q->rowCount()===1;};
    $pendingResult=$service->deliverPending($user,$pending,'old-escrow',$issued,'escrow',$complete);check(false,$pendingResult['replayed'],'old pending source delivered');
    check('delivered',$pdo->query("SELECT status FROM inbox_pending_fixture WHERE id='escrow'")->fetchColumn(),'pending source consumed atomically');
    check(true,$service->get($user)['entries'][0]['overdue'],'old return visible overdue');
    $count=count($service->get($user)['entries']);reject('SOURCE_NOT_PENDING',static function()use($service,$user,$pending,$issued,$complete){$service->deliverPending($user,$pending,'old-escrow',$issued,'escrow',$complete);});check($count,count($service->get($user)['entries']),'completed source cannot duplicate');
    reject('SOURCE_NOT_PENDING',static function()use($service,$user,$content,$now){$service->deliverPending($user,$content(1),'false-source',$now,'escrow',static function(){return false;});});check($count,count($service->get($user)['entries']),'false source guard rolls new inbox entry back');
    $pdo->beginTransaction();$outer=$service->deliver($user,$content(),'outer',$now,'fixture');$pdo->rollBack();check($count,count($service->get($user)['entries']),'joins caller PDO transaction');
    // Receipt lifetime of Player Values does not weaken the permanent inbox guard.
    for($i=1;$i<=51;$i++)$values->correct($user,[['key'=>'coins','balance'=>13]],'evict receipt',$operation($i));
    check(true,$service->claim($user,$broadcast)['replayed'],'claim replay survives value receipt eviction');check(13,balance($values->get($user)),'eviction never regrants');$now+=100;
    foreach([['attachment'=>['items'=>[]]],['attachment'=>['values'=>null]],['attachment'=>['values'=>[['key'=>'coins','amount'=>'1']]]],['attachment'=>['values'=>[['key'=>'coins','amount'=>1.5]]]],['textKey'=>"\u{2003}"],['parameters'=>null]] as $bad){$input=array_replace($content(),$bad);reject('INVALID_REQUEST',static function()use($service,$user,$input,$now){$service->deliver($user,$input,Database::uuid(),$now,'invalid');});}
    reject('INVALID_REQUEST',static function()use($service,$user,$content,$now){$service->deliver($user,$content(1001),'too-large',$now,'fixture');});
    $unknown=$content(1);$unknown['attachment']['values'][0]['key']='missing';reject('INVALID_REQUEST',static function()use($service,$user,$unknown,$now){$service->deliver($user,$unknown,'unknown',$now,'fixture');});
    $large=$content();$large['parameters']=array_fill_keys(['a','b','c','d','e','f','g','h'],str_repeat('x',120));reject('INVALID_REQUEST',static function()use($service,$user,$large,$now){$service->deliver($user,$large,'large',$now,'fixture');});
    reject('INVALID_REQUEST',static function()use($service,$user){$service->get($user,str_repeat('x',129));});
    reject('INVALID_REQUEST',static function()use($service,$user,$content,$now){$service->deliver($user,$content(),'future',$now+30001,'fixture');});
    reject('INVALID_REQUEST',static function()use($service,$content,$operation){$service->publish($content(),$operation(30001),2);});
    $unicodeSource=$content();$unicodeSource['parameters']=array_fill_keys(['a','b','c','d'],str_repeat('x',120));
    foreach([str_repeat('ä',128),str_repeat('"',128)] as $escapedOperation) reject('INVALID_REQUEST',static function()use($service,$user,$unicodeSource,$escapedOperation,$now){$service->deliver($user,$unicodeSource,$escapedOperation,$now,'fixture');});
    // Full inbox protects every unclaimed reward and the source transition.
    for($i=0;$i<50;$i++)$service->deliver($other,$content(1),'full-'.$i,$now,'fixture');
    reject('INBOX_FULL',static function()use($service,$other,$content,$now){$service->deliver($other,$content(1),'full-overflow',$now,'fixture');});
    check(50,count(array_filter($service->get($other)['entries'],static function($row){return $row['kind']==='message';})),'hard target bound');
    // Seed a valid saturated retained catalogue to exercise marker pruning/capacity cheaply.
    $catalog=json_decode($pdo->query('SELECT data FROM inbox_catalog WHERE id=1')->fetchColumn(),true);$catalog['entries']=[];$catalog['revision']=20;$catalog['receipts']=[];$markers=[];
    for($i=0;$i<500;$i++){$id='broadcast:'.Database::uuid();$catalog['entries'][]=InboxRules::message($id,InboxRules::content($content(1)),$now,'pinned-'.$i,str_repeat('0',64));$markers[]=['id'=>$id,'read'=>true,'claimed'=>true,'removed'=>false];}
    $pdo->prepare('UPDATE inbox_catalog SET data=? WHERE id=1')->execute([InboxRules::json($catalog)]);
    $saturated=InboxRules::emptyState();$saturated['markers']=$markers;$pdo->prepare('UPDATE inbox_state SET data=? WHERE user_id=?')->execute([InboxRules::json($saturated),$user]);
    $service->remove($user,$markers[0]['id']);$stored=json_decode($pdo->query("SELECT data FROM inbox_state WHERE user_id='$user'")->fetchColumn(),true);check(500,count($stored['markers']),'all reward markers remain pinned');check(true,$stored['markers'][499]['removed'],'removed reward marker stays pinned');
    reject('BROADCAST_LIMIT_REACHED',static function()use($service,$content,$operation){$service->publish($content(),$operation(),20);});check(500,count($service->broadcasts()['entries']),'hard broadcast bound');
    // Reset only own fixtures for actual independent-process claim and publication races.
    $catalog['entries']=[];$catalog['revision']=30;$catalog['receipts']=[];$catalog['evictedThroughMillis']=0;$pdo->prepare('UPDATE inbox_catalog SET data=? WHERE id=1')->execute([InboxRules::json($catalog)]);
    $race=$service->publish($content(1),$operation(),30)['operation']['entryId'];$jobs=[];
    for($i=0;$i<4;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--claim',$target,$user,$race,(string)$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker failed');fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $applied=0;foreach($jobs as [$p,$pipes]){$reply=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(0,proc_close($p),'claim worker');if($err!=='')throw new RuntimeException($err);$r=json_decode($reply,true,512,JSON_THROW_ON_ERROR);check(false,isset($r['code']),'claim succeeds/replays');if(!$r['replayed'])$applied++;}check(1,$applied,'four concurrent claims grant once');check(14,balance($values->get($user)),'concurrent balance exact');
    $jobs=[];for($i=0;$i<4;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--publish',$target,'31',$operation($i),(string)$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker failed');fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $wins=0;foreach($jobs as [$p,$pipes]){$reply=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(0,proc_close($p),'publication worker');if($err!=='')throw new RuntimeException($err);$r=json_decode($reply,true,512,JSON_THROW_ON_ERROR);if(isset($r['code']))check('REVISION_CONFLICT',$r['code'],'publication CAS loser');else$wins++;}check(1,$wins,'concurrent publishers have one CAS winner');
    // Bounded delivery receipts reject old/unseen IDs after removal; retained replay precedes expiry.
    $history=$content();$firstHistory='history-'.Database::uuid();
    for($i=0;$i<51;$i++){$lastHistory=$i===0?$firstHistory:'history-'.Database::uuid();$h=$service->deliver($late,$history,$lastHistory,$now+$i,'history');$service->remove($late,$h['entryId']);}
    $stored=json_decode($pdo->query("SELECT data FROM inbox_state WHERE user_id='$late'")->fetchColumn(),true);
    check(50,count($stored['receipts']),'delivery receipts bounded50');check($now,$stored['evictedThroughMillis'],'delivery floor persisted');
    reject('OPERATION_EXPIRED',static function()use($service,$late,$history,$firstHistory,$now){$service->deliver($late,$history,$firstHistory,$now,'history');});
    reject('OPERATION_EXPIRED',static function()use($service,$late,$history,$now){$service->deliver($late,$history,'unseen-old',$now,'history');});
    $now+=InboxRules::RETENTION+1;
    check(true,$service->deliver($late,$history,$lastHistory,$now-InboxRules::RETENTION-1+50,'history')['replayed'],'retained delivery before expiry');
    $scope=new SqlTransaction($pdo);$prior=$pdo->query("SELECT data FROM inbox_state WHERE user_id='$user'")->fetchColumn();
    try{$scope->run(static function()use($scope,$pdo,$user){try{$scope->run(static function()use($pdo,$user){$pdo->prepare('DELETE FROM inbox_state WHERE user_id=?')->execute([$user]);throw new RuntimeException('nested');});}catch(RuntimeException $ignored){}return true;});throw new RuntimeException('Expected rollback-only');}catch(SqlTransactionRollbackOnly $expected){check($prior,$pdo->query("SELECT data FROM inbox_state WHERE user_id='$user'")->fetchColumn(),'caught nested failure still rolls back');}
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$other]);check(0,(int)$pdo->query("SELECT COUNT(*) FROM inbox_state WHERE user_id='$other'")->fetchColumn(),'delete cascade');
    echo "PASS: $passed inbox ".($dsn?'MySQL':'SQLite')." assertions\n";
}finally{
    if($pdo&&isset($user)){if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare('DELETE FROM users WHERE id IN(?,?,?)')->execute([$user,$other,$late]);}$pdo=null;
    if(!$dsn)foreach([$target,$target.'-wal',$target.'-shm']as$f)if(file_exists($f))unlink($f);
}
