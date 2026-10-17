<?php

declare(strict_types=1);
$base=$argv[1] ?? ''; $key=$argv[2] ?? ''; $actor=$argv[3] ?? ''; $token=$argv[4] ?? '';
if (!preg_match('/^http:\/\/localhost:[0-9]{1,5}\/api\/v1\/app$/D',$base) || $key!=='test-key-integration-12345') throw new RuntimeException('Only suite-owned loopback fixture permitted');
$passed=0;
function check($expected,$actual,string $label): void { global $passed; if ($expected!==$actual) throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual)); $passed++; }
function api(string $method,string $path,$body=null,?string $session=null): array
{
    global $base,$key;
    $headers=['X-API-Key: '.$key,'Content-Type: application/json'];if($session!==null)$headers[]='Authorization: Bearer '.$session;
    $options=['method'=>$method,'header'=>implode("\r\n",$headers),'ignore_errors'=>true,'timeout'=>5];if($body!==null)$options['content']=is_string($body)?$body:json_encode($body,JSON_THROW_ON_ERROR);
    $raw=file_get_contents($base.$path,false,stream_context_create(['http'=>$options]));
    if($raw===false||!preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0]??'',$match))throw new RuntimeException('Fixture HTTP failure');
    return [(int)$match[1],json_decode($raw,true,64,JSON_THROW_ON_ERROR),$raw];
}
function signup(string $name): array
{[, $user]=api('POST','/user-management/signup',['type'=>'ANONYMOUS','username'=>$name]);[, $session]=api('POST','/user-management/signin',['type'=>'ANONYMOUS','anonymousToken'=>$user['anonymousToken']]);return[$user['userId'],$session['accessToken']];}
function operator(array $arguments,?string $input=null): array
{
    $pipes=[];$process=proc_open(array_merge([PHP_BINARY,__DIR__.'/../bin/items.php'],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process))throw new RuntimeException('Operator fixture failed');if($input!==null)fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(0,proc_close($process),'Actual local operator exit');if($error!=='')throw new RuntimeException($error);return json_decode($out,true,64,JSON_THROW_ON_ERROR);
}
function valuesOperator(string $input,int $revision,int $exit=0): array
{
    $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/../bin/player-values.php','configure',(string)$revision],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if(!is_resource($process))throw new RuntimeException('PV operator fixture failed');fwrite($pipes[0],$input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check($exit,proc_close($process),'Actual PV catalog operator exit');
    return json_decode($exit===0 ? $out : $error,true,64,JSON_THROW_ON_ERROR);
}
[$status,$error]=api('GET','/items?userId='.$actor);check(401,$status,'Bearer required');check('SESSION_REQUIRED',$error['code'],'Session error');
[$status]=api('GET','/items?userId=00000000-0000-4000-8000-000000001011',null,$token);check(403,$status,'Foreign identity forbidden');
[$user,$session]=signup('ItemPlayer');[$peer,$peerSession]=signup('ItemPeer');
[, $empty]=api('GET','/items?userId='.$user,null,$session);check(0,$empty['revision'],'Lazy inventory revision');check([],$empty['instances'],'Empty inventory');
$template=['name'=>'HTTP Sword','type'=>'weapon','rarity'=>'common','active'=>true,'baseProperties'=>(object)['power'=>9007199254740990,'label'=>'é/😀'],'propertyRules'=>[['key'=>'power','min'=>0,'max'=>9007199254740991,'maxChangePerCall'=>1]]];
$entry=operator(['put','sword','0'],json_encode($template,JSON_THROW_ON_ERROR));check(1,$entry['revision'],'Actual operator CAS');
$emptyTemplate=$template;$emptyTemplate['baseProperties']=(object)[];$emptyTemplate['propertyRules']=[];operator(['put','empty_item','1'],json_encode($emptyTemplate,JSON_THROW_ON_ERROR));
check(2,operator(['catalog'])['total'],'Local operator catalog');check(150,operator(['limits'])['inventory'],'Selfhost inventory limit');
[, $page,$raw]=api('GET','/items/catalog?userId='.$user.'&limit=1',null,$session);check('empty_item',$page['definitions'][0]['key'],'Catalog keyset order');check('empty_item',$page['nextAfterKey'],'Page continuation');check(true,strpos($raw,'"baseProperties":{}')!==false,'Empty property object survives response');
[, $page2]=api('GET','/items/catalog?userId='.$user.'&limit=1&afterKey='.$page['nextAfterKey'].'&catalogRevision='.$page['revision'],null,$session);check('sword',$page2['definitions'][0]['key'],'Second page');check(null,$page2['nextAfterKey'],'End of catalog');
foreach(['limit=0','limit=51','limit=1.0','limit[]=1','catalogRevision[]=2','catalogRevision=9007199254740992','afterKey[]=sword','afterKey=Bad']as$query){[$status,$error]=api('GET','/items/catalog?userId='.$user.'&'.$query,null,$session);check(400,$status,'Malformed page '.$query);check('INVALID_REQUEST',$error['code'],'Stable page error');}
[$status]=api('GET','/items/catalog?userId='.$user.'&catalogRevision=0',null,$session);check(409,$status,'Page revision conflict');
require_once __DIR__.'/../src/PlayerValues/PlayerValuesService.php';
$pdo=new PDO('sqlite:'.__DIR__.'/../data/test_horizon_integration.db',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$pdo->exec('PRAGMA foreign_keys=ON');$values=new PlayerValuesService($pdo);
$config=['definitions'=>[['key'=>'item_coin','name'=>'Item Coins','initialBalance'=>100,'maxBalance'=>1000]],'offers'=>[['key'=>'sword','name'=>'Sword','costs'=>[['key'=>'item_coin','amount'=>5]],'rewards'=>[],'itemRewards'=>[['itemKey'=>'sword','quantity'=>1]]]]];
valuesOperator(json_encode($config,JSON_THROW_ON_ERROR),$values->config(false)['revision']);
$revision=$values->config(false)['revision'];$duplicate=str_replace('"quantity":1','"quantity":1,"quant\\u0069ty":1',json_encode($config));
check('INVALID_REQUEST',valuesOperator($duplicate,$revision,1)['code'],'PV item reward duplicate decoded key');check($revision,$values->config(false)['revision'],'Rejected operator input cannot mutate catalog');
foreach ([(object)['0'=>(object)['itemKey'=>'sword','quantity'=>1]],(object)[]] as $objectRewards) {
    $invalidConfig=$config;$invalidConfig['offers'][0]['itemRewards']=$objectRewards;
    check('INVALID_REQUEST',valuesOperator(json_encode($invalidConfig,JSON_THROW_ON_ERROR),$revision,1)['code'],'JSON object cannot masquerade as itemRewards array');
    check($revision,$values->config(false)['revision'],'Rejected JSON shape preserves configuration revision');
}


$operation=Database::nowMillis().':'.Database::uuid();$buy=['userId'=>$user,'offerKey'=>'sword','operationId'=>$operation];
[$status,$purchase]=api('POST','/player-values/purchase',$buy,$session);check(200,$status,'Paid item purchase');check(95,$purchase['values'][0]['balance'],'One debit');$id=$purchase['operation']['items'][0]['instanceId'];
[, $inventory]=api('GET','/items?userId='.$user,null,$session);check(1,count($inventory['instances']),'Inventory explicit refresh');check($id,$inventory['instances'][0]['instanceId'],'Same server-owned UUID');check(9007199254740990,$inventory['instances'][0]['properties']['power'],'Exact large integer output');
$adjust=['userId'=>$user,'instanceId'=>$id,'expectedRevision'=>1,'changes'=>[['key'=>'power','amount'=>1]]];
foreach(['"1"','1.0','1e0','null','-1','9007199254740992']as$bad){$raw=str_replace('"expectedRevision":1','"expectedRevision":'.$bad,json_encode($adjust));[$status,$error]=api('POST','/items/adjust',$raw,$session);check(400,$status,'Strict original revision '.$bad);check('INVALID_REQUEST',$error['code'],'Revision code');}
foreach(['1.0','1e0','"1"','0','9007199254740992']as$bad){$raw=str_replace('"amount":1','"amount":'.$bad,json_encode($adjust));[$status]=api('POST','/items/adjust',$raw,$session);check(400,$status,'Strict delta '.$bad);}
$raw=json_encode($adjust);$raw=substr($raw,0,-1).',"expectedRevision":1}';[$status]=api('POST','/items/adjust',$raw,$session);check(400,$status,'Duplicate root key');
$raw=str_replace('"amount":1','"amount":1,"amou\\u006et":1',json_encode($adjust));[$status]=api('POST','/items/adjust',$raw,$session);check(400,$status,'Duplicate decoded nested key');
$unknown=$adjust;$unknown['properties']=(object)['power'=>1];[$status]=api('POST','/items/adjust',$unknown,$session);check(400,$status,'No arbitrary property replacement');
[$status]=api('POST','/items/adjust',$adjust,$peerSession);check(403,$status,'Mutation foreign session');
[$status,$changed]=api('POST','/items/adjust',$adjust,$session);check(200,$status,'Exact property delta');check(9007199254740991,$changed['instances'][0]['properties']['power'],'Safe integer maximum preserved');check(2,$changed['revision'],'Mutation full snapshot');
[$status,$error]=api('POST','/items/adjust',$adjust,$session);check(409,$status,'Original revision is not silently replaced');check('REVISION_CONFLICT',$error['code'],'Stable conflict');
$adjust['expectedRevision']=2;[$status,$error]=api('POST','/items/adjust',$adjust,$session);check(422,$status,'Property overflow');check('ITEM_PROPERTY_LIMIT_REACHED',$error['code'],'Property overflow code');
$equip=['userId'=>$user,'instanceId'=>$id,'expectedRevision'=>2,'equipped'=>true];$invalid=$equip;$invalid['equipped']='true';[$status]=api('POST','/items/equip',$invalid,$session);check(400,$status,'Equipment boolean cannot coerce');
[, $equipped]=api('POST','/items/equip',$equip,$session);check(true,$equipped['instances'][0]['equipped'],'Equipment explicit intent');check(3,$equipped['revision'],'Equipment revision');
[, $discarded]=api('POST','/items/discard',['userId'=>$user,'instanceId'=>$id,'expectedRevision'=>3],$session);check([],$discarded['instances'],'Explicit discard');
$values->configure(['definitions'=>$config['definitions'],'offers'=>[]],$values->config(false)['revision']);
[, $replayed]=api('POST','/player-values/purchase',$buy,$session);check(true,$replayed['operation']['replayed'],'Removed offer replay');check([],$replayed['operation']['items'],'Replay carries no historical item references');
[, $current]=api('GET','/items?userId='.$user,null,$session);check([],$current['instances'],'Replay cannot recreate discarded item');check(4,$current['revision'],'Replay cannot mutate inventory');
check(4,operator(['player',$user])['inventory']['revision'],'Operator bounded inspection');
foreach(['/items/grant','/admin/items/catalog']as$path){[$status]=api('POST',$path,['userId'=>$user],$session);check(404,$status,'No public grant/admin API');}
[, $peerState]=api('GET','/items?userId='.$peer,null,$peerSession);check([],$peerState['instances'],'Player isolation');
echo 'PASS: '.$passed." Items HTTP assertions, all5 app routes, actual local CLI, strict JSON/session/revisions, paid grant and original replay after discard/offer removal\n";
