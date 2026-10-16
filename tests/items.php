<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Items/ItemService.php';
require_once __DIR__.'/../src/Items/ItemJson.php';
require_once __DIR__.'/../src/PlayerValues/PlayerValuesService.php';

class ItemsProbePDO extends PDO
{
    public bool $loseCommit=false; public array $statements=[]; public $onPrepare=null;
    private function observe(string $sql): void { $this->statements[]=$sql; if ($this->onPrepare!==null) ($this->onPrepare)($sql); }
    #[\ReturnTypeWillChange]
    public function prepare($sql,$options=[]) { $this->observe($sql); return parent::prepare($sql,$options); }
    #[\ReturnTypeWillChange]
    public function query($sql,?int $mode=null,...$args) { $this->observe($sql); return $mode===null ? parent::query($sql) : parent::query($sql,$mode,...$args); }
    private function acknowledge(): void { if ($this->loseCommit) { $this->loseCommit=false; throw new RuntimeException('Lost commit response'); } }
    #[\ReturnTypeWillChange]
    public function exec($sql) { $this->observe($sql); $result=parent::exec($sql); if (strtoupper(trim($sql))==='COMMIT') $this->acknowledge(); return $result; }
    #[\ReturnTypeWillChange]
    public function commit() { $result=parent::commit(); $this->acknowledge(); return $result; }
}
function connect(string $target): ItemsProbePDO
{
    $mysql=strpos($target,'mysql:')===0;
    $pdo=new ItemsProbePDO($mysql ? $target : 'sqlite:'.$target,$mysql ? getenv('HORIZON_ITEMS_TEST_USER') : null,$mysql ? getenv('HORIZON_ITEMS_TEST_PASSWORD') : null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if (!$mysql) { $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA journal_mode=WAL'); } return $pdo;
}
if (($argv[1] ?? '')==='--worker') {
    $pdo=connect($argv[2]); $input=json_decode(base64_decode($argv[4],true),true,32,JSON_THROW_ON_ERROR); $clock=static function () use ($input) { return $input['now']; };
    try {
        if ($argv[3]==='purchase') $result=(new PlayerValuesService($pdo,$clock))->purchase($input['user'],'sword',$input['operation']);
        elseif ($argv[3]==='adjust') $result=(new ItemService($pdo,$clock))->adjust($input['user'],$input['instance'],1,[['key'=>'power','amount'=>1]]);
        else { $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$input['user']]); $result=['deleted'=>true]; }
        echo json_encode($result,JSON_THROW_ON_ERROR);
    } catch (PlayerValuesFailure $error) { echo json_encode(['code'=>$error->errorCode],JSON_THROW_ON_ERROR); } exit;
}
$passed=0;
function check($expected,$actual,string $label): void { global $passed; if ($expected!==$actual) throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual)); $passed++; }
function reject(string $code,callable $work): void
{ try { $work(); } catch (PlayerValuesFailure $error) { check($code,$error->errorCode,'Rejected action'); return; } throw new RuntimeException('Expected '.$code); }
function job(string $target,string $action,array $input): array
{
    $pipes=[]; $process=proc_open([PHP_BINARY,__FILE__,'--worker',$target,$action,base64_encode(json_encode($input))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Worker failed to start'); fclose($pipes[0]); return [$process,$pipes];
}
function finish(array $job): array
{
    [$process,$pipes]=$job; $text=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);check(0,proc_close($process),'Worker exit');
    if ($errors!=='') throw new RuntimeException($errors); return json_decode($text,true,32,JSON_THROW_ON_ERROR);
}
function costs(ItemsProbePDO $pdo): array
{
    $counts=['SELECT'=>0,'DML'=>0,'BEGIN'=>0,'COMMIT'=>0]; foreach ($pdo->statements as $sql) {
        if (preg_match('/^SELECT/i',$sql)) $counts['SELECT']++; elseif (preg_match('/^(UPDATE|INSERT|DELETE)/i',$sql)) $counts['DML']++;
        elseif (strpos($sql,'BEGIN')===0) $counts['BEGIN']++; elseif ($sql==='COMMIT') $counts['COMMIT']++;
    } return $counts;
}
$dsn=getenv('HORIZON_ITEMS_TEST_DSN');
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1011_[a-f0-9]+;charset=utf8mb4$/D',$dsn)) throw new RuntimeException('Only explicit loopback task1011_<hex> test DB permitted');
$target=$dsn ?: tempnam(sys_get_temp_dir(),'horizon-task1011-'); $pdo=null;
try {
    $pdo=connect($target); $pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn ? 'mysql' : 'sqlite').'.sql')); Database::ensurePlayerValuesConfig($pdo);
    $now=1792152000000; $clock=static function () use (&$now) { return $now; }; $scope=new SqlTransaction($pdo); $items=new ItemService($pdo,$clock,$scope); $values=new PlayerValuesService($pdo,$clock,$scope);
    $newUser=static function () use ($pdo): string { $id=Database::uuid(); $pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at) VALUES(?,?,?,?)')->execute([$id,'Item Player',Database::uuid(),'2026-10-16 12:00:00']); return $id; };
    $operation=static function () use (&$now): string { return $now.':'.Database::uuid(); };
    $template=static function ($properties=null): array { return ['name'=>'Sword','type'=>'weapon','rarity'=>'common','active'=>true,'baseProperties'=>$properties ?? (object)['power'=>10,'label'=>'é/😀'],
        'propertyRules'=>[['key'=>'power','min'=>0,'max'=>100,'maxChangePerCall'=>5]]]; };
    $u=$newUser(); $pdo->statements=[]; check(0,$items->get($u)['revision'],'Lazy inventory');$sqlCosts=['inventoryGet'=>costs($pdo)];
    check(0,(int)$pdo->query('SELECT COUNT(*) FROM player_items')->fetchColumn(),'GET never inserts inventory');check(0,$items->catalog($u)['revision'],'Lazy catalog');check(0,(int)$pdo->query('SELECT COUNT(*) FROM item_catalog')->fetchColumn(),'GET never inserts header');
    // Byte/Unicode parity, duplicate decoded keys and original strict number lexemes.
    check('{"a":"é/😀","b":"\b\t\n\f\r\u0000\u001f","c":9007199254740991}',ItemRules::canonical((object)['c'=>9007199254740991,'b'=>"\x08\t\n\x0c\r\x00\x1f",'a'=>'é/😀']),'Exact cross-language canonical form');
    check('{"x":"'."\u{2028}\u{2029}".'"}',ItemRules::canonical((object)['x'=>"\u{2028}\u{2029}"]),'Unicode line separators remain literal');
    foreach (['{"a":1,"a":2}','{"a":1,"\\u0061":2}','{"a":1.0}','{"a":1e0}','{"a":9007199254740992}','{"a":"\\ud800"}','{"a":1,}','[]'] as $bad) reject('INVALID_REQUEST',static function () use ($bad) { ItemJson::decode($bad); });
    check(9007199254740991,ItemJson::decode('{"a":9007199254740991}')->a,'Safe integer boundary');
    foreach ([(object)['x'=>null],(object)['x'=>1.0],(object)['x'=>[]],(object)['x'=>(object)[]],(object)['Bad'=>1],(object)['x'=>9007199254740992],(object)['x'=>"\xff"]] as $bad) reject('INVALID_REQUEST',static function () use ($bad) { ItemRules::properties($bad); });
    check(256,strlen(ItemRules::canonical(ItemRules::properties((object)['x'=>str_repeat('a',248)]))),'Exact property bytes');
    reject('INVALID_REQUEST',static function () { ItemRules::properties((object)['x'=>str_repeat('a',249)]); });
    $rules=$template();$rules['propertyRules'][]=$rules['propertyRules'][0];reject('INVALID_REQUEST',static function () use ($rules) { ItemRules::definition('sword',$rules); });
    $rules=$template();$rules['propertyRules'][0]['key']='absent';reject('INVALID_REQUEST',static function () use ($rules) { ItemRules::definition('sword',$rules); });
    foreach ([null,1.0,'0',-1,9007199254740992] as $bad) reject('INVALID_REQUEST',static function () use ($items,$template,$bad) { $items->put('sword',$bad,$template()); });
    foreach ([[],[['itemKey'=>'sword','quantity'=>0]],[['itemKey'=>'sword','quantity'=>1.0]],[['itemKey'=>'sword','quantity'=>5]],[['itemKey'=>'sword','quantity'=>3],['itemKey'=>'other','quantity'=>2]],[['itemKey'=>'sword','quantity'=>1],['itemKey'=>'sword','quantity'=>1]]] as $bad)
        reject('INVALID_REQUEST',static function () use ($items,$u,$operation,$now,$bad) { $items->grant($u,$bad,$operation(),$now,'fixture'); });
    // An initial-header creation during a page must be noticed, too.
    $other=connect($target);$operator=new ItemService($other,$clock);$pdo->onPrepare=static function ($sql) use ($pdo,$operator,$template) { if (strpos($sql,'SELECT data FROM item_templates ORDER BY')===0) { $pdo->onPrepare=null;$operator->put('sword',0,$template()); } };
    reject('REVISION_CONFLICT',static function () use ($items,$u) { $items->catalog($u); });$pdo->onPrepare=null;$other=null;$operator=null;
    check(1,$items->put('sword',1,$template())['revision'],'Identical catalog is a no-op');
    $items->put('shield',1,$template((object)['power'=>10,'label'=>'shield']));
    $pdo->statements=[];$first=$items->catalog($u,null,1);$sqlCosts['catalogPage']=costs($pdo);check('shield',$first['definitions'][0]['key'],'ASCII keyset order');check('shield',$first['nextAfterKey'],'Explicit continuation');
    check('sword',$items->catalog($u,$first['nextAfterKey'],1,$first['revision'])['definitions'][0]['key'],'Same revision page');
    reject('REVISION_CONFLICT',static function () use ($items,$u) { $items->catalog($u,null,1,1); });
    $small=new ItemService($pdo,$clock,$scope,1,1,1); reject('ITEM_CATALOG_LIMIT_REACHED',static function () use ($small,$template) { $small->put('third',2,$template()); });
    $id=$operation();$grant=$items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$id,$now,'fixture');$instance=$grant['items'][0]['instanceId'];check(false,$grant['replayed'],'Initial grant');check(false,$items->get($u)['instances'][0]['equipped'],'Server default equipment');
    $pdo->statements=[];$changed=$items->adjust($u,$instance,1,[['key'=>'power','amount'=>5]]);$sqlCosts['adjust']=costs($pdo);check(15,$changed['instances'][0]['properties']->power,'Allowed delta');
    reject('REVISION_CONFLICT',static function () use ($items,$u,$instance) { $items->equip($u,$instance,1,true); });
    reject('ITEM_PROPERTY_LIMIT_REACHED',static function () use ($items,$u,$instance) { $items->adjust($u,$instance,2,[['key'=>'power','amount'=>6]]); });
    reject('ITEM_PROPERTY_NOT_ALLOWED',static function () use ($items,$u,$instance) { $items->adjust($u,$instance,2,[['key'=>'label','amount'=>1]]); });
    foreach ([[],[['key'=>'power','amount'=>0]],[['key'=>'power','amount'=>1],['key'=>'power','amount'=>1]]] as $bad) reject('INVALID_REQUEST',static function () use ($items,$u,$instance,$bad) { $items->adjust($u,$instance,2,$bad); });
    $archived=$template();$archived['active']=false;$small->put('sword',2,$archived);check($grant['items'],$items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$id,$now,'fixture')['items'],'Grant replay precedes archived template/cap');
    reject('ITEM_TEMPLATE_NOT_FOUND',static function () use ($items,$u,$operation,$now) { $items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now,'fixture'); });
    reject('ITEM_PROPERTY_NOT_ALLOWED',static function () use ($items,$u,$instance) { $items->adjust($u,$instance,2,[['key'=>'power','amount'=>1]]); });
    $pdo->statements=[];check(true,$items->equip($u,$instance,2,true)['instances'][0]['equipped'],'Archived equip');$sqlCosts['equip']=costs($pdo);$items->discard($u,$instance,3);
    check($grant['items'],$items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$id,$now,'fixture')['items'],'Discarded reference replays');check([],$items->get($u)['instances'],'Replay cannot recreate discarded item');
    reject('OPERATION_CONFLICT',static function () use ($items,$u,$id,$now) { $items->grant($u,[['itemKey'=>'shield','quantity'=>1]],$id,$now,'fixture'); });
    $items->put('sword',3,$template());
    // One atomic paid item purchase; original PV receipt precedes offer/catalog changes.
    $config=['definitions'=>[['key'=>'coins','name'=>'Coins','initialBalance'=>100,'maxBalance'=>10000]],'offers'=>[['key'=>'sword','name'=>'Sword','costs'=>[['key'=>'coins','amount'=>5]],'rewards'=>[],'itemRewards'=>[['itemKey'=>'sword','quantity'=>1]]]]];
    $values->configure($config,0);$buyer=$newUser();$values->get($buyer);$purchaseId=$operation();$pdo->statements=[];$purchase=$values->purchase($buyer,'sword',$purchaseId);$sqlCosts['itemPurchase']=costs($pdo);
    check(95,$purchase['values'][0]['balance'],'One debit');check(1,count($purchase['operation']['items']),'Original item reference in PV receipt');$bought=$purchase['operation']['items'][0]['instanceId'];
    $items->discard($buyer,$bought,1);$emptyConfig=$config;$emptyConfig['offers']=[];$values->configure($emptyConfig,1);$replay=$values->purchase($buyer,'sword',$purchaseId);
    check(true,$replay['operation']['replayed'],'Removed offer replays');check($purchase['operation']['items'],$replay['operation']['items'],'Original purchase references');check([],$items->get($buyer)['instances'],'Purchase replay cannot recreate discarded item');
    $values->configure($config,2);
    // Default150-slot bound and transaction rollback after debit/daily mutation.
    $full=$newUser();$values->get($full);for($i=0;$i<37;$i++)$items->grant($full,[['itemKey'=>'sword','quantity'=>4]],$operation(),$now,'fill');$items->grant($full,[['itemKey'=>'sword','quantity'=>2]],$operation(),$now,'fill');
    $before=$values->history($full);$fullId=$operation();reject('INVENTORY_FULL',static function () use ($values,$full,$fullId) { $values->purchase($full,'sword',$fullId); });
    check($before,$values->history($full),'Full inventory rolls back value state, daily counters and receipt');check(150,count($items->get($full)['instances']),'No over-cap item');
    $roll=$newUser();$values->get($roll);$rollId=$operation();try {$scope->run(static function () use ($values,$roll,$rollId) { $values->purchase($roll,'sword',$rollId);throw new RuntimeException('outer rollback'); });}catch(RuntimeException $error){check('outer rollback',$error->getMessage(),'Outer failure preserved');}
    check([],$items->get($roll)['instances'],'Outer rollback removes new inventory');check(100,$values->get($roll)['values'][0]['balance'],'Outer rollback restores debit');
    // Commit acknowledgement loss retains the original receipt and instance.
    $lost=$newUser();$lostId=$operation();$pdo->loseCommit=true;reject('STATE_CONFLICT',static function () use ($values,$lost,$lostId) { $values->purchase($lost,'sword',$lostId); });
    check(true,$values->purchase($lost,'sword',$lostId)['operation']['replayed'],'Lost response replays');check(1,count($items->get($lost)['instances']),'Lost response did not regrant');
    // Bounded receipts/floor remain after discard and reject evicted/old intents.
    $retained=$newUser();$limited=new ItemService($pdo,$clock,$scope,5000,150,1);$old=$operation();$limited->grant($retained,[['itemKey'=>'sword','quantity'=>1]],$old,$now,'floor');$now++;
    $new=$operation();$limited->grant($retained,[['itemKey'=>'sword','quantity'=>1]],$new,$now,'floor');
    reject('OPERATION_EXPIRED',static function () use ($limited,$retained,$old,$now) { $limited->grant($retained,[['itemKey'=>'sword','quantity'=>1]],$old,$now-1,'floor'); });
    check(1,count($limited->inspect($retained)['receipts']),'Receipt cap');check(2,count($limited->get($retained)['instances']),'Downgrade preserves items');
    reject('INVENTORY_FULL',static function () use ($pdo,$clock,$scope,$retained,$operation,$now) { (new ItemService($pdo,$clock,$scope,1,1,1))->grant($retained,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now,'new'); });
    reject('INVALID_REQUEST',static function () use ($items,$u,$operation,$now) { $items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now+30001,'future'); });
    reject('OPERATION_EXPIRED',static function () use ($items,$u,$operation,$now) { $items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now-ItemRules::RETENTION-1,'old'); });
    // Strict post-change byte growth and no implicit properties after a template edit.
    $byteTemplate=$template((object)['power'=>9,'label'=>str_repeat('a',234)]);$byteTemplate['propertyRules'][0]['max']=100;$items->put('bytes',4,$byteTemplate);$bytesUser=$newUser();$byteGrant=$items->grant($bytesUser,[['itemKey'=>'bytes','quantity'=>1]],$operation(),$now,'bytes');
    check(256,strlen(ItemRules::canonical($items->get($bytesUser)['instances'][0]['properties'])),'Digit boundary setup');
    reject('INVALID_REQUEST',static function () use ($items,$bytesUser,$byteGrant) { $items->adjust($bytesUser,$byteGrant['items'][0]['instanceId'],1,[['key'=>'power','amount'=>1]]); });
    $newTemplate=$template();$newTemplate['baseProperties']->added=1;$newTemplate['propertyRules'][]=['key'=>'added','min'=>0,'max'=>10,'maxChangePerCall'=>1];$items->put('sword',5,$newTemplate);
    reject('ITEM_PROPERTY_NOT_ALLOWED',static function () use ($items,$lost) { $item=$items->get($lost)['instances'][0];$items->adjust($lost,$item['instanceId'],1,[['key'=>'added','amount'=>1]]); });
    // Independent real processes: same ID, one remaining slot, exact property CAS and deletion.
    $parallel=$newUser();$values->get($parallel);$same=$operation();$jobs=[];for($i=0;$i<4;$i++)$jobs[]=job($target,'purchase',['user'=>$parallel,'operation'=>$same,'now'=>$now]);$results=array_map('finish',$jobs);
    foreach($results as $result)check($results[0]['operation']['items'],$result['operation']['items'],'Same-ID original references');check(95,$values->get($parallel)['values'][0]['balance'],'Concurrent same ID once');check(1,count($items->get($parallel)['instances']),'Concurrent same ID one instance');
    $raceInstance=$items->get($parallel)['instances'][0]['instanceId'];$jobs=[];for($i=0;$i<2;$i++)$jobs[]=job($target,'adjust',['user'=>$parallel,'instance'=>$raceInstance,'now'=>$now]);$results=array_map('finish',$jobs);check(1,count(array_filter($results,static function($r){return isset($r['code']);})),'One property CAS loser');check(11,$items->get($parallel)['instances'][0]['properties']->power,'Property applied once');
    $last=$newUser();$values->get($last);for($i=0;$i<37;$i++)$items->grant($last,[['itemKey'=>'sword','quantity'=>4]],$operation(),$now,'fill');$items->grant($last,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now,'fill');
    $a=job($target,'purchase',['user'=>$last,'operation'=>$operation(),'now'=>$now]);$b=job($target,'purchase',['user'=>$last,'operation'=>$operation(),'now'=>$now]);$results=[finish($a),finish($b)];check(1,count(array_filter($results,static function($r){return ($r['code'] ?? '')==='INVENTORY_FULL';})),'One last-slot loser');check(150,count($items->get($last)['instances']),'Last-slot bound');check(95,$values->get($last)['values'][0]['balance'],'Last slot one debit');
    $deleted=$newUser();$a=job($target,'purchase',['user'=>$deleted,'operation'=>$operation(),'now'=>$now]);$b=job($target,'delete',['user'=>$deleted,'now'=>$now]);finish($a);finish($b);$q=$pdo->prepare('SELECT COUNT(*) FROM player_items WHERE user_id=?');$q->execute([$deleted]);check(0,(int)$q->fetchColumn(),'Deletion race cannot leave inventory');
    reject('PLAYER_NOT_FOUND',static function () use ($items,$deleted,$operation,$now) { $items->grant($deleted,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now,'deleted'); });
    // Value-only legacy offers/receipts keep their old path and require no inventory SQL.
    $legacyConfig=$config;$legacyConfig['offers'][0]['rewards']=[['key'=>'coins','amount'=>1]];unset($legacyConfig['offers'][0]['itemRewards']);
    $values->configure($legacyConfig,$values->config(false)['revision']);$legacy=$newUser();$legacyId=$operation();$pdo->statements=[];$legacyPurchase=$values->purchase($legacy,'sword',$legacyId);
    check([],$legacyPurchase['operation']['items'],'Value-only operation item default');
    check([],array_values(array_filter($pdo->statements,static function($sql){return preg_match('/(player_items|item_templates|item_catalog)/',$sql)===1;})),'Value-only path has no item reads/writes');
    $query=$pdo->prepare('SELECT data FROM player_values WHERE user_id=?');$query->execute([$legacy]);$legacyState=json_decode($query->fetchColumn(),true);unset($legacyState['receipts'][0]['items']);$pdo->prepare('UPDATE player_values SET data=? WHERE user_id=?')->execute([json_encode($legacyState),$legacy]);
    check([],$values->purchase($legacy,'sword',$legacyId)['operation']['items'],'Old receipt has empty item references');
    $invalidConfig=$config;$invalidConfig['offers'][0]['itemRewards']=null;reject('INVALID_REQUEST',static function()use($values,$invalidConfig){$values->configure($invalidConfig,$values->config(false)['revision']);});
    $values->configure($config,$values->config(false)['revision']);
    // A nested failure caught by its caller still makes the explicit shared scope rollback-only.
    $caught=false;try{$scope->run(static function()use($values,$full,$operation){try{$values->purchase($full,'sword',$operation());}catch(ItemFailure $ignored){}});}catch(SqlTransactionRollbackOnly $error){$caught=true;}
    check(true,$caught,'Caught nested grant failure cannot commit an outer scope');
    $separate=connect($target);try{new ItemService($pdo,$clock,new SqlTransaction($separate));throw new RuntimeException('Expected connection mismatch');}catch(InvalidArgumentException $error){check(true,true,'Explicit same-PDO ownership');}$separate=null;
    // Complete-template bytes and actual indexed keyset plan, separate from property bytes.
    $wide=$template((object)[]);$wide['name']=str_repeat('n',99);$wide['propertyRules']=[];
    for($i=0;$i<16;$i++){$key='stat__'.str_pad((string)$i,2,'0',STR_PAD_LEFT);$wide['baseProperties']->{$key}=0;$wide['propertyRules'][]=['key'=>$key,'min'=>-ItemRules::MAX,'max'=>ItemRules::MAX,'maxChangePerCall'=>ItemRules::MAX];}
    check(2048,strlen(ItemRules::canonical((object)ItemRules::definition('wide',$wide))),'Exact complete-template bytes');
    $wide['name'].='n';
    reject('INVALID_REQUEST',static function()use($wide){ItemRules::definition('wide',$wide);});
    if(!$dsn){$plan=$pdo->query("EXPLAIN QUERY PLAN SELECT data FROM item_templates WHERE item_key>'shield' ORDER BY item_key LIMIT 51")->fetchAll(PDO::FETCH_ASSOC);check(true,strpos(json_encode($plan),'SEARCH')!==false,'Keyset uses indexed search');}
    $now+=ItemRules::RETENTION+1;
    check(true,$items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$id,1792152000000,'fixture')['replayed'],'Retained receipt is checked before age/catalog policy');
    $items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now,'prune');
    reject('OPERATION_EXPIRED',static function()use($items,$u,$id){$items->grant($u,[['itemKey'=>'sword','quantity'=>1]],$id,1792152000000,'fixture');});
    if($dsn){
        $plan=$pdo->query("EXPLAIN SELECT data FROM item_templates WHERE item_key>'shield' ORDER BY item_key LIMIT 51")->fetch(PDO::FETCH_ASSOC);check('PRIMARY',$plan['key'],'MySQL keyset index');
        // Every mutation input comes from a current locking read even inside an old outer snapshot.
        $external=connect($target);$externalValues=new PlayerValuesService($external,$clock);$outer=$newUser();$values->get($outer);
        $pdo->beginTransaction();$pdo->query("SELECT data FROM player_values WHERE user_id='".$outer."'")->fetch();
        $externalValues->purchase($outer,'sword',$operation());$second=$values->purchase($outer,'sword',$operation());$pdo->commit();
        check(90,$second['values'][0]['balance'],'Old outer snapshot cannot restore another purchase debit');check(2,count($items->get($outer)['instances']),'Old outer snapshot preserves both item grants');
        $gone=$newUser();$pdo->beginTransaction();$pdo->query("SELECT id FROM users WHERE id='".$gone."'")->fetch();$external->prepare('DELETE FROM users WHERE id=?')->execute([$gone]);
        reject('PLAYER_NOT_FOUND',static function()use($items,$gone,$operation,$now){$items->grant($gone,[['itemKey'=>'sword','quantity'=>1]],$operation(),$now,'deleted-outer');});$pdo->rollBack();
        $q=$pdo->prepare('SELECT COUNT(*) FROM player_items WHERE user_id=?');$q->execute([$gone]);check(0,(int)$q->fetchColumn(),'Old outer snapshot cannot resurrect deleted inventory');$external=null;
    }
    echo 'Item SQL costs excluding authentication/bootstrap and MySQL PDO transaction-method calls: '.json_encode($sqlCosts)."\n";
    echo 'PASS: '.$passed.' Items '.($dsn ? 'MySQL' : 'SQLite')." assertions; canonical UTF8, transactional purchases/replay, bounded catalog/receipts, real process races and deletion fences\n";
} finally {
    $pdo=null;if(!$dsn)foreach([$target,$target.'-wal',$target.'-shm'] as $file)if(file_exists($file))unlink($file);
}
