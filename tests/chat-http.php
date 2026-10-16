<?php

declare(strict_types=1);
$base = $argv[1] ?? ''; $key = $argv[2] ?? ''; $user = $argv[3] ?? ''; $token = $argv[4] ?? '';
if (!preg_match('/^http:\/\/localhost:[0-9]{1,5}\/api\/v1\/app$/D', $base) || $key !== 'test-key-integration-12345') {
    throw new RuntimeException('Only suite-owned loopback fixture allowed');
}
$server = substr($base, 0, -strlen('/api/v1/app')); $checks = 0;
function check($want, $actual, string $label): void {
    global $checks; if ($want !== $actual) throw new RuntimeException($label . ': expected ' . json_encode($want) . ', got ' . json_encode($actual)); $checks++;
}
function request(string $method, string $path, $body = null, ?string $api = null, ?string $bearer = null, ?string $account = null): array {
    global $server;
    $headers = ['Content-Type: application/json'];
    if ($api !== null) $headers[] = 'X-API-Key: ' . $api;
    if ($bearer !== null) $headers[] = 'Authorization: Bearer ' . $bearer;
    if ($account !== null) $headers[] = 'X-Account-API-Key: ' . $account;
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5];
    if ($body !== null) $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    $raw = file_get_contents($server . $path, false, stream_context_create(['http' => $options]));
    if ($raw === false || !preg_match('/^HTTP\/\S+ ([0-9]{3})/', $http_response_header[0] ?? '', $match)) throw new RuntimeException('Fixture HTTP failed');
    return [(int)$match[1], json_decode($raw, true, 64, JSON_THROW_ON_ERROR)];
}
$id = '00000000-0000-4000-8000-000000001014'; $message = '00000000-0000-4000-8000-000000002014';
$prefix = '/api/v1/app/chat';
$cases = [
    ['GET', '/limits', null],
    ['POST', '/channels/open', ['kind' => 'project']],
    ['POST', '/channels/' . $id . '/sync', ['epoch' => null, 'knownMessageIds' => []]],
    ['POST', '/channels/' . $id . '/messages', ['operationId' => $message, 'issuedAt' => '2026-10-16T12:00:00.000Z', 'text' => "😀\nplain text"]],
    ['GET', '/operations/' . $message, null],
    ['POST', '/channels/' . $id . '/messages/' . $message . '/report', ['reason' => 'Original reason']],
];
foreach ($cases as [$method, $suffix, $body]) {
    $path = $prefix . $suffix . ($method === 'GET' ? '?userId=' . $user : '');
    $body = $body === null ? null : array_merge($body, ['userId' => $user]);
    foreach ([[null, $token, 401, 'UNAUTHORIZED'], [$key, null, 401, 'SESSION_REQUIRED'], [$key, 'unknown-session', 401, 'SESSION_REQUIRED'], [$key, $token, 501, 'NOT_SUPPORTED']] as [$api, $session, $status, $code]) {
        [$actual, $result] = request($method, $path, $body, $api, $session);
        check($status, $actual, $method . $suffix . ' auth/capability status');
        check($code, $result['code'], 'Structured boundary code');
        if ($status === 501) {
            check(true, strpos($result['message'], 'Cloud only') !== false, 'Hosted-only reason');
            check(false, array_key_exists('channel', $result) || array_key_exists('receipt', $result) || array_key_exists('limits', $result), 'No fabricated supported payload');
        }
    }
    $foreignPath = $prefix . $suffix . ($method === 'GET' ? '?userId=' . $id : '');
    [$actual, $result] = request($method, $foreignPath, $body === null ? null : array_replace($body, ['userId' => $id]), $key, $token);
    check(403, $actual, 'Foreign player cannot gain capability access'); check('SESSION_FORBIDDEN', $result['code'], 'Foreign identity code');
}
foreach ([['GET', $prefix . '/limits', null], ['POST', $prefix . '/channels/open', ['kind' => 'project']]] as [$method, $path, $body]) {
    [$actual, $result] = request($method, $path, $body, $key, $token); check(400, $actual, 'Missing player identity'); check('INVALID_REQUEST', $result['code'], 'Missing user code');
}
[$actual, $result] = request('GET', $prefix . '/limits?userId=' . $user, null, null, $token, 'fixture-account-key');
check(401, $actual, 'Account Key cannot replace project API key'); check('UNAUTHORIZED', $result['code'], 'Account/app scope stays distinct');
$admin = '/api/v1/admin/features/chat/' . $id;
$operators = [
    ['GET', '/config'], ['PUT', '/config'], ['GET', '/limits'], ['GET', '/channels'], ['GET', '/channels/' . $id],
    ['DELETE', '/channels/' . $id . '/messages/' . $message], ['GET', '/reports'], ['POST', '/reports/' . $message . '/resolve'],
    ['DELETE', '/reports/' . $message], ['GET', '/players/' . $user . '/moderation'], ['PUT', '/players/' . $user . '/moderation'], ['POST', '/cleanup'],
];
foreach ($operators as [$method, $suffix]) {
    foreach ([[null, null], [$key, $token]] as [$api, $bearer]) {
        [$actual, $result] = request($method, $admin . $suffix, ['malicious' => 'must not be persisted'], $api, $bearer);
        check(501, $actual, 'Operator route is unsupported regardless of app credentials'); check('NOT_SUPPORTED', $result['code'], 'No app moderation authority');
        check(['error', 'message', 'code'], array_keys($result), 'Only constant refusal, no private state');
    }
}
// Disable the database classes specifically; -n can also remove mandatory JSON on modular PHP7.4.
$pipes = []; $process = proc_open([PHP_BINARY, '-d', 'disable_classes=PDO,SQLite3', __DIR__ . '/../bin/chat.php', 'send'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir());
if (!is_resource($process)) throw new RuntimeException('CLI fixture failed');
fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
check(1, proc_close($process), 'Unsupported CLI exits nonzero without config/PDO'); check('', $out, 'CLI never reports success');
$cli = json_decode($err, true, 64, JSON_THROW_ON_ERROR); check('NOT_SUPPORTED', $cli['code'], 'CLI code'); check(501, $cli['status'], 'CLI status');
require_once __DIR__ . '/../src/Core/Config.php'; require_once __DIR__ . '/../src/Core/Database.php'; Config::load(dirname(__DIR__) . '/.env');
check([], Database::connect()->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'chat_%'")->fetchAll(PDO::FETCH_COLUMN), 'No Chat persistence created');
echo 'PASS: ' . $checks . " Chat HTTP/auth/unsupported checks, all6 app +12 admin routes and config-free CLI; no Chat tables\n";
