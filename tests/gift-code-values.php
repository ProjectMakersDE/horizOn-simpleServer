<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/GiftCodes/GiftCodesService.php';

/** Real PDO with observable feature reads and an injected lost acknowledgement after a real commit. */
class GiftProbePDO extends PDO {
    public int $valueQueries = 0;
    public bool $loseCommit = false;
    private function observed(string $sql): void { if (preg_match('/player_values_config|player_value_state|player_value_offers/i', $sql)) $this->valueQueries++; }
    private function acknowledge(): void { if ($this->loseCommit) { $this->loseCommit=false; throw new RuntimeException('Injected lost commit acknowledgement'); } }
    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = []) { $this->observed($sql); return parent::prepare($sql, $options); }
    #[\ReturnTypeWillChange]
    public function query($sql, ...$args) { $this->observed($sql); return parent::query($sql, ...$args); }
    #[\ReturnTypeWillChange]
    public function exec($sql) { $this->observed($sql); $result=parent::exec($sql); if(strtoupper(trim($sql))==='COMMIT')$this->acknowledge(); return $result; }
    #[\ReturnTypeWillChange]
    public function commit() { $result=parent::commit();$this->acknowledge();return $result; }
}
function giftConnection(string $target): GiftProbePDO {
    $mysql=strpos($target,'mysql:')===0;
    $pdo=new GiftProbePDO($mysql?$target:'sqlite:'.$target,$mysql?getenv('HORIZON_GIFT_TEST_USER'):null,$mysql?getenv('HORIZON_GIFT_TEST_PASSWORD'):null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if(!$mysql){$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA journal_mode=WAL');}
    return $pdo;
}
if(($argv[1]??'')==='--redeem'){
    try{$service=new GiftCodesService(giftConnection($argv[2]),static function()use($argv){return (int)$argv[6];});echo json_encode($service->redeem($argv[3],$argv[4],$argv[5]),JSON_THROW_ON_ERROR);}
    catch(GiftCodesFailure $error){echo json_encode(['code'=>$error->errorCode,'status'=>$error->httpStatus]);}
    exit;
}
$passed=0;
function check($expected,$actual,string $label):void{global$passed;if($expected!==$actual)throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual));$passed++;}
function reject(string $code,callable $work):void{try{$work();}catch(GiftCodesFailure $error){check($code,$error->errorCode,'gift rejection');return;}catch(PlayerValuesFailure $error){check($code,$error->errorCode,'value rejection');return;}throw new RuntimeException('Expected '.$code);}
function coins(array $view):int{foreach($view['values']as$row)if($row['key']==='coins')return $row['balance'];throw new RuntimeException('No coins');}
$dsn=getenv('HORIZON_GIFT_TEST_DSN');
if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1007_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Only explicit loopback task1007_<hex> database is allowed');
$target=$dsn?:tempnam(sys_get_temp_dir(),'horizon-task1007-');$pdo=null;
try{
    $pdo=giftConnection($target);
    // Start with the previous real table shape, then run the additive upgrade twice.
    $schema=file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql');
    $schema=preg_replace('/^    (values_json|idempotency_id|gift_data|granted_values|value_granted_at) .*\n/m','',$schema);
    $pdo->exec($schema);
    $user=Database::uuid();$other=Database::uuid();
    $insert=$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at) VALUES(?,?,?,?)');
    foreach([$user,$other]as$id)$insert->execute([$id,'Gift',Database::uuid(),'2026-10-16 12:00:00']);
    $legacy=Database::uuid();$legacyReceipt=Database::uuid();
    $pdo->prepare('INSERT INTO gift_codes(id,code,reward_data,max_redemptions,current_redemptions) VALUES(?,?,?,?,?)')->execute([$legacy,'MIGRATED','opaque legacy data',3,1]);
    $pdo->prepare('INSERT INTO gift_code_redemptions(id,gift_code_id,user_id) VALUES(?,?,?)')->execute([$legacyReceipt,$legacy,$other]);
    Database::ensureGiftCodeValues($pdo);Database::ensureGiftCodeValues($pdo);
    check(1,(int)$pdo->query("SELECT current_redemptions FROM gift_codes WHERE code='MIGRATED'")->fetchColumn(),'migration preserves counter');
    check($legacyReceipt,$pdo->query('SELECT id FROM gift_code_redemptions')->fetchColumn(),'migration preserves source receipt');
    check(null,$pdo->query('SELECT value_granted_at FROM gift_code_redemptions')->fetchColumn(),'legacy receipt remains non-value history');
    $indexes=$dsn?$pdo->query("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='gift_code_redemptions'")->fetchAll(PDO::FETCH_COLUMN):array_column($pdo->query('PRAGMA index_list(gift_code_redemptions)')->fetchAll(),'name');
    check(true,in_array('idx_gift_idempotency',$indexes,true),'idempotency index exists');check(true,in_array('idx_gift_retention',$indexes,true),'permanent guard retention index exists');
    Database::ensurePlayerValuesConfig($pdo);
    $now=(int)(strtotime('2026-10-16T12:00:00Z')*1000);$clock=static function()use(&$now){return $now;};
    $service=new GiftCodesService($pdo,$clock);$values=new PlayerValuesService($pdo,$clock);
    $gift=static function(string $code,array $rewards=[],?string $data=null,int $limit=100)use($pdo){$id=Database::uuid();$pdo->prepare('INSERT INTO gift_codes(id,code,reward_data,values_json,max_redemptions) VALUES(?,?,?,?,?)')->execute([$id,$code,$data,json_encode($rewards,JSON_THROW_ON_ERROR),$limit]);return $id;};
    $op=static function()use(&$now){return ($now++).':'.Database::uuid();};
    $pdo->valueQueries=0;
    check(true,$service->validate('MIGRATED',$user),'legacy validate');
    $old=$service->redeem('MIGRATED',$user,'legacy-first');check('opaque legacy data',$old['giftData'],'legacy opaque gift data');check([],$old['grantedValues'],'legacy values empty');check(null,$old['playerValues'],'legacy has no balance state');
    check(true,$service->redeem('MIGRATED',$user,'legacy-first')['replayed'],'legacy retained original replay');check(0,$pdo->valueQueries,'legacy path performs zero player-values queries');
    $values->configure(['definitions'=>[['key'=>'coins','name'=>'Coins','maxBalance'=>1000]],'offers'=>[]],0);
    foreach([null,[['key'=>'coins','amount'=>'1']],[['key'=>'coins','amount'=>1.5]],[['key'=>'coins','amount'=>0]],[['key'=>'coins','amount'=>9007199254740992]],[['key'=>'coins','amount'=>1],['key'=>'coins','amount'=>2]],array_fill(0,9,['key'=>'coins','amount'=>1])]as$bad)
        reject('INVALID_REQUEST',static function()use($service,$bad){$service->configureValues('MIGRATED',['values'=>$bad]);});
    reject('INVALID_REQUEST',static function()use($service){$service->configureValues('MIGRATED',['values'=>[['key'=>'missing','amount'=>1]]]);});
    reject('INVALID_REQUEST',static function()use($service){$service->configureValues('MIGRATED',['values'=>[['key'=>'coins','amount'=>1001]]]);});
    $configured=$service->configureValues('MIGRATED',['values'=>[['key'=>'coins','amount'=>10]]]);check(2,$configured['currentRedemptions'],'configuration never resets counter');
    check($configured['values'],$service->configureValues('MIGRATED',[])['values'],'omitted values preserved');check([],$service->configureValues('MIGRATED',['values'=>[]])['values'],'explicit empty list clears rewards');
    $pdo->prepare('INSERT INTO cosmetics(cosmetic_id,type,locked) VALUES(?,?,?)')->execute(['reward-badge','badge',1]);
    $reward=[['key'=>'coins','amount'=>10]];$mixed=$gift('MIXED',$reward,'{"grants":["reward-badge"],"item":"original"}');
    $first=$service->redeem('MIXED',$user,'  Original-ID  ');check(false,$first['replayed'],'first mixed redemption');check($reward,$first['grantedValues'],'original reward exact');check(10,coins($first['playerValues']),'value credited once');check(['reward-badge'],$first['grantedUnlocks'],'cosmetic granted atomically');
    reject('OPERATION_CONFLICT',static function()use($service,$other){$service->redeem('MIXED',$other,'Original-ID');});
    reject('OPERATION_CONFLICT',static function()use($service,$user){$service->redeem('MIGRATED',$user,'Original-ID');});
    $gift('CASE-ID',$reward);check(false,$service->redeem('CASE-ID',$other,'original-id')['replayed'],'idempotency identifiers are case-sensitive');
    foreach(['',str_repeat('x',129),1]as$bad)reject('INVALID_REQUEST',static function()use($service,$user,$bad){$service->redeem('MIXED',$user,$bad);});
    $gift('NO-ID',[]);check(false,$service->redeem('NO-ID',$user)['replayed'],'older clients may omit idempotencyId');
    $gift('OVERFLOW',[['key'=>'coins','amount'=>1000]]);reject('MAX_BALANCE_REACHED',static function()use($service,$user){$service->redeem('OVERFLOW',$user,'overflow');});
    check(0,(int)$pdo->query("SELECT current_redemptions FROM gift_codes WHERE code='OVERFLOW'")->fetchColumn(),'balance failure rolls counter back');check(0,(int)$pdo->query("SELECT COUNT(*) FROM gift_code_redemptions WHERE idempotency_id='overflow'")->fetchColumn(),'balance failure rolls source guard back');check(10,coins($values->get($user)),'failed values unchanged');
    $full=[];for($i=0;$i<25;$i++)$full[]='owned-'.$i;
    $pdo->prepare('UPDATE users SET unlocks=? WHERE id=?')->execute([json_encode($full),$other]);
    $gift('COSMETIC-FAIL',$reward,'{"grants":["reward-badge"]}');$prior=$values->get($other);
    reject('UNLOCK_LIMIT_REACHED',static function()use($service,$other){$service->redeem('COSMETIC-FAIL',$other,'cosmetic-fail');});
    check($prior,$values->get($other),'later cosmetic failure rolls complete value receipt/state back');check(0,(int)$pdo->query("SELECT current_redemptions FROM gift_codes WHERE code='COSMETIC-FAIL'")->fetchColumn(),'cosmetic failure rolls counter back');check(0,(int)$pdo->query("SELECT COUNT(*) FROM gift_code_redemptions WHERE idempotency_id='cosmetic-fail'")->fetchColumn(),'cosmetic failure rolls source guard back');
    $gift('OUTER',$reward);$pdo->beginTransaction();$service->redeem('OUTER',$user,'outer');$pdo->rollBack();check(10,coins($values->get($user)),'joins caller PDO transaction');check(0,(int)$pdo->query("SELECT current_redemptions FROM gift_codes WHERE code='OUTER'")->fetchColumn(),'outer rollback includes code counter');
    $gift('UNCERTAIN',$reward);$pdo->loseCommit=true;reject('STATE_CONFLICT',static function()use($service,$user){$service->redeem('UNCERTAIN',$user,'uncertain');});
    $recovered=$service->redeem('UNCERTAIN',$user,'uncertain');check(true,$recovered['replayed'],'actual committed lost acknowledgement safely replays');check(20,coins($recovered['playerValues']),'lost acknowledgement never doubles values');
    $nullGift=$gift('NULL-DATA',[['key'=>'coins','amount'=>1]],null);
    check(null,$service->redeem('NULL-DATA',$user,'original-null')['giftData'],'original null gift payload');
    $pdo->prepare('UPDATE gift_codes SET reward_data=? WHERE id=?')->execute(['{"grants":["reward-badge"],"item":"later-edit"}',$nullGift]);
    $nullReplay=$service->redeem('NULL-DATA',$user,'original-null');check(null,$nullReplay['giftData'],'replay preserves original null after edit');check([],$nullReplay['grantedUnlocks'],'edited cosmetics never appear in original replay');
    for($i=0;$i<51;$i++)$values->correct($user,[['key'=>'coins','balance'=>5]],'fixture receipt eviction',$op());
    $pdo->prepare('UPDATE gift_codes SET values_json=?,reward_data=?,expires_at=?,max_redemptions=current_redemptions WHERE id=?')->execute(['[{"key":"coins","amount":999}]','{"item":"edited"}','2000-01-01 00:00:00',$mixed]);
    $now+=91*86400000;
    // The documented ordinary-history cleanup excludes every permanent value source guard.
    $pdo->prepare('DELETE FROM gift_code_redemptions WHERE value_granted_at IS NULL AND redeemed_at<?')->execute([gmdate('Y-m-d H:i:s',intdiv($now,1000)-90*86400)]);
    $replay=$service->redeem('MIXED',$user,'Original-ID');check(true,$replay['replayed'],'permanent original replay survives provider eviction and ordinary retention');check($reward,$replay['grantedValues'],'replay retains original granted amounts after code edits');check(5,coins($replay['playerValues']),'replay returns current balance without regrant');check($first['giftData'],$replay['giftData'],'original opaque gift data retained');
    // Real independent processes compete for one final global redemption.
    $gift('RACE',$reward,null,1);$jobs=[];
    for($i=0;$i<4;$i++){$pipes=[];$id=$i%2===0?$user:$other;$p=proc_open([PHP_BINARY,__FILE__,'--redeem',$target,'RACE',$id,'race-'.$i,(string)$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Worker failed');fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $wins=0;foreach($jobs as[$p,$pipes]){$reply=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(0,proc_close($p),'race worker');if($error!=='')throw new RuntimeException($error);$result=json_decode($reply,true,512,JSON_THROW_ON_ERROR);if(isset($result['success']))$wins++;else check(true,in_array($result['code'],['INVALID_GIFT_CODE','STATE_CONFLICT'],true),'race loser safe');}
    check(1,$wins,'four simultaneous redemptions have one winner');check(1,(int)$pdo->query("SELECT current_redemptions FROM gift_codes WHERE code='RACE'")->fetchColumn(),'race never oversells');
    echo 'PASS: '.$passed.' gift-code values '.($dsn?'MySQL':'SQLite')." assertions\n";
}finally{
    if($pdo&&$pdo->inTransaction())$pdo->rollBack();$pdo=null;
    if(!$dsn)foreach([$target,$target.'-wal',$target.'-shm']as$file)if(file_exists($file))unlink($file);
}
