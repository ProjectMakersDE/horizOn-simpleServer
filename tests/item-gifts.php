<?php

declare(strict_types=1);
require_once __DIR__.'/inbox-fixtures.php';
require_once __DIR__.'/../src/ItemGifts/GiftService.php';

// Item gifts (TASK-1140): send, replay, Inbox claim, decline, expiry, returns, fee, deletion and recovery on real SQL.
$passed=0;
function check($wanted,$actual,string $label): void{global$passed;if($wanted!==$actual)throw new RuntimeException($label.': expected '.json_encode($wanted).', got '.json_encode($actual));$passed++;}
function reject(string $code,callable $work): void
{
    try{$work();}catch(GiftFailure $e){check($code,$e->errorCode,'Rejected gift');return;}catch(PlayerValuesFailure $e){check($code,$e->errorCode,'Rejected owning provider');return;}catch(InboxFailure $e){check($code,$e->errorCode,'Rejected inbox');return;}
    throw new RuntimeException('Expected '.$code);
}
$dsn=getenv('HORIZON_GIFT_TEST_DSN');if($dsn&&!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]{1,5};dbname=task1140_[a-f0-9]+;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Only explicit task1140 loopback database permitted');
$target=$dsn?:tempnam(sys_get_temp_dir(),'horizon-task1140-');$env=tempnam(sys_get_temp_dir(),'horizon-task1140-env-');$pdo=null;
try{
    // A small inventory makes the full-inventory path reachable; every other operator limit keeps its default.
    file_put_contents($env,"ITEMS_INVENTORY=3\n");Config::load($env);
    $pdo=new PDO($dsn?:'sqlite:'.$target,$dsn?getenv('HORIZON_GIFT_TEST_USER'):null,$dsn?getenv('HORIZON_GIFT_TEST_PASSWORD'):null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if(!$dsn){$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA journal_mode=WAL');}
    $pdo->exec(file_get_contents(__DIR__.'/../migrations/'.($dsn?'mysql':'sqlite').'.sql'));Database::ensurePlayerValuesConfig($pdo);Database::ensureInboxConfig($pdo);Database::ensureMarketOperations($pdo);
    $now=1792152000000;$clock=static function()use(&$now){return $now;};$scope=new SqlTransaction($pdo);
    $s=new GiftService($pdo,$clock,$scope);$items=new ItemService($pdo,$clock,$scope);$values=new PlayerValuesService($pdo,$clock,$scope);$inbox=new InboxService($pdo,$clock,$scope);$friends=new FriendsService($pdo,$clock,$scope);
    $user=static function()use($pdo,&$now):string{$id=Database::uuid();$pdo->prepare('INSERT INTO users(id,display_name,anonymous_token,created_at,created_at_millis) VALUES(?,?,?,?,?)')->execute([$id,'Gift player',Database::uuid(),'2026-10-16 12:00:00',$now]);return$id;};
    $template=['name'=>'Gift sword','type'=>'weapon','rarity'=>'rare','active'=>true,'baseProperties'=>(object)['label'=>"original\0😀é/",'power'=>15],'propertyRules'=>[['key'=>'power','min'=>0,'max'=>100,'maxChangePerCall'=>5]]];
    $grant=static function(string $u)use($items,&$now){return$items->grant($u,[['itemKey'=>'sword','quantity'=>1]],Database::uuid(),$now,'test-gift')['items'][0];};
    $intent=static function(string $u,string $to,string $instance)use($s,$items,&$now){return['userId'=>$u,'operationId'=>Database::uuid(),'issuedAt'=>GiftRules::instant($now),'recipientId'=>$to,'instanceId'=>$instance,'expectedInventoryRevision'=>$items->get($u)['revision'],'expectedConfigRevision'=>$s->adminConfig()['config']['revision']];};
    $entry=static function(string $u,string $type,?string $gift=null)use($inbox){foreach($inbox->get($u)['entries']as$e)if($e['type']===$type&&($gift===null||$e['reference']===$gift)&&($gift!==null||!$e['claimed']))return$e;return null;};
    $balance=static function(string $u)use($values){return$values->get($u)['values'][0]['balance'];};

    // Limits and configuration.
    foreach(['FREE'=>[3,50,10],'BASIC'=>[5,500,30],'PLUS'=>[10,1000,45],'PRO'=>[20,2500,60],'ENTERPRISE'=>[50,10000,120]]as$tier=>$expected){$l=GiftRules::limits($tier);check($expected,[$l['openGiftsPerPlayer'],$l['retainedGiftsPerProject'],$l['projectWritesPerMinute']],'Five-tier defaults '.$tier);}
    check(['openGiftsPerPlayer','retainedGiftsPerProject','projectWritesPerMinute','historyPerPlayer','receivedPageSize','playerWriteIntervalMillis','expirySeconds','terminalRetentionSeconds','operationRetentionSeconds'],array_keys($s->limits()),'Exact limits shape');
    $a=$user();$b=$user();$c=$user();
    check(['revision'=>0,'enabled'=>false,'currencyKey'=>null,'fee'=>0],$s->config($a)['config'],'Disabled by default');check(0,(int)$pdo->query('SELECT COUNT(*) FROM gift_header')->fetchColumn(),'Lazy header');
    reject('PLAYER_NOT_FOUND',static function()use($s){$s->config(Database::uuid());});
    $items->put('sword',0,$template);$values->configure(['definitions'=>[['key'=>'gift.gold','name'=>'Gold','initialBalance'=>100,'maxBalance'=>10000]],'offers'=>[]],0);
    reject('INVALID_REQUEST',static function()use($s){$s->configure(0,['enabled'=>true,'currencyKey'=>null,'fee'=>5]);});
    reject('INVALID_REQUEST',static function()use($s){$s->configure(0,['enabled'=>true,'currencyKey'=>'gift.gold','fee'=>0]);});
    reject('INVALID_REQUEST',static function()use($s){$s->configure(0,['enabled'=>true,'currencyKey'=>'missing.coin','fee'=>5]);});
    reject('INVALID_REQUEST',static function()use($s){$s->configure(0,['enabled'=>true,'currencyKey'=>null]);});
    $ref=$grant($a);$disabled=$intent($a,$b,$ref['instanceId']);reject('ITEM_GIFTS_DISABLED',static function()use($s,$a,$disabled){$s->send($a,$disabled);});
    check(true,$s->configure(0,['enabled'=>true,'currencyKey'=>null,'fee'=>0])['config']['enabled'],'Free gifts enabled');reject('REVISION_CONFLICT',static function()use($s){$s->configure(0,['enabled'=>false,'currencyKey'=>null,'fee'=>0]);});

    // Send, replay and reconcile.
    $before=$items->get($a)['instances'][0];$request=$intent($a,$b,$ref['instanceId']);
    reject('REVISION_CONFLICT',static function()use($s,$a,$request){$stale=$request;$stale['expectedConfigRevision']=0;$s->send($a,$stale);});
    reject('REVISION_CONFLICT',static function()use($s,$a,$request){$stale=$request;$stale['expectedInventoryRevision']++;$s->send($a,$stale);});
    reject('SELF_GIFT',static function()use($s,$a,$request){$self=$request;$self['recipientId']=$a;$s->send($a,$self);});
    reject('RECIPIENT_NOT_FOUND',static function()use($s,$a,$request){$unknown=$request;$unknown['recipientId']=Database::uuid();$s->send($a,$unknown);});
    reject('ITEM_NOT_FOUND',static function()use($s,$a,$request){$missing=$request;$missing['instanceId']=Database::uuid();$s->send($a,$missing);});
    reject('INVALID_REQUEST',static function()use($s,$a,$request){$extra=$request;$extra['fee']=0;$s->send($a,$extra);});
    reject('INVALID_REQUEST',static function()use($s,$b,$request){$s->send($b,$request);});
    reject('OPERATION_EXPIRED',static function()use($s,$a,$request,&$now){$old=$request;$old['issuedAt']=GiftRules::instant($now-GiftRules::RETENTION-1);$s->send($a,$old);});
    $sent=$s->send($a,$request);$g=$sent['gift'];check(false,$sent['replayed'],'First send');check('offered',$g['status'],'Offered');check([],$items->get($a)['instances'],'Original withdrawn');
    check(GiftRules::instant($now+GiftRules::EXPIRY),$g['expiresAt'],'Seven days of server time');check([null,0,1],[$g['currencyKey'],$g['fee'],$g['configRevision']],'Free gift quote');check(2,$g['revision'],'Offer entry bound in second revision');
    check(['operationId'=>$request['operationId'],'issuedAt'=>$request['issuedAt'],'giftId'=>$g['id'],'giftRevision'=>2,'recordedAt'=>GiftRules::instant($now),'recipientId'=>$b,'itemReference'=>['instanceId'=>$ref['instanceId'],'itemKey'=>'sword'],'currencyKey'=>null,'fee'=>0,'configRevision'=>1],$sent['receipt'],'Exact receipt');
    $replay=$s->send($a,array_reverse($request,true));check(true,$replay['replayed'],'Field order cannot change the intent');check($sent['receipt'],$replay['receipt'],'Original receipt');
    $changed=$request;$changed['recipientId']=$c;reject('OPERATION_CONFLICT',static function()use($s,$a,$changed){$s->send($a,$changed);});
    check($sent['receipt'],$s->operation($a,$request['operationId'])['receipt'],'Operation point');reject('OPERATION_NOT_FOUND',static function()use($s,$b,$request){$s->operation($b,$request['operationId']);});
    check(1,$s->adminConfig()['config']['escrowCount'],'Escrow counter');check(1,$s->adminConfig()['config']['revision'],'Counters do not revise the quote');
    $offer=$entry($b,'item_gift');check('horizon.item_gifts.offer',$offer['textKey'],'Offer text key');check(['senderId'=>$a],(array)$offer['parameters'],'Offer parameters');check($g['offerEntryId'],$offer['id'],'Bound entry');
    check(['values'=>[],'itemGift'=>['giftId'=>$g['id'],'instanceId'=>$ref['instanceId'],'itemKey'=>'sword','direction'=>'offer']],$offer['attachment'],'Offer attachment');check($g['expiresAt'],$offer['expiresAt'],'Offer deadline');
    check(1,$inbox->get($b)['counts']->item_gift['claimable'],'Offer counts as claimable');reject('UNCLAIMED_REWARD',static function()use($inbox,$b,$offer){$inbox->remove($b,$offer['id']);});
    $state=$s->state($a)['state'];check([$g['id']],array_column($state['sent'],'id'),'Sender open list');check([$g['id']],array_column($s->state($b)['state']['received'],'id'),'Recipient received list');
    check($g,$s->get($b,$g['id'])['gift'],'Recipient point');reject('GIFT_NOT_FOUND',static function()use($s,$c,$g){$s->get($c,$g['id']);});
    $player=GiftRules::fields($state,['userId','revision','sent','history','received','operationWindow','limits']);check(GiftRules::instant($now-GiftRules::RETENTION),$player['operationWindow']['acceptAfter'],'Acceptance window');
    $fake=['type'=>'item_gift','reference'=>$g['id'],'textKey'=>'horizon.item_gifts.offer','expiresAt'=>GiftRules::instant($now+1000),'attachment'=>['values'=>[],'itemGift'=>['giftId'=>$g['id'],'instanceId'=>$ref['instanceId'],'itemKey'=>'sword','direction'=>'offer']]];
    reject('INVALID_REQUEST',static function()use($inbox,$c,$fake,$now){$inbox->deliver($c,$fake,Database::uuid(),$now,'untrusted');});reject('INVALID_REQUEST',static function()use($inbox,$fake,$now){$inbox->publish($fake,$now.':'.Database::uuid(),0);});

    // Claim through the existing Inbox claim delivers the original exactly once.
    $claimed=$inbox->claim($b,$offer['id']);check([['instanceId'=>$ref['instanceId'],'itemKey'=>'sword']],$claimed['items'],'Claim lists the original');check(false,$claimed['replayed'],'First claim');
    check(GiftRules::json($before),GiftRules::json($items->get($b)['instances'][0]),'Original identity and properties unchanged');check('claimed',$s->get($a,$g['id'])['gift']['status'],'Claimed');
    check(0,$s->adminConfig()['config']['escrowCount'],'Escrow released');check([],$s->state($a)['state']['sent'],'No longer open');check([$g['id']],array_column($s->state($a)['state']['history'],'id'),'Sender history');
    $items->discard($b,$ref['instanceId'],$items->get($b)['revision']);$again=$inbox->claim($b,$offer['id']);check(true,$again['replayed'],'Claim replay');check($claimed['items'],$again['items'],'Replay lists the original');check([],$items->get($b)['instances'],'No second original');
    reject('GIFT_NOT_FOUND',static function()use($s,$c,$g){$s->decline($c,$g['id'],['userId'=>$c,'expectedGiftRevision'=>3]);});
    reject('GIFT_ALREADY_CLAIMED',static function()use($s,$b,$g){$s->decline($b,$g['id'],['userId'=>$b,'expectedGiftRevision'=>3]);});

    // A fee is charged once and never refunded; decline returns the original to the sender's Inbox.
    $now+=2000;$s->configure(1,['enabled'=>true,'currencyKey'=>'gift.gold','fee'=>30]);$ref2=$grant($a);$before2=$items->get($a)['instances'][0];$paid=$intent($a,$b,$ref2['instanceId']);
    $sent2=$s->send($a,$paid);$g2=$sent2['gift'];check(['gift.gold',30,2],[$g2['currencyKey'],$g2['fee'],$g2['configRevision']],'Fee quote frozen on gift');check(70,$balance($a),'Fee debited');
    check(true,$s->send($a,$paid)['replayed'],'Paid replay');check(70,$balance($a),'Replay never charges again');
    reject('REVISION_CONFLICT',static function()use($s,$b,$g2){$s->decline($b,$g2['id'],['userId'=>$b,'expectedGiftRevision'=>1]);});
    $declined=$s->decline($b,$g2['id'],['userId'=>$b,'expectedGiftRevision'=>2]);check(false,$declined['replayed'],'First decline');check('delivered',$declined['returnDelivery'],'Return delivered');check('return_delivered',$declined['gift']['status'],'Return delivered status');
    check(GiftRules::instant($now),$declined['gift']['declinedAt'],'Decline time');check(null,$entry($b,'item_gift',$g2['id']),'Offer entry disappears on decline');
    $replayDecline=$s->decline($b,$g2['id'],['userId'=>$b,'expectedGiftRevision'=>2]);check(true,$replayDecline['replayed'],'Decline replay');check('delivered',$replayDecline['returnDelivery'],'Replay keeps delivery');
    $return=$entry($a,'item_gift_return');check('horizon.item_gifts.return',$return['textKey'],'Return text key');check(['reason'=>'declined','recipientId'=>$b],(array)$return['parameters'],'Return parameters');check($declined['gift']['returnEntryId'],$return['id'],'Return entry bound');
    check(['values'=>[],'itemGift'=>['giftId'=>$g2['id'],'instanceId'=>$ref2['instanceId'],'itemKey'=>'sword','direction'=>'return']],$return['attachment'],'Return attachment');
    reject('UNCLAIMED_REWARD',static function()use($inbox,$a,$return){$inbox->remove($a,$return['id']);});
    $now+=GiftRules::RETENTION+1;check(true,$entry($a,'item_gift_return')['overdue'],'Unclaimed return has no deleting expiry');
    $back=$inbox->claim($a,$return['id']);check([['instanceId'=>$ref2['instanceId'],'itemKey'=>'sword']],$back['items'],'Return claim lists the original');check(GiftRules::json($before2),GiftRules::json($items->get($a)['instances'][0]),'Original back unchanged');
    check(70,$balance($a),'Fee not refunded');check('returned',$s->get($a,$g2['id'])['gift']['status'],'Returned');check(true,$inbox->claim($a,$return['id'])['replayed'],'Return claim replay');check(1,count($items->get($a)['instances']),'No duplicate return');

    // Expiry is enforced at claim time, before any maintenance ran.
    $now+=2000;$s->configure(2,['enabled'=>true,'currencyKey'=>null,'fee'=>0]);$ref3=$items->get($a)['instances'][0];$request3=$intent($a,$c,$ref3['instanceId']);$sent3=$s->send($a,$request3);$g3=$sent3['gift'];$offer3=$entry($c,'item_gift');
    $now=$g3===null?$now:GiftRules::millis($g3['expiresAt']);reject('ENTRY_NOT_FOUND',static function()use($inbox,$c,$offer3){$inbox->claim($c,$offer3['id']);});
    reject('GIFT_EXPIRED',static function()use($scope,$pdo,$clock,$c,$offer3){$scope->run(static function()use($pdo,$clock,$scope,$c,$offer3){return(new GiftService($pdo,$clock,$scope))->claim($c,$offer3['id'],$offer3['attachment']['itemGift']);});});
    check('offered',$s->get($c,$g3['id'])['gift']['status'],'Rejected claim changed nothing');reject('GIFT_EXPIRED',static function()use($s,$c,$g3){$s->decline($c,$g3['id'],['userId'=>$c,'expectedGiftRevision'=>2]);});
    $run=$s->cleanup();check([1,1,2],[$run['returnsPrepared'],$run['returnsDelivered'],$run['terminalRemoved']],'Maintenance returns the expired offer and removes old terminal gifts');
    $return3=$entry($a,'item_gift_return');check('expired',$return3['parameters']->reason,'Expired reason');check([$ref3['instanceId']],array_column($inbox->claim($a,$return3['id'])['items'],'instanceId'),'Expired return claimed');

    // A full recipient Inbox and an insufficient balance roll the whole send back.
    $now+=2000;$ref4=$items->get($a)['instances'][0];// The expired offer tuple is pruned on delivery, so fill one beyond the quota.
    fillInboxFixture($pdo,$c,$now,51);$full=$intent($a,$c,$ref4['instanceId']);
    reject('RECIPIENT_INBOX_FULL',static function()use($s,$a,$full){$s->send($a,$full);});check(1,count($items->get($a)['instances']),'Nothing withdrawn on full Inbox');check(0,(int)$pdo->query('SELECT COUNT(*) FROM item_gifts WHERE status=\'offered\'')->fetchColumn(),'No gift row');
    $s->configure(3,['enabled'=>true,'currencyKey'=>'gift.gold','fee'=>500]);$poor=$intent($a,$b,$ref4['instanceId']);reject('INSUFFICIENT_BALANCE',static function()use($s,$a,$poor){$s->send($a,$poor);});check(1,count($items->get($a)['instances']),'Nothing withdrawn without balance');check(70,$balance($a),'Nothing charged');
    $s->configure(4,['enabled'=>true,'currencyKey'=>null,'fee'=>0]);
    $items->equip($a,$ref4['instanceId'],$items->get($a)['revision'],true);$equipped=$intent($a,$b,$ref4['instanceId']);reject('ITEM_EQUIPPED',static function()use($s,$a,$equipped){$s->send($a,$equipped);});$items->equip($a,$ref4['instanceId'],$items->get($a)['revision'],false);
    $friends->change('block',$b,$a,$friends->get($b)['revision']);$blocked=$intent($a,$b,$ref4['instanceId']);reject('RECIPIENT_NOT_FOUND',static function()use($s,$a,$blocked){$s->send($a,$blocked);});$friends->change('unblock',$b,$a,$friends->get($b)['revision']);

    // Player cooldown and project rate.
    $d=$user();$e=$user();$refD=[$grant($d)['instanceId'],$grant($d)['instanceId']];$s->send($d,$intent($d,$e,$refD[0]));
    try{$s->send($d,$intent($d,$e,$refD[1]));throw new RuntimeException('Expected cooldown');}catch(GiftFailure $failure){check(['ITEM_GIFTS_RATE_LIMITED',429,1],[$failure->errorCode,$failure->status,$failure->retryAfter],'Player cooldown with Retry-After');}
    $limited=new GiftService($pdo,$clock,$scope,array_replace($s->limits(),['projectWritesPerMinute'=>1]));$now=intdiv($now,60000)*60000+60000;$s->send($d,$intent($d,$e,$refD[1]));
    $f=$user();$refF=$grant($f);$rate=$intent($f,$e,$refF['instanceId']);reject('ITEM_GIFTS_RATE_LIMITED',static function()use($limited,$f,$rate){$limited->send($f,$rate);});
    $open=new GiftService($pdo,$clock,$scope,array_replace($s->limits(),['openGiftsPerPlayer'=>2]));$now+=60000;$refD3=$grant($d);$third=$intent($d,$e,$refD3['instanceId']);reject('OPEN_GIFT_LIMIT_REACHED',static function()use($open,$d,$third){$open->send($d,$third);});
    $retained=new GiftService($pdo,$clock,$scope,array_replace($s->limits(),['retainedGiftsPerProject'=>1]));reject('GIFT_LIMIT_REACHED',static function()use($retained,$d,$third){$retained->send($d,$third);});

    // Full inventory leaves the offer open until its deadline.
    $giftsToE=array_values(array_filter($inbox->get($e)['entries'],static function($v){return $v['type']==='item_gift';}));check(2,count($giftsToE),'Two open offers');$grant($e);$grant($e);$grant($e);
    reject('INVENTORY_FULL',static function()use($inbox,$e,$giftsToE){$inbox->claim($e,$giftsToE[0]['id']);});check(false,$entry($e,'item_gift')['claimed'],'Offer stays open');
    $items->discard($e,$items->get($e)['instances'][0]['instanceId'],$items->get($e)['revision']);check(1,count($inbox->claim($e,$giftsToE[0]['id'])['items']),'Claim after freeing space');

    // Full sender Inbox keeps a return pending; the next maintenance delivers it.
    $g5=$s->get($e,array_values(array_filter($s->state($e)['state']['received'],static function($v){return true;}))[0]['id'])['gift'];fillInboxFixture($pdo,$d,$now);
    $dec=$s->decline($e,$g5['id'],['userId'=>$e,'expectedGiftRevision'=>$g5['revision']]);check(['pending','return_pending'],[$dec['returnDelivery'],$dec['gift']['status']],'Full sender Inbox keeps the return pending');
    $pdo->prepare('DELETE FROM inbox_state WHERE user_id=?')->execute([$d]);check(0,$s->cleanup()['returnsDelivered'],'Retry waits thirty minutes');$now+=GiftRules::RETRY;check(1,$s->cleanup()['returnsDelivered'],'Retry delivers');
    check('return_delivered',$s->get($d,$g5['id'])['gift']['status'],'Delivered after retry');

    // Terminal removal raises the sender's floor so an old intent cannot reopen escrow.
    $now+=GiftRules::TERMINAL+1;$retainedBefore=$s->adminConfig()['config']['retainedCount'];$removed=$s->cleanup()['terminalRemoved'];check(true,$removed>=1,'Terminal gifts removed after seven days');
    check($retainedBefore-$removed,$s->adminConfig()['config']['retainedCount'],'Retained counter follows removal');reject('GIFT_NOT_FOUND',static function()use($s,$a,$g){$s->get($a,$g['id']);});
    check(true,GiftRules::millis($request3['issuedAt'])>$now-GiftRules::RETENTION,'Intent still inside the window');reject('OPERATION_EXPIRED',static function()use($s,$a,$request3){$s->send($a,$request3);});reject('OPERATION_NOT_FOUND',static function()use($s,$a,$request3){$s->operation($a,$request3['operationId']);});

    // Deleted recipient: the offer goes back. Deleted sender: the offer stays claimable, a return is held for recovery.
    $now+=2000;$h=$user();$i=$user();$j=$user();$refH=$grant($h);$toDeleted=$s->send($h,$intent($h,$i,$refH['instanceId']))['gift'];$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$i]);
    $clean=$s->cleanupDeleted();check(1,$clean['offersReturned'],'Offer to deleted recipient returns');check('return_delivered',$s->get($h,$toDeleted['id'])['gift']['status'],'Return delivered to sender');
    $refJ=$grant($j);$now+=2000;$fromDeleted=$s->send($j,$intent($j,$h,$refJ['instanceId']))['gift'];$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$j]);$s->cleanupDeleted();
    $claimOrphan=$inbox->claim($h,$s->get($h,$fromDeleted['id'])['gift']['offerEntryId']);check([$refJ['instanceId']],array_column($claimOrphan['items'],'instanceId'),'Offer of a deleted sender stays claimable');
    $k=$user();$l=$user();$refK=$grant($k);$now+=2000;$held=$s->send($k,$intent($k,$l,$refK['instanceId']))['gift'];$s->decline($l,$held['id'],['userId'=>$l,'expectedGiftRevision'=>2]);$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$k]);
    $clean=$s->cleanupDeleted();check([1,true],[$clean['returnsHeld'],$clean['complete']],'Return of deleted owner held');$heldView=$s->get(null,$held['id']);check(true,$heldView['gift']['ownerUnavailable'],'Owner unavailable');
    reject('RECOVERY_NOT_ALLOWED',static function()use($s,$toDeleted,$h){$current=$s->get(null,$toDeleted['id'])['gift'];$s->recover($toDeleted['id'],$current['revision'],$h,'owner alive');});
    reject('REVISION_CONFLICT',static function()use($s,$held,$l){$s->recover($held['id'],1,$l,'stale');});
    $recovered=$s->recover($held['id'],$heldView['gift']['revision'],$l,'support recovery');check(['delivered',$l,false],[$recovered['delivery'],$recovered['gift']['returnOwnerId'],$recovered['gift']['ownerUnavailable']],'Recovered to an active player');
    check('support recovery',$recovered['recoveries'][0]['note'],'Recovery audit');$recoveredEntry=$entry($l,'item_gift_return');check([$refK['instanceId']],array_column($inbox->claim($l,$recoveredEntry['id'])['items'],'instanceId'),'Recovered original claimed once');
    check(0,(int)$pdo->query('SELECT COUNT(*) FROM gift_players WHERE active_user_id IS NULL')->fetchColumn(),'Ended ledgers removed');
    $list=$s->adminGifts(null,2);check(2,count($list['gifts']),'Bounded operator page');check(true,$list['nextCursor']!==null,'Operator cursor');check(true,count($s->adminGifts(null,50,$list['nextCursor'])['gifts'])>=1,'Next operator page');
    echo 'PASS: Item gifts '.($dsn?'MySQL':'SQLite').' '.$passed." assertions\n";
}finally{$pdo=null;@unlink($env);if(!$dsn)foreach([$target,$target.'-wal',$target.'-shm']as$file)if(is_file($file))unlink($file);}
