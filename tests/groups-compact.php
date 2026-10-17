<?php

declare(strict_types=1);
require_once __DIR__.'/../src/Core/Config.php';
require_once __DIR__.'/../src/Core/Database.php';
require_once __DIR__.'/../src/Groups/GroupService.php';
require_once __DIR__.'/../src/Groups/GroupMigration.php';

$dsn=getenv('HORIZON_GROUPS_TEST_DSN');
if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1010_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Only an explicit loopback task1010_<hex> DB is permitted');
$path=tempnam(sys_get_temp_dir(),'horizon-groups-compact-');$env=$path.'.env';$pdo=null;$passed=0;
function check($expected,$actual,string $label):void {global $passed;if($expected!==$actual)throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual));$passed++;}
function reject(string $code,callable $work):void {try{$work();}catch(GroupFailure $e){check($code,$e->errorCode,'Rejection');return;}throw new RuntimeException('Expected '.$code);}
function policy(array $values=[]):void {global $env;$s='';foreach($values as $k=>$v)$s.=$k.'='.$v."\n";file_put_contents($env,$s);Config::load($env);}
try {
    $pdo=new PDO($dsn?:'sqlite:'.$path,$dsn?getenv('HORIZON_GROUPS_TEST_USER'):null,$dsn?getenv('HORIZON_GROUPS_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if(!$dsn){$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA journal_mode=WAL');}
    $pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));Database::ensurePlayerValuesConfig($pdo);Database::ensureInboxConfig($pdo);Database::ensureUserActivityColumns($pdo);Database::ensureGroupStorage($pdo);policy();
    $now=1792238400000;$clock=static function()use(&$now){return $now;};$s=new GroupService($pdo,$clock);$store=new GroupStore($pdo);
    $newUser=static function()use($pdo):string{$u=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at) VALUES(?,?,?,?)')->execute([$u,'Player',Database::uuid(),'2026-10-17 12:00:00']);return $u;};
    $create=static function(string $u,string $mode='open')use($s):array{return $s->create($u,$s->get($u)['revision'],'Group','',$mode,(object)[])['group'];};
    $act=static function(string $a,string $u,string $g,?string $t=null,?string $r=null)use($s):array{return $s->change($a,$u,$g,$s->detail($u,$g)['revision'],$t,$r);};
    check(2,$s->limits()['maxPlayerInvitations'],'Default two player invitations');check(2,$s->limits()['maxPlayerRequests'],'Default two player requests');check(11,count($s->limits()),'All configurable public fields');
    $target=$newUser();$owners=[];$groups=[];
    for($i=0;$i<3;$i++){$owners[]=$u=$newUser();$groups[]=$g=$create($u);if($i<2)$act('invite',$u,$g['id'],$target);}
    reject('PLAYER_PENDING_LIMIT',static function()use($act,$owners,$groups,$target){$act('invite',$owners[2],$groups[2]['id'],$target);});
    $before=$s->get($target);check(2,count($before['incomingInvites']),'Two retained invitations');
    check(false,array_key_exists('createdAt',$before['incomingInvites'][0]),'New pending has no redundant date');
    $requester=$newUser();$requestGroups=[];for($i=0;$i<3;$i++){$requestGroups[]=$g=$create($newUser(),'request');if($i<2)$act('request',$requester,$g['id']);}
    reject('PLAYER_PENDING_LIMIT',static function()use($act,$requester,$requestGroups){$act('request',$requester,$requestGroups[2]['id']);});check(2,count($s->get($requester)['outgoingRequests']),'Default two outgoing requests');
    $q=$pdo->prepare('SELECT data FROM player_groups WHERE user_id=?');$q->execute([$target]);check('{"s":1,"i":2}',$q->fetchColumn(),'Only quota counters persist');
    policy(['GROUP_PLAYER_INVITATIONS'=>1,'GROUP_INVITATIONS'=>1]);check(2,count($s->get($target)['incomingInvites']),'Lower policy never truncates retained pending');
    $first=$before['incomingInvites'][0];$act('decline-invite',$target,$first['groupId'],null,$first['requestId']);check(1,count($s->get($target)['incomingInvites']),'Overhang can resolve under lower policy');
    policy(['GROUP_MEMBERS'=>700,'GROUP_MEMBERSHIPS'=>60,'GROUP_INVITATIONS'=>70,'GROUP_JOIN_REQUESTS'=>70,'GROUP_PLAYER_INVITATIONS'=>70,'GROUP_PLAYER_REQUESTS'=>70,'GROUP_MEMBER_PAGE_SIZE'=>351,'GROUP_NAME_BYTES'=>240,'GROUP_DESCRIPTION_BYTES'=>2048,'GROUP_DATA_BYTES'=>4096,'GROUP_DATA_DEPTH'=>24,'INBOX_TARGETED'=>250]);
    $higher=$s->limits();check(700,$higher['maxMembers'],'Admin member limit above 500');check(351,$higher['memberPageSize'],'Admin page above profile batch');
    $deep=0;for($i=0;$i<21;$i++)$deep=(object)['x'=>$deep];$meta=GroupRules::metadata(str_repeat('é',100),str_repeat('x',1500),'open',(object)['body'=>str_repeat('x',2500),'deep'=>$deep],$higher);check(true,strlen($meta['dataJson'])>2048,'Configured metadata above former bounds');
    $manyOwner=$newUser();$many=[];for($i=0;$i<46;$i++)$many[]=$create($manyOwner)['id'];check(46,count($s->get($manyOwner)['groups']),'46 memberships survive old five/45 bounds');
    $pendingOwner=$newUser();$pendingGroup=$create($pendingOwner,'request');
    for($i=0;$i<45;$i++){$u=$newUser();$act('invite',$pendingOwner,$pendingGroup['id'],$u);$u=$newUser();$act('request',$u,$pendingGroup['id']);}
    $p=$s->pending($pendingOwner,$pendingGroup['id']);check(45,count($p['invitations']),'Configured group invitations above20');check(45,count($p['joinRequests']),'Configured requests above20');
    $q=$pdo->prepare('SELECT COUNT(*) FROM group_pending_references WHERE group_id=?');$q->execute([$pendingGroup['id']]);check(91,(int)$q->fetchColumn(),'More than80 reference identities, no hidden cap');
    $raw=$pdo->prepare('SELECT data FROM groups_state WHERE id=?');$raw->execute([$pendingGroup['id']]);$persisted=json_decode($raw->fetchColumn(),true);check(false,array_key_exists('issuerId',$persisted['joinRequests'][0]),'Request issuer derives from requester');
    $pageOwner=$newUser();$pageGroup=$create($pageOwner);for($i=0;$i<350;$i++){$u=$newUser();$act('join',$u,$pageGroup['id']);}
    $page=$s->members($pageOwner,$pageGroup['id'],null,351);check(351,count($page['members']),'351-member page profiles are batched');check(null,$page['nextAfterUserId'],'Exact final page');
    policy(['GROUP_MEMBERS'=>1,'GROUP_MEMBERSHIPS'=>1,'GROUP_INVITATIONS'=>1,'GROUP_JOIN_REQUESTS'=>1,'GROUP_DATA_BYTES'=>1,'GROUP_NAME_BYTES'=>1,'GROUP_DESCRIPTION_BYTES'=>1,'GROUP_DATA_DEPTH'=>1]);
    check(46,count($s->get($manyOwner)['groups']),'Membership overhang readable after lowering');check(45,count($s->pending($pendingOwner,$pendingGroup['id'])['invitations']),'Group pending overhang readable');check(351,$s->detail($pageOwner,$pageGroup['id'])['memberCount'],'Retained large group readable');
    policy();
    // Real old copies and unresolved timestamps survive index migration. No startup data rewrite.
    $legacyOwner=$newUser();$legacyUser=$newUser();$legacyGroup=$create($legacyOwner,'request');$request=Database::uuid();$date='2026-01-02T03:04:05.006Z';
    $g=$store->group($legacyGroup['id']);$g['joinRequests'][]=['userId'=>$legacyUser,'requestId'=>$request,'issuerId'=>$legacyUser,'createdAt'=>$date];$g['extension']=(object)['empty'=>(object)[]];
    $original=GroupRules::json($g);$pdo->prepare('UPDATE groups_state SET data=? WHERE id=?')->execute([$original,$g['id']]);
    $legacy=['groupIds'=>[],'incomingInvites'=>[],'outgoingRequests'=>[['groupId'=>$g['id'],'requestId'=>$request,'issuerId'=>$legacyUser,'createdAt'=>$date]]];
    $legacyRaw=GroupRules::json($legacy);$pdo->prepare('INSERT INTO player_groups(user_id,active_user_id,revision,data) VALUES(?,?,7,?)')->execute([$legacyUser,$legacyUser,$legacyRaw]);
    $pdo->exec('UPDATE group_storage SET ready=0,cursor_group=NULL');$cold=new GroupService($pdo,$clock);check($date,$cold->get($legacyUser)['outgoingRequests'][0]['createdAt'],'Old date readable before migration');
    reject('GROUP_STORAGE_CONFLICT',static function()use($cold,$legacyUser){$cold->create($legacyUser,7,'Blocked','','open',(object)[]);});
    $migration=new GroupMigration($pdo);$preview=$migration->indexPage(100,false);check(false,$preview['ready'],'Inspect does not mark ready');
    do{$part=$migration->indexPage(100,true);}while(!$part['ready']);
    $raw->execute([$g['id']]);check($original,$raw->fetchColumn(),'Index migration preserves original group bytes');
    check($date,(new GroupService($pdo,$clock))->get($legacyUser)['outgoingRequests'][0]['createdAt'],'Exact old date after source proof');
    $cursor=null;do{$part=$migration->groups($cursor,100,true);$cursor=$part['nextAfterGroupId'];}while($cursor!==null);
    $raw->execute([$g['id']]);$compactGroup=json_decode($raw->fetchColumn());check(false,isset($compactGroup->id),'SQL PK is not duplicated in JSON');check(false,isset($compactGroup->revision),'SQL revision is not duplicated in JSON');check($date,$compactGroup->joinRequests[0]->createdAt,'Explicit Group conversion retains original date');check(true,$compactGroup->extension->empty instanceof stdClass,'Explicit Group conversion retains unknown object');
    $cursor=null;do{$part=$migration->players($cursor,100,true);$cursor=$part['nextAfterUserId'];}while($cursor!==null);
    $q=$pdo->prepare('SELECT revision,data FROM player_groups WHERE user_id=?');$q->execute([$legacyUser]);$v=$q->fetch();check(7,(int)$v['revision'],'Storage conversion preserves revision');check('{"s":1,"r":1}',$v['data'],'Old request copy removed only after proof');
    $snap=$v['data'];$pdo->prepare('UPDATE player_groups SET data=? WHERE user_id=?')->execute(['{"s":1}',$legacyUser]);reject('GROUP_STORAGE_CONFLICT',static function()use($pdo,$clock,$legacyUser){(new GroupService($pdo,$clock))->get($legacyUser);});$q->execute([$legacyUser]);check('{"s":1}',$q->fetch()['data'],'Partial restore left unchanged');$pdo->prepare('UPDATE player_groups SET data=? WHERE user_id=?')->execute([$snap,$legacyUser]);
    $bad=$legacy;$bad['outgoingRequests'][0]['createdAt']='2026-01-02T03:04:05.007Z';$badRaw=GroupRules::json($bad);$pdo->prepare('UPDATE player_groups SET data=? WHERE user_id=?')->execute([$badRaw,$legacyUser]);reject('GROUP_STORAGE_CONFLICT',static function()use($pdo,$clock,$legacyUser){(new GroupService($pdo,$clock))->get($legacyUser);});$q->execute([$legacyUser]);check($badRaw,$q->fetch()['data'],'Changed original date fails without data loss');
    $pdo->prepare('UPDATE player_groups SET data=? WHERE user_id=?')->execute([$snap,$legacyUser]);
    // Group updates preserve unknown object shapes and the original legacy pending timestamp.
    $act('invite',$legacyOwner,$g['id'],$newUser());$raw->execute([$g['id']]);$kept=json_decode($raw->fetchColumn());check(true,$kept->extension->empty instanceof stdClass,'Unknown object type preserved');check($date,$kept->joinRequests[0]->createdAt,'Legacy timestamp survives later write');
    $sql='SELECT group_id,target_kind FROM group_pending_references WHERE user_id=? AND target_kind IN (1,2) ORDER BY target_kind,group_id LIMIT 3';$q=$pdo->prepare(($dsn?'EXPLAIN ':'EXPLAIN QUERY PLAN ').$sql);$q->execute([$target]);$plan=$q->fetchAll();check(true,strpos(json_encode($plan),'idx_group_pending_target')!==false,'Directed pending index plan');
    echo 'Groups compact '.($dsn?'MySQL':'SQLite').': '.$passed." checks passed (configured overhang, canonical sources, source-only index migration, raw preservation, batched profiles).\n";
}finally{$pdo=null;foreach([$path,$path.'-wal',$path.'-shm',$env]as $f)if(file_exists($f))unlink($f);}
