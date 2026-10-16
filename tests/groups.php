<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Groups/GroupService.php';

class GroupsProbePDO extends PDO
{
    public bool $loseCommit = false;
    public ?string $failSql = null;
    public array $statements = [];
    private function observe(string $sql): void
    {
        $this->statements[] = $sql;
        if ($this->failSql !== null && strpos($sql,$this->failSql) === 0) { $this->failSql = null; throw new GroupFailure('REVISION_CONFLICT'); }
    }
    private function acknowledge(): void { if ($this->loseCommit) { $this->loseCommit = false; throw new RuntimeException('Injected lost commit acknowledgement'); } }
    #[\ReturnTypeWillChange]
    public function prepare($sql,$options = []) { $this->observe($sql); return parent::prepare($sql,$options); }
    #[\ReturnTypeWillChange]
    public function exec($sql) { $this->observe($sql); $result = parent::exec($sql); if (strtoupper(trim($sql)) === 'COMMIT') $this->acknowledge(); return $result; }
    #[\ReturnTypeWillChange]
    public function query($sql,?int $fetchMode = null,...$fetchModeArgs) { $this->observe($sql); return $fetchMode === null ? parent::query($sql) : parent::query($sql,$fetchMode,...$fetchModeArgs); }
    #[\ReturnTypeWillChange]
    public function commit() { $result = parent::commit(); $this->acknowledge(); return $result; }
}
function connect(string $target): GroupsProbePDO
{
    $mysql = strpos($target,'mysql:') === 0;
    $pdo = new GroupsProbePDO($mysql ? $target : 'sqlite:' . $target,$mysql ? getenv('HORIZON_GROUPS_TEST_USER') : null,$mysql ? getenv('HORIZON_GROUPS_TEST_PASSWORD') : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (!$mysql) { $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA journal_mode=WAL'); } return $pdo;
}
if (($argv[1] ?? '') === '--worker') {
    try {
        $input = json_decode(base64_decode($argv[4],true),true,512,JSON_THROW_ON_ERROR); $service = new GroupService(connect($argv[2]),null,null,2,1);
        $result = $argv[3] === 'create' ? $service->create($input['user'],0,'Concurrent','','open',(object)[]) : $service->change('join',$input['user'],$input['group'],1);
        echo GroupRules::json($result);
    } catch (GroupFailure $error) { echo json_encode(['code' => $error->errorCode]); } exit;
}
$passed = 0;
function check($expected,$actual,string $label): void { global $passed; if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)); $passed++; }
function reject(string $code,callable $work): void
{
    try { $work(); } catch (GroupFailure $error) { check($code,$error->errorCode,'Group rejection'); return; }
    catch (InboxFailure $error) { check($code,$error->errorCode,'Inbox rejection'); return; } throw new RuntimeException('Expected ' . $code);
}
function worker(string $target,string $action,array $input): array
{
    $pipes = []; $process = proc_open([PHP_BINARY,__FILE__,'--worker',$target,$action,base64_encode(json_encode($input))],[0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Worker did not start'); fclose($pipes[0]); return [$process,$pipes];
}
function finish(array $job): array
{
    [$process,$pipes] = $job; $reply = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(0,proc_close($process),'Worker exit'); if ($errors !== '') throw new RuntimeException($errors); return json_decode($reply,true,512,JSON_THROW_ON_ERROR);
}
function counts(GroupsProbePDO $pdo): array
{
    $result = ['SELECT' => 0,'DML' => 0,'BEGIN' => 0,'COMMIT' => 0]; foreach ($pdo->statements as $sql) {
        if (preg_match('/^SELECT/i',$sql)) $result['SELECT']++; elseif (preg_match('/^(INSERT|UPDATE|DELETE)/i',$sql)) $result['DML']++;
        elseif (strpos($sql,'BEGIN') === 0) $result['BEGIN']++; elseif ($sql === 'COMMIT') $result['COMMIT']++;
    } return $result;
}
$dsn = getenv('HORIZON_GROUPS_TEST_DSN');
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1010_[a-f0-9]+;charset=utf8mb4$/D',$dsn)) throw new RuntimeException('Only explicit loopback task1010_<hex> test DB is permitted');
$target = $dsn ?: tempnam(sys_get_temp_dir(),'horizon-task1010-'); $pdo = null;
try {
    $pdo = connect($target); $pdo->exec(file_get_contents(__DIR__ . '/../migrations/' . ($dsn ? 'mysql' : 'sqlite') . '.sql'));
    Database::ensurePlayerValuesConfig($pdo); Database::ensureInboxConfig($pdo); Database::ensureUserActivityColumns($pdo);
    $now = (int)(strtotime('2026-10-16T12:00:00Z') * 1000); $clock = static function () use (&$now) { return $now; };
    $service = new GroupService($pdo,$clock); $inbox = new InboxService($pdo,$clock);
    $insert = $pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at,email,session_token) VALUES(?,?,?,?,?,?)');
    $createUser = static function (?string $id = null) use ($insert): string { $id = $id ?? Database::uuid(); $insert->execute([$id,'Public Player',Database::uuid(),'2026-10-16 12:00:00','private@example.invalid','private-session']); return $id; };
    $deleteUser = static function (string $id) use ($pdo): void { $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]); };
    $newGroup = static function (string $owner,string $mode = 'open') use ($service): array { $rev = $service->get($owner)['revision']; return $service->create($owner,$rev,'Group','Description',$mode,(object)['private' => true])['group']; };
    $rev = static function (string $actor,string $id) use ($service): int { return $service->detail($actor,$id)['revision']; };
    $act = static function (string $action,string $actor,string $id,?string $targetUser = null,?string $request = null,?string $role = null) use ($service,$rev): array { return $service->change($action,$actor,$id,$rev($actor,$id),$targetUser,$request,$role); };
    $users = []; for ($i = 0;$i < 20;$i++) $users[] = $createUser(); [$leader,$b,$c,$outsider,$invitee,$officer,$member,$full,$requester,$issuer,$raceA,$raceB,$joiner,$otherLeader,$rollback,$uncertain] = $users;
    $pdo->statements = []; $empty = $service->get($leader); $costs = ['emptyGet' => counts($pdo)];
    check(0,$empty['revision'],'Lazy player revision'); check(0,(int)$pdo->query('SELECT COUNT(*) FROM player_groups')->fetchColumn(),'GET does not create state'); check(500,$empty['limits']['maxMembers'],'Selfhost member bound');
    foreach ([null,-1,1.0,1.5,'0',9007199254740992] as $bad) reject('INVALID_REVISION',static function () use ($service,$leader,$bad) { $service->create($leader,$bad,'Group','','open',(object)[]); });
    foreach ([[],null,(object)['x' => INF]] as $bad) reject('INVALID_GROUP',static function () use ($service,$leader,$bad) { $service->create($leader,0,'Group','','open',$bad); });
    reject('INVALID_GROUP',static function () use ($service,$leader) { $service->create($leader,0,'Group','','open',(object)['x' => str_repeat('x',2048)]); });
    $deep = 0; for ($i = 0;$i < 18;$i++) $deep = (object)['x' => $deep]; reject('INVALID_GROUP',static function () use ($service,$leader,$deep) { $service->create($leader,0,'Group','','open',$deep); });
    $data = json_decode('{"$key":{"a.b":[],"":{},"nested":{"n":1.5}},"name":"é/😀"}');
    check(2048,strlen(GroupRules::metadata(str_repeat('é',60),'','open',(object)['x' => str_repeat('a',2040)])['dataJson']),'Exact serialized UTF8 data boundary');
    reject('INVALID_GROUP',static function () { GroupRules::metadata('Group','','open',(object)['x' => str_repeat('a',2041)]); });
    reject('INVALID_GROUP',static function () { GroupRules::metadata(str_repeat('é',61),'','open',(object)[]); });
    $created = $service->create($leader,0,'Group','','open',$data); $group = $created['group']; $id = $group['id'];
    check(1,$created['groups']['revision'],'Create player revision'); check('leader',$group['myRole'],'Owner role'); check(GroupRules::json($data),GroupRules::json($group['data']),'Object identity and legal keys survive SQL');
    reject('REVISION_CONFLICT',static function () use ($service,$leader) { $service->create($leader,0,'Duplicate','','open',(object)[]); });
    check(false,array_key_exists('data',$service->detail($outsider,$id)),'Nonmember cannot read private data'); check(false,array_key_exists('myRole',$service->detail($outsider,$id)),'Nonmember has no role');
    reject('GROUP_PERMISSION_DENIED',static function () use ($service,$outsider,$id) { $service->members($outsider,$id,null,50); });
    $act('join',$b,$id); $act('join',$c,$id); $act('join',$officer,$id); $act('role',$leader,$id,$b,null,'officer'); $act('role',$leader,$id,$officer,null,'officer');
    reject('GROUP_PERMISSION_DENIED',static function () use ($act,$c,$id,$invitee) { $act('invite',$c,$id,$invitee); });
    reject('GROUP_PERMISSION_DENIED',static function () use ($act,$b,$id,$officer) { $act('remove',$b,$id,$officer); });
    reject('GROUP_PERMISSION_DENIED',static function () use ($act,$b,$id,$c) { $act('role',$b,$id,$c,null,'officer'); });
    reject('GROUP_PERMISSION_DENIED',static function () use ($service,$c,$id) { $service->pending($c,$id); });
    reject('LEADERSHIP_TRANSFER_REQUIRED',static function () use ($act,$leader,$id) { $act('leave',$leader,$id); });
    $page = $service->members($b,$id,null,2); check(2,count($page['members']),'Bounded page'); check(true,$page['nextAfterUserId'] !== null,'Explicit next cursor');
    $nextPage = $service->members($b,$id,$page['nextAfterUserId'],2); check([] ,array_values(array_intersect(array_column($page['members'],'userId'),array_column($nextPage['members'],'userId'))),'Keyset pages do not overlap');
    check(false,strpos(GroupRules::json($page),'private@') !== false,'Profile bulk projection excludes email');
    $invited = $act('invite',$b,$id,$invitee); $pending = $service->get($invitee)['incomingInvites'][0]; $original = $pending['requestId'];
    check(false,array_key_exists('data',$pending['group']),'Pending metadata has no private data');
    $accepted = $act('accept-invite',$invitee,$id,null,$original); check('member',$accepted['group']['myRole'],'Recipient accepts exact invitation'); check([],$accepted['groups']['incomingInvites'],'Invitation consumed on player');
    reject('REQUEST_NOT_FOUND',static function () use ($act,$invitee,$id,$original) { $act('accept-invite',$invitee,$id,null,$original); });
    $act('remove',$leader,$id,$invitee); $act('invite',$leader,$id,$invitee); $oldId = $service->get($invitee)['incomingInvites'][0]['requestId'];
    $act('cancel-invite',$leader,$id,$invitee,$oldId); $act('invite',$leader,$id,$invitee); $newId = $service->get($invitee)['incomingInvites'][0]['requestId']; check(false,$oldId === $newId,'Replacement invitation gets another UUID');
    reject('REQUEST_NOT_FOUND',static function () use ($act,$invitee,$id,$oldId) { $act('accept-invite',$invitee,$id,null,$oldId); }); $act('decline-invite',$invitee,$id,null,$newId);
    $pdo->statements = []; $beforeNoop = $rev($leader,$id); $noop = $service->change('role',$leader,$id,$beforeNoop,$b,null,'officer'); check($beforeNoop,$noop['group']['revision'],'Identical role is no-op'); check(0,counts($pdo)['DML'],'Reviewed no-op writes nothing');
    $act('transfer',$leader,$id,$b); check('member',$service->detail($leader,$id)['myRole'],'Previous leader becomes member'); check('leader',$service->detail($b,$id)['myRole'],'Target becomes leader'); $act('leave',$leader,$id); check(null,$act('leave',$c,$id)['group'],'Leave returns null actor group');
    $requestGroup = $newGroup($otherLeader,'request'); $rid = $requestGroup['id'];
    $requested = $act('request',$requester,$rid); check(false,array_key_exists('data',$requested['group']),'Nonmember mutation returns public group'); $requestId = $requested['groups']['outgoingRequests'][0]['requestId'];
    reject('GROUP_PENDING_EXISTS',static function () use ($act,$otherLeader,$rid,$requester) { $act('invite',$otherLeader,$rid,$requester); });
    $service->update($otherLeader,$rid,$rev($otherLeader,$rid),'Changed','','invite',(object)[]);
    check(1,count($service->pending($otherLeader,$rid)['joinRequests']),'Mode change retains request'); $act('accept-request',$otherLeader,$rid,$requester,$requestId); check(1,count($service->get($requester)['groups']),'Original request accepted after mode change');
    $decliner = $createUser(); $service->update($otherLeader,$rid,$rev($otherLeader,$rid),'Changed','','request',(object)[]); $req = $act('request',$decliner,$rid)['groups']['outgoingRequests'][0]['requestId']; $act('decline-request',$otherLeader,$rid,$decliner,$req);
    check([],$service->get($decliner)['outgoingRequests'],'Explicit request decline'); $req = $act('request',$decliner,$rid)['groups']['outgoingRequests'][0]['requestId']; $act('cancel-request',$decliner,$rid,null,$req); check([],$service->pending($otherLeader,$rid)['joinRequests'],'Own exact request cancel');
    // Leader and original issuer blocks cannot be bypassed through an officer's invitation.
    $blockLeader = $createUser(); $blockOfficer = $createUser(); $blockTarget = $createUser(); $blockedGroup = $newGroup($blockLeader); $bid = $blockedGroup['id']; $act('join',$blockOfficer,$bid); $act('role',$blockLeader,$bid,$blockOfficer,null,'officer');
    $friends = new FriendsService($pdo,$clock); $friends->change('block',$blockLeader,$blockTarget,0);
    reject('GROUP_RELATION_BLOCKED',static function () use ($act,$blockOfficer,$bid,$blockTarget) { $act('invite',$blockOfficer,$bid,$blockTarget); });
    $friends->change('unblock',$blockLeader,$blockTarget,1); $act('invite',$blockOfficer,$bid,$blockTarget); $pendingId = $service->get($blockTarget)['incomingInvites'][0]['requestId']; $act('remove',$blockLeader,$bid,$blockOfficer);
    $friends->change('block',$blockOfficer,$blockTarget,0); reject('GROUP_RELATION_BLOCKED',static function () use ($act,$blockTarget,$bid,$pendingId) { $act('accept-invite',$blockTarget,$bid,null,$pendingId); }); $act('decline-invite',$blockTarget,$bid,null,$pendingId);
    // Full recipient/confirmation Inboxes roll back every membership/player/pending write.
    $content = ['type' => 'fixture','textKey' => 'fixture','expiresAt' => InboxRules::instant($now + InboxRules::RETENTION)];
    $fill = static function (string $user) use ($inbox,$content,$now): void { for ($i = count($inbox->get($user)['entries']);$i < 50;$i++) $inbox->deliver($user,$content,'groups-full:' . $user . ':' . $i,$now,'fixture'); };
    $fill($full); $rollbackGroup = $newGroup($rollback); $gid = $rollbackGroup['id']; $before = $rev($rollback,$gid);
    reject('INBOX_FULL',static function () use ($act,$rollback,$gid,$full) { $act('invite',$rollback,$gid,$full); }); check($before,$rev($rollback,$gid),'Full Inbox preserves group revision'); check([],$service->get($full)['incomingInvites'],'Full Inbox preserves player pending');
    $acceptOwner = $createUser(); $acceptUser = $createUser(); $acceptGroup = $newGroup($acceptOwner,'request'); $aid = $acceptGroup['id']; $req = $act('request',$acceptUser,$aid)['groups']['outgoingRequests'][0]['requestId']; $fill($acceptUser);
    reject('INBOX_FULL',static function () use ($act,$acceptOwner,$aid,$acceptUser,$req) { $act('accept-request',$acceptOwner,$aid,$acceptUser,$req); }); check(1,$service->detail($acceptOwner,$aid)['memberCount'],'Acceptance Inbox refusal leaves no membership'); check($req,$service->get($acceptUser)['outgoingRequests'][0]['requestId'],'Original pending survives failed acceptance');
    $faultTarget = $createUser(); $beforePlayer = $service->get($faultTarget)['revision']; $pdo->failSql = 'UPDATE groups_state';
    reject('REVISION_CONFLICT',static function () use ($act,$rollback,$gid,$faultTarget) { $act('invite',$rollback,$gid,$faultTarget); });
    check($beforePlayer,$service->get($faultTarget)['revision'],'Final group CAS failure rolls player CAS back'); check([],$inbox->get($faultTarget)['entries'],'Final group CAS failure rolls Inbox back');
    $tx = new SqlTransaction($pdo); $joined = new GroupService($pdo,$clock,$tx); $outerUser = $createUser();
    try { $tx->run(static function () use ($joined,$outerUser) { $joined->create($outerUser,0,'Outer','','open',(object)[]); throw new RuntimeException('outer rollback'); }); } catch (RuntimeException $error) { check('outer rollback',$error->getMessage(),'Outer transaction propagated'); }
    check([],$service->get($outerUser)['groups'],'Outer rollback removes group and membership'); check(0,$service->get($outerUser)['revision'],'Outer rollback removes player CAS');
    $pdo->loseCommit = true; reject('REVISION_CONFLICT',static function () use ($service,$uncertain) { $service->create($uncertain,0,'Lost reply','','open',(object)[]); });
    $originalGroup = $service->get($uncertain)['groups'][0]; reject('REVISION_CONFLICT',static function () use ($service,$uncertain) { $service->create($uncertain,0,'Lost reply','','open',(object)[]); }); check(1,count($service->get($uncertain)['groups']),'Lost create acknowledgement cannot duplicate group');
    $act('delete',$uncertain,$originalGroup['id']); check(2,$service->get($uncertain)['revision'],'Delete retains monotone player revision'); reject('REVISION_CONFLICT',static function () use ($service,$uncertain) { $service->create($uncertain,0,'Old intent','','open',(object)[]); });
    $fresh = $service->create($uncertain,2,'New explicit','','open',(object)[])['group']; check(false,$fresh['id'] === $originalGroup['id'],'New explicit creation allocates a fresh UUID');
    if ($dsn) {
        // An outer InnoDB snapshot may predate a committed role change. Current group
        // locks must not let its old officer projection authorize a new metadata write.
        $fenceLeader = $createUser(); $fenceOfficer = $createUser(); $fenced = $newGroup($fenceLeader); $fid = $fenced['id']; $act('join',$fenceOfficer,$fid); $act('role',$fenceLeader,$fid,$fenceOfficer,null,'officer');
        $otherPdo = connect($target); $otherService = new GroupService($otherPdo,$clock); $tx = new SqlTransaction($pdo); $nested = new GroupService($pdo,$clock,$tx);
        reject('REVISION_CONFLICT',static function () use ($tx,$nested,$pdo,$otherService,$fid,$fenceLeader,$fenceOfficer) {
            $tx->run(static function () use ($nested,$pdo,$otherService,$fid,$fenceLeader,$fenceOfficer) {
                $pdo->query('SELECT revision FROM groups_state')->fetchAll();
                $changed = $otherService->change('role',$fenceLeader,$fid,3,$fenceOfficer,null,'member');
                $nested->update($fenceOfficer,$fid,$changed['group']['revision'],'Forbidden stale officer','','open',(object)[]);
            });
        });
        check('Group',$service->detail($fenceLeader,$fid)['name'],'Revoked officer cannot combine old role with new group revision');
        $snapshotCreator = $createUser();
        reject('REVISION_CONFLICT',static function () use ($tx,$nested,$pdo,$otherService,$snapshotCreator) {
            $tx->run(static function () use ($nested,$pdo,$otherService,$snapshotCreator) {
                $pdo->query('SELECT revision FROM player_groups')->fetchAll();
                $first = $otherService->create($snapshotCreator,0,'Other commit','','open',(object)[]);
                $nested->create($snapshotCreator,$first['groups']['revision'],'Mixed snapshot','','open',(object)[]);
            });
        });
        check(1,count($service->get($snapshotCreator)['groups']),'Outer old player snapshot cannot hide a concurrently created group'); $otherPdo = null;
    }
    // Real separate-connection races: last group slot, cross-group player capacity and duplicate create.
    $lastOwner = $createUser(); $last = $newGroup($lastOwner); $one = worker($target,'join',['user' => $raceA,'group' => $last['id']]); $two = worker($target,'join',['user' => $raceB,'group' => $last['id']]); $results = [finish($one),finish($two)];
    check(1,count(array_filter($results,static function ($r) { return isset($r['group']); })),'Concurrent last slot admits once'); check(2,$service->detail($lastOwner,$last['id'])['memberCount'],'Last slot remains bounded');
    $ownerA = $createUser(); $ownerB = $createUser(); $ga = $newGroup($ownerA); $gb = $newGroup($ownerB);
    $one = worker($target,'join',['user' => $joiner,'group' => $ga['id']]); $two = worker($target,'join',['user' => $joiner,'group' => $gb['id']]); $results = [finish($one),finish($two)];
    check(1,count($service->get($joiner)['groups']),'Concurrent different groups preserve player cap'); check(1,count(array_filter($results,static function ($r) { return isset($r['group']); })),'One cross-group join succeeds');
    $parallelCreator = $createUser(); $one = worker($target,'create',['user' => $parallelCreator]); $two = worker($target,'create',['user' => $parallelCreator]); $results = [finish($one),finish($two)];
    check(1,count($service->get($parallelCreator)['groups']),'Concurrent original create allocates once');
    // Offline cleanup, deterministic officer succession, deleted candidate retry and operator restore.
    $deletedLeader = $createUser(); $successor = $createUser(); $older = $createUser(); $cleanup = $newGroup($deletedLeader); $cid = $cleanup['id']; $act('join',$older,$cid); $now++; $act('join',$successor,$cid); $act('role',$deletedLeader,$cid,$successor,null,'officer');
    $deleteUser($deletedLeader); check(true,$service->cleanupDeletedPlayer($deletedLeader)['complete'],'Offline leader cleanup completes'); check($successor,$service->detail($successor,$cid)['leaderId'],'Officer precedes older member'); check(2,$service->detail($successor,$cid)['memberCount'],'Deleted leader removed');
    $deletedLeader = $createUser(); $deletedCandidate = $createUser(); $survivor = $createUser(); $cleanup = $newGroup($deletedLeader); $cid = $cleanup['id']; $act('join',$deletedCandidate,$cid); $now++; $act('join',$survivor,$cid); $deleteUser($deletedLeader); $deleteUser($deletedCandidate);
    check(false,$service->cleanupDeletedPlayer($deletedLeader)['complete'],'Deleted deterministic successor retains retry anchor'); check(true,$service->cleanupDeletedPlayer($deletedCandidate)['complete'],'Deleted nonleader can clear first'); check(true,$service->cleanupDeletedPlayer($deletedLeader)['complete'],'Leader cleanup resumes'); check($survivor,$service->detail($survivor,$cid)['leaderId'],'Surviving member succeeds');
    $restored = $createUser(); $restoredGroup = $newGroup($restored); $savedRevision = $service->get($restored)['revision']; $deleteUser($restored); $createUser($restored); $service->cleanupDeleted(); check($savedRevision,$service->get($restored)['revision'],'Restore does not reset player revision'); check($restoredGroup['id'],$service->get($restored)['groups'][0]['id'],'Restore preserves membership');
    // Issuer references survive leaving arbitrarily many historical groups, ten per invocation.
    $historicIssuer = $createUser(); $recipients = [];
    for ($i = 0;$i < 23;$i++) {
        $owner = $createUser(); $recipient = $createUser(); $recipients[] = $recipient; $historical = $newGroup($owner); $hid = $historical['id'];
        $act('join',$historicIssuer,$hid); $act('role',$owner,$hid,$historicIssuer,null,'officer'); $act('invite',$historicIssuer,$hid,$recipient); $act('leave',$historicIssuer,$hid);
    }
    check([],$service->get($historicIssuer)['groups'],'Historical issuer has no current membership'); $deleteUser($historicIssuer);
    foreach ([10,10,3] as $index => $expected) { $clean = $service->cleanupDeletedPlayer($historicIssuer); check($expected,$clean['updated'],'Bounded historical cleanup'); check($index === 2,$clean['complete'],'Cleanup retry anchor retained until final page'); }
    foreach ($recipients as $recipient) check([],$service->get($recipient)['incomingInvites'],'Original issuer invitation cancelled');
    // Cursor can move beyond early deleted leaders waiting on later deleted successors.
    for ($i = 0;$i < 11;$i++) {
        $owner = $createUser('00000000-0000-4000-8000-' . sprintf('%012d',100+$i)); $candidate = $createUser('ffffffff-ffff-4fff-8fff-' . sprintf('%012d',100+$i)); $waiting = $newGroup($owner); $act('join',$candidate,$waiting['id']); $deleteUser($owner); $deleteUser($candidate);
    }
    $batch = $service->cleanupDeleted(); check(10,count($batch['results']),'Global cleanup anchor page bounded'); check(true,$batch['nextAfterUserId'] !== null,'Explicit cursor passes blocked early anchors');
    $batch2 = $service->cleanupDeleted($batch['nextAfterUserId']); check(true,count($batch2['results']) > 0,'Later successor anchors reachable');
    // Member query uses exact group/user keyset and <=51 rows amid unrelated memberships.
    $pageOwner = $createUser(); $paged = $newGroup($pageOwner); $pid = $paged['id']; $store = new GroupStore($pdo);
    for ($i = 0;$i < 150;$i++) { $u = $createUser(); $store->addMember($pid,$u,'member',$now); }
    $raw = $store->group($pid); $next = $raw; $next['memberCount'] = 151; $store->saveGroup($raw,$next,InboxRules::instant($now));
    for ($groupIndex = 0;$groupIndex < 4;$groupIndex++) {
        $other = $newGroup($createUser()); for ($i = 0;$i < 499;$i++) $store->addMember($other['id'],Database::uuid(),'member',$now);
        $next = $store->group($other['id']); $before = $next; $next['memberCount'] = 500; $store->saveGroup($before,$next,InboxRules::instant($now));
    }
    $first = $service->members($pageOwner,$pid,null,50); $second = $service->members($pageOwner,$pid,$first['nextAfterUserId'],50); check(50,count($first['members']),'First page50'); check(50,count($second['members']),'Second page50'); check(0,count(array_intersect(array_column($first['members'],'userId'),array_column($second['members'],'userId'))),'Adversarial keyset page disjoint');
    $sql = 'SELECT user_id,role,joined_millis FROM group_members WHERE group_id=? AND user_id>? ORDER BY user_id LIMIT 51'; $explain = $pdo->prepare(($dsn ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ') . $sql); $explain->execute([$pid,$first['nextAfterUserId']]); $plan = $explain->fetchAll();
    check(true,strpos(json_encode($plan),$dsn ? 'PRIMARY' : 'INDEX') !== false,'Indexed member keyset plan');
    $pdo->statements = []; $service->get($pageOwner); $costs['populatedGet'] = counts($pdo);
    $pdo->statements = []; $service->detail($outsider,$pid); $costs['publicDetail'] = counts($pdo);
    $pdo->statements = []; $service->members($pageOwner,$pid,null,50); $costs['members50'] = counts($pdo);
    $costActor = $createUser(); $costOwner = $createUser(); $costGroup = $newGroup($costOwner); $pdo->statements = []; $service->change('join',$costActor,$costGroup['id'],1); $costs['openJoin'] = counts($pdo);
    echo 'Groups SQL statement counts excluding auth/bootstrap: ' . json_encode($costs) . "\n";
    echo 'Groups ' . ($dsn ? 'MySQL' : 'SQLite') . ': ' . $passed . " checks passed (real concurrency, lost acknowledgement, final CAS/Inbox/outer rollback, original issuer blocks, indexed bounded cleanup and pages).\n";
} finally {
    $pdo = null;
    if (!$dsn) foreach ([$target,$target . '-wal',$target . '-shm'] as $path) if (file_exists($path)) unlink($path);
}
