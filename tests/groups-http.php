<?php

declare(strict_types=1);
require_once __DIR__.'/inbox-fixtures.php';
$base = $argv[1] ?? ''; $key = $argv[2] ?? ''; $actor = $argv[3] ?? ''; $token = $argv[4] ?? '';
if (!preg_match('/^http:\/\/localhost:[0-9]{1,5}\/api\/v1\/app$/D',$base) || $key !== 'test-key-integration-12345') throw new RuntimeException('Only the integration suite loopback fixture is permitted');
$passed = 0;
function check($expected,$actual,string $label): void { global $passed; if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . json_encode($expected) . ', got ' . json_encode($actual)); $passed++; }
function api(string $method,string $path,$body = null,?string $session = null): array
{
    global $base,$key;
    $headers = ['X-API-Key: ' . $key,'Content-Type: application/json']; if ($session !== null) $headers[] = 'Authorization: Bearer ' . $session;
    $options = ['method' => $method,'header' => implode("\r\n",$headers),'ignore_errors' => true,'timeout' => 5]; if ($body !== null) $options['content'] = is_string($body) ? $body : json_encode($body,JSON_THROW_ON_ERROR);
    $raw = file_get_contents($base . $path,false,stream_context_create(['http' => $options]));
    if ($raw === false || !preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0] ?? '',$match)) throw new RuntimeException('Fixture HTTP failure');
    return [(int)$match[1],json_decode($raw,true,512,JSON_THROW_ON_ERROR),$raw];
}
function signup(string $name): array
{
    [, $signup] = api('POST','/user-management/signup',['type' => 'ANONYMOUS','username' => $name]);
    [, $signin] = api('POST','/user-management/signin',['type' => 'ANONYMOUS','anonymousToken' => $signup['anonymousToken']]); return [$signup['userId'],$signin['accessToken']];
}
[$status] = api('GET','/groups?userId=' . $actor); check(401,$status,'Groups require Bearer');
[$status] = api('GET','/groups?userId=00000000-0000-4000-8000-000000001010',null,$token); check(403,$status,'Groups reject foreign player session');
[, $empty] = api('GET','/groups?userId=' . $actor,null,$token); check(0,$empty['revision'],'Missing group state revision0');
$body = ['userId' => $actor,'expectedPlayerRevision' => 0,'name' => 'HTTP Group','description' => 'Private','joinMode' => 'open','data' => (object)[]];
foreach (['null','"0"','1.0','1e0','1.0000000000000001','-1','9007199254740992'] as $bad) {
    $raw = json_encode($body); $raw = str_replace('"expectedPlayerRevision":0','"expectedPlayerRevision":' . $bad,$raw);
    [$status,$error] = api('POST','/groups',$raw,$token); check(400,$status,'Create revision is strict ' . $bad); check('INVALID_REVISION',$error['code'],'Stable revision error');
}
$missing = $body; unset($missing['expectedPlayerRevision']); [$status] = api('POST','/groups',$missing,$token); check(400,$status,'Missing create revision cannot mean0');
foreach ([[],null] as $invalidData) { $invalid = $body; $invalid['data'] = $invalidData; [$status] = api('POST','/groups',$invalid,$token); check(400,$status,'Group data must be object'); }
$invalid = $body; unset($invalid['data']); [$status] = api('POST','/groups',$invalid,$token); check(400,$status,'Missing data invalid');
$invalid = $body; $invalid['groupId'] = '00000000-0000-4000-8000-000000001010'; [$status] = api('POST','/groups',$invalid,$token); check(400,$status,'No client-selected group UUID');
$invalid = $body; $invalid['data'] = (object)['large' => str_repeat('é',1100)]; [$status] = api('POST','/groups',$invalid,$token); check(400,$status,'Actual UTF8 bytes bounded');
[$status,$created,$raw] = api('POST','/groups',$body,$token); check(200,$status,'Create group'); $id = $created['group']['id']; $group = $created['group'];
check(true,strpos($raw,'"data":{}') !== false,'Empty object stays object through actual Request/Response'); check(1,$created['groups']['revision'],'Player revision advances');
[$status] = api('POST','/groups',$body,$token); check(409,$status,'Uncertain original create cannot duplicate');
[$peer,$peerToken] = signup('GroupPeer'); [$third,$thirdToken] = signup('GroupThird');
[$status,$public] = api('GET','/groups/' . $id . '?userId=' . $peer,null,$peerToken); check(200,$status,'Exact group lookup'); check(false,array_key_exists('data',$public),'No private data to nonmembers'); check(false,array_key_exists('myRole',$public),'No nonmember role');
[$status] = api('GET','/groups/' . $id . '/members?userId=' . $peer,null,$peerToken); check(403,$status,'Members are private');
foreach (['"1"','1.0','null','9007199254740992'] as $bad) {
    $raw = '{"userId":"' . $peer . '","expectedRevision":' . $bad . '}'; [$status] = api('POST','/groups/' . $id . '/join',$raw,$peerToken); check(400,$status,'Action revision cannot coerce');
}
[$status,$joined] = api('POST','/groups/' . $id . '/join',['userId' => $peer,'expectedRevision' => 1],$peerToken); check(200,$status,'Open join'); $group = $joined['group'];
[$status] = api('GET','/groups/' . $id . '/pending?userId=' . $peer,null,$peerToken); check(403,$status,'Ordinary member cannot inspect pending');
[$status,$promoted] = api('POST','/groups/' . $id . '/role',['userId' => $actor,'expectedRevision' => $group['revision'],'targetUserId' => $peer,'role' => 'officer'],$token); check(200,$status,'Leader promotes officer'); $group = $promoted['group'];
$metadata = ['userId' => $peer,'expectedRevision' => $group['revision'],'name' => 'Updated','description' => 'D','joinMode' => 'invite','data' => json_decode('{"$x":{"a.b":[],"":{}},"n":1.5}')];
[$status,$updated,$raw] = api('PUT','/groups/' . $id,$metadata,$peerToken); check(200,$status,'Officer updates metadata'); check(true,strpos($raw,'"":{}') !== false && strpos($raw,'"a.b":[]') !== false,'Nested empty object/list and legal keys preserved'); $group = $updated['group'];
[$status] = api('PUT','/groups/' . $id,$metadata,$peerToken); check(409,$status,'Metadata keeps reviewed revision');
foreach (['0','51','1.5','abc'] as $limit) { [$status] = api('GET','/groups/' . $id . '/members?userId=' . $actor . '&limit=' . $limit,null,$token); check(400,$status,'Member page size bounded'); }
[, $page] = api('GET','/groups/' . $id . '/members?userId=' . $actor . '&limit=1',null,$token); check(1,count($page['members']),'Page size1'); check(true,$page['nextAfterUserId'] !== null,'Member cursor explicit');
[, $next] = api('GET','/groups/' . $id . '/members?userId=' . $actor . '&limit=1&afterUserId=' . $page['nextAfterUserId'],null,$token); check(false,$next['members'][0]['userId'] === $page['members'][0]['userId'],'Keyset next member');
$command = static function (string $action,string $user,string $session,array $extra = []) use ($id,&$group): array {
    [$status,$response] = api('POST','/groups/' . $id . '/' . $action,['userId' => $user,'expectedRevision' => $group['revision']] + $extra,$session);
    check(200,$status,'Group action ' . $action); if ($response['group'] !== null) $group = $response['group']; return $response;
};
$command('invite',$peer,$peerToken,['targetUserId' => $third]); [, $pending] = api('GET','/groups/' . $id . '/pending?userId=' . $actor,null,$token); $request = $pending['invitations'][0]['requestId'];
[$status] = api('POST','/groups/' . $id . '/accept-invite',['userId' => $third,'expectedRevision' => $group['revision']],$thirdToken); check(400,$status,'Pending decision requires UUID');
$command('decline-invite',$third,$thirdToken,['requestId' => $request]); $command('invite',$peer,$peerToken,['targetUserId' => $third]);
[, $mine] = api('GET','/groups?userId=' . $third,null,$thirdToken); $request = $mine['incomingInvites'][0]['requestId']; check(false,array_key_exists('data',$mine['incomingInvites'][0]['group']),'Private data excluded from own pending public metadata');
$command('cancel-invite',$peer,$peerToken,['targetUserId' => $third,'requestId' => $request]); $command('invite',$peer,$peerToken,['targetUserId' => $third]);
[, $mine] = api('GET','/groups?userId=' . $third,null,$thirdToken); $newRequest = $mine['incomingInvites'][0]['requestId'];
[$status] = api('POST','/groups/' . $id . '/accept-invite',['userId' => $third,'expectedRevision' => $group['revision'],'requestId' => $request],$thirdToken); check(404,$status,'Old invitation cannot accept replacement');
$command('accept-invite',$third,$thirdToken,['requestId' => $newRequest]); $command('remove',$peer,$peerToken,['targetUserId' => $third]);
$metadata['expectedRevision'] = $group['revision']; $metadata['joinMode'] = 'request'; [, $updated] = api('PUT','/groups/' . $id,$metadata,$peerToken); $group = $updated['group'];
$requested = $command('request',$third,$thirdToken); $request = $requested['groups']['outgoingRequests'][0]['requestId']; $command('decline-request',$peer,$peerToken,['targetUserId' => $third,'requestId' => $request]);
$requested = $command('request',$third,$thirdToken); $request = $requested['groups']['outgoingRequests'][0]['requestId']; $command('cancel-request',$third,$thirdToken,['requestId' => $request]);
$requested = $command('request',$third,$thirdToken); $request = $requested['groups']['outgoingRequests'][0]['requestId']; $command('accept-request',$peer,$peerToken,['targetUserId' => $third,'requestId' => $request]);
[$status] = api('POST','/groups/' . $id . '/leave',['userId' => $actor,'expectedRevision' => $group['revision']],$token); check(409,$status,'Leader must explicitly transfer');
$command('transfer',$actor,$token,['targetUserId' => $peer]); $left = $command('leave',$third,$thirdToken); check(null,$left['group'],'Leave response clears actor group');
[, $group] = api('GET','/groups/' . $id . '?userId=' . $peer,null,$peerToken); $command('leave',$actor,$token);
[, $group] = api('GET','/groups/' . $id . '?userId=' . $peer,null,$peerToken); $deleted = $command('delete',$peer,$peerToken); check(null,$deleted['group'],'Delete returns null group');
[$status] = api('GET','/groups/' . $id . '?userId=' . $peer,null,$peerToken); check(404,$status,'Deleted group unavailable');
[, $mine] = api('GET','/groups?userId=' . $actor,null,$token); $body['expectedPlayerRevision'] = $mine['revision']; $body['joinMode'] = 'request'; [, $created] = api('POST','/groups',$body,$token); $fullId = $created['group']['id'];
[, $requested] = api('POST','/groups/' . $fullId . '/request',['userId' => $third,'expectedRevision' => 1],$thirdToken); $request = $requested['groups']['outgoingRequests'][0]['requestId'];
require_once __DIR__ . '/../src/Core/Database.php'; require_once __DIR__ . '/../src/Inbox/InboxService.php';
$pdo = new PDO('sqlite:' . __DIR__ . '/../data/test_horizon_integration.db',null,null,[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$inbox = new InboxService($pdo); $now = Database::nowMillis(); $content = ['type' => 'fixture','textKey' => 'fixture','expiresAt' => InboxRules::instant($now + InboxRules::RETENTION)];
fillInboxFixture($pdo,$third,$now); $pdo = null;
[$status,$failure] = api('POST','/groups/' . $fullId . '/accept-request',['userId' => $actor,'expectedRevision' => $requested['group']['revision'],'targetUserId' => $third,'requestId' => $request],$token); check(409,$status,'Full Inbox refuses group action'); check('INBOX_FULL',$failure['code'],'Full Inbox code');
[, $unchanged] = api('GET','/groups/' . $fullId . '/pending?userId=' . $actor,null,$token); check($request,$unchanged['joinRequests'][0]['requestId'],'Full Inbox retains original pending');
[, $stillPublic] = api('GET','/groups/' . $fullId . '?userId=' . $third,null,$thirdToken); check(1,$stillPublic['memberCount'],'Full Inbox prevents partial membership'); check(false,array_key_exists('data',$stillPublic),'Rejected requester remains nonmember');
[$status] = api('GET','/../admin/groups?apiKeyId=' . $id,null,$token); check(404,$status,'Hosted admin surface not exposed');
echo 'PASS: ' . $passed . " Groups HTTP assertions, all20 app routes, true JSON object data, strict revisions/session ownership and full Inbox rollback\n";
