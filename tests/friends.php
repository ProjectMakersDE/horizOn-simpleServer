<?php

declare(strict_types=1);
require_once __DIR__.'/inbox-fixtures.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Friends/FriendsService.php';

class FriendsProbePDO extends PDO
{
    public bool $loseCommit = false;
    public int $writes = 0;
    private function observe(string $sql): void { if (preg_match('/^\s*(INSERT|UPDATE|DELETE)/i', $sql)) $this->writes++; }
    private function acknowledge(): void { if ($this->loseCommit) { $this->loseCommit = false; throw new RuntimeException('Injected lost commit acknowledgement'); } }
    #[\ReturnTypeWillChange]
    public function prepare($sql, $options = []) { $this->observe($sql); return parent::prepare($sql, $options); }
    #[\ReturnTypeWillChange]
    public function exec($sql) { $this->observe($sql); $result = parent::exec($sql); if (strtoupper(trim($sql)) === 'COMMIT') $this->acknowledge(); return $result; }
    #[\ReturnTypeWillChange]
    public function commit() { $result = parent::commit(); $this->acknowledge(); return $result; }
}
function connection(string $target): FriendsProbePDO
{
    $mysql = strpos($target, 'mysql:') === 0;
    $pdo = new FriendsProbePDO($mysql ? $target : 'sqlite:' . $target, $mysql ? getenv('HORIZON_FRIENDS_TEST_USER') : null, $mysql ? getenv('HORIZON_FRIENDS_TEST_PASSWORD') : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (!$mysql) { $pdo->exec('PRAGMA foreign_keys=ON'); $pdo->exec('PRAGMA journal_mode=WAL'); }
    return $pdo;
}
if (($argv[1] ?? '') === '--change') {
    try { $service = new FriendsService(connection($argv[2]), static function () use ($argv) { return (int)$argv[8]; }); echo InboxRules::json($service->change($argv[3], $argv[4], $argv[5], (int)$argv[6], $argv[7] === '-' ? null : $argv[7])); }
    catch (FriendsFailure $error) { echo json_encode(['code' => $error->errorCode]); }
    exit;
}
$passed = 0;
function check($expected, $actual, string $label): void { global $passed; if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)); $passed++; }
function reject(string $code, callable $work): void
{
    try { $work(); }
    catch (FriendsFailure $error) { check($code, $error->errorCode, 'Friends rejection'); return; }
    catch (InboxFailure $error) { check($code, $error->errorCode, 'Inbox rejection'); return; }
    throw new RuntimeException('Expected rejection ' . $code);
}
function worker(string $target, string $action, string $user, string $peer, int $revision, int $now, ?string $requestId = null): array
{
    $pipes = []; $process = proc_open([PHP_BINARY, __FILE__, '--change', $target, $action, $user, $peer, (string)$revision, $requestId ?? '-', (string)$now], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Worker did not start'); fclose($pipes[0]); return [$process, $pipes];
}
function finish(array $job): array
{
    [$process, $pipes] = $job; $reply = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(0, proc_close($process), 'Worker exit'); if ($error !== '') throw new RuntimeException($error); return json_decode($reply, true, 512, JSON_THROW_ON_ERROR);
}
$dsn = getenv('HORIZON_FRIENDS_TEST_DSN');
if ($dsn && !preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1009_[a-f0-9]+;charset=utf8mb4$/D', $dsn)) throw new RuntimeException('Only explicit loopback task1009_<hex> test DB is permitted');
$target = $dsn ?: tempnam(sys_get_temp_dir(), 'horizon-task1009-'); $pdo = null;
try {
    $pdo = connection($target); $pdo->exec(file_get_contents(__DIR__ . '/../migrations/' . ($dsn ? 'mysql' : 'sqlite') . '.sql'));
    Database::ensurePlayerValuesConfig($pdo); Database::ensureInboxConfig($pdo); Database::ensureUserActivityColumns($pdo);
    $now = (int)(strtotime('2026-10-16T12:00:00Z') * 1000); $clock = static function () use (&$now) { return $now; };
    $service = new FriendsService($pdo, $clock); $inbox = new InboxService($pdo, $clock);
    $insert = $pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at,last_login_millis,email,session_token,unlocks) VALUES(?,?,?,?,?,?,?,?)');
    $create = static function () use ($insert, &$now): string { $id = Database::uuid(); $insert->execute([$id, 'Public Player', Database::uuid(), '2026-10-16 12:00:00', $now - 1000, 'private@example.invalid', 'private-session', '["private-unlock"]']); return $id; };
    $users = []; for ($i = 0; $i < 20; $i++) $users[] = $create();
    [$a, $b, $c, $d, $e, $full, $rollback, $second, $outer, $outerPeer, $uncertain, $uncertainPeer, $parallel, $gone, $deletionActor] = $users;
    $empty = $service->get($a); check(0, $empty['revision'], 'Lazy initial revision'); check(200, $empty['limits']['maxFriends'], 'Selfhost maximum');
    check($a, FriendsRules::decode($empty['friendCode']), 'Canonical public code roundtrip');
    foreach ([null, -1, 1.5, '1', 9007199254740992] as $bad) reject('INVALID_REQUEST', static function () use ($service, $a, $b, $bad) { $service->change('request', $a, $b, $bad); });
    foreach (['name', 'private@example.invalid', $empty['friendCode'] . "\n", 'H-AAAAAAAAAAAAAAAAAAAAAB', $a . "\n"] as $bad) reject('INVALID_REQUEST', static function () use ($service, $a, $bad) { $service->find($a, $bad); });
    $found = $service->find($a, FriendsRules::code($b)); check(['userId', 'friendCode', 'display'], array_keys($found), 'Lookup contains only public fields');
    check(['name', 'profile'], array_keys($found['display']), 'Bounded Player Profile display'); check(false, strpos(InboxRules::json($found), 'private') !== false, 'No email, token or unlock disclosure');
    reject('INVALID_REQUEST', static function () use ($service, $a) { $service->change('request', $a, $a, 0); });
    $request = $service->change('request', $a, $b, 0); $requestId = $request['outgoing'][0]['requestId'];
    check(1, $request['revision'], 'Sender revision'); check($requestId, $service->get($b)['incoming'][0]['requestId'], 'Same request on both sides'); check(1, count($inbox->get($b)['entries']), 'Atomic recipient notification');
    reject('REVISION_CONFLICT', static function () use ($service, $a, $b) { $service->change('request', $a, $b, 0); });
    $pdo->writes = 0; check(1, $service->change('request', $a, $b, 1)['revision'], 'Reviewed repeated request is noop'); check(0, $pdo->writes, 'Noop writes nothing'); check(1, count($inbox->get($b)['entries']), 'No duplicate notice');
    $cross = $service->change('request', $b, $a, 1); check(1, count($cross['incoming']), 'Crossed request remains pending'); check([], $cross['friends'], 'No automatic acceptance');
    $accepted = $service->change('accept', $a, $b, 2, $cross['outgoing'][0]['requestId']);
    check($b, $accepted['friends'][0]['userId'], 'Friend on actor'); check($a, $service->get($b)['friends'][0]['userId'], 'Friend on counterpart');
    check($accepted['friends'][0]['since'], $service->get($b)['friends'][0]['since'], 'Identical friendship time');
    check(InboxRules::instant($now - 1000), $accepted['friends'][0]['lastActiveAt'], 'Latest known login, not HTTP activity');
    check([], $accepted['incoming'], 'Crossed incoming consumed'); check([], $accepted['outgoing'], 'Crossed outgoing consumed');
    check(1, count($inbox->get($b)['entries']), 'Accepted confirmation remains; resolved request disappears');check('friend_accept',$inbox->get($b)['entries'][0]['type'],'Authoritative accepted notice');
    $removed = $service->change('remove', $a, $b, 3); check([], $removed['friends'], 'Remove actor friendship'); check([], $service->get($b)['friends'], 'Remove peer friendship');
    $blocked = $service->change('block', $a, $b, 4); check(true, $service->blocked($a, $b), 'Shared bilateral block policy'); check(true, $service->blocked($b, $a), 'Either block direction applies');
    reject('RELATIONSHIP_NOT_ALLOWED', static function () use ($service, $b, $a) { $service->change('request', $b, $a, 4); });
    reject('PLAYER_NOT_FOUND', static function () use ($service, $b, $a) { $service->find($b, $a); }); reject('PLAYER_NOT_FOUND', static function () use ($service, $a, $b) { $service->find($a, $b); });
    $unblocked = $service->change('unblock', $a, $b, $blocked['revision']); check([], $unblocked['friends'], 'Unblock never restores friendship'); check(false, $service->blocked($a, $b), 'Unblock removes only own block');
    $first = $service->change('request', $c, $d, 0); $oldId = $first['outgoing'][0]['requestId'];
    $service->change('decline', $d, $c, 1, $oldId); $replacement = $service->change('request', $c, $d, 2); $newId = $replacement['outgoing'][0]['requestId'];
    check(false, $oldId === $newId, 'New explicit request gets another identity');
    reject('FRIEND_REQUEST_NOT_FOUND', static function () use ($service, $d, $c, $oldId) { $service->change('accept', $d, $c, 3, $oldId); });
    check($newId, $service->get($d)['incoming'][0]['requestId'], 'Old decision cannot affect replacement');
    $service->change('cancel', $c, $d, 3, $newId); check([], $service->get($d)['incoming'], 'Exact cancellation clears counterpart');
    $toE = $service->change('request', $c, $e, 4); $service->change('accept', $e, $c, 1, $toE['outgoing'][0]['requestId']);
    $toC = $service->change('request', $d, $c, 4); $limited = new FriendsService($pdo, $clock, null, 1);
    reject('FRIEND_LIMIT_REACHED', static function () use ($limited, $c, $d, $toC) { $limited->change('accept', $c, $d, 7, $toC['outgoing'][0]['requestId']); });
    check(1, count($limited->get($c)['friends']), 'Capacity refusal retains existing friend'); check(1, count($service->get($c)['incoming']), 'Capacity refusal retains pending request');
    $content = ['type' => 'fixture', 'textKey' => 'fixture', 'expiresAt' => InboxRules::instant($now + InboxRules::RETENTION)];
    fillInboxFixture($pdo,$full,$now);
    reject('INBOX_FULL', static function () use ($service, $rollback, $full) { $service->change('request', $rollback, $full, 0); });
    check(0, $service->get($rollback)['revision'], 'Full Inbox rolls first CAS back'); check(0, $service->get($full)['revision'], 'Full Inbox rolls second CAS back'); check(50, count($inbox->get($full)['entries']), 'Existing Inbox preserved');
    $acceptSender = $create(); $acceptReceiver = $create();
    $acceptPending = $service->change('request', $acceptSender, $acceptReceiver, 0);
    $acceptId = $acceptPending['outgoing'][0]['requestId'];
    fillInboxFixture($pdo,$acceptSender,$now);
    reject('INBOX_FULL', static function () use ($service, $acceptSender, $acceptReceiver, $acceptId) { $service->change('accept', $acceptReceiver, $acceptSender, 1, $acceptId); });
    check(1, $service->get($acceptReceiver)['revision'], 'Full confirmation Inbox rolls accepting revision back');
    check($acceptId, $service->get($acceptReceiver)['incoming'][0]['requestId'], 'Failed acceptance retains exact incoming intent');
    check($acceptId, $service->get($acceptSender)['outgoing'][0]['requestId'], 'Failed acceptance retains exact outgoing intent');
    check([], $service->get($acceptSender)['friends'], 'Failed confirmation cannot leave one-sided friendship');
    $boundaryA = FriendsRules::emptyState(); $boundaryB = FriendsRules::emptyState();
    for ($i = 0; $i < 20; $i++) $boundaryA['outgoing'][] = ['userId' => Database::uuid(), 'requestId' => Database::uuid(), 'createdAt' => InboxRules::instant($now)];
    reject('FRIEND_REQUEST_LIMIT_REACHED', static function () use ($a, $b, $boundaryA, $boundaryB, $now) { FriendsRules::transition('request', $a, $b, $boundaryA, $boundaryB, null, InboxRules::instant($now), 200); });
    $boundaryB['incoming'] = $boundaryA['outgoing']; $boundaryA['outgoing'] = [];
    reject('FRIEND_REQUEST_LIMIT_REACHED', static function () use ($a, $b, $boundaryA, $boundaryB, $now) { FriendsRules::transition('request', $a, $b, $boundaryA, $boundaryB, null, InboxRules::instant($now), 200); });
    for ($i = 0; $i < 100; $i++) $boundaryA['blocked'][] = ['userId' => Database::uuid(), 'blockedAt' => InboxRules::instant($now)];
    reject('BLOCK_LIMIT_REACHED', static function () use ($a, $b, $boundaryA, $boundaryB, $now) { FriendsRules::transition('block', $a, $b, $boundaryA, $boundaryB, null, InboxRules::instant($now), 200); });
    $triggerUser = $second;
    $pdo->exec($dsn ? "CREATE TRIGGER friends_failure BEFORE INSERT ON friends_state FOR EACH ROW BEGIN IF NEW.user_id='$triggerUser' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='second CAS failure'; END IF; END"
        : "CREATE TRIGGER friends_failure BEFORE INSERT ON friends_state WHEN NEW.user_id='$triggerUser' BEGIN SELECT RAISE(ABORT,'second CAS failure'); END");
    try { $service->change('request', $rollback, $second, 0); throw new RuntimeException('Expected second CAS failure'); }
    catch (PDOException $expected) {} catch (FriendsFailure $expected) { check('REVISION_CONFLICT', $expected->errorCode, 'Safe SQLite injected failure'); }
    $pdo->exec('DROP TRIGGER friends_failure'); check(0, $service->get($rollback)['revision'], 'Second CAS failure rolls actor insert back'); check(0, $service->get($second)['revision'], 'Failed counterpart insert absent');
    $scope = new SqlTransaction($pdo); $joined = new FriendsService($pdo, $clock, $scope); $joinedInbox = new InboxService($pdo, $clock, $scope);
    try { $scope->run(static function () use ($joined, $joinedInbox, $outer, $outerPeer, $content, &$now) { $joined->change('block', $outer, $outerPeer, 0); $joinedInbox->freeText($outerPeer,Database::uuid(),0,$now,$now+60000,'outer rollback'); throw new RuntimeException('outer rollback'); }); }
    catch (RuntimeException $expected) {}
    check(0, $service->get($outer)['revision'], 'Shared caller scope rolls actor back'); check([], $inbox->get($outerPeer)['entries'], 'Same PDO rolls other repository back');
    $pdo->loseCommit = true; reject('REVISION_CONFLICT', static function () use ($service, $uncertain, $uncertainPeer) { $service->change('request', $uncertain, $uncertainPeer, 0); });
    check(1, $service->get($uncertain)['revision'], 'Lost response may already have committed');
    reject('REVISION_CONFLICT', static function () use ($service, $uncertain, $uncertainPeer) { $service->change('request', $uncertain, $uncertainPeer, 0); });
    check(1, count($inbox->get($uncertainPeer)['entries']), 'Original revision cannot duplicate uncertain notice');
    $jobs = []; $parallelPeers = []; for ($i = 0; $i < 4; $i++) { $peer = $create(); $parallelPeers[] = $peer; $jobs[] = worker($target, 'request', $parallel, $peer, 0, $now); }
    $wins = 0; foreach ($jobs as $job) { $result = finish($job); if (isset($result['code'])) check('REVISION_CONFLICT', $result['code'], 'Concurrent loser fails closed'); else $wins++; }
    check(1, $wins, 'Exactly one concurrent actor revision wins'); check(1, count($service->get($parallel)['outgoing']), 'Concurrent requests do not overbook');
    $notices = 0; foreach ($parallelPeers as $peer) $notices += count($inbox->get($peer)['entries']); check(1, $notices, 'Only committed concurrent notice delivered');
    $raceA = $create(); $raceB = $create(); $raceRequest = $service->change('request', $raceA, $raceB, 0);
    $acceptJob = worker($target, 'accept', $raceB, $raceA, 1, $now, $raceRequest['outgoing'][0]['requestId']);
    $blockJob = worker($target, 'block', $raceA, $raceB, 1, $now);
    $raceReplies = [finish($acceptJob), finish($blockJob)];
    check(1, count(array_filter($raceReplies, static function ($reply) { return !isset($reply['code']); })), 'Only one accept/block collision commits');
    foreach ($raceReplies as $reply) if (isset($reply['code'])) check('REVISION_CONFLICT', $reply['code'], 'Accept/block loser reconciles explicit revision');
    $raceSnapshot = $service->get($raceA); check(false, count($raceSnapshot['friends']) > 0 && $service->blocked($raceA, $raceB), 'No friendship survives a committed block');
    if ($dsn) {
        $snapshotA = $create(); $snapshotB = $create(); $service->change('block', $snapshotA, $snapshotB, 0);
        $scope->run(static function () use ($pdo, $joined, $snapshotA, $snapshotB, $target, $clock) {
            $pdo->query("SELECT blocked FROM friends_state WHERE user_id='$snapshotA'")->fetchColumn();
            $other = new FriendsService(connection($target), $clock); $other->change('unblock', $snapshotA, $snapshotB, 1);
            check(true, $joined->blocked($snapshotA, $snapshotB), 'Block projection retains the established caller MySQL snapshot');
        });
        check(false, $service->blocked($snapshotA, $snapshotB), 'A new policy scope observes the committed unblock');
    }
    $owners = []; for ($i = 0; $i < 63; $i++) { $owner = $create(); $owners[] = $owner; $service->change('block', $owner, $gone, 0); }
    check(0, $service->get($gone)['revision'], 'Block-only target anchor preserves public revision zero');
    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$gone]);
    check(null, $pdo->query("SELECT active_user_id FROM friends_state WHERE user_id='$gone'")->fetchColumn(), 'Deleted player anchor survives physical deletion');
    $explain = $pdo->query(($dsn ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ') . "SELECT user_id FROM friends_state WHERE active_user_id IS NULL ORDER BY user_id LIMIT 10")->fetchAll();
    check(true, strpos(InboxRules::json($explain), 'idx_friends_deleted') !== false, 'Deleted anchor query uses covering bounded index');
    $explain = $pdo->query(($dsn ? 'EXPLAIN ' : 'EXPLAIN QUERY PLAN ') . "SELECT owner_user_id FROM friends_references WHERE related_user_id='$gone' ORDER BY owner_user_id LIMIT 51")->fetchAll();
    check(true, strpos(InboxRules::json($explain), 'idx_friends_related') !== false, 'Reverse cleanup uses related-player index');
    $orderedOwners = $owners; sort($orderedOwners, SORT_STRING); $stopAt = $orderedOwners[7];
    $pdo->exec($dsn ? "CREATE TRIGGER friends_cleanup_failure BEFORE UPDATE ON friends_state FOR EACH ROW BEGIN IF OLD.user_id='$stopAt' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='interrupted cleanup'; END IF; END"
        : "CREATE TRIGGER friends_cleanup_failure BEFORE UPDATE ON friends_state WHEN OLD.user_id='$stopAt' BEGIN SELECT RAISE(ABORT,'interrupted cleanup'); END");
    try { $service->cleanupDeletedPlayer($gone); throw new RuntimeException('Expected interrupted cleanup'); }
    catch (PDOException $expected) {} catch (FriendsFailure $expected) { check('REVISION_CONFLICT', $expected->errorCode, 'Safe interrupted SQLite cleanup'); }
    $pdo->exec('DROP TRIGGER friends_cleanup_failure');
    check(56, (int)$pdo->query("SELECT COUNT(*) FROM friends_references WHERE related_user_id='$gone'")->fetchColumn(), 'Interrupted cleanup preserves exactly its unprocessed peers');
    check(1, (int)$pdo->query("SELECT COUNT(*) FROM friends_state WHERE user_id='$gone'")->fetchColumn(), 'Interrupted cleanup preserves deletion retry anchor');
    $clean = $service->cleanupDeletedPlayer($gone); check(50, $clean['updated'], 'Deletion cleanup page is bounded'); check(false, $clean['complete'], 'Deletion anchor retained for later page');
    check(6, (int)$pdo->query("SELECT COUNT(*) FROM friends_references WHERE related_user_id='$gone'")->fetchColumn(), 'Unprocessed references retained');
    $clean = $service->cleanupDeletedPlayer($gone); check(6, $clean['updated'], 'Next cleanup resumes remaining references'); check(true, $clean['complete'], 'Cleanup completes');
    foreach ($owners as $owner) check([], $service->get($owner)['blocked'], 'Incoming block reference removed with CAS');
    check(true, $service->cleanupDeletedPlayer($gone)['complete'], 'Repeated cleanup is safe'); check([], $service->cleanupDeleted(), 'No lifetime/global player scan needed');
    $restored = '00000000-0000-0000-0000-000000000001'; $laterDeleted = '00000000-0000-0000-0000-000000000002';
    foreach ([$restored, $laterDeleted] as $id) $insert->execute([$id, 'Restorable fixture', Database::uuid(), '2026-10-16', $now, null, null, null]);
    $restoreOwner = $create(); $laterOwner = $create(); $service->change('block', $restoreOwner, $restored, 0); $service->change('block', $laterOwner, $laterDeleted, 0);
    foreach ([$restored, $laterDeleted] as $id) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    $insert->execute([$restored, 'Restored fixture', Database::uuid(), '2026-10-16', $now, null, null, null]);
    reject('STATE_CONFLICT', static function () use ($service, $restored) { $service->cleanupDeletedPlayer($restored); });
    $restoredBatch = $service->cleanupDeleted(); check(true, $restoredBatch[0]['restored'], 'Restored first anchor is explicitly skipped');
    check($restored, $pdo->query("SELECT active_user_id FROM friends_state WHERE user_id='$restored'")->fetchColumn(), 'Restore rebinds only FK anchor');
    check(true, $service->blocked($restoreOwner, $restored), 'Restore preserves relationship contents');
    check([], $service->get($laterOwner)['blocked'], 'Restored first anchor does not starve the next deletion');
    check([], $service->cleanupDeleted(), 'Restored anchor no longer poisons later batches');
    $raceTarget = $create(); $raceJob = null;
    $scope->run(static function () use ($pdo, $raceTarget, $target, $deletionActor, &$raceJob, &$now) { $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$raceTarget]); $raceJob = worker($target, 'block', $deletionActor, $raceTarget, 0, $now); usleep(150000); });
    $raceResult = finish($raceJob); check('PLAYER_NOT_FOUND', $raceResult['code'], 'First-reference creation cannot commit behind deletion'); check(0, $service->get($deletionActor)['revision'], 'Deletion race leaves no actor state');
    echo 'PASS: ' . $passed . ' friends ' . ($dsn ? 'MySQL' : 'SQLite') . " assertions\n";
} finally {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack(); $pdo = null;
    if (!$dsn) foreach ([$target, $target . '-wal', $target . '-shm'] as $file) if (file_exists($file)) unlink($file);
}
