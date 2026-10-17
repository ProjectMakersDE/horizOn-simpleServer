<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Statistics/StatisticsService.php';
require_once __DIR__.'/../src/Core/Database.php';
$passed=0;
function check($expected,$actual,string $label):void {global $passed;if($expected!==$actual)throw new RuntimeException($label.': '.json_encode([$expected,$actual]));$passed++;}
function blocked(string $code,callable $work):void {try{$work();}catch(StatisticsFailure $e){check($code,$e->errorCode,'error');return;}throw new RuntimeException('Expected '.$code);}
$dsn=getenv('HORIZON_STATISTICS_TEST_DSN');
if($dsn&&!preg_match('/;dbname=task1005_[a-f0-9]+(?:;|$)/D',$dsn))throw new RuntimeException('Only isolated task1005 database permitted');
$file=$dsn?:tempnam(sys_get_temp_dir(),'statistics-compact-');$pdo=null;
try {
    $pdo=new PDO($dsn?:'sqlite:'.$file,$dsn?getenv('HORIZON_STATISTICS_TEST_USER'):null,$dsn?getenv('HORIZON_STATISTICS_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if(!$dsn)$pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));Database::ensureStatisticsConfig($pdo);
    $pdo->exec('DELETE FROM statistics_state');$pdo->exec("UPDATE statistics_catalog SET version=0,definitions='[]' WHERE id=1");
    $now=1792250000000;$clock=static function()use(&$now){return $now;};
    $service=new StatisticsService($pdo,$clock,null,201);
    $defs=[];for($i=0;$i<201;$i++)$defs[]=['key'=>'k'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'name'=>'Counter','mode'=>'sum'];
    check(201,count($service->configure($defs,0)['definitions']),'operator above former100');
    $catalog=$service->config();check(201,count(array_unique(array_column($catalog['definitions'],'slot'))),'distinct slots');
    check(false,array_key_exists('slot',$service->definitions()['definitions'][0]),'internal slot not exposed');
    $user=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token) VALUES(?,?,?)')->execute([$user,'compact',Database::uuid()]);
    $operation=$now.':'.strtoupper(Database::uuid());$updates=array_map(static function($d){return ['key'=>$d['key'],'value'=>-7];},$defs);
    check($operation,$service->update($user,$operation,$updates)['operationId'],'fresh preserves original operation text');
    $data=static function()use($pdo,$user){$q=$pdo->prepare('SELECT data FROM statistics_state WHERE user_id=?');$q->execute([$user]);$s=$q->fetchColumn();$q->closeCursor();return $s;};
    $raw=$data();$packed=json_decode($raw,true);check(1,$packed['s'],'compact format');check(201*29,strlen(base64_decode($packed['c'],true)),'counter tuple bytes');check(56,strlen(base64_decode($packed['o'],true)),'guard tuple bytes');
    check(-7,$service->get($user)['values'][0]['value'],'signed roundtrip');check(true,$service->update($user,$operation,$updates)['replayed'],'uppercase original replay');check($operation,$service->update($user,$operation,$updates)['operationId'],'replay preserves original operation text');
    $lower=new StatisticsService($pdo,$clock,null,1);check(201,count($lower->get($user)['values']),'lowered limit keeps all reads');check(true,$lower->update($user,$operation,$updates)['replayed'],'lowered limit keeps replay');
    blocked('STATISTIC_LIMIT_REACHED',static function()use($lower,$defs){$lower->configure($defs,1);});
    $next=$defs;array_shift($next);$next[]=['key'=>'zz_new','name'=>'New','mode'=>'sum'];$service->configure($next,1);
    $view=$service->get($user);check('k001',$view['values'][0]['key'],'stable surviving slot');check(-7,$view['values'][0]['value'],'survivor retains value');check(false,$view['values'][200]['hasValue'],'reused slot does not resurrect deleted value');
    $before=$data();$pdo->beginTransaction();$service->update($user,($now+1).':'.Database::uuid(),[['key'=>'k001','value'=>10]]);$pdo->rollBack();check($before,$data(),'outer transaction rollback byte exact');
    // Original legacy evidence stays unchanged on reads and invalid migration pages.
    $legacy=StatisticsRules::emptyState();$legacy['revision']=3;$legacy['catalogVersion']=2;$legacy['values']=['k001'=>['g'=>1,'p'=>0,'v'=>StatisticsRules::MAX]];
    $legacy['receipts']=[['id'=>$now.':'.Database::uuid(),'hash'=>str_repeat('ab',32),'at'=>$now]];
    $write=static function($value)use($pdo,$user){$pdo->prepare('UPDATE statistics_state SET data=? WHERE user_id=?')->execute([json_encode($value,JSON_THROW_ON_ERROR),$user]);};
    $write($legacy);$before=$data();check(StatisticsRules::MAX,$service->get($user)['values'][0]['value'],'legacy readable');check($before,$data(),'no read-time write');
    check(1,$service->migrateStatePage()['converted'],'explicit migration');check(0,$service->migrateStatePage()['converted'],'idempotent resume');check(StatisticsRules::MAX,$service->get($user)['values'][0]['value'],'migration retains original value');
    $known=$data();$row=json_decode($known,true);
    foreach (['unknown'=>function($x){$x['unknown']=1;return $x;},'base64'=>function($x){$x['c'].='!';return $x;},'partial'=>function($x){$x['c']=base64_encode('x');return $x;},'duplicate'=>function($x){$x['c']=base64_encode(base64_decode($x['c']).base64_decode($x['c']));return $x;},'flag'=>function($x){$raw=base64_decode($x['c']);$raw[28]=chr(2);$x['c']=base64_encode($raw);return $x;},'header'=>function($x){$x['r']='3';return $x;}] as $name=>$change) {
        $write($change($row));$before=$data();blocked('MIGRATION_REQUIRED',static function()use($service,$user){$service->get($user);});check($before,$data(),'invalid compact preserved '.$name);
    }
    foreach (['top'=>function($x){$x['oldUnknown']=['payload'=>1];return $x;},'cell'=>function($x){$x['values']['k001']['oldUnknown']=1;return $x;},'guard'=>function($x){$x['receipts'][0]['result']='original';return $x;},'identity'=>function($x){$x['receipts'][]=$x['receipts'][0];return $x;},'hash'=>function($x){$x['receipts'][0]['hash']='invalid';return $x;}] as $name=>$change) {
        $write($change($legacy));$before=$data();blocked('MIGRATION_REQUIRED',static function()use($service){$service->migrateStatePage();});check($before,$data(),'invalid legacy preserved '.$name);
    }
    $write($legacy);$pdo->beginTransaction();$service->migrateStatePage();$pdo->rollBack();check(json_encode($legacy,JSON_THROW_ON_ERROR),$data(),'migration rollback');
    // This isolated codec measurement excludes SQL records/pages and user/catalog metadata.
    $profile=StatisticsRules::emptyState();$profile['values']=['k001'=>['g'=>1,'p'=>0,'v'=>-7]];$profile['receipts']=$legacy['receipts'];
    $json=StatisticsStorage::encode($profile,$service->config()['definitions']);
    check(29,strlen(base64_decode(json_decode($json,true)['c'])),'one actual cell29B');check(56,strlen(base64_decode(json_decode($json,true)['o'])),'one actual guard56B');
    echo json_encode(['emptyJsonBytes'=>strlen(StatisticsStorage::encode(StatisticsRules::emptyState(),[])),'oneCounterOneGuardJsonBytes'=>strlen($json),'counterPayloadBytes'=>29,'guardPayloadBytes'=>56]).PHP_EOL;
    echo 'PASS: '.$passed.' compact statistics '.($dsn?'MySQL':'SQLite').' assertions'.PHP_EOL;
} finally {if($pdo&&$dsn){$pdo->exec('DELETE FROM statistics_state');$pdo->exec('DELETE FROM users');}$pdo=null;if(!$dsn&&is_file($file))unlink($file);}
