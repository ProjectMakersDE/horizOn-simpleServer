<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/Statistics/StatisticsService.php';
require_once __DIR__ . '/../src/Core/Database.php';

function statsConnection(string $target): PDO {
    $mysql = strpos($target, 'mysql:') === 0;
    $pdo = new PDO($mysql ? $target : 'sqlite:' . $target, $mysql ? getenv('HORIZON_STATISTICS_TEST_USER') : null, $mysql ? getenv('HORIZON_STATISTICS_TEST_PASSWORD') : null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if (!$mysql) { $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA foreign_keys=ON'); }
    return $pdo;
}
if (($argv[1] ?? '') === '--update') {
    try { echo json_encode((new StatisticsService(statsConnection($argv[2]), static function () use ($argv) { return (int)$argv[5]; }))->update($argv[3], $argv[4], [['key' => 'sum', 'value' => 1]]), JSON_THROW_ON_ERROR); }
    catch (StatisticsFailure $failure) { echo json_encode(['code' => $failure->errorCode]); }
    exit(0);
}
$passed = 0;
function verify($expected, $actual, string $message): void { global $passed; if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)); $passed++; }
function denied(string $code, callable $work): void { try { $work(); } catch (StatisticsFailure $failure) { verify($code, $failure->errorCode, 'rejection'); return; } throw new RuntimeException('Expected ' . $code); }
function counter(array $view, string $key): array { foreach ($view['values'] as $value) if ($value['key'] === $key) return $value; throw new RuntimeException('Missing counter ' . $key); }
$dsn = getenv('HORIZON_STATISTICS_TEST_DSN');
if ($dsn && !preg_match('/;dbname=task1005_[a-f0-9]+(?:;|$)/D', $dsn)) throw new RuntimeException('Only a dedicated task1005_<hex> MySQL test database is permitted');
$target = $dsn ?: tempnam(sys_get_temp_dir(), 'horizon-task1005-'); $pdo = null;
try {
    $pdo = statsConnection($target); $pdo->exec(file_get_contents(__DIR__ . '/../migrations/' . ($dsn ? 'mysql' : 'sqlite') . '.sql')); Database::ensureStatisticsConfig($pdo);
    $now = strtotime('2026-10-18T23:59:59Z') * 1000;
    $service = new StatisticsService($pdo, static function () use (&$now) { return $now; });
    $user = Database::uuid(); $other = Database::uuid();
    $insert = $pdo->prepare('INSERT INTO users(id, display_name, anonymous_token) VALUES (?, ?, ?)');
    $insert->execute([$user, 'Statistics', Database::uuid()]); $insert->execute([$other, 'Parallel', Database::uuid()]);
    $defs = []; foreach (['sum', 'min', 'max', 'last'] as $mode) $defs[] = ['key' => $mode, 'name' => $mode, 'mode' => $mode];
    $defs[] = ['key' => 'wide', 'name' => 'Wide', 'mode' => 'sum'];
    $defs[] = ['key' => 'daily', 'name' => 'Daily', 'mode' => 'sum', 'reset' => 'daily']; $defs[] = ['key' => 'weekly', 'name' => 'Weekly', 'mode' => 'sum', 'reset' => 'weekly'];
    denied('INVALID_REQUEST', static function () use ($service) { $service->configure([['key' => 'blank', 'name' => "\u{00A0}\u{2003}", 'mode' => 'sum']], 0); });
    verify(1, $service->configure($defs, 0)['definitionVersion'], 'catalog CAS');
    denied('REVISION_CONFLICT', static function () use ($service, $defs) { $service->configure($defs, 0); });
    verify(false, counter($service->get($user), 'min')['hasValue'], 'positive min is not preinitialized to zero');
    verify(null, $service->get($user, 1)['definitions'], 'conditional catalog');
    $op = static function (int $offset = 0) use (&$now) { return ($now + $offset) . ':' . Database::uuid(); };
    $first = $op(); $batch = [['key' => 'sum', 'value' => 4], ['key' => 'min', 'value' => 8], ['key' => 'max', 'value' => -7], ['key' => 'last', 'value' => 3], ['key' => 'daily', 'value' => 2], ['key' => 'weekly', 'value' => 6]];
    $written = $service->update($user, $first, $batch);
    verify(1, $written['revision'], 'one batch one revision'); verify(8, counter($written, 'min')['value'], 'positive min first report'); verify(-7, counter($written, 'max')['value'], 'negative max first report');
    verify(true, $service->update($user, $first, array_reverse($batch))['replayed'], 'order independent receipt hash');
    denied('OPERATION_CONFLICT', static function () use ($service, $user, $first) { $service->update($user, $first, [['key' => 'sum', 'value' => 9]]); });
    $aggregated = $service->update($user, $op(), [['key' => 'min', 'value' => 5], ['key' => 'max', 'value' => -3], ['key' => 'last', 'value' => -5]]);
    verify(5, counter($aggregated, 'min')['value'], 'min aggregation'); verify(-3, counter($aggregated, 'max')['value'], 'max aggregation'); verify(-5, counter($aggregated, 'last')['value'], 'last aggregation');
    $service->update($user, $op(), [['key' => 'wide', 'value' => StatisticsRules::MAX]]);
    denied('VALUE_OVERFLOW', static function () use ($service, $user, $op) { $service->update($user, $op(), [['key' => 'last', 'value' => 99], ['key' => 'wide', 'value' => 1]]); });
    verify(-5, counter($service->get($user), 'last')['value'], 'overflow rolls whole batch back');
    foreach ([1.5, '1', null, StatisticsRules::MAX + 1] as $value) denied('INVALID_REQUEST', static function () use ($service, $user, $op, $value) { $service->update($user, $op(), [['key' => 'sum', 'value' => $value]]); });
    denied('INVALID_REQUEST', static function () use ($service, $user, $op) { $service->update($user, $op(), [['key' => 'sum', 'value' => 1], ['key' => 'sum', 'value' => 2]]); });
    denied('UNKNOWN_STATISTIC', static function () use ($service, $user, $op) { $service->update($user, $op(), [['key' => 'sum', 'value' => 1], ['key' => 'zz_missing', 'value' => 2]]); });
    verify(4, counter($service->get($user), 'sum')['value'], 'unknown key rolls whole batch back');
    $defs[0]['name'] = 'Renamed'; $defs[0]['maxPerUpdate'] = 10; $service->configure($defs, 1);
    verify(4, counter($service->get($user), 'sum')['value'], 'rename/bound preserve generation');
    denied('UPDATE_ABOVE_LIMIT', static function () use ($service, $user, $op) { $service->update($user, $op(), [['key' => 'sum', 'value' => -11]]); });
    $now += 2000; $period = $service->get($user);
    verify(false, counter($period, 'daily')['hasValue'], 'lazy UTC day reset'); verify(false, counter($period, 'weekly')['hasValue'], 'ISO Monday reset'); verify('2026-W43', counter($period, 'weekly')['period'], 'ISO week label');
    $service->update($user, $op(), [['key' => 'daily', 'value' => 7], ['key' => 'weekly', 'value' => 9]]);
    $now -= 2000;
    denied('STATE_CONFLICT', static function () use ($service, $user) { $service->get($user); });
    denied('STATE_CONFLICT', static function () use ($service, $user, $first, $batch) { $service->update($user, $first, $batch); });
    denied('STATE_CONFLICT', static function () use ($service, $user, $op) { $service->update($user, $op(), [['key' => 'last', 'value' => 8]]); });
    $now += 2000;
    verify(7, counter($service->get($user), 'daily')['value'], 'stale instance cannot reset unmodified daily counter');
    $defs[0]['mode'] = 'last'; $service->configure($defs, 2);
    verify(false, counter($service->get($user), 'sum')['hasValue'], 'mode change starts generation');
    $service->configure([], 3);
    verify(true, $service->update($user, $first, $batch)['replayed'], 'retained receipt before removed-policy error');
    $now += 86400001;
    verify(true, $service->update($user, $first, $batch)['replayed'], 'retained receipt before expiry');
    $service->configure($defs, 4);
    verify(false, counter($service->get($user), 'sum')['hasValue'], 'delete/recreate never restores old values');
    denied('OPERATION_EXPIRED', static function () use ($service, $user, $now) { $service->update($user, ($now - 86400001) . ':' . Database::uuid(), [['key' => 'sum', 'value' => 1]]); });
    denied('OPERATION_IN_FUTURE', static function () use ($service, $user, $op) { $service->update($user, $op(30001), [['key' => 'sum', 'value' => 1]]); });
    for ($index = 1; $index <= 10; $index++) $service->update($user, $op($index), [['key' => 'sum', 'value' => 1]]);
    $stored = StatisticsStorage::decode($pdo->query("SELECT data FROM statistics_state WHERE user_id = '" . $user . "'")->fetch()['data'], $service->config());
    verify(8, count($stored['receipts']), 'bounded receipts'); verify(true, $stored['floor'] > 0, 'persistent eviction floor');
    denied('OPERATION_EXPIRED', static function () use ($service, $user, $first, $batch) { $service->update($user, $first, $batch); });
    denied('OPERATION_EXPIRED', static function () use ($service, $user, $now) { $service->update($user, $now . ':' . Database::uuid(), [['key' => 'sum', 'value' => 1]]); });
    // Outer owner can roll all statistics effects back, including receipt and floor.
    $before = $pdo->query("SELECT data FROM statistics_state WHERE user_id = '" . $user . "'")->fetch()['data'];
    $pdo->beginTransaction(); $service->update($user, $op(20), [['key' => 'sum', 'value' => 9]]); $pdo->rollBack();
    verify($before, $pdo->query("SELECT data FROM statistics_state WHERE user_id = '" . $user . "'")->fetch()['data'], 'real PDO outer rollback');
    $defs[0]['mode'] = 'sum'; $service->configure($defs, 5);
    $jobs = []; $parallelId = $op(21);
    for ($index = 0; $index < 4; $index++) {
        $pipes = []; $process = proc_open([PHP_BINARY, __FILE__, '--update', $target, $other, $parallelId, (string)$now], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start parallel fixture'); fclose($pipes[0]); $jobs[] = [$process, $pipes];
    }
    foreach ($jobs as [$process, $pipes]) {
        $reply = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        verify(0, proc_close($process), 'parallel worker'); if ($error !== '') throw new RuntimeException($error);
        $decoded = json_decode($reply, true, 512, JSON_THROW_ON_ERROR); verify(false, isset($decoded['code']), 'parallel duplicate is safe');
    }
    verify(1, counter($service->get($other), 'sum')['value'], 'concurrent duplicate books once');
    verify(1, $service->get($other)['revision'], 'duplicate writes one receipt/revision');
    $jobs = [];
    for ($index = 0; $index < 4; $index++) {
        $pipes = []; $process = proc_open([PHP_BINARY, __FILE__, '--update', $target, $other, $op(22 + $index), (string)$now], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot start parallel fixture'); fclose($pipes[0]); $jobs[] = [$process, $pipes];
    }
    foreach ($jobs as [$process, $pipes]) {
        $reply = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        verify(0, proc_close($process), 'parallel distinct worker'); if ($error !== '') throw new RuntimeException($error);
        $decoded = json_decode($reply, true, 512, JSON_THROW_ON_ERROR); verify(false, isset($decoded['code']), 'distinct write accepted');
    }
    verify(5, counter($service->get($other), 'sum')['value'], 'concurrent distinct counters never lose an increment');
    verify(5, $service->get($other)['revision'], 'one revision per distinct report');

    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$other]);
    $query = $pdo->prepare('SELECT COUNT(*) FROM statistics_state WHERE user_id = ?'); $query->execute([$other]); verify(0, (int)$query->fetchColumn(), 'delete cascades only own state');
    echo "PASS: {$passed} statistics " . ($dsn ? 'MySQL' : 'SQLite') . " assertions\n";
} finally {
    if ($pdo && isset($user)) { if ($pdo->inTransaction()) $pdo->rollBack(); $pdo->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$user, $other]); }
    $pdo = null;
    if (!$dsn) foreach ([$target, $target . '-wal', $target . '-shm'] as $file) if (file_exists($file)) unlink($file);
}
