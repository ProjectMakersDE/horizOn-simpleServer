<?php

declare(strict_types=1);
$base = $argv[1] ?? ''; $key = $argv[2] ?? ''; $actor = $argv[3] ?? ''; $token = $argv[4] ?? '';
if (!preg_match('/^http:\/\/localhost:[0-9]{1,5}\/api\/v1\/app$/D', $base) || $key !== 'test-key-integration-12345') throw new RuntimeException('Only the integration suite loopback fixture is permitted');
$passed = 0;
function check($expected, $actual, string $label): void { global $passed; if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)); $passed++; }
function callApi(string $method, string $path, $body = null, ?string $session = null): array
{
    global $base, $key;
    $headers = ['X-API-Key: ' . $key, 'Content-Type: application/json']; if ($session !== null) $headers[] = 'Authorization: Bearer ' . $session;
    $options = ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5];
    if ($body !== null) $options['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    $raw = file_get_contents($base . $path, false, stream_context_create(['http' => $options]));
    if ($raw === false || !preg_match('/^HTTP\/\S+ ([0-9]{3})/', $http_response_header[0] ?? '', $match)) throw new RuntimeException('Fixture HTTP failure');
    return [(int)$match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
[$status] = callApi('GET', '/friends?userId=' . $actor); check(401, $status, 'Friends require Bearer');
[$status] = callApi('GET', '/friends?userId=00000000-0000-0000-0000-000000000009', null, $token); check(403, $status, 'Foreign player session rejected');
[, $signup] = callApi('POST', '/user-management/signup', ['type' => 'ANONYMOUS', 'username' => 'FriendPeer']); $peer = $signup['userId'];
[, $signin] = callApi('POST', '/user-management/signin', ['type' => 'ANONYMOUS', 'anonymousToken' => $signup['anonymousToken']]); $peerToken = $signin['accessToken'];
[, $snapshot] = callApi('GET', '/friends?userId=' . $actor, null, $token); check(0, $snapshot['revision'], 'Initial actor revision');
[, $peerState] = callApi('GET', '/friends?userId=' . $peer, null, $peerToken);
[$status, $found] = callApi('GET', '/friends/find?userId=' . $actor . '&query=' . urlencode($peerState['friendCode']), null, $token);
check(200, $status, 'Exact canonical H-code lookup'); check('FriendPeer', $found['display']['name'], 'Public display from profile projection'); check(['userId', 'friendCode', 'display'], array_keys($found), 'Lookup omits private data/activity');
[$status] = callApi('GET', '/friends/find?userId=' . $actor . '&query=Friend', null, $token); check(400, $status, 'No name prefix search');
foreach (['null', '1.0', '"0"', '-1', '9007199254740992'] as $revision) {
    $body = '{"userId":"' . $actor . '","targetUserId":"' . $peer . '","expectedRevision":' . $revision . '}';
    [$status] = callApi('POST', '/friends/request', $body, $token); check(400, $status, 'Revision does not coerce ' . $revision);
}
[$status] = callApi('POST', '/friends/request', ['userId' => $actor, 'targetUserId' => $peer], $token); check(400, $status, 'Missing revision cannot mean zero');
$body = ['userId' => $actor, 'targetUserId' => $peer, 'expectedRevision' => 0];
[$status, $request] = callApi('POST', '/friends/request', $body, $token); check(200, $status, 'Send request'); $requestId = $request['outgoing'][0]['requestId'];
[$status] = callApi('POST', '/friends/request', $body, $token); check(409, $status, 'Uncertain original revision never repeats');
[, $incoming] = callApi('GET', '/friends?userId=' . $peer, null, $peerToken); check($requestId, $incoming['incoming'][0]['requestId'], 'Exact bilateral request ID');
[$status] = callApi('POST', '/friends/decline', ['userId' => $peer, 'targetUserId' => $actor, 'expectedRevision' => 1], $peerToken); check(400, $status, 'Pending decision requires request ID');
[$status] = callApi('POST', '/friends/decline', ['userId' => $peer, 'targetUserId' => $actor, 'expectedRevision' => 1, 'requestId' => $requestId], $peerToken); check(200, $status, 'Explicit decline');
$body['expectedRevision'] = 2; [, $request] = callApi('POST', '/friends/request', $body, $token); $newId = $request['outgoing'][0]['requestId'];
[$status] = callApi('POST', '/friends/accept', ['userId' => $peer, 'targetUserId' => $actor, 'expectedRevision' => 3, 'requestId' => $requestId], $peerToken); check(404, $status, 'Old request cannot accept a replacement');
[$status, $accepted] = callApi('POST', '/friends/accept', ['userId' => $peer, 'targetUserId' => $actor, 'expectedRevision' => 3, 'requestId' => $newId], $peerToken); check(200, $status, 'Explicit acceptance');
[, $actorState] = callApi('GET', '/friends?userId=' . $actor, null, $token); check($peer, $actorState['friends'][0]['userId'], 'Bilateral friendship visible');
$login = $actorState['friends'][0]['lastActiveAt']; check(true, is_string($login) && preg_match('/Z$/D', $login) === 1, 'Actual sign-in supplies login activity');
callApi('GET', '/player-profile?userId=' . $peer, null, $peerToken);
[, $unchanged] = callApi('GET', '/friends?userId=' . $actor, null, $token); check($login, $unchanged['friends'][0]['lastActiveAt'], 'Ordinary HTTP traffic does not update activity');
$pdo = new PDO('sqlite:' . __DIR__ . '/../data/test_horizon_integration.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->prepare('UPDATE users SET last_login_millis=NULL WHERE id=?')->execute([$peer]);
[, $legacy] = callApi('GET', '/friends?userId=' . $actor, null, $token); check(null, $legacy['friends'][0]['lastActiveAt'], 'Legacy unknown activity remains null');
$future = (int)floor(microtime(true) * 1000) + 60000; $pdo->prepare('UPDATE users SET last_login_millis=? WHERE id=?')->execute([$future, $peer]);
[, $signin] = callApi('POST', '/user-management/signin', ['type' => 'ANONYMOUS', 'anonymousToken' => $signup['anonymousToken']]); $peerToken = $signin['accessToken'];
check($future, (int)$pdo->query("SELECT last_login_millis FROM users WHERE id='$peer'")->fetchColumn(), 'Login maximum cannot regress with a slower clock'); $pdo = null;
[$status] = callApi('POST', '/friends/remove', ['userId' => $actor, 'targetUserId' => $peer, 'expectedRevision' => 4], $token); check(200, $status, 'Explicit bilateral removal');
[$status] = callApi('POST', '/friends/block', ['userId' => $actor, 'targetUserId' => $peer, 'expectedRevision' => 5], $token); check(200, $status, 'Explicit block');
[$status] = callApi('POST', '/friends/request', ['userId' => $peer, 'targetUserId' => $actor, 'expectedRevision' => 5], $peerToken); check(403, $status, 'Blocked request rejected');
[$status] = callApi('GET', '/friends/find?userId=' . $peer . '&query=' . $actor, null, $peerToken); check(404, $status, 'Blocked lookup has same missing response');
[$status, $unblocked] = callApi('POST', '/friends/unblock', ['userId' => $actor, 'targetUserId' => $peer, 'expectedRevision' => 6], $token); check(200, $status, 'Explicit unblock'); check([], $unblocked['friends'], 'Unblock never restores relationships');
[, $request] = callApi('POST', '/friends/request', ['userId' => $peer, 'targetUserId' => $actor, 'expectedRevision' => 5], $peerToken);
[$status] = callApi('POST', '/friends/cancel', ['userId' => $peer, 'targetUserId' => $actor, 'expectedRevision' => 6, 'requestId' => $request['outgoing'][0]['requestId']], $peerToken); check(200, $status, 'Exact outgoing cancellation');
echo 'PASS: ' . $passed . " Friends HTTP assertions\n";
