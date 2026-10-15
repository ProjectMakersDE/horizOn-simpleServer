<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/PlayerValues/PlayerValuesService.php';
require_once __DIR__ . '/../src/Core/Database.php';

function connectValues(string $path): PDO {
    $mysql = strpos($path, 'mysql:') === 0;
    $pdo = new PDO($mysql ? $path : 'sqlite:' . $path, $mysql ? getenv('HORIZON_VALUES_TEST_USER') : null, $mysql ? getenv('HORIZON_VALUES_TEST_PASSWORD') : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (!$mysql) { $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA foreign_keys=ON'); }
    return $pdo;
}
if (($argv[1] ?? '') === '--purchase') {
    $service = new PlayerValuesService(connectValues($argv[2]), static function () use ($argv) { return (int)$argv[5]; });
    try { echo json_encode($service->purchase($argv[3], 'gems', $argv[4]), JSON_THROW_ON_ERROR); exit(0); }
    catch (PlayerValuesFailure $failure) { echo json_encode(['code' => $failure->errorCode]); exit(0); }
}

$passed = 0;
function check($expected, $actual, string $message): void {
    global $passed;
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    $passed++;
}
function rejected(string $code, callable $action): void {
    try { $action(); } catch (PlayerValuesFailure $failure) { check($code, $failure->errorCode, 'rejection'); return; }
    throw new RuntimeException('Expected rejection ' . $code);
}
function balance(array $view, string $key = 'gold'): int {
    foreach ($view['values'] as $value) if ($value['key'] === $key) return $value['balance'];
    throw new RuntimeException('Missing value');
}

$mysqlDsn = getenv('HORIZON_VALUES_TEST_DSN');
if ($mysqlDsn && !preg_match('/;dbname=task1004_[a-f0-9]+(?:;|$)/D', $mysqlDsn)) throw new RuntimeException('MySQL test requires its own task1004_<hex> database');
$path = $mysqlDsn ?: tempnam(sys_get_temp_dir(), 'horizon-task1004-');
try {
    $pdo = connectValues($path); $pdo->exec(file_get_contents(__DIR__ . '/../migrations/' . ($mysqlDsn ? 'mysql' : 'sqlite') . '.sql'));
    Database::ensurePlayerValuesConfig($pdo);
    $now = (int)floor(microtime(true) * 1000);
    $service = new PlayerValuesService($pdo, static function () use (&$now) { return $now; });
    $user = Database::uuid();
    $pdo->prepare('INSERT INTO users(id, display_name, anonymous_token) VALUES (?, ?, ?)')->execute([$user, 'Test', Database::uuid()]);
    $config = ['definitions' => [
        ['key' => 'gold', 'name' => 'Gold', 'initialBalance' => 100, 'maxBalance' => 1000, 'dailyCap' => 500,
            'adjustment' => ['enabled' => true, 'maxPerCall' => 50, 'dailyLimit' => 100]],
        ['key' => 'gem', 'name' => 'Gem', 'maxBalance' => 100],
    ], 'offers' => [['key' => 'gems', 'name' => 'Gems', 'costs' => [['key' => 'gold', 'amount' => 30]], 'rewards' => [['key' => 'gem', 'amount' => 2]]]]];
    check(1, $service->configure($config, 0)['revision'], 'config revision');
    rejected('REVISION_CONFLICT', static function () use ($service, $config) { $service->configure($config, 0); });
    foreach ([1.5, '10', null, 9007199254740992, -1] as $number) {
        $bad = $config; $bad['definitions'][0]['initialBalance'] = $number;
        rejected('INVALID_REQUEST', static function () use ($service, $bad) { $service->configure($bad, 1); });
    }
    $op = static function (int $offset = 0) use (&$now) { return ($now + $offset) . ':' . Database::uuid(); };
    check(100, balance($service->get($user)), 'initial once');
    check(100, balance($service->get($user)), 'repeat read');
    $first = $op();
    $buy = $service->purchase($user, 'gems', $first);
    check(70, balance($buy), 'cost'); check(2, balance($buy, 'gem'), 'reward');
    check(true, $service->purchase($user, 'gems', $first)['operation']['replayed'], 'duplicate');
    rejected('OPERATION_CONFLICT', static function () use ($service, $user, $first) { $service->purchase($user, 'other', $first); });
    $withoutOffers = $config; $withoutOffers['offers'] = [];
    $service->configure($withoutOffers, 1);
    check(true, $service->purchase($user, 'gems', $first)['operation']['replayed'], 'replay after offer removal');
    rejected('OFFER_NOT_FOUND', static function () use ($service, $user, $op) { $service->purchase($user, 'gems', $op()); });
    $service->configure($config, 2);
    check(60, balance($service->adjust($user, 'gold', -10, 'unlock', $op())), 'adjust debit');
    check(65, balance($service->adjust($user, 'gold', 5, 'earned', $op())), 'adjust credit');
    check(5, $service->get($user)['values'][1]['earnedToday'], 'positive daily counter');
    rejected('ADJUST_NOT_ALLOWED', static function () use ($service, $user, $op) { $service->adjust($user, 'gem', 1, 'test', $op()); });
    rejected('INVALID_REQUEST', static function () use ($service, $user, $op) { $service->adjust($user, 'gold', 1, '', $op()); });
    rejected('ADJUST_ABOVE_LIMIT', static function () use ($service, $user, $op) { $service->adjust($user, 'gold', 51, 'test', $op()); });
    $correct = $op();
    check(0, balance($service->correct($user, [['key' => 'gold', 'balance' => 0]], 'support correction', $correct)), 'zero correction');
    check(0, balance($service->get($user)), 'zero never reinitialized');
    check(true, $service->correct($user, [['key' => 'gold', 'balance' => 0]], 'support correction', $correct)['operation']['replayed'], 'correction replay');
    $before = $service->history($user);
    rejected('MAX_BALANCE_REACHED', static function () use ($service, $user, $now) {
        $service->grant($user, [['key' => 'gold', 'amount' => 5], ['key' => 'gem', 'amount' => 100]], 'too-full', $now, 'test');
    });
    check($before, $service->history($user), 'failed grant all-or-nothing');
    $pdo->beginTransaction();
    $service->grant($user, [['key' => 'gold', 'amount' => 9]], 'outer-rollback', $now, 'fixture');
    $pdo->rollBack();
    check(0, balance($service->get($user)), 'outer transaction rollback');
    check(9, balance($service->grant($user, [['key' => 'gold', 'amount' => 9]], 'outer-rollback', $now, 'fixture')), 'same grant after rollback');
    check(true, $service->grant($user, [['key' => 'gold', 'amount' => 9]], 'outer-rollback', $now, 'fixture')['operation']['replayed'], 'internal grant replay');
    $old = $op(-1000);
    $service->adjust($user, 'gold', 1, 'once', $old);
    for ($i = 0; $i < 51; $i++) { $now++; $service->correct($user, [['key' => 'gold', 'balance' => 100]], 'fixture', $op()); }
    check(50, count($service->history($user)['receipts']), 'bounded receipts');
    rejected('OPERATION_EXPIRED', static function () use ($service, $user, $old) { $service->adjust($user, 'gold', 1, 'once', $old); });
    // Two independent processes share the same SQLite state, followed by overspend races.
    $race = $op(5); $now += 5;
    $run = static function (array $ids) use ($path, $user, &$now) {
        $jobs = [];
        foreach ($ids as $id) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, '--purchase', $path, $user, $id, (string)$now],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start race worker');
            fclose($pipes[0]); $jobs[] = [$process, $pipes];
        }
        $results = [];
        foreach ($jobs as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('Worker failed: ' . $err);
            $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    };
    $results = $run(array_fill(0, 8, $race));
    check(1, count(array_filter($results, static function ($r) { return isset($r['operation']) && !$r['operation']['replayed']; })), 'concurrent duplicate books once');
    check(70, balance($service->get($user)), 'race balance');
    $results = $run([$op(), $op(), $op(), $op()]);
    check(2, count(array_filter($results, static function ($r) { return isset($r['operation']); })), 'only affordable concurrent purchases');
    check(10, balance($service->get($user)), 'no overspend');
    if ($mysqlDsn) {
        $now++;
        $service->correct($user, [['key' => 'gold', 'balance' => 100]], 'snapshot fixture', $op());
        $other = new PlayerValuesService(connectValues($path), static function () use (&$now) { return $now; });
        $pdo->beginTransaction();
        // Establish an older repeatable-read snapshot before another connection commits.
        $pdo->query('SELECT data FROM player_values')->fetchAll();
        $purchaseId = $op();
        check(70, balance($other->purchase($user, 'gems', $purchaseId)), 'other transaction commits purchase');
        check(75, balance($service->grant($user, [['key' => 'gold', 'amount' => 5]], 'snapshot-grant', $now, 'fixture')), 'outer grant reads current committed state');
        $pdo->commit();
        check(75, balance($service->get($user)), 'outer commit keeps concurrent debit');
        check(true, $service->purchase($user, 'gems', $purchaseId)['operation']['replayed'], 'outer grant preserves concurrent receipt');
        $service->correct($user, [['key' => 'gold', 'balance' => 10]], 'restore fixture', $op());
    }
    $numeric = ['definitions' => [['key' => '0', 'name' => 'Zero', 'initialBalance' => 100], ['key' => '1', 'name' => 'One']],
        'offers' => [['key' => 'numeric', 'name' => 'Numeric', 'costs' => [['key' => '0', 'amount' => 1]], 'rewards' => [['key' => '1', 'amount' => 1]]]]];
    $service->configure($numeric, 3); $numericId = $op();
    $numericPurchase = $service->purchase($user, 'numeric', $numericId);
    check(['0', '1'], array_column($numericPurchase['operation']['applied'], 'key'), 'numeric-looking keys stay strings');
    check(['0', '1'], array_column($service->purchase($user, 'numeric', $numericId)['operation']['applied'], 'key'), 'numeric key replay stays strings');
    $service->configure($config, 4);
    $now += 86400000;
    check(0, $service->get($user)['values'][1]['earnedToday'], 'UTC reset');
    check(10, balance($service->get($user)), 'day rollover preserves balance');
    $now -= 86400000;
    rejected('STATE_CONFLICT', static function () use ($service, $user) { $service->get($user); });
    echo "Player values: {$passed} assertions passed (SQL storage, rollback, real process races).\n";
} finally {
    $pdo = null;
    if (!$mysqlDsn) foreach ([$path, $path . '-wal', $path . '-shm'] as $file) if (file_exists($file)) unlink($file);
}
