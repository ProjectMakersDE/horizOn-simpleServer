<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Achievements/AchievementService.php';

class AchievementProbePDO extends PDO {
    public bool $loseCommit=false;
    public int $writes=0,$progressReads=0;
    private function observe(string $sql):void{if(preg_match('/^\s*(INSERT|UPDATE|DELETE)/i',$sql))$this->writes++;if(preg_match('/^\s*SELECT.*achievement_state/i',$sql))$this->progressReads++;}
    private function acknowledge():void{if($this->loseCommit){$this->loseCommit=false;throw new RuntimeException('Injected lost commit acknowledgement');}}
    #[\ReturnTypeWillChange]
    public function prepare($sql,$options=[]){$this->observe($sql);return parent::prepare($sql,$options);}
    #[\ReturnTypeWillChange]
    public function query($sql,...$args){$this->observe($sql);return parent::query($sql,...$args);}
    #[\ReturnTypeWillChange]
    public function exec($sql){$this->observe($sql);$result=parent::exec($sql);if(strtoupper(trim($sql))==='COMMIT')$this->acknowledge();return $result;}
    #[\ReturnTypeWillChange]
    public function commit(){$result=parent::commit();$this->acknowledge();return $result;}
}
function connection(string $target):AchievementProbePDO{
    $mysql=strpos($target,'mysql:')===0;
    $pdo=new AchievementProbePDO($mysql?$target:'sqlite:'.$target,$mysql?getenv('HORIZON_ACHIEVEMENTS_TEST_USER'):null,$mysql?getenv('HORIZON_ACHIEVEMENTS_TEST_PASSWORD'):null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if(!$mysql){$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA journal_mode=WAL');}return $pdo;
}
if(($argv[1]??'')==='--unlock'||($argv[1]??'')==='--deliver'){
    try{$service=new AchievementService(connection($argv[2]),static function()use($argv){return (int)$argv[5];});echo InboxRules::json($argv[1]==='--unlock'?$service->unlock($argv[3],$argv[4]):$service->deliver($argv[3],[$argv[4]]));}
    catch(AchievementFailure $error){echo json_encode(['code'=>$error->errorCode]);}exit;
}
$passed=0;
function check($expected,$actual,string $label):void{global$passed;if($expected!==$actual)throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual));$passed++;}
function reject(string $code,callable $work):void{try{$work();}catch(AchievementFailure $error){check($code,$error->errorCode,'achievement rejection');return;}catch(InboxFailure $error){check($code,$error->errorCode,'inbox rejection');return;}throw new RuntimeException('Expected '.$code);}
$dsn=getenv('HORIZON_ACHIEVEMENTS_TEST_DSN');
if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1008_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Only explicit loopback task1008_<hex> DB permitted');
$target=$dsn?:tempnam(sys_get_temp_dir(),'horizon-task1008-');$pdo=null;
try{
    $pdo=connection($target);$pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));
    Database::ensurePlayerValuesConfig($pdo);Database::ensureStatisticsConfig($pdo);Database::ensureInboxConfig($pdo);Database::ensureAchievementsConfig($pdo);
    $now=(int)(strtotime('2026-10-16T12:00:00Z')*1000);$clock=static function()use(&$now){return $now;};
    $service=new AchievementService($pdo,$clock);$stats=new StatisticsService($pdo,$clock);$inbox=new InboxService($pdo,$clock);$values=new PlayerValuesService($pdo,$clock);
    $users=[];$insert=$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at,created_at_millis) VALUES(?,?,?,?,?)');
    for($i=0;$i<5;$i++){$id=Database::uuid();$users[]=$id;$insert->execute([$id,'Achievement',Database::uuid(),'2026-10-16 12:00:00',$now]);}
    [$user,$other,$rollback,$uncertain,$parallel]=$users;
    $values->configure(['definitions'=>[['key'=>'coins','name'=>'Coins','maxBalance'=>1000]],'offers'=>[]],0);
    $stats->configure([['key'=>'kills','name'=>'Kills','mode'=>'sum'],['key'=>'rounds','name'=>'Rounds','mode'=>'sum']],0);
    $definition=static function(string $key,bool $manual=false,int $amount=1,bool $hidden=false){return ['key'=>$key,'name'=>'Achievement '.$key,'hidden'=>$hidden,'mode'=>$manual?'manual':'statistic',
        'condition'=>$manual?null:['statisticKey'=>'kills','threshold'=>1],'rewards'=>['values'=>$amount?[['key'=>'coins','amount'=>$amount]]:[]]];};
    $op=static function()use(&$now){return ($now++).':'.Database::uuid();};
    $empty=$service->get($user);check([],$empty['progress'],'empty progress');check(250,$empty['limits']['maxDefinitions'],'selfhost active bound');
    $config=$service->configure([$definition('hidden',true,0,true),$definition('manual',true,2)],0);check(1,$config['revision'],'initial catalog revision');
    $locked=$service->get($user);check(['manual'],array_column($locked['definitions'],'key'),'locked hidden metadata omitted');
    check(null,$service->get($user,$locked['definitionVersion'])['definitions'],'matching opaque version omits metadata');
    check(false,$service->get($other)['definitionVersion']===$locked['definitionVersion'],'version is player-specific');
    $pdo->writes=0;$service->get($user);check(0,$pdo->writes,'GET stays read-only');
    $hidden=$service->unlock($user,'hidden');check(false,$hidden['replayed'],'first manual unlock');check(['hidden'],$hidden['newlyUnlocked'],'manual notification');
    check(['hidden','manual'],array_column($hidden['achievements']['definitions'],'key'),'unlock exposes hidden metadata');check(false,$hidden['achievements']['definitionVersion']===$locked['definitionVersion'],'progress invalidates hidden metadata version');
    $service->configure([],1);check(true,$service->unlock($user,'hidden')['replayed'],'guard precedes archival and manual policy');check([],$service->unlock($user,'hidden')['newlyUnlocked'],'replay never repeats notification');
    reject('REVISION_CONFLICT',static function()use($service){$service->configure([],0);});
    foreach([['threshold'=>'1'],['threshold'=>1.5],['threshold'=>-1],['threshold'=>9007199254740992]]as$bad){$row=$definition('bad');$row['condition']=array_merge($row['condition'],$bad);reject('INVALID_REQUEST',static function()use($service,$row){$service->configure([$row],2);});}
    foreach([['hidden'=>1],['active'=>null],['description'=>null],['name'=>str_repeat('ä',61)],['imageUrl'=>'https://user:pass@example.invalid/pic']]as$bad){$row=array_merge($definition('bad'),$bad);reject('INVALID_REQUEST',static function()use($service,$row){$service->configure([$row],2);});}
    $bad=$definition('bad');$bad['condition']['statisticKey']='missing';reject('UNKNOWN_STATISTIC',static function()use($service,$bad){$service->configure([$bad],2);});
    $bad=$definition('bad');$bad['rewards']['values'][0]['key']='missing';reject('UNKNOWN_VALUE',static function()use($service,$bad){$service->configure([$bad],2);});
    $bad=$definition('bad');$bad['rewards']=['items'=>[]];reject('INVALID_REQUEST',static function()use($service,$bad){$service->configure([$bad],2);});
    $rows=[];for($i=0;$i<20;$i++)$rows[]=$definition('kill-'.$i);
    $rows[]=$definition('manual',true,2);$service->configure($rows,2);
    $pdo->progressReads=0;$unrelated=$service->report($user,$op(),[['key'=>'rounds','value'=>1]]);check([],$unrelated['newlyUnlocked'],'unrelated stats do not unlock');check(0,$pdo->progressReads,'unaffected report avoids progress read');
    $content=['type'=>'fixture','textKey'=>'fixture','expiresAt'=>InboxRules::instant($now+InboxRules::RETENTION),'attachment'=>['values'=>[['key'=>'coins','amount'=>1]]]];
    for($i=0;$i<50;$i++)$inbox->deliver($user,$content,'capacity-'.$i,$now,'fixture');
    $original=$op();$reported=$service->report($user,$original,[['key'=>'kills','value'=>1]]);check(20,count($reported['newlyUnlocked']),'twenty unlocks commit despite full inbox');check(1,array_column($stats->get($user)['values'],null,'key')['kills']['value'],'stats still committed');
    $pending=$service->get($user);check(20,count(array_filter($pending['progress'],static function($row){return $row['rewardState']==='pending';})),'all rewards durable pending');
    check(1,$pending['progress'][0]['key']==='hidden'?1:0,'older guard preserved');
    $repeat=$service->report($user,$original,[['key'=>'kills','value'=>1]]);check(true,$repeat['replayed'],'statistics replay stays transaction-safe');check([],$repeat['newlyUnlocked'],'statistics replay has no duplicate unlocks');
    $blocked=$service->deliver($user,['kill-0','kill-1']);check(['INBOX_FULL','INBOX_FULL'],array_column($blocked['results'],'code'),'bounded partial drain reports full');
    // Later failure after the actual statistic write rolls both feature states back.
    if($dsn)$pdo->exec("CREATE TRIGGER achievement_failure BEFORE INSERT ON achievement_state FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rollback'");
    else $pdo->exec("CREATE TRIGGER achievement_failure BEFORE INSERT ON achievement_state BEGIN SELECT RAISE(ABORT,'fixture rollback'); END");
    try{$service->report($rollback,$op(),[['key'=>'kills','value'=>1]]);throw new RuntimeException('Expected injected progress failure');}
    catch(PDOException $expected){}
    catch(AchievementFailure $expected){check('STATE_CONFLICT',$expected->errorCode,'safe injected SQLite write failure');}
    $pdo->exec('DROP TRIGGER achievement_failure');check(0,(int)$pdo->query("SELECT COUNT(*) FROM statistics_state WHERE user_id='$rollback'")->fetchColumn(),'progress failure rolls statistic write back');check([],$service->get($rollback)['progress'],'failed progress absent');
    $scope=new SqlTransaction($pdo);$joined=new AchievementService($pdo,$clock,$scope);
    try{$scope->run(static function()use($joined,$rollback,$op){$joined->report($rollback,$op(),[['key'=>'kills','value'=>1]]);throw new RuntimeException('outer rollback');});}catch(RuntimeException $expected){}
    check(0,(int)$pdo->query("SELECT COUNT(*) FROM statistics_state WHERE user_id='$rollback'")->fetchColumn(),'explicit shared scope rolls stats back');check([],$service->get($rollback)['progress'],'explicit shared scope rolls unlocks back');check([],$inbox->get($rollback)['entries'],'no delivery before outer commit');
    // Free capacity and deliver the original immutable amount after current catalog reward edits and31days.
    $entry=$inbox->get($user)['entries'][0]['id'];$inbox->claim($user,$entry);$inbox->remove($user,$entry);
    $rows[0]['rewards']['values'][0]['amount']=999;$service->configure($rows,3);$now+=31*86400000;
    $delivered=$service->deliver($user,['kill-0','kill-1']);check('delivered',$delivered['results'][0]['state'],'old pending escrow delivered after expiry');check('pending',$delivered['results'][1]['state'],'next reward remains pending at capacity');
    $rewardId=$delivered['results'][0]['inboxEntryId'];$message=null;foreach($inbox->get($user)['entries']as$row)if($row['id']===$rewardId)$message=$row;
    check(1,$message['attachment']['values'][0]['amount'],'accepted original reward survives catalog edit');check(true,$message['overdue'],'overdue reward stays claimable');
    $claimed=$inbox->claim($user,$rewardId);check(2,$claimed['playerValues']['values'][0]['balance'],'actual original grant credited only on claim');check('delivered',$service->get($user)['progress'][1]['rewardState'],'pending transition persisted');
    $pdo->loseCommit=true;reject('STATE_CONFLICT',static function()use($service,$uncertain){$service->unlock($uncertain,'manual');});
    check('pending',$service->get($uncertain)['progress'][0]['rewardState'],'lost commit keeps permanent pending guard');
    $recovered=$service->unlock($uncertain,'manual');check(true,$recovered['replayed'],'uncertain unlock safely repeats');check(1,count($inbox->get($uncertain)['entries']),'uncertain handoff produces one Inbox entry');
    $scope->run(static function()use($joined,$other){$joined->unlock($other,'manual');});
    check('pending',$service->get($other)['progress'][0]['rewardState'],'external source commits before delivery');
    $pdo->loseCommit=true;$unknownHandoff=$service->deliver($other,['manual']);
    check('delivered',$unknownHandoff['results'][0]['state'],'lost handoff acknowledgement confirms atomic committed source');
    check(1,count($inbox->get($other)['entries']),'uncertain handoff has one original Inbox entry');
    $service->deliver($other,['manual']);check(1,count($inbox->get($other)['entries']),'repeated delivery after uncertain commit never duplicates');
    // Real independent processes: duplicate manual unlock and handoff never duplicate source/reward.
    $jobs=[];for($i=0;$i<4;$i++){$pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'--unlock',$target,$parallel,'manual',(string)$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker failed');fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $wins=0;foreach($jobs as[$p,$pipes]){$reply=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(0,proc_close($p),'unlock worker');if($error!=='')throw new RuntimeException($error);$r=json_decode($reply,true,512,JSON_THROW_ON_ERROR);if(isset($r['code']))check('STATE_CONFLICT',$r['code'],'safe collision');elseif(!$r['replayed'])$wins++;}
    check(1,$wins,'one first concurrent unlock');check(1,count($service->get($parallel)['progress']),'permanent once per key');check(1,count($inbox->get($parallel)['entries']),'one atomic Inbox handoff');
    foreach([[],array_fill(0,9,'manual'),['manual','manual'],[1]]as$bad)reject('INVALID_REQUEST',static function()use($service,$user,$bad){$service->deliver($user,$bad);});
    // Reserve every lifetime key, archive metadata, then reject a501st identity without forgetting guards.
    $catalog=json_decode($pdo->query('SELECT data FROM achievement_catalog WHERE id=1')->fetchColumn(),true);$catalog['definitions']=[];
    for($i=count($catalog['lifetimeKeys']);$i<500;$i++)$catalog['lifetimeKeys'][]='reserved-'.$i;
    $catalog['revision']++;$pdo->prepare('UPDATE achievement_catalog SET data=? WHERE id=1')->execute([InboxRules::json($catalog)]);
    reject('ACHIEVEMENT_LIFETIME_LIMIT_REACHED',static function()use($service,$definition,$catalog){$service->configure([$definition('new-501',true,0)],$catalog['revision']);});
    check(true,$service->unlock($user,'kill-0')['replayed'],'archived statistic unlock guard precedes manual check');
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$parallel]);check(0,(int)$pdo->query("SELECT COUNT(*) FROM achievement_state WHERE user_id='$parallel'")->fetchColumn(),'own-player cleanup cascades');
    echo 'PASS: '.$passed.' achievements '.($dsn?'MySQL':'SQLite')." assertions\n";
}finally{if($pdo&&$pdo->inTransaction())$pdo->rollBack();$pdo=null;if(!$dsn)foreach([$target,$target.'-wal',$target.'-shm']as$file)if(file_exists($file))unlink($file);}
