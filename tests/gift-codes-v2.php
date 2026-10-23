<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/GiftCodes/GiftCodesService.php';
class GiftV2ProbePDO extends PDO {
    public bool $loseCommit=false;
    private function ack():void{if($this->loseCommit){$this->loseCommit=false;throw new RuntimeException('Lost actual commit acknowledgement');}}
    #[\ReturnTypeWillChange]
    public function exec($sql){$result=parent::exec($sql);if(strtoupper(trim($sql))==='COMMIT')$this->ack();return$result;}
    #[\ReturnTypeWillChange]
    public function commit(){$result=parent::commit();$this->ack();return$result;}
}
function giftV2Connect(string $target):GiftV2ProbePDO{
    $mysql=strpos($target,'mysql:')===0;$p=new GiftV2ProbePDO($mysql?$target:'sqlite:'.$target,$mysql?getenv('HORIZON_GIFT_TEST_USER'):null,$mysql?getenv('HORIZON_GIFT_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if(!$mysql){$p->exec('PRAGMA foreign_keys=ON');$p->exec('PRAGMA journal_mode=WAL');}return$p;
}
if(($argv[1]??'')==='--race'){
    try{$p=giftV2Connect($argv[2]);$s=new GiftCodesService($p);echo json_encode($s->redeemPrepared('RACE',$argv[3],json_decode($argv[4],true)),JSON_THROW_ON_ERROR);}
    catch(GiftCodesFailure $e){echo json_encode(['code'=>$e->errorCode]);}exit;
}
if(($argv[1]??'')==='--identity-race'){
    try{
        $p=giftV2Connect($argv[2]);$scope=new SqlTransaction($p);$mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $work=static function()use($p,$scope,$argv,$mysql){
            // Both MySQL writers establish an old repeatable-read snapshot before contending on the global mutex.
            if($mysql)$p->query('SELECT COUNT(*) FROM gift_codes')->fetchColumn();
            file_put_contents($argv[5].'.ready','ready');$until=microtime(true)+5;while(!is_file($argv[5].'.go')){if(microtime(true)>$until)throw new RuntimeException('Barrier timed out');usleep(1000);}
            (new GiftCodeIdentity($p,$scope))->assign($argv[3],$argv[4]);
        };
        if($mysql)$scope->run($work);else{
            file_put_contents($argv[5].'.ready','ready');$until=microtime(true)+5;while(!is_file($argv[5].'.go')){if(microtime(true)>$until)throw new RuntimeException('Barrier timed out');usleep(1000);}
            $scope->run(static function()use($p,$scope,$argv){(new GiftCodeIdentity($p,$scope))->assign($argv[3],$argv[4]);});
        }
        echo json_encode(['assigned'=>true]);
    }catch(GiftCodesFailure $e){echo json_encode(['code'=>$e->errorCode]);}exit;
}
$passed=0;
function giftV2Check($expected,$actual,string $label):void{global$passed;if($expected!==$actual)throw new RuntimeException($label.': '.json_encode([$expected,$actual]));$passed++;}
function giftV2Reject(string $code,callable $work):void{try{$work();}catch(GiftCodesFailure $e){giftV2Check($code,$e->errorCode,'gift failure');return;}catch(PlayerValuesFailure $e){giftV2Check($code,$e->errorCode,'value failure');return;}throw new RuntimeException('Expected '.$code);}
function giftV2Balance(array $view):int{foreach($view['values']as$row)if($row['key']==='gold')return$row['balance'];throw new RuntimeException('Missing gold');}
$dsn=getenv('HORIZON_GIFT_TEST_DSN');if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1007_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Own explicit loopback gift fixture required');
$target=$dsn?:tempnam(sys_get_temp_dir(),'horizon-gift-v2-');$p=null;
try{
    $p=giftV2Connect($target);$p->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));Database::ensurePlayerValuesConfig($p);Database::ensureGiftCodeValues($p);
    $user=Database::uuid();$other=Database::uuid();foreach([$user,$other]as$id)$p->prepare('INSERT INTO users(id,display_name,anonymous_token) VALUES(?,?,?)')->execute([$id,'GiftV2',Database::uuid()]);
    $now=1792230000000;$clock=static function()use(&$now){return$now;};$scope=new SqlTransaction($p);$s=new GiftCodesService($p,$clock,$scope);$values=new PlayerValuesService($p,$clock,$scope);
    $values->configure(['definitions'=>[['key'=>'gold','name'=>'Gold','maxBalance'=>1000]],'offers'=>[]],0);
    foreach([null,[['key'=>'gold','amount'=>'1']],[['key'=>'gold','amount'=>1.5]],[['key'=>'gold','amount'=>0]],[['key'=>'gold','amount'=>9007199254740992]],[['key'=>'gold','amount'=>1],['key'=>'gold','amount'=>2]],array_fill(0,9,['key'=>'gold','amount'=>1]),[['key'=>'missing','amount'=>1]],[['key'=>'gold','amount'=>1001]]]as$bad)
        giftV2Reject('INVALID_REQUEST',static function()use($s,$bad){$s->create(['code'=>'INVALID','values'=>$bad]);});
    foreach([0,-1,'2',2147483648]as$bad)giftV2Reject('INVALID_REQUEST',static function()use($s,$bad){$s->create(['code'=>'INVALID','maxTotalRedemptions'=>$bad]);});
    $created=$s->create(['code'=>'REWARD','giftData'=>'opaque','values'=>[['key'=>'gold','amount'=>7]],'maxTotalRedemptions'=>null,'maxRedemptionsPerUser'=>null]);
    giftV2Check(2,$created['protocolVersion'],'new campaign starts V2');giftV2Check(null,$created['exactRemainingRedemptions'],'null alone is unlimited');
    $prepared=$s->prepare('REWARD',$user);$intent=array_merge($prepared,['code'=>'REWARD','userId'=>$user]);unset($intent['valid']);
    giftV2Check('0',$prepared['expectedCount'],'read-only prepare zero');giftV2Check(0,(int)$p->query('SELECT COUNT(*) FROM gift_code_counts')->fetchColumn(),'prepare stores nothing');
    $result=$s->redeemPrepared('REWARD',$user,$intent);giftV2Check('1',$result['count'],'fresh next exact string');giftV2Check(false,$result['alreadyApplied'],'fresh flag');giftV2Check(7,giftV2Balance($result['playerValues']),'one grant');
    $raw=$p->query('SELECT data FROM player_values')->fetchColumn();$stored=json_decode($raw,true);giftV2Check('', $stored['q'],'source-owned grant writes no PV guard');
    giftV2Check(0,(int)$p->query('SELECT COUNT(*) FROM gift_code_redemptions')->fetchColumn(),'V2 keeps no redemption history');
    $configured=$s->configure('REWARD',['giftData'=>'changed','values'=>[['key'=>'gold','amount'=>9]],'expiresAt'=>'2000-01-01T00:00:00Z'],$created['configRevision']);
    $replay=$s->redeemPrepared('REWARD',$user,$intent);giftV2Check(true,$replay['alreadyApplied'],'replay before current expiry/revision');giftV2Check(true,$replay['replayed'],'both flags');giftV2Check(null,$replay['giftData'],'no old opaque data');giftV2Check([],$replay['grantedValues'],'no old deltas');giftV2Check([],$replay['grantedUnlocks'],'no historical cosmetic grants');giftV2Check(7,giftV2Balance($replay['playerValues']),'current balance unchanged');giftV2Check($raw,$p->query('SELECT data FROM player_values')->fetchColumn(),'replay leaves PV bytes unchanged');
    $s->configure('REWARD',['expiresAt'=>null],$configured['configRevision']);
    giftV2Reject('CAMPAIGN_CHANGED',static function()use($s,$other,$intent){$s->redeemPrepared('REWARD',$other,$intent);});
    giftV2Reject('COUNT_AHEAD',static function()use($s,$user,$intent){$s->redeemPrepared('REWARD',$user,array_merge($intent,['expectedCount'=>'2']));});
    foreach([0,1.5,'01','-1','1.0','1e3',' 1',"1\n",'9223372036854775808']as$bad)giftV2Reject('INVALID_REQUEST',static function()use($s,$user,$intent,$bad){$s->redeemPrepared('REWARD',$user,array_merge($intent,['expectedCount'=>$bad]));});
    giftV2Reject('INVALID_REQUEST',static function()use($s,$user,$intent){$s->redeemPrepared('REWARD',$user,array_merge($intent,['idempotencyId'=>'v1']));});
    giftV2Reject('UPGRADE_REQUIRED',static function()use($s,$user){$s->redeem('REWARD',$user,'old-original');});
    giftV2Check(['valid'=>false],$s->prepare('UNKNOWN',$user),'invalid prepare reserves nothing');
    $capped=$s->configure('REWARD',['maxTotalRedemptions'=>1,'maxRedemptionsPerUser'=>1],null);
    giftV2Check('0',$capped['exactRemainingRedemptions'],'finite exhausted is exact zero');
    giftV2Check(true,$s->redeemPrepared('REWARD',$user,$intent)['alreadyApplied'],'lowered limits never erase consumed identity');
    giftV2Reject('REVISION_CONFLICT',static function()use($s){$s->configure('REWARD',[],'0');});
    giftV2Reject('INVALID_GIFT_CODE',static function()use($s,$user,$capped,$intent){$s->redeemPrepared('REWARD',$user,array_merge($intent,['expectedCount'=>'1','configRevision'=>$capped['configRevision']]));});
    $revision=$s->configureValues('REWARD',[])['configRevision'];giftV2Reject('INVALID_REQUEST',static function()use($s,$revision){$s->configure('REWARD',['expiresAt'=>'2026-02-30T00:00:00Z'],$revision);});
    $s->create(['code'=>'OVERFLOW','values'=>[['key'=>'gold','amount'=>1000]]]);$bad=$s->prepare('OVERFLOW',$user);giftV2Reject('MAX_BALANCE_REACHED',static function()use($s,$user,$bad){$s->redeemPrepared('OVERFLOW',$user,$bad);});
    giftV2Check(0,(int)$p->query("SELECT current_redemptions FROM gift_codes WHERE code='OVERFLOW'")->fetchColumn(),'grant failure rolls source back');
    $p->prepare('INSERT INTO cosmetics(cosmetic_id,type,locked) VALUES(?,?,?)')->execute(['new-badge','badge',1]);$owned=[];for($i=0;$i<25;$i++)$owned[]='owned-'.$i;$p->prepare('UPDATE users SET unlocks=? WHERE id=?')->execute([json_encode($owned),$other]);
    $s->create(['code'=>'COSMETIC','giftData'=>'{"grants":["new-badge"]}','values'=>[['key'=>'gold','amount'=>7]]]);$bad=$s->prepare('COSMETIC',$other);$before=$values->get($other);
    giftV2Reject('UNLOCK_LIMIT_REACHED',static function()use($s,$other,$bad){$s->redeemPrepared('COSMETIC',$other,$bad);});giftV2Check($before,$values->get($other),'later failure rolls entire PV state back');giftV2Check(0,(int)$p->query("SELECT current_redemptions FROM gift_codes WHERE code='COSMETIC'")->fetchColumn(),'cosmetic rolls source back');
    $s->create(['code'=>'OUTER','values'=>[['key'=>'gold','amount'=>1]]]);$original=$s->prepare('OUTER',$user);
    try{$scope->run(static function()use($s,$user,$original){$s->redeemPrepared('OUTER',$user,$original);throw new RuntimeException('outer rollback');});}catch(RuntimeException $e){if($e->getMessage()!=='outer rollback')throw$e;}
    giftV2Check('0',$s->prepare('OUTER',$user)['expectedCount'],'owning outer scope rolls pair back');giftV2Check(7,giftV2Balance($values->get($user)),'outer rollback values');
    $s->create(['code'=>'UNCERTAIN','values'=>[['key'=>'gold','amount'=>1]]]);$original=$s->prepare('UNCERTAIN',$user);$p->loseCommit=true;
    giftV2Reject('STATE_CONFLICT',static function()use($s,$user,$original){$s->redeemPrepared('UNCERTAIN',$user,$original);});$recovered=$s->redeemPrepared('UNCERTAIN',$user,$original);giftV2Check(true,$recovered['alreadyApplied'],'actual committed lost ack replays');giftV2Check(8,giftV2Balance($recovered['playerValues']),'lost ack never doubles');
    $oldId=$created['id'];$p->prepare('UPDATE gift_codes SET code=? WHERE id=?')->execute(['OLDREWARD',$oldId]);$replacement=$s->create(['code'=>'REWARD','values'=>[['key'=>'gold','amount'=>100]]]);
    giftV2Check($oldId,$s->redeemPrepared('REWARD',$user,$intent)['campaignId'],'visible reuse cannot retarget UUID');giftV2Check('0',$s->prepare('REWARD',$user)['expectedCount'],'replacement not consumed');
    $s->create(['code'=>'MAXCOUNT','maxTotalRedemptions'=>null,'maxRedemptionsPerUser'=>null]);$max=$s->prepare('MAXCOUNT',$user);$p->prepare("UPDATE gift_codes SET current_redemptions=? WHERE code='MAXCOUNT'")->execute([GiftCodeProtocol::MAX]);
    $maxView=$s->configureValues('MAXCOUNT',[]);giftV2Check(GiftCodeProtocol::MAX,$maxView['exactCurrentTotalRedemptions'],'exact count beyond JS safe integer');
    giftV2Reject('COUNTER_EXHAUSTED',static function()use($s,$user,$max){$s->redeemPrepared('MAXCOUNT',$user,$max);});giftV2Check('18446744073709551614',GiftCodeProtocol::sum(GiftCodeProtocol::MAX,GiftCodeProtocol::MAX),'aggregate beyond Int64 stays decimal');
    $s->create(['code'=>'RACE','values'=>[['key'=>'gold','amount'=>1]],'maxTotalRedemptions'=>1]);$original=$s->prepare('RACE',$user);$jobs=[];
    for($i=0;$i<4;$i++){$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--race',$target,$user,json_encode($original)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Worker failed');fclose($pipes[0]);$jobs[]=[$proc,$pipes];}
    $fresh=0;$replayed=0;foreach($jobs as[$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);giftV2Check(0,proc_close($proc),'race process');if($err!=='')throw new RuntimeException($err);$r=json_decode($out,true,512,JSON_THROW_ON_ERROR);if(isset($r['success'])){$r['alreadyApplied']?$replayed++:$fresh++;}else giftV2Check('STATE_CONFLICT',$r['code'],'race safe conflict');}
    giftV2Check(1,$fresh,'one fresh result across four processes');giftV2Check(3,$replayed,'same original resolves all device replays');giftV2Check(1,(int)$p->query("SELECT current_redemptions FROM gift_codes WHERE code='RACE'")->fetchColumn(),'no oversell');
    $p->beginTransaction();try{
        try{$s->create(['code'=>'UNMANAGED']);throw new RuntimeException('Expected owner rejection');}catch(LogicException $e){giftV2Check('Gift writes require the explicit owning SQL scope',$e->getMessage(),'raw caller transactions cannot counterfeit shared owner');}
    }finally{$p->rollBack();}
    // Two independent identity writers race, including a stale MySQL RR snapshot.
    $candidate=Database::uuid();$ids=['legacy-one-'.substr(Database::uuid(),0,16),'legacy-two-'.substr(Database::uuid(),0,16)];$jobs=[];$barriers=[];
    foreach($ids as$i=>$id){$p->prepare('INSERT INTO gift_codes(id,code,protocol_version) VALUES(?,?,1)')->execute([$id,'ALIAS'.$i]);$barrier=tempnam(sys_get_temp_dir(),'gift-alias-');unlink($barrier);$barriers[]=$barrier;$pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--identity-race',$target,$id,$candidate,$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$jobs[]=[$proc,$pipes];}
    $until=microtime(true)+5;foreach($barriers as$barrier)while(!is_file($barrier.'.ready')){if(microtime(true)>$until)throw new RuntimeException('Identity fixture timeout');usleep(1000);}foreach($barriers as$barrier)touch($barrier.'.go');
    $assigned=0;$conflicted=0;foreach($jobs as[$proc,$pipes]){$reply=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);giftV2Check(0,proc_close($proc),'identity worker');if($err!=='')throw new RuntimeException($err);$result=json_decode($reply,true,512,JSON_THROW_ON_ERROR);if(isset($result['assigned']))$assigned++;else{giftV2Check('STATE_CONFLICT',$result['code'],'cross-column alias collision rejected');$conflicted++;}}
    foreach($barriers as$barrier){unlink($barrier.'.ready');unlink($barrier.'.go');}
    giftV2Check(1,$assigned,'exactly one sealed alias');giftV2Check(1,$conflicted,'other identity writer cannot retarget');
    giftV2Reject('STATE_CONFLICT',static function()use($scope,$p,$candidate){$scope->run(static function()use($scope,$p,$candidate){(new GiftCodeIdentity($p,$scope))->available($candidate);});});
    $natural=$created['id'];giftV2Reject('STATE_CONFLICT',static function()use($scope,$p,$natural,$ids){$scope->run(static function()use($scope,$p,$natural,$ids){(new GiftCodeIdentity($p,$scope))->assign($ids[1],$natural);});});
    $warnings=[];set_error_handler(static function($severity,$message)use(&$warnings){$warnings[]=$message;return true;},E_WARNING|E_NOTICE);
    try{foreach([$created['id'],Database::uuid()]as$aliasId)giftV2Reject('MIGRATION_REQUIRED',static function()use($created,$aliasId){GiftCodeIdentity::canonical(['id'=>$created['id'],'v2_id'=>GiftCodeProtocol::binary($aliasId)]);});}finally{restore_error_handler();}
    giftV2Check([],$warnings,'redundant or retargeted canonical UUID alias fails without warnings');
    $retired=Database::uuid();$scope->run(static function()use($scope,$p,$retired,$user){(new GiftCodeCounterStore($p,$scope))->install($retired,$user,1);});
    giftV2Reject('STATE_CONFLICT',static function()use($scope,$p,$retired){$scope->run(static function()use($scope,$p,$retired){(new GiftCodeIdentity($p,$scope))->available($retired);});});
    $row=$p->query('SELECT LENGTH(campaign_id) a,LENGTH(player_id) b FROM gift_code_counts LIMIT 1')->fetch();giftV2Check(16,(int)$row['a'],'actual binary campaign length');giftV2Check(16,(int)$row['b'],'actual binary player length');
    echo 'PASS: '.$passed.' gift V2 '.($dsn?'MySQL':'SQLite')." assertions\n";
}finally{if($p&&$p->inTransaction())$p->rollBack();$p=null;if(!$dsn)foreach([$target,$target.'-wal',$target.'-shm']as$file)if(file_exists($file))unlink($file);}
