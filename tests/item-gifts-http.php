<?php

declare(strict_types=1);
require_once __DIR__.'/../src/ItemGifts/GiftService.php';
// Item gifts (TASK-1140): all six app routes over real HTTP, strict auth/JSON, Inbox claim and every operator CLI command.
$base=$argv[1]??'';$key=$argv[2]??'';
if(!preg_match('/^http:\/\/localhost:[0-9]{1,5}\/api\/v1\/app$/D',$base)||$key!=='test-key-integration-12345')throw new RuntimeException('Only owned integration loopback allowed');
$passed=0;$routes=[];$commands=[];
function check($expected,$actual,string $name): void{global$passed;if($expected!==$actual)throw new RuntimeException($name.': expected '.json_encode($expected).', got '.json_encode($actual));$passed++;}
function api(string $method,string $path,$body=null,?string $session=null): array
{
    global$base,$key;$headers=['X-API-Key: '.$key,'Content-Type: application/json'];if($session!==null)$headers[]='Authorization: Bearer '.$session;
    $options=['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>5];if($body!==null)$options['content']=is_string($body)?$body:json_encode($body,JSON_THROW_ON_ERROR);
    $raw=file_get_contents($base.$path,false,stream_context_create(['http'=>$options]));if($raw===false||!preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0]??'',$m))throw new RuntimeException('HTTP fixture failed');
    $retry=null;foreach($http_response_header as$h)if(stripos($h,'Retry-After:')===0)$retry=trim(substr($h,12));return[(int)$m[1],json_decode($raw,true,64,JSON_THROW_ON_ERROR),$retry];
}
function gold(string $u): int{global$values;foreach($values->get($u)['values']as$v)if($v['key']==='gift.gold')return$v['balance'];throw new RuntimeException('No gift.gold');}
function signup(string $name): array{[$code,$u]=api('POST','/user-management/signup',['type'=>'ANONYMOUS','username'=>$name]);if($code!==201&&$code!==200)throw new RuntimeException('Signup fixture');[, $s]=api('POST','/user-management/signin',['type'=>'ANONYMOUS','anonymousToken'=>$u['anonymousToken']]);return[$u['userId'],$s['accessToken']];}
function operator(array $args,$body=null,int $exit=0): array
{
    global$commands;$commands[$args[0]]=true;$pipes=[];$p=proc_open(array_merge([PHP_BINARY,__DIR__.'/../bin/item-gifts.php'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));if(!is_resource($p))throw new RuntimeException('CLI fixture start');
    if($body!==null)fwrite($pipes[0],is_string($body)?$body:json_encode($body,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check($exit,proc_close($p),'CLI exit '.$args[0].' '.$err);return json_decode($exit===0?$out:$err,true,64,JSON_THROW_ON_ERROR);
}
[$a,$tokenA]=signup('GiftSender');[$b,$tokenB]=signup('GiftRecipient');[$c,$tokenC]=signup('GiftOutsider');
$pdo=new PDO('sqlite:'.__DIR__.'/../data/test_horizon_integration.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=5000');
$items=new ItemService($pdo);$values=new PlayerValuesService($pdo);
$items->put('gift_sword',$items->catalog()['revision'],['name'=>'Gift sword','type'=>'weapon','rarity'=>'rare','active'=>true,'baseProperties'=>(object)['serial'=>"é\0😀",'power'=>7],'propertyRules'=>[]]);
$config=$values->config(false);if(!in_array('gift.gold',array_column($config['definitions'],'key'),true))
    $values->configure(['definitions'=>array_merge($config['definitions'],[['key'=>'gift.gold','name'=>'Gift Gold','initialBalance'=>100,'maxBalance'=>10000]]),'offers'=>[]],$config['revision']);

$initial=operator(['config']);check(['revision'=>0,'enabled'=>false,'currencyKey'=>null,'fee'=>0,'escrowCount'=>0,'retainedCount'=>0],$initial['config'],'Operator config with counters');
check(50,operator(['limits'])['limits']['openGiftsPerPlayer'],'Local Enterprise default');
check('INVALID_REQUEST',operator(['configure','0'],['enabled'=>true,'currencyKey'=>null,'fee'=>10],1)['code'],'Fee needs a currency');
check('gift.gold',operator(['configure','0'],['enabled'=>true,'currencyKey'=>'gift.gold','fee'=>10])['config']['currencyKey'],'Operator configure');
check('REVISION_CONFLICT',operator(['configure','0'],['enabled'=>false,'currencyKey'=>null,'fee'=>0],1)['code'],'Configure binds the revision');

$missing='00000000-0000-4000-8000-000000001140';
foreach(['/item-gifts/config','/item-gifts','/item-gifts/operations/'.$missing,'/item-gifts/'.$missing]as$path){
    [$status,$e]=api('GET',$path.'?userId='.$a);check([401,'SESSION_REQUIRED'],[$status,$e['code']],'Bearer required '.$path);
    [$status,$e]=api('GET',$path.'?userId='.$a,null,$tokenB);check([403,'SESSION_FORBIDDEN'],[$status,$e['code']],'Foreign session '.$path);
}
foreach(['/item-gifts','/item-gifts/'.$missing.'/decline']as$path){[$status]=api('POST',$path,['userId'=>$a],$tokenB);check(403,$status,'Mutating foreign bearer '.$path);}
[$status,$e]=api('GET','/item-gifts/config?userId=not-a-uuid',null,$tokenA);check([400,'INVALID_REQUEST'],[$status,$e['code']],'Canonical UUID');
[$status,$cfg]=api('GET','/item-gifts/config?userId='.$a,null,$tokenA);check(200,$status,'Config route');check(['revision'=>1,'enabled'=>true,'currencyKey'=>'gift.gold','fee'=>10],$cfg['config'],'Public config without counters');
check(['openGiftsPerPlayer','retainedGiftsPerProject','projectWritesPerMinute','historyPerPlayer','receivedPageSize','playerWriteIntervalMillis','expirySeconds','terminalRetentionSeconds','operationRetentionSeconds'],array_keys($cfg['limits']),'Limits shape');
check(1,preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',$cfg['serverTime']),'Millisecond UTC time');
[, $state]=api('GET','/item-gifts?userId='.$a,null,$tokenA);check([[],[],[]],[$state['state']['sent'],$state['state']['history'],$state['state']['received']],'Lazy empty lists');

$now=Database::nowMillis();$ref=$items->grant($a,[['itemKey'=>'gift_sword','quantity'=>1]],Database::uuid(),$now,'gift-http-fixture')['items'][0];$original=$items->get($a)['instances'][0];
$send=['userId'=>$a,'operationId'=>Database::uuid(),'issuedAt'=>GiftRules::instant($now),'recipientId'=>$b,'instanceId'=>$ref['instanceId'],'expectedInventoryRevision'=>$items->get($a)['revision'],'expectedConfigRevision'=>1];
foreach(['1.0','1e0','"1"','null','-1','9007199254740992']as$n){$raw=str_replace('"expectedConfigRevision":1','"expectedConfigRevision":'.$n,json_encode($send));[$status,$e]=api('POST','/item-gifts',$raw,$tokenA);check([400,'INVALID_REQUEST'],[$status,$e['code']],'Strict number '.$n);}
$raw=str_replace('"expectedConfigRevision":1','"expectedConfigRevision":1,"expectedConfigR\\u0065vision":1',json_encode($send));[$status]=api('POST','/item-gifts',$raw,$tokenA);check(400,$status,'Decoded duplicate field');
[$status]=api('POST','/item-gifts',array_merge($send,['note'=>'x']),$tokenA);check(400,$status,'Unknown field');
[$status,$e]=api('POST','/item-gifts',array_merge($send,['recipientId'=>$a]),$tokenA);check([422,'SELF_GIFT'],[$status,$e['code']],'Self gift');
[$status,$created]=api('POST','/item-gifts',$send,$tokenA);check(201,$status,'First send 201');$g=$created['gift'];check(false,$created['replayed'],'Not replayed');check(90,gold($a),'Fee charged');
[$status,$replay]=api('POST','/item-gifts',$send,$tokenA);check([200,true],[$status,$replay['replayed']],'Replay 200');check($created['receipt'],$replay['receipt'],'Original receipt');check(90,gold($a),'No second fee');
[$status,$e,$retry]=api('POST','/item-gifts',array_merge($send,['operationId'=>Database::uuid()]),$tokenA);check([429,'ITEM_GIFTS_RATE_LIMITED','1'],[$status,$e['code'],$retry],'Cooldown with Retry-After');
[$status,$op]=api('GET','/item-gifts/operations/'.$send['operationId'].'?userId='.$a,null,$tokenA);check([200,$created['receipt']],[$status,$op['receipt']],'Operation route');
[$status,$e]=api('GET','/item-gifts/operations/'.$send['operationId'].'?userId='.$b,null,$tokenB);check([404,'OPERATION_NOT_FOUND'],[$status,$e['code']],'Only the sender reconciles');
[$status,$point]=api('GET','/item-gifts/'.$g['id'].'?userId='.$b,null,$tokenB);check([200,$g['id']],[$status,$point['gift']['id']],'Recipient point');
[$status,$e]=api('GET','/item-gifts/'.$g['id'].'?userId='.$c,null,$tokenC);check([404,'GIFT_NOT_FOUND'],[$status,$e['code']],'Outsider cannot read');
[, $state]=api('GET','/item-gifts?userId='.$b,null,$tokenB);check([$g['id']],array_column($state['state']['received'],'id'),'Received list');
[, $inbox]=api('GET','/inbox?userId='.$b,null,$tokenB);$offer=null;foreach($inbox['entries']as$e)if($e['type']==='item_gift')$offer=$e;
check(['values'=>[],'itemGift'=>['giftId'=>$g['id'],'instanceId'=>$ref['instanceId'],'itemKey'=>'gift_sword','direction'=>'offer']],$offer['attachment'],'Offer entry over HTTP');
[$status,$claim]=api('POST','/inbox/claim',['userId'=>$b,'entryId'=>$offer['id']],$tokenB);check([200,[$ref]],[$status,$claim['items']],'Existing Inbox claim lists the original');
check(json_decode(json_encode($original),true),json_decode(json_encode($items->get($b)['instances'][0]),true),'Original properties including NUL');
[, $claim2]=api('POST','/inbox/claim',['userId'=>$b,'entryId'=>$offer['id']],$tokenB);check([true,[$ref]],[$claim2['replayed'],$claim2['items']],'Claim replay');

// Decline over HTTP, then the sender claims the return.
usleep(1100000);$ref2=$items->grant($a,[['itemKey'=>'gift_sword','quantity'=>1]],Database::uuid(),Database::nowMillis(),'gift-http-fixture')['items'][0];
$send2=array_replace($send,['operationId'=>Database::uuid(),'issuedAt'=>GiftRules::instant(Database::nowMillis()),'recipientId'=>$c,'instanceId'=>$ref2['instanceId'],'expectedInventoryRevision'=>$items->get($a)['revision']]);
[$status,$created2]=api('POST','/item-gifts',$send2,$tokenA);check(201,$status,'Second send');$g2=$created2['gift'];
[$status,$e]=api('POST','/item-gifts/'.$g2['id'].'/decline',['userId'=>$c,'expectedGiftRevision'=>1],$tokenC);check([409,'REVISION_CONFLICT'],[$status,$e['code']],'Decline binds the gift revision');
[$status,$e]=api('POST','/item-gifts/'.$g2['id'].'/decline','{"userId":"'.$c.'","expectedGiftRevision":2.0}',$tokenC);check(400,$status,'Strict decline revision');
[$status,$declined]=api('POST','/item-gifts/'.$g2['id'].'/decline',['userId'=>$c,'expectedGiftRevision'=>2],$tokenC);check([200,false,'delivered','return_delivered'],[$status,$declined['replayed'],$declined['returnDelivery'],$declined['gift']['status']],'Decline');
[, $again]=api('POST','/item-gifts/'.$g2['id'].'/decline',['userId'=>$c,'expectedGiftRevision'=>2],$tokenC);check(true,$again['replayed'],'Decline replay');
[, $inbox]=api('GET','/inbox?userId='.$a,null,$tokenA);$return=null;foreach($inbox['entries']as$e)if($e['type']==='item_gift_return')$return=$e;check(['reason'=>'declined','recipientId'=>$c],$return['parameters'],'Return entry');
[, $back]=api('POST','/inbox/claim',['userId'=>$a,'entryId'=>$return['id']],$tokenA);check([$ref2],$back['items'],'Return claimed');check(80,gold($a),'Fee never refunded');

// Operator equivalents.
check(true,count(operator(['list','-','1'])['gifts'])===1,'Operator bounded page');check($g['id'],operator(['get',$g['id']])['gift']['id'],'Operator point');check([],operator(['get',$g['id']])['recoveries'],'Operator recoveries');
check($a,operator(['player',$a])['state']['userId'],'Operator player state');check(true,operator(['cleanup'])['examined']<=20,'Bounded maintenance');check(true,operator(['cleanup-deleted'])['complete'],'Deleted identity cleanup');
check('GIFT_NOT_FOUND',operator(['retry-return',$missing],null,1)['code'],'Retry needs a gift');check('claimed',operator(['retry-return',$g['id']])['delivery'],'Retry reports a claimed gift');
check('RECOVERY_NOT_ALLOWED',operator(['recover',$g2['id'],(string)operator(['get',$g2['id']])['gift']['revision']],['recipientId'=>$c,'note'=>'not orphaned'],1)['code'],'Recovery only for ended owners');
foreach(['/item-gifts/config','/admin/features/item-gifts/config']as$path){[$status]=api('PUT',$path,['enabled'=>true],$tokenA);check(404,$status,'No public operator configuration');}
check(10,count($commands),'All ten operator commands exercised');
echo'PASS: '.$passed." Item gifts HTTP/CLI assertions; six app routes, Inbox claim, ten operator commands\n";
