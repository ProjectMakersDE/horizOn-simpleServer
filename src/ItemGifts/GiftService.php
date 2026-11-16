<?php

declare(strict_types=1);
require_once __DIR__.'/GiftStore.php';
require_once __DIR__.'/../Core/Database.php';
require_once __DIR__.'/../Core/SqlTransaction.php';
require_once __DIR__.'/../Items/ItemService.php';
require_once __DIR__.'/../PlayerValues/PlayerValuesService.php';
require_once __DIR__.'/../Inbox/InboxService.php';
require_once __DIR__.'/../Friends/FriendsService.php';

/**
 * Private item gifts (TASK-1140). The sender's original moves into escrow, the optional fee is debited and the
 * recipient's typed Inbox reference is delivered in one SQL transaction. Any failure rolls all of it back.
 * Decline and expiry prepare the return in one commit; delivery to the sender's Inbox is a second commit.
 */
class GiftService
{
    private PDO $pdo; private bool $mysql; private SqlTransaction $transactions; private GiftStore $store;
    private ItemService $items; private PlayerValuesService $values; private InboxService $inbox; private FriendsService $blocks; private $clock; private array $cap;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null,?array $limits=null,?ItemService $items=null)
    {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->clock=$clock??static function(){return Database::nowMillis();};
        $this->transactions=$transactions??new SqlTransaction($pdo);if(!$this->transactions->owns($pdo))throw new InvalidArgumentException('Foreign item gift SQL connection');
        $this->store=new GiftStore($pdo);$this->items=$items??new ItemService($pdo,$this->clock,$this->transactions);
        $this->values=new PlayerValuesService($pdo,$this->clock,$this->transactions);$this->inbox=new InboxService($pdo,$this->clock,$this->transactions);
        $this->blocks=new FriendsService($pdo,$this->clock,$this->transactions);
        $this->cap=$limits??GiftRules::operatorLimits();GiftRules::validateLimits($this->cap);
        if(!$this->mysql)$pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function now(): int { return ($this->clock)(); }
    private function time(): string { return GiftRules::instant($this->now()); }
    private function atomic(callable $work)
    {
        if($this->pdo->inTransaction()&&!$this->transactions->isRunning())GiftRules::fail('STATE_CONFLICT');
        try{return $this->transactions->run($work);}
        catch(SqlTransactionUncertain $e){GiftRules::fail('STATE_CONFLICT');}
        catch(SqlTransactionRollbackOnly $e){GiftRules::fail('STATE_CONFLICT');}
        catch(PDOException $e){if(in_array((int)($e->errorInfo[1]??0),[5,6,19,1062,1205,1213,1452,2006,2013],true))GiftRules::fail('STATE_CONFLICT');throw $e;}
    }
    private function snapshot(callable $work)
    {
        if($this->transactions->isActive()){if($this->mysql&&!$this->transactions->isRunning())GiftRules::fail('STATE_CONFLICT');return $work();}
        if($this->mysql)$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->beginTransaction();
        try{$r=$work();$this->pdo->commit();return $r;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function actors(array $ids,bool $lock=false): void
    { if(count($this->store->users($ids,$lock))!==count(array_unique($ids)))GiftRules::fail('PLAYER_NOT_FOUND',404); }
    private function gift(string $id,bool $lock=false): array
    { $g=$this->store->get($id,$lock);if($g===null)GiftRules::fail('GIFT_NOT_FOUND',404);return $g; }
    private static function delivery(array $g): string
    { return ['returned'=>'returned','return_delivered'=>'delivered','claimed'=>'claimed'][$g['status']]??'pending'; }

    public function limits(): array { return $this->cap; }
    public function config(string $user): array
    { $this->actors([GiftRules::uuid($user)]);return['config'=>GiftRules::config($this->store->header()),'limits'=>$this->cap,'serverTime'=>$this->time()]; }
    /** Local operator view with escrow counters. */
    public function adminConfig(): array { return['config'=>GiftRules::config($this->store->header(),true),'limits'=>$this->cap,'serverTime'=>$this->time()]; }
    /** Local operator only. fee > 0 needs a saved Player Values currency, fee = 0 needs currencyKey null. */
    public function configure(int $expected,$raw): array
    {
        GiftRules::integer($expected);$r=GiftRules::fields($raw,['enabled','currencyKey','fee']);GiftRules::boolean($r['enabled']);GiftRules::integer($r['fee']);
        if($r['currencyKey']!==null)GiftRules::currency($r['currencyKey']);if(($r['fee']>0)!==($r['currencyKey']!==null))GiftRules::invalid();
        return $this->atomic(function()use($expected,$r){
            $h=$this->store->header(true);GiftRules::expected($h['revision'],$expected);
            if($r['currencyKey']!==null&&!in_array($r['currencyKey'],array_column($this->values->config(false,true)['definitions'],'key'),true))GiftRules::invalid();
            $next=array_replace($h,$r);$next['revision']=GiftRules::next($h['revision']);$next=$this->store->saveHeader($h,$next);
            return['config'=>GiftRules::config($next,true),'limits'=>$this->cap,'serverTime'=>$this->time()];
        });
    }
    public function state(string $user): array
    {
        $user=GiftRules::uuid($user);
        return $this->snapshot(function()use($user){
            $this->actors([$user]);$p=$this->store->player($user);$now=$this->now();$rows=[];
            foreach($this->store->many(array_merge($p['openIds'],$p['historyIds']))as$g)$rows[$g['id']]=$g;
            $sent=[];foreach($p['openIds']as$id){$g=$rows[$id]??null;if($g===null||GiftRules::terminal($g)||$g['returnOwnerId']!==$user)GiftRules::fail('STATE_CONFLICT');$sent[]=GiftRules::view($g);}
            $history=[];foreach($p['historyIds']as$id){$g=$rows[$id]??null;if($g!==null&&GiftRules::terminal($g)&&!GiftRules::expiredAudit($g,$now))$history[]=GiftRules::view($g);}
            $received=array_map([GiftRules::class,'view'],$this->store->received($user,$now,$this->cap['receivedPageSize']));
            $window=['serverTime'=>GiftRules::instant($now),'acceptAfter'=>GiftRules::instant(max($now-GiftRules::RETENTION,$p['floor']+1))];
            return['state'=>['userId'=>$user,'revision'=>$p['revision'],'sent'=>$sent,'history'=>$history,'received'=>$received,'operationWindow'=>$window,'limits'=>$this->cap],'serverTime'=>GiftRules::instant($now)];
        });
    }
    /** Sender, recipient and return owner see the gift until seven days after it ended. The operator also sees recoveries. */
    public function get(?string $user,string $id): array
    {
        $id=GiftRules::uuid($id);if($user!==null)$this->actors([GiftRules::uuid($user)]);$g=$this->gift($id);
        if($user!==null&&(!in_array($user,[$g['senderId'],$g['recipientId'],$g['returnOwnerId']],true)||GiftRules::expiredAudit($g,$this->now())))GiftRules::fail('GIFT_NOT_FOUND',404);
        $r=['gift'=>GiftRules::view($g),'serverTime'=>$this->time()];if($user===null)$r=['gift'=>$r['gift'],'recoveries'=>$g['recoveries'],'serverTime'=>$r['serverTime']];return $r;
    }
    public function operation(string $user,string $operation): array
    {
        $user=GiftRules::uuid($user);$operation=GiftRules::uuid($operation);$this->actors([$user]);$g=$this->store->operation($user,$operation);
        if($g!==null)foreach($g['ops']as$op)if($op['u']===$user&&$op['o']===$operation)return['receipt'=>GiftRules::receipt($op,$g),'serverTime'=>$this->time()];
        GiftRules::fail('OPERATION_NOT_FOUND',404);
    }
    private function replay(string $user,string $operation,string $hash): ?array
    {
        $g=$this->store->operation($user,$operation,true);if($g===null)return null;
        foreach($g['ops']as$op)if($op['u']===$user&&$op['o']===$operation){if(!hash_equals($op['h'],$hash))GiftRules::fail('OPERATION_CONFLICT');
            return['receipt'=>GiftRules::receipt($op,$g),'gift'=>GiftRules::expiredAudit($g,$this->now())?null:GiftRules::view($g),'replayed'=>true,'serverTime'=>$this->time()];}
        GiftRules::fail('STATE_CONFLICT');
    }
    private function rate(array &$h,int $now): void
    {
        $bucket=intdiv($now,60000);$retry=static function(int $until)use($now){return max(1,(int)ceil(($until-$now)/1000));};
        if($bucket<$h['bucket'])GiftRules::fail('ITEM_GIFTS_RATE_LIMITED',429,$retry(($h['bucket']+1)*60000));
        if($bucket>$h['bucket']){$h['bucket']=$bucket;$h['writes']=0;}
        if($h['writes']>=$this->cap['projectWritesPerMinute'])GiftRules::fail('ITEM_GIFTS_RATE_LIMITED',429,$retry(($bucket+1)*60000));$h['writes']++;
    }
    public function send(string $user,$raw): array
    {
        $user=GiftRules::uuid($user);$r=GiftRules::fields($raw,['userId','operationId','issuedAt','recipientId','instanceId','expectedInventoryRevision','expectedConfigRevision']);
        if(GiftRules::uuid($r['userId'])!==$user)GiftRules::invalid();GiftRules::uuid($r['operationId']);$issued=GiftRules::millis($r['issuedAt']);GiftRules::uuid($r['recipientId']);GiftRules::uuid($r['instanceId']);
        GiftRules::integer($r['expectedInventoryRevision']);GiftRules::integer($r['expectedConfigRevision']);
        $hash=PlayerValuesLedger::hash('item-gift',$user,$r['operationId'],(string)$issued,'send',$r['recipientId'],$r['instanceId'],(string)$r['expectedInventoryRevision'],(string)$r['expectedConfigRevision']);
        return $this->atomic(function()use($user,$r,$issued,$hash){
            $h=$this->store->header(true);$this->actors([$user],true);$p=$this->store->player($user,true);
            $old=$this->replay($user,$r['operationId'],$hash);if($old!==null)return $old;
            $now=$this->now();GiftRules::window($p,$issued,$now);
            if($p['nextAllowedAt']>$now)GiftRules::fail('ITEM_GIFTS_RATE_LIMITED',429,max(1,(int)ceil(($p['nextAllowedAt']-$now)/1000)));
            $recipient=$r['recipientId'];if($recipient===$user)GiftRules::fail('SELF_GIFT',422);
            if(!$h['enabled']||($h['fee']>0&&$h['currencyKey']===null))GiftRules::fail('ITEM_GIFTS_DISABLED',403);GiftRules::expected($h['revision'],$r['expectedConfigRevision']);
            // A block in either direction looks exactly like an unknown player.
            if(!$this->store->users([$recipient])||$this->blocks->blocked($user,$recipient))GiftRules::fail('RECIPIENT_NOT_FOUND',404);
            if(count($p['openIds'])>=$this->cap['openGiftsPerPlayer'])GiftRules::fail('OPEN_GIFT_LIMIT_REACHED',422);
            if($h['retainedCount']>=$this->cap['retainedGiftsPerProject'])GiftRules::fail('GIFT_LIMIT_REACHED',422);
            $nextH=$h;$this->rate($nextH,$now);$this->actors([$user,$recipient],true);
            $item=$this->items->escrowWithdraw($user,$r['instanceId'],$r['expectedInventoryRevision']);
            if($h['fee']>0)$this->values->charge($user,$h['currencyKey'],$h['fee']);
            $id=Database::uuid();
            $g=['id'=>$id,'revision'=>1,'senderId'=>$user,'recipientId'=>$recipient,'returnOwnerId'=>$user,'item'=>$item,'itemReference'=>['instanceId'=>$item['instanceId'],'itemKey'=>$item['itemKey']],
                'currencyKey'=>$h['fee']>0?$h['currencyKey']:null,'fee'=>$h['fee'],'configRevision'=>$h['revision'],'status'=>'offered','createdAt'=>$now,'expiresAt'=>$now+GiftRules::EXPIRY,
                'terminalAt'=>null,'declinedAt'=>null,'offerEntryId'=>null,'returnEntryId'=>null,'returnIssuedAt'=>null,'returnGeneration'=>0,'ownerUnavailable'=>false,
                'dueKind'=>GiftRules::DUE_RETURN,'dueAt'=>$now+GiftRules::EXPIRY,'recoveries'=>[],'ops'=>[['u'=>$user,'o'=>$r['operationId'],'i'=>$issued,'h'=>$hash,'r'=>2,'t'=>$now]]];
            $this->store->insert($g);$g=$this->deliverOffer($g,$now);
            $nextP=$p;$nextP['openIds'][]=$id;$nextP['nextAllowedAt']=$now+$this->cap['playerWriteIntervalMillis'];$this->store->savePlayer($user,$p,$nextP);
            $nextH['retainedCount']++;$nextH['escrowCount']++;$this->store->saveHeader($h,$nextH);
            return['receipt'=>GiftRules::receipt($g['ops'][0],$g),'gift'=>GiftRules::view($g),'replayed'=>false,'serverTime'=>GiftRules::instant($now)];
        });
    }
    /** Joins the send transaction. A full recipient Inbox fails the whole send, so nothing is withdrawn or charged. */
    private function deliverOffer(array $g,int $now): array
    {
        $source=GiftRules::SOURCE.$g['id'].':offer';$next=null;
        $content=['type'=>'item_gift','reference'=>$g['id'],'textKey'=>'horizon.item_gifts.offer','parameters'=>['senderId'=>$g['senderId']],'expiresAt'=>GiftRules::instant($g['expiresAt']),
            'attachment'=>['values'=>[],'itemGift'=>['giftId'=>$g['id'],'instanceId'=>$g['itemReference']['instanceId'],'itemKey'=>$g['itemReference']['itemKey'],'direction'=>'offer']]];
        try{
            $this->inbox->deliverItemGiftPending($g['recipientId'],$content,$source,$now,$source,function(PDO $connection,string $entry)use($g,&$next){
                if($connection!==$this->pdo)throw new LogicException('Foreign gift PDO');$next=$g;$next['offerEntryId']=$entry;$next=$this->store->save($g,$next);return true;});
        }catch(InboxFailure $e){if($e->errorCode==='INBOX_FULL')GiftRules::fail('RECIPIENT_INBOX_FULL');throw $e;}
        if($next===null)GiftRules::fail('STATE_CONFLICT');return $next;
    }
    /** Only the recipient declines, only while the offer is open. A repeated decline replays the declined state. */
    public function decline(string $user,string $id,$raw): array
    {
        $user=GiftRules::uuid($user);$id=GiftRules::uuid($id);$r=GiftRules::fields($raw,['userId','expectedGiftRevision']);if(GiftRules::uuid($r['userId'])!==$user)GiftRules::invalid();GiftRules::integer($r['expectedGiftRevision']);
        [$declined,$replayed]=$this->atomic(function()use($user,$id,$r){
            $this->actors([$user],true);$g=$this->gift($id,true);$now=$this->now();
            if($g['recipientId']!==$user)GiftRules::fail('GIFT_NOT_FOUND',404);
            if($g['status']!=='offered'){if($g['declinedAt']!==null)return[$g,true];GiftRules::fail($g['status']==='claimed'?'GIFT_ALREADY_CLAIMED':'GIFT_EXPIRED');}
            GiftRules::expected($g['revision'],$r['expectedGiftRevision']);if(!GiftRules::claimable($g,$now))GiftRules::fail('GIFT_EXPIRED');
            return[$this->store->save($g,GiftRules::prepareReturn($g,$now,true)),false];
        });
        // Delivery is its own commit: a full sender Inbox never undoes the decline.
        try{$delivery=$this->deliverReturn($id);}catch(Throwable $e){$delivery='pending';}
        $current=$this->store->get($id)??$declined;
        return['gift'=>GiftRules::view($current),'replayed'=>$replayed,'returnDelivery'=>$delivery,'serverTime'=>$this->time()];
    }
    /** Prepares an expired offer and delivers a pending return. An offer that is still claimable is never cancelled. */
    public function retryReturn(string $id): array
    {
        $id=GiftRules::uuid($id);
        $prepared=$this->atomic(function()use($id){$g=$this->gift($id,true);$now=$this->now();
            if($g['status']==='offered'){if(GiftRules::claimable($g,$now))GiftRules::fail('GIFT_UNAVAILABLE');return $this->store->save($g,GiftRules::prepareReturn($g,$now));}return $g;});
        $delivery=$prepared['status']==='return_pending'&&!$prepared['ownerUnavailable']?$this->deliverReturn($id):self::delivery($prepared);
        return['gift'=>GiftRules::view($this->gift($id)),'delivery'=>$delivery,'serverTime'=>$this->time()];
    }
    private function returnContent(array $g): array
    {
        return['type'=>'item_gift_return','reference'=>$g['id'],'textKey'=>'horizon.item_gifts.return','parameters'=>['recipientId'=>$g['recipientId'],'reason'=>$g['declinedAt']!==null?'declined':'expired'],
            'expiresAt'=>GiftRules::instant($g['returnIssuedAt']+GiftRules::RETENTION),
            'attachment'=>['values'=>[],'itemGift'=>['giftId'=>$g['id'],'instanceId'=>$g['itemReference']['instanceId'],'itemKey'=>$g['itemReference']['itemKey'],'direction'=>'return']]];
    }
    private function deliverReturn(string $id): string
    {
        try{return $this->atomic(function()use($id){
            $g=$this->gift($id,true);if($g['status']!=='return_pending')return self::delivery($g);if($g['ownerUnavailable'])return'pending';
            if(!$this->store->users([$g['returnOwnerId']],true)){
                $next=$g;$next['ownerUnavailable']=true;$next['returnEntryId']=null;$next['dueKind']=GiftRules::DUE_NONE;$next['dueAt']=GiftRules::MAX;$this->store->save($g,$next);return'pending';}
            if($g['item']===null||$g['item']['instanceId']!==$g['itemReference']['instanceId'])GiftRules::fail('STATE_CONFLICT');
            $source=GiftRules::SOURCE.$g['id'].':return:'.$g['returnGeneration'];
            $this->inbox->deliverItemGiftPending($g['returnOwnerId'],$this->returnContent($g),$source,$g['returnIssuedAt'],$source,function(PDO $connection,string $entry)use($g){
                if($connection!==$this->pdo)throw new LogicException('Foreign gift PDO');$current=$this->store->get($g['id'],true);
                if($current===null||$current['revision']!==$g['revision']||$current['status']!=='return_pending'||$current['returnOwnerId']!==$g['returnOwnerId'])return false;
                $next=$current;$next['status']='return_delivered';$next['returnEntryId']=$entry;$next['dueKind']=GiftRules::DUE_NONE;$next['dueAt']=GiftRules::MAX;$this->store->save($current,$next);return true;});
            return'delivered';
        });}catch(InboxFailure $e){if($e->errorCode!=='INBOX_FULL')throw $e;
            // A fresh transaction after the failed delivery transaction has rolled back.
            $this->atomic(function()use($id){$g=$this->gift($id,true);if($g['status']==='return_pending'&&!$g['ownerUnavailable']){$next=$g;$next['dueAt']=max($g['dueAt'],$this->now()+GiftRules::RETRY);$this->store->save($g,$next);}});
            return'pending';}
    }
    /** Only Inbox claim, inside its exact shared SQL scope. The original instance reaches exactly one inventory. */
    public function claim(string $user,string $entry,array $attachment): array
    {
        if(!$this->transactions->isRunning())throw new LogicException('Gift claim requires Inbox SQL scope');
        return $this->atomic(function()use($user,$entry,$attachment){
            $h=$this->store->header(true);$this->actors([$user],true);$g=$this->store->get(GiftRules::uuid($attachment['giftId']),true);$now=$this->now();
            if($g===null||$g['item']===null||$g['itemReference']!==['instanceId'=>$attachment['instanceId'],'itemKey'=>$attachment['itemKey']]||$g['item']['instanceId']!==$attachment['instanceId'])GiftRules::fail('STATE_CONFLICT');
            $next=$g;
            if($attachment['direction']==='offer'){
                if($g['recipientId']!==$user||$g['offerEntryId']!==$entry)GiftRules::fail('STATE_CONFLICT');
                // Decline, expiry and maintenance compete for the same revision. A visible expired entry never claims.
                if($g['status']!=='offered')GiftRules::fail($g['declinedAt']!==null?'GIFT_DECLINED':'GIFT_EXPIRED');if(!GiftRules::claimable($g,$now))GiftRules::fail('GIFT_EXPIRED');
                $next['status']='claimed';
            }else{
                if($g['status']!=='return_delivered'||$g['returnOwnerId']!==$user||$g['ownerUnavailable']||$g['returnEntryId']!==$entry)GiftRules::fail('STATE_CONFLICT');
                $next['status']='returned';
            }
            $reference=$this->items->escrowReceive($user,$g['item']);
            $next['item']=null;$next['terminalAt']=$now;$next['dueKind']=GiftRules::DUE_TERMINAL;$next['dueAt']=$now+GiftRules::TERMINAL;$this->store->save($g,$next);
            $owner=$this->store->player($g['returnOwnerId'],true);
            if($owner['exists']){if(!in_array($g['id'],$owner['openIds'],true))GiftRules::fail('STATE_CONFLICT');$this->store->savePlayer($g['returnOwnerId'],$owner,GiftRules::history($owner,$g['id'],$this->cap['historyPerPlayer']));}
            elseif($this->store->users([$g['returnOwnerId']]))GiftRules::fail('STATE_CONFLICT');
            // A deleted sender's ledger is gone. The recipient still receives the original exactly once.
            $nextH=$h;$nextH['escrowCount']--;$this->store->saveHeader($h,$nextH);return[$reference];
        });
    }
    /** Operator recovery of a return whose owner identity ended, to another active player. */
    public function recover(string $id,int $expected,string $recipient,string $note): array
    {
        $id=GiftRules::uuid($id);$recipient=GiftRules::uuid($recipient);GiftRules::integer($expected);GiftRules::note($note);
        $this->atomic(function()use($id,$expected,$recipient,$note){
            $this->store->header(true);$g=$this->gift($id,true);GiftRules::expected($g['revision'],$expected);
            if(GiftRules::terminal($g)||$g['status']==='offered'||$this->store->users([$g['returnOwnerId']],true))GiftRules::fail('RECOVERY_NOT_ALLOWED',422);
            if(count($g['recoveries'])>=8)GiftRules::fail('RECOVERY_LIMIT_REACHED');
            $this->actors([$recipient],true);$p=$this->store->player($recipient,true);if(count($p['openIds'])>=$this->cap['openGiftsPerPlayer'])GiftRules::fail('OPEN_GIFT_LIMIT_REACHED',422);
            $old=$this->store->player($g['returnOwnerId'],true);if($old['exists']){$oldNext=$old;$oldNext['openIds']=array_values(array_diff($old['openIds'],[$id]));$this->store->savePlayer($g['returnOwnerId'],$old,$oldNext);}
            $newP=$p;$newP['openIds'][]=$id;$this->store->savePlayer($recipient,$p,$newP);
            $next=GiftRules::prepareReturn($g,$this->now());$next['returnOwnerId']=$recipient;$next['ownerUnavailable']=false;$next['returnGeneration']++;
            $next['recoveries'][]=['previousOwnerId'=>$g['returnOwnerId'],'recipientId'=>$recipient,'actor'=>'local-operator','at'=>$this->time(),'note'=>$note];$this->store->save($g,$next);
        });
        try{$delivery=$this->deliverReturn($id);}catch(Throwable $e){$delivery='pending';}
        return$this->get(null,$id)+['delivery'=>$delivery];
    }
    public function adminGifts(?string $status=null,int $limit=20,?string $cursor=null): array
    {
        if($status!==null&&!in_array($status,GiftRules::STATUSES,true))GiftRules::invalid();GiftRules::integer($limit,1,50);
        $c=GiftRules::unCursor($cursor);if($c!==null){$c=GiftRules::fields($c,['status','created','id']);if($c['status']!==$status)GiftRules::invalid();GiftRules::integer($c['created']);GiftRules::uuid($c['id']);}
        $rows=$this->store->adminPage($status,$c,$limit);$page=array_slice($rows,0,$limit);$out=array_map([GiftRules::class,'view'],$this->store->many(array_column($page,'id')));$last=$page?end($page):null;
        return['gifts'=>$out,'nextCursor'=>count($rows)>$limit?GiftRules::cursor(['status'=>$status,'created'=>(int)$last['created_millis'],'id'=>$last['id']]):null,'serverTime'=>$this->time()];
    }
    /** Floors and deletion commit together. Ended identities and already expired intents need no anchor. */
    private function retireOperations(array $g): void
    {
        foreach($g['ops']as$op){if($op['i']<$this->now()-GiftRules::RETENTION||!$this->store->users([$op['u']],true))continue;
            $p=$this->store->player($op['u'],true);$next=$p;$next['floor']=max($p['floor'],$op['i']);$next['historyIds']=array_values(array_diff($p['historyIds'],[$g['id']]));$this->store->savePlayer($op['u'],$p,$next);}
    }
    /** One bounded maintenance batch: at most ten due returns and ten terminal removals through the due index. */
    public function cleanup(): array
    {
        $summary=['examined'=>0,'returnsPrepared'=>0,'returnsDelivered'=>0,'terminalRemoved'=>0,'deferred'=>0];
        foreach($this->store->due(GiftRules::DUE_RETURN,$this->now(),10)as$row){$summary['examined']++;$id=$row['id'];
            try{
                $prepared=$this->atomic(function()use($id){$g=$this->store->get($id,true);if($g===null||$g['status']!=='offered'||GiftRules::claimable($g,$this->now()))return false;$this->store->save($g,GiftRules::prepareReturn($g,$this->now()));return true;});
                if($prepared)$summary['returnsPrepared']++;$g=$this->store->get($id);
                if($g!==null&&$g['status']==='return_pending'){if($this->deliverReturn($id)==='delivered')$summary['returnsDelivered']++;else$summary['deferred']++;}
            }catch(Throwable $e){$summary['deferred']++;$this->defer($id);}
        }
        foreach($this->store->due(GiftRules::DUE_TERMINAL,$this->now(),10)as$row){$summary['examined']++;$id=$row['id'];
            try{
                $removed=$this->atomic(function()use($id){$h=$this->store->header(true);$g=$this->store->get($id,true);if($g===null||!GiftRules::expiredAudit($g,$this->now())||$g['item']!==null)return false;
                    $this->retireOperations($g);$this->store->remove($g);$next=$h;$next['retainedCount']--;$this->store->saveHeader($h,$next);return true;});
                if($removed)$summary['terminalRemoved']++;
            }catch(Throwable $e){$summary['deferred']++;$this->defer($id);}
        }
        $summary['serverTime']=$this->time();return $summary;
    }
    /** A failed row moves behind the retry delay so it never blocks the head of the due index. */
    private function defer(string $id): void
    { try{$this->atomic(function()use($id){$g=$this->store->get($id,true);if($g!==null&&$g['dueKind']!==GiftRules::DUE_NONE){$next=$g;$next['dueAt']=max($g['dueAt'],$this->now()+GiftRules::RETRY);$this->store->save($g,$next);}});}catch(Throwable $e){} }
    /**
     * Offers to a deleted recipient go back to the sender. Returns owned by a deleted sender stay escrowed and
     * unavailable for operator recovery. Ledgers of ended identities are removed. Each step is bounded to 20 rows.
     */
    public function cleanupDeleted(): array
    {
        $summary=['offersReturned'=>0,'returnsHeld'=>0,'playersRemoved'=>0];$offers=$this->store->orphanedOffers(20);
        foreach($offers as$row){$id=$row['id'];
            $prepared=$this->atomic(function()use($id){$g=$this->store->get($id,true);if($g===null||$g['status']!=='offered'||$this->store->users([$g['recipientId']],true))return false;$this->store->save($g,GiftRules::prepareReturn($g,$this->now()));return true;});
            if($prepared){$summary['offersReturned']++;try{$this->deliverReturn($id);}catch(Throwable $e){}}
        }
        $returns=$this->store->orphanedReturns(20);
        foreach($returns as$row){$id=$row['id'];
            $held=$this->atomic(function()use($id){$g=$this->store->get($id,true);if($g===null||!in_array($g['status'],['return_pending','return_delivered'],true)||$g['ownerUnavailable']||$this->store->users([$g['returnOwnerId']],true))return false;
                $next=$g;$next['ownerUnavailable']=true;$next['returnEntryId']=null;$next['dueKind']=GiftRules::DUE_NONE;$next['dueAt']=GiftRules::MAX;$this->store->save($g,$next);return true;});
            if($held)$summary['returnsHeld']++;
        }
        $players=$this->store->deletedPlayers(20);
        foreach($players as$row)$this->atomic(function()use($row,&$summary){$this->store->removePlayer($row['user_id'],(int)$row['revision']);$summary['playersRemoved']++;});
        return$summary+['complete'=>count($offers)<20&&count($returns)<20&&count($players)<20,'serverTime'=>$this->time()];
    }
}
