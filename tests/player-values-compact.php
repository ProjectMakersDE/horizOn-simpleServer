<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/PlayerValues/PlayerValuesService.php';
require_once __DIR__ . '/../src/PlayerValues/PlayerValuesMigration.php';

$passed = 0;
function expectCompact($expected, $actual, string $message): void {
    global $passed;
    if ($expected !== $actual) throw new RuntimeException($message . ': ' . json_encode([$expected, $actual]));
    $passed++;
}
function failCompact(string $code, callable $action): void {
    try { $action(); } catch (PlayerValuesFailure $failure) { expectCompact($code, $failure->errorCode, 'failure code'); return; }
    throw new RuntimeException('Expected ' . $code);
}

$dsn = getenv('HORIZON_VALUES_TEST_DSN');
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1004_[a-f0-9]+;charset=utf8mb4$/D', $dsn)) throw new RuntimeException('Own loopback fixture required');
$path = $dsn ? null : tempnam(sys_get_temp_dir(), 'horizon-values-compact-');
$settings = tempnam(sys_get_temp_dir(), 'horizon-values-settings-'); $pdo = null;
try {
    $pdo = new PDO($dsn ?: 'sqlite:' . $path, $dsn ? getenv('HORIZON_VALUES_TEST_USER') : null, $dsn ? getenv('HORIZON_VALUES_TEST_PASSWORD') : null,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec(file_get_contents(__DIR__ . '/../migrations/' . ($dsn ? 'mysql' : 'sqlite') . '.sql'));
    Database::ensurePlayerValuesConfig($pdo);
    file_put_contents($settings, "PLAYER_VALUES_DEFINITIONS=80\nPLAYER_VALUES_OFFERS=1100\nPLAYER_VALUES_RECEIPTS=75\n"); Config::load($settings);
    $now = 1792230000000; $scope = new SqlTransaction($pdo);
    $service = new PlayerValuesService($pdo, static function () use ($now) { return $now; }, $scope);
    expectCompact(['definitions'=>80,'offers'=>1100,'receipts'=>75], $service->config()['limits'], 'operator quotas above defaults');
    $definitions = [];
    for ($i=0; $i<65; $i++) $definitions[] = ['key'=>'v'.$i,'name'=>'Value '.$i,'initialBalance'=>0,'adjustment'=>['enabled'=>true,'maxPerCall'=>1]];
    $offers = [];
    for ($i=0; $i<1001; $i++) $offers[] = ['key'=>'o'.$i,'name'=>'Offer '.$i,'costs'=>[['key'=>'v0','amount'=>1]],'rewards'=>[['key'=>'v1','amount'=>1]]];
    $config = $service->configure(['definitions'=>$definitions,'offers'=>$offers], 0);
    expectCompact(65, count($config['definitions']), '65 definitions really accepted');
    expectCompact(1001, count($config['offers']), '1001 offers really accepted');
    $user = Database::uuid(); $other = Database::uuid();
    $insert = $pdo->prepare('INSERT INTO users(id,display_name,anonymous_token) VALUES(?,?,?)');
    foreach ([$user,$other] as $id) $insert->execute([$id,'Fixture',Database::uuid()]);
    $read = static function (string $id) use ($pdo): string {
        $q=$pdo->prepare('SELECT data FROM player_values WHERE user_id=?'); $q->execute([$id]); $raw=$q->fetchColumn(); $q->closeCursor(); return $raw;
    };
    $service->get($user); $initial=json_decode($read($user),true);
    expectCompact(9, strlen(base64_decode($initial['i'])), '65 zero balances use initialization bitmap');
    expectCompact('', $initial['b'], 'zero balances have no numeric payload');
    $last='';
    for ($i=0; $i<76; $i++) { $last=($now+$i).':'.Database::uuid(); $service->adjust($user,'v0',1,'earned',$last); }
    $raw=$read($user); $saved=json_decode($raw,true);
    expectCompact(75*57, strlen(base64_decode($saved['q'])), '75 public guards use 57 actual raw bytes each');
    expectCompact(9, strlen(base64_decode($saved['b'])), 'one nonzero balance uses 9 bytes');
    expectCompact($now, $saved['f'], 'evicted first ID advances permanent floor');
    expectCompact(true, $service->adjust($user,'v0',1,'earned','000'.$last)['operation']['replayed'], 'leading timestamp zeros retry same action');
    expectCompact(true, $service->adjust($user,'v0',1,'earned',strtoupper($last))['operation']['replayed'], 'UUID casing retry same action');
    expectCompact([], $service->adjust($user,'v0',1,'earned',$last)['operation']['applied'], 'replay returns no copied applied payload');
    expectCompact($raw, $read($user), 'read and retries do not rewrite stored state');
    $legacy=PlayerValuesStorage::decode($raw,$config['knownKeys']);
    $legacy['offerPurchases']=['old_offer'=>7]; $legacy['receipts'][0]['note']='old copied purchase detail';
    $legacy['receipts'][0]['applied']=[['key'=>'v0','amount'=>1]];
    $legacyRaw=json_encode($legacy,JSON_THROW_ON_ERROR);
    $pdo->prepare('UPDATE player_values SET data=? WHERE user_id=?')->execute([$legacyRaw,$user]);
    $service->get($user);
    expectCompact($legacyRaw, $read($user), 'ordinary read does not migrate a legacy row');
    $migration=new PlayerValuesMigration($pdo); $dry=$migration->page('',100,false);
    expectCompact(1, $dry['changed'], 'dry run finds one legacy row');
    expectCompact($legacyRaw, $read($user), 'dry run does not write');
    $done=$migration->page('',100,true); $converted=$read($user);
    expectCompact(true, $done['afterBytes']<$done['beforeBytes'], 'migration measures actual shrinking SQL JSON');
    $decoded=PlayerValuesStorage::decode($converted,$config['knownKeys']);
    expectCompact($legacy['revision'], $decoded['revision'], 'storage migration preserves public revision');
    expectCompact($legacy['evictedThroughMillis'], $decoded['evictedThroughMillis'], 'migration preserves replay floor');
    expectCompact($legacy['values'], $decoded['values'], 'migration preserves values');
    expectCompact([], $decoded['offerPurchases'], 'per-offer counters gone');
    expectCompact([], $decoded['receipts'][0]['applied'], 'purchase details gone');
    expectCompact(0, $migration->page('',100,true)['changed'], 'idempotent cold migration');
    // A malformed source rolls back the complete bounded page instead of losing or advancing it.
    $bad=$legacy; $bad['unknownAuthoritativeField']=1;
    $pdo->prepare('INSERT INTO player_values(user_id,data) VALUES(?,?)')->execute([$other,json_encode($bad)]);
    $pdo->prepare('UPDATE player_values SET data=? WHERE user_id=?')->execute([$legacyRaw,$user]);
    failCompact('MIGRATION_REQUIRED', static function () use ($migration) { $migration->page('',100,true); });
    expectCompact($legacyRaw, $read($user), 'blocked page preserves original good row too');
    $pdo->prepare('DELETE FROM player_values WHERE user_id=?')->execute([$other]);
    // Existing catalogues/guards remain visible after the operator lowers quotas.
    file_put_contents($settings, "PLAYER_VALUES_DEFINITIONS=1\nPLAYER_VALUES_OFFERS=1\nPLAYER_VALUES_RECEIPTS=1\n"); Config::load($settings);
    $lowered=new PlayerValuesService($pdo,static function()use($now){return $now;},$scope);
    expectCompact(65,count($lowered->get($user)['values']),'lower quota preserves old definitions/balances');
    expectCompact(1001,count($lowered->config()['offers']),'lower quota preserves old offers');
    expectCompact($legacyRaw,$read($user),'lower quota does not prune old guards on read');
    // Source-owned grants require the explicit same scope and add no public replay guard.
    try { $lowered->grantFromSource($user,[['key'=>'v1','amount'=>2]],'source-test',$now,'fixture'); throw new RuntimeException('Missing scope accepted'); }
    catch (LogicException $expected) { $passed++; }
    $scope->run(static function()use($lowered,$user,$now){$lowered->grantFromSource($user,[['key'=>'v1','amount'=>2]],'source-test',$now,'fixture');});
    $afterGrant=PlayerValuesStorage::decode($read($user),$config['knownKeys']);
    expectCompact(75,count($afterGrant['receipts']),'source grant adds no redundant guard and does not evict old ones');
    expectCompact(2,$afterGrant['values']['v1'],'source grant applied');
    $beforeRollback=$read($user);
    try { $scope->run(static function()use($lowered,$user,$now){$lowered->grantFromSource($user,[['key'=>'v1','amount'=>3]],'source-test2',$now,'fixture');throw new RuntimeException('rollback');}); }
    catch (RuntimeException $expected) { if($expected->getMessage()!=='rollback')throw $expected; }
    expectCompact($beforeRollback,$read($user),'outer rollback restores grant and guard bytes');
    $badOffer=['definitions'=>[$definitions[0],$definitions[1]],'offers'=>[$offers[0]]]; $badOffer['offers'][0]['purchasesPerDay']=5;
    failCompact('INVALID_REQUEST',static function()use($badOffer){PlayerValuesLedger::config($badOffer);});
    $compact=json_decode($beforeRollback,true);
    foreach ([['s',3],['i',base64_encode(str_repeat("\xff",33))],['b',base64_encode("\x00")],['q',base64_encode("\xff")],['q',base64_encode("\0")],['r',-1],['f',1.5],['d','tomorrow']] as [$field,$value]) {
        $broken=$compact; $broken[$field]=$value;
        failCompact('MIGRATION_REQUIRED',static function()use($broken,$config){PlayerValuesStorage::decode(json_encode($broken),$config['knownKeys']);});
    }
    $unknown=$compact; $unknown['extra']='data'; failCompact('MIGRATION_REQUIRED',static function()use($unknown,$config){PlayerValuesStorage::decode(json_encode($unknown),$config['knownKeys']);});
    $duplicate=$compact; $duplicate['q']=base64_encode(str_repeat(substr(base64_decode($compact['q']),0,57),2));
    failCompact('MIGRATION_REQUIRED',static function()use($duplicate,$config){PlayerValuesStorage::decode(json_encode($duplicate),$config['knownKeys']);});
    echo "Compact player values: {$passed} assertions passed. Measured SQL JSON: ".strlen($beforeRollback)." bytes for 65 initialized keys, two balances, 75 guards.\n";
} finally {
    $pdo=null; @unlink($settings);
    if($path!==null)foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);
}
