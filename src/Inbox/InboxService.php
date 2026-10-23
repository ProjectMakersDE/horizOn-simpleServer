<?php

declare(strict_types=1);
require_once __DIR__.'/InboxRules.php';
require_once __DIR__.'/InboxStorage.php';
require_once __DIR__.'/InboxLimits.php';
require_once __DIR__.'/InboxSourceResolver.php';
require_once __DIR__.'/InboxLegacyRewards.php';
require_once __DIR__.'/../Core/SqlTransaction.php';
require_once __DIR__.'/../PlayerValues/PlayerValuesService.php';

/** A shared catalogue, one compact state per interacting player, explicit source-owned transactions. */
class InboxService
{
    private PDO $pdo; private bool $mysql; private $clock;
    private SqlTransaction $transactions; private PlayerValuesService $values; private InboxSourceResolver $sources;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null) {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->clock=$clock??static function(){return (int)floor(microtime(true)*1000);};
        $this->transactions=$transactions??new SqlTransaction($pdo);
        if(!$this->transactions->owns($pdo))throw new InvalidArgumentException('Foreign transaction connection');
        $this->values=new PlayerValuesService($pdo,$this->clock,$this->transactions);$this->sources=new InboxSourceResolver($pdo);
        if(!$this->mysql)$pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function transaction(callable $work) {
        try{return $this->transactions->run($work);}
        catch(SqlTransactionUncertain $e){InboxRules::fail('STATE_CONFLICT','Commit result uncertain; retry the original action');}
        catch(SqlTransactionRollbackOnly $e){InboxRules::fail('STATE_CONFLICT','Source transaction failed; retain the original intent');}
        catch(PDOException $e){if(in_array((int)($e->errorInfo[1]??0),[5,6,1205,1213,2006,2013],true))InboxRules::fail('STATE_CONFLICT','Concurrent write; retry the original action');throw $e;}
    }
    private function player(string $user,bool $lock=false): array {
        $q=$this->pdo->prepare('SELECT id,created_at,created_at_millis FROM users WHERE id=?'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute([$user]);$row=$q->fetch();
        if($row===false)InboxRules::fail('PLAYER_NOT_FOUND','Player not found',404);return $row;
    }
    private function catalog(int $lock=0): array {
        $suffix=$this->mysql?($lock===2?' FOR UPDATE':($lock===1?' LOCK IN SHARE MODE':'')):'';
        $raw=$this->pdo->query('SELECT data FROM inbox_catalog WHERE id=1'.$suffix)->fetchColumn();
        if($raw===false)throw new RuntimeException('Inbox schema is not initialized');return InboxStorage::catalog($raw);
    }
    private function state(string $user,bool $lock=false): array {
        $q=$this->pdo->prepare('SELECT data FROM inbox_state WHERE user_id=?'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute([$user]);$raw=$q->fetchColumn();
        return $raw===false?InboxStorage::emptyState():InboxStorage::state($raw);
    }
    private static function writable(array $state): void { if(!$state['_compact'])InboxStorage::required(); }
    private static function generation(array $state,int $issued): int { return max(PlayerValuesLedger::integer($issued,0),PlayerValuesLedger::integer($state['revision'],0,PlayerValuesLedger::MAX-1)+1); }
    private static function bump(array $state,?int $generation=null): array { $state=InboxRules::bump($state);if($generation!==null)$state['revision']=max($state['revision'],$generation);return $state; }
    public static function earliestExpiry(array $state,array $catalog): ?int {
        $times=[];foreach($state['sourceEntries']as$e)if($e['kind']!==7||$e['claimed'])$times[]=$e['expiresAtMillis'];
        foreach($state['freeTextEntries']as$e)$times[]=$e['expiresAtMillis'];
        foreach($state['entries']as$e)if(!InboxRules::valuable($e)||$e['claimed'])$times[]=InboxRules::millis($e['content']['expiresAt']);
        $ids=array_fill_keys(array_column(array_merge($state['ordinaryMarkers'],$state['markers']),'id'),true);
        foreach($catalog['entries']as$e)if(isset($ids[$e['id']])&&!InboxRules::valuable($e))$times[]=InboxRules::millis($e['content']['expiresAt']);
        return $times?min($times):null;
    }
    private function save(string $user,array $state,array $catalog): void {
        $sql=$this->mysql?'INSERT INTO inbox_state(user_id,data,next_expiry) VALUES(?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data),next_expiry=VALUES(next_expiry)':'INSERT INTO inbox_state(user_id,data,next_expiry) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data,next_expiry=excluded.next_expiry';
        $this->pdo->prepare($sql)->execute([$user,InboxStorage::encodeState($state),self::earliestExpiry($state,$catalog)]);
    }
    private function saveCatalog(array $catalog): void { $this->pdo->prepare('UPDATE inbox_catalog SET data=? WHERE id=1')->execute([InboxStorage::encodeCatalog($catalog)]); }
    private static function sourceRetained(array $entry,int $now): bool { return $entry['expiresAtMillis']>$now||($entry['kind']===7&&!$entry['claimed']); }
    private static function marker(array $state,array $catalog,string $id): array {
        $slot=InboxStorage::rewardSlot($catalog,$id);
        if($slot>=0&&$state['_compact']){$status=InboxStorage::status($state['rewardStates'],$slot);return ['id'=>$id,'read'=>$status!==0,'claimed'=>$status===2,'removed'=>$status===3];}
        foreach(array_merge($state['ordinaryMarkers'],$state['markers'])as$mark)if($mark['id']===$id)return $mark;
        return ['id'=>$id,'read'=>false,'claimed'=>false,'removed'=>false];
    }
    private static function mark(array $state,array $catalog,string $id,bool $claimed,bool $removed): array {
        $slot=InboxStorage::rewardSlot($catalog,$id);
        if($slot>=0){$state['rewardStates']=InboxStorage::setStatus($state['rewardStates'],$slot,$removed?3:($claimed?2:1));return $state;}
        // Noncanonical finite legacy identities remain exact instead of being silently lowercased.
        $target=$id==='broadcast:'.strtolower(substr($id,10))?'ordinaryMarkers':'markers';
        foreach(['ordinaryMarkers','markers']as$key)$state[$key]=array_values(array_filter($state[$key],static function($m)use($id){return $m['id']!==$id;}));
        $state[$target][]=['id'=>$id,'read'=>true,'claimed'=>$claimed,'removed'=>$removed];return $state;
    }
    private function entries(array $player,array $state,array $catalog,int $now): array {
        $entries=[];foreach($state['entries']as$e)if(InboxRules::retained($e,$now))$entries[]=InboxRules::viewEntry($e,$now);
        $source=array_values(array_filter($state['sourceEntries'],static function($e)use($now){return self::sourceRetained($e,$now);}));$resolved=$this->sources->resolve($player['id'],$source);
        foreach($source as$e){$id=InboxStorage::sourceId($e,$player['id']);if(isset($resolved[$id]))$entries[]=InboxRules::viewEntry(['id'=>$id,'content'=>$resolved[$id]['content'],'createdAt'=>InboxRules::instant($e['issuedAtMillis']),'read'=>$e['read'],'claimed'=>$e['claimed']],$now);}
        foreach($state['freeTextEntries']as$e)if($e['expiresAtMillis']>$now)$entries[]=InboxRules::viewEntry(['id'=>'message:'.$e['id'],'content'=>['type'=>'free_text','reference'=>'','textKey'=>'horizon.inbox.free_text','parameters'=>['text'=>$e['text']],'expiresAt'=>InboxRules::instant($e['expiresAtMillis']),'attachment'=>null],'createdAt'=>InboxRules::instant($e['createdAtMillis']),'read'=>$e['read'],'claimed'=>false],$now);
        $registered=$player['created_at_millis']===null?(new DateTimeImmutable($player['created_at'],new DateTimeZone('UTC')))->getTimestamp()*1000:(int)$player['created_at_millis'];
        foreach($catalog['entries']as$e){
            if($registered>InboxRules::millis($e['createdAt'])||!InboxRules::retained($e,$now))continue;$m=self::marker($state,$catalog,$e['id']);
            if($m['removed']||($m['claimed']&&InboxRules::millis($e['content']['expiresAt'])<=$now))continue;$entries[]=InboxRules::viewEntry($e,$now,$m);
        }
        usort($entries,static function($a,$b){return strcmp($b['createdAt'],$a['createdAt'])?:strcmp($a['id'],$b['id']);});return $entries;
    }
    private static function guard(array $state,array $entry): array {
        $state['legacyClaims']=array_values(array_filter($state['legacyClaims'],static function($g)use($entry){return $g['id']!==$entry['id'];}));
        $state['legacyClaims'][]=['id'=>$entry['id'],'sourceOperationId'=>$entry['sourceOperationId']];return $state;
    }
    private function prune(string $user,array $state,array $catalog,int $now): array {
        // Only consumed originals may compact to a small source guard; open valuable payloads remain intact.
        foreach($state['entries']as$e)if(!InboxRules::retained($e,$now)&&$e['claimed']&&InboxRules::valuable($e)){self::storedOriginal($state,$catalog,$e['id']);$state=self::guard($state,$e);}
        $state['entries']=array_values(array_filter($state['entries'],static function($e)use($now){return InboxRules::retained($e,$now);}));
        $candidates=array_values(array_filter($state['sourceEntries'],static function($e)use($now){return self::sourceRetained($e,$now);}));$resolved=$this->sources->resolve($user,$candidates);
        $state['sourceEntries']=array_values(array_filter($candidates,static function($e)use($resolved,$user){return isset($resolved[InboxStorage::sourceId($e,$user)]);}));
        $state['freeTextEntries']=array_values(array_filter($state['freeTextEntries'],static function($e)use($now){return $e['expiresAtMillis']>$now;}));
        $ordinary=[];foreach($catalog['entries']as$e)if(!InboxRules::valuable($e)&&InboxRules::retained($e,$now))$ordinary[$e['id']]=true;
        foreach($state['markers']as$m)if(InboxStorage::rewardSlot($catalog,$m['id'])>=0)$state=self::mark($state,$catalog,$m['id'],$m['claimed'],$m['removed']);
        foreach(['ordinaryMarkers','markers']as$key)$state[$key]=array_values(array_filter($state[$key],static function($m)use($ordinary){return isset($ordinary[$m['id']]);}));return $state;
    }
    private function view(array $player,array $state,array $catalog,int $now,?string $since=null): array {
        $entries=$this->entries($player,$state,$catalog,$now);$counts=[];
        foreach($entries as&$e){$e['parameters']=(object)$e['parameters'];$type=$e['type'];if(!isset($counts[$type]))$counts[$type]=['total'=>0,'unread'=>0,'claimable'=>0];$counts[$type]['total']++;if(!$e['read'])$counts[$type]['unread']++;if(!$e['claimed']&&InboxRules::attachmentValuable($e['attachment']))$counts[$type]['claimable']++;}unset($e);
        // A source may disappear/change independently of this player's Inbox revision.
        $visibility=array_map(static function($e){return [$e['id'],$e['overdue'],$e['parameters'],$e['read'],$e['claimed']];},$entries);
        $cursor=hash('sha256',InboxRules::json([$catalog['apiKeyId'],$player['id'],$state['revision'],$catalog['revision'],$visibility]));
        return ['userId'=>$player['id'],'cursor'=>$cursor,'revision'=>$state['revision'],'broadcastRevision'=>$catalog['revision'],'serverTime'=>InboxRules::instant($now),'pollAfterSeconds'=>120,'unchanged'=>$cursor===$since,'entries'=>$cursor===$since?[]:$entries,'counts'=>(object)$counts,'limits'=>InboxLimits::current()];
    }
    public function get(string $user,?string $since=null): array {
        if($since!==null&&!preg_match('/^[A-Za-z0-9:_-]{0,128}$/D',$since))InboxRules::invalid('Invalid since cursor');
        return $this->view($this->player($user),$this->state($user),$this->catalog(),($this->clock)(),$since);
    }
    public function markRead(string $user,string $entry): array { return $this->change($user,InboxRules::entry($entry),false); }
    public function remove(string $user,string $entry): array { return $this->change($user,InboxRules::entry($entry),true); }
    private function change(string $user,string $entry,bool $remove): array {
        return $this->transaction(function()use($user,$entry,$remove){
            $player=$this->player($user,true);$catalog=$this->catalog(1);$state=$this->state($user,true);$now=($this->clock)();$found=null;
            foreach($this->entries($player,$state,$catalog,$now)as$e)if($e['id']===$entry)$found=$e;
            if($found===null){if($remove)return $this->view($player,$state,$catalog,$now);InboxRules::fail('ENTRY_NOT_FOUND','Inbox entry not found',404);}
            if($remove)self::storedOriginal($state,$catalog,$entry);
            if($remove&&InboxRules::attachmentValuable($found['attachment'])&&!$found['claimed'])InboxRules::fail('UNCLAIMED_REWARD','Claim this reward before removing it');
            if(!$remove&&$found['read'])return $this->view($player,$state,$catalog,$now);
            self::writable($state);$next=$this->prune($user,$state,$catalog,$now);
            if($found['kind']==='broadcast')$next=self::mark($next,$catalog,$entry,$found['claimed'],$remove);
            else {
                foreach(['entries','sourceEntries','freeTextEntries']as$key){$kept=[];foreach($next[$key]as$e){$id=$key==='entries'?$e['id']:($key==='sourceEntries'?InboxStorage::sourceId($e,$user):'message:'.$e['id']);
                    if($id===$entry){if($remove){if($key==='entries'&&InboxRules::valuable($e))$next=self::guard($next,$e);continue;}$e['read']=true;}$kept[]=$e;}$next[$key]=$kept;}
            }
            $next=self::bump($next);$this->save($user,$next,$catalog);return $this->view($player,$next,$catalog,$now);
        });
    }
    /** Retained/future valuable shapes cannot be partially acknowledged or discarded. No current policy is applied. */
    private static function storedOriginal(array $state,array $catalog,string $id): void {
        foreach(array_merge($state['entries'],$state['legacyClaims'],$catalog['entries'])as$entry)if(($entry['id']??null)===$id){
            try{
                if(count($entry)!==7||array_diff(array_keys($entry),['id','content','createdAt','read','claimed','sourceOperationId','payloadHash'])||!is_bool($entry['read'])||!is_bool($entry['claimed'])||!is_array($entry['content']))InboxStorage::required();
                $content=$entry['content'];$fields=['type','reference','textKey','parameters','expiresAt','attachment'];
                if(array_diff(array_keys($content),$fields)||array_diff($fields,array_keys($content)))InboxStorage::required();
                InboxRules::content($content,isset($content['attachment']['itemReturn']));
            }catch(Throwable $error){InboxStorage::required();}
        }
    }
    public function claim(string $user,string $entry): array {
        InboxRules::entry($entry);return $this->transaction(function()use($user,$entry){
            $player=$this->player($user,true);$catalog=$this->catalog(1);$state=$this->state($user,true);$now=($this->clock)();$found=null;
            foreach($this->entries($player,$state,$catalog,$now)as$e)if($e['id']===$entry)$found=$e;
            if($found===null){
                foreach($state['entries']as$e)if(($e['id']??null)===$entry&&($e['claimed']??false)&&isset($e['content'])&&InboxRules::valuable($e))$found=InboxRules::viewEntry($e,$now);
                foreach($catalog['entries']as$e)if($e['id']===$entry&&InboxRules::valuable($e)){$m=self::marker($state,$catalog,$entry);if($m['claimed']&&!$m['removed'])$found=InboxRules::viewEntry($e,$now,$m);}
                if($found===null){$reference=$this->sources->claimedReturn($user,$entry);if($reference!==null)return ['entryId'=>$entry,'replayed'=>true,'inbox'=>$this->view($player,$state,$catalog,$now),'playerValues'=>$this->values->get($user),'items'=>[$reference]];}
            }
            if($found===null)InboxRules::fail('ENTRY_NOT_FOUND','Inbox entry not found',404);
            self::storedOriginal($state,$catalog,$entry);
            if(!InboxRules::attachmentValuable($found['attachment']))InboxRules::fail('NO_ATTACHMENT','This entry has no reward',422);
            $return=$found['attachment']['itemReturn']??null;$references=$return===null?[]:[['instanceId'=>$return['instanceId'],'itemKey'=>$return['itemKey']]];
            $old=false;foreach($state['entries']as$e)if($e['id']===$entry)$old=true;foreach($catalog['entries']as$e)if($e['id']===$entry&&$e['sourceOperationId']!==null)$old=true;
            $bridge=$old&&$return===null?new InboxLegacyRewards($this->pdo,$this->clock,$this->transactions):null;
            if($bridge!==null){$applied=$bridge->wasApplied($user,$entry,$found['attachment']['values']);if(!$found['claimed']&&$applied)InboxStorage::required();if($found['claimed']&&$applied)$bridge->acknowledge($user,$entry,$found['attachment']['values']);}
            if($found['claimed'])return ['entryId'=>$entry,'replayed'=>true,'inbox'=>$this->view($player,$state,$catalog,$now),'playerValues'=>$this->values->get($user),'items'=>$references];
            self::writable($state);$next=$this->prune($user,$state,$catalog,$now);
            if($found['kind']==='broadcast')$next=self::mark($next,$catalog,$entry,true,false);
            else {foreach($next['entries']as&$e)if($e['id']===$entry){$e['read']=true;$e['claimed']=true;}unset($e);foreach($next['sourceEntries']as&$e)if(InboxStorage::sourceId($e,$user)===$entry){$e['read']=true;$e['claimed']=true;}unset($e);}
            $next=self::bump($next);$this->save($user,$next,$catalog);
            if($return!==null){require_once __DIR__.'/../Marketplace/MarketService.php';try{$references=(new MarketService($this->pdo,$this->clock,$this->transactions))->claimReturn($user,$entry,$return);}catch(MarketFailure $e){InboxRules::fail($e->errorCode,$e->getMessage(),$e->status);}$values=$this->values->get($user);}
            else $values=$this->values->grantFromSource($user,$found['attachment']['values'],'inbox:'.$entry,$now,'inbox:'.$entry);
            return ['entryId'=>$entry,'replayed'=>false,'inbox'=>$this->view($player,$next,$catalog,$now),'playerValues'=>$values,'items'=>$references];
        });
    }
    private function validateRewards(array $content): void {
        if(empty($content['attachment']['values']))return;$definitions=array_column($this->values->config(false)['definitions'],null,'key');
        foreach($content['attachment']['values']as$v)if(!isset($definitions[$v['key']])||$v['amount']>$definitions[$v['key']]['maxBalance'])InboxRules::invalid('Rewards require configured keys and must fit their maximum balance');
    }
    public function deliver(string $user,array $input,string $operation,int $issued,string $source): array {
        return $this->transaction(function()use($user,$input,$operation,$issued,$source){return $this->delivery($user,$input,$operation,$issued,$source,false)['result'];});
    }
    public function deliverPending(string $user,array $input,string $operation,int $issued,string $source,callable $complete): array {
        return $this->deliverPendingGenerated($user,$input,$operation,$issued,$source,static function(PDO $pdo,string $id,int $generation)use($complete){return $complete($pdo,$id);});
    }
    public function deliverPendingGenerated(string $user,array $input,string $operation,int $issued,string $source,callable $complete): array {
        return $this->transaction(function()use($user,$input,$operation,$issued,$source,$complete){$delivered=$this->delivery($user,$input,$operation,$issued,$source,true);if(!$complete($this->pdo,$delivered['result']['entryId'],$delivered['generation']))InboxRules::fail('SOURCE_NOT_PENDING','Source is no longer pending');return $delivered['result'];});
    }
    public function deliverItemReturnPending(string $user,array $input,string $operation,int $issued,string $source,callable $complete): array {
        if(!$this->transactions->isRunning())throw new LogicException('Return delivery requires the source SQL scope');
        return $this->transaction(function()use($user,$input,$operation,$issued,$source,$complete){$delivered=$this->delivery($user,$input,$operation,$issued,$source,true,true);if(!$complete($this->pdo,$delivered['result']['entryId']))InboxRules::fail('SOURCE_NOT_PENDING','Return source changed');return $delivered['result'];});
    }
    private function delivery(string $user,array $input,string $operation,int $issued,string $source,bool $pending,bool $trustedReturn=false): array {
        InboxRules::text($operation,128,false);InboxRules::text($source,200,false);$this->player($user,true);$state=$this->state($user,true);$content=InboxRules::content($input,$trustedReturn);
        $hash=InboxRules::hash($content,$source,$issued);$receipt=InboxRules::replay($state,$operation,$hash);
        if($receipt!==null)return ['result'=>['entryId'=>$receipt['entryId'],'replayed'=>true],'generation'=>$issued];
        self::writable($state);$now=($this->clock)();if($issued>$now+30000)InboxRules::invalid('Intent is in the future');InboxRules::validate($content,$now,$pending);
        $generation=self::generation($state,$issued);$message=InboxSourceResolver::intent($content,$source,$generation);$catalog=$this->catalog(1);$state=$this->prune($user,$state,$catalog,$now);
        $state['sourceEntries']=array_values(array_filter($state['sourceEntries'],static function($e)use($message){return $e['kind']!==$message['kind']||$e['sourceId']!==$message['sourceId'];}));
        if(count($state['entries'])+count($state['freeTextEntries'])+count($state['sourceEntries'])>=InboxLimits::current()['targeted'])InboxRules::fail('INBOX_FULL','Inbox is full; keep the source pending');
        $state['sourceEntries'][]=$message;$state=self::bump($state,$generation);$this->save($user,$state,$catalog);
        return ['result'=>['entryId'=>InboxStorage::sourceId($message,$user),'replayed'=>false],'generation'=>$generation];
    }
    /** Existing persisted legacy recipes may prove an exact accepted operation, never create another one. */
    public function replayLegacy(string $user,array $input,string $operation,int $issued,string $source): array {
        $this->player($user);$content=InboxRules::content($input);$receipt=InboxRules::replay($this->state($user),$operation,InboxRules::hash($content,$source,$issued));
        if($receipt===null)InboxStorage::required();return ['entryId'=>$receipt['entryId'],'replayed'=>true];
    }
    /** Trusted plain text. Identity binds the exact original compare-and-set revision and issued time. */
    public function freeText(string $user,string $operation,int $expected,int $issued,int $expires,string $text): array {
        InboxRules::uuid($operation);PlayerValuesLedger::integer($expected,0);PlayerValuesLedger::integer($issued,0);PlayerValuesLedger::integer($expires,0);
        if(!preg_match('//u',$text)||preg_match('/^[\p{Z}\s]*$/u',$text)||$expires<=$issued)InboxRules::invalid('Invalid plain text');
        return $this->transaction(function()use($user,$operation,$expected,$issued,$expires,$text){
            $this->player($user,true);$catalog=$this->catalog(1);$state=$this->state($user,true);$id=InboxStorage::namedUuid($catalog['apiKeyId'].':'.$user.':'.$operation.':'.$expected.':'.$issued);
            foreach($state['freeTextEntries']as$e)if($e['id']===$id){if($e['text']!==$text||$e['expiresAtMillis']!==$expires)InboxRules::fail('OPERATION_CONFLICT','Original plain text differs');return ['entryId'=>'message:'.$id,'replayed'=>true];}
            self::writable($state);if($state['revision']!==$expected)InboxRules::fail('STATE_CONFLICT','Original Inbox revision differs');
            $limits=InboxLimits::current();if(strlen($text)>$limits['freeTextBytes']||$expires-$issued>$limits['freeTextRetentionDays']*86400000)InboxRules::invalid('Plain text exceeds configured bounds');
            $now=($this->clock)();if($expires<=$now||$issued>$now+30000)InboxRules::invalid('Plain text expired or is in the future');
            $state=$this->prune($user,$state,$catalog,$now);
            if(count($state['freeTextEntries'])>=$limits['freeTexts']||count($state['entries'])+count($state['freeTextEntries'])+count($state['sourceEntries'])>=$limits['targeted'])InboxRules::fail('INBOX_FULL','Plain text or targeted capacity reached');
            $generation=self::generation($state,$issued);$state['freeTextEntries'][]=['id'=>$id,'text'=>$text,'createdAtMillis'=>$generation,'expiresAtMillis'=>$expires,'read'=>false];
            $state=self::bump($state,$generation);$this->save($user,$state,$catalog);return ['entryId'=>'message:'.$id,'replayed'=>false];
        });
    }
    private function broadcastView(array $catalog,int $now,?array $operation=null): array {
        $entries=[];foreach($catalog['entries']as$row)if(InboxRules::retained($row,$now)){$e=InboxRules::viewEntry($row,$now);$e['parameters']=(object)$e['parameters'];$entries[]=$e;}
        return ['apiKeyId'=>$catalog['apiKeyId'],'revision'=>$catalog['revision'],'serverTime'=>InboxRules::instant($now),'entries'=>$entries,'limits'=>InboxLimits::current(),'operation'=>$operation];
    }
    public function broadcasts(): array { return $this->broadcastView($this->catalog(),($this->clock)()); }
    public function publish(array $input,string $operation,int $expected): array {
        $issued=InboxRules::issued($operation);PlayerValuesLedger::integer($expected,0);$content=InboxRules::content($input);
        return $this->transaction(function()use($content,$operation,$issued,$expected){
            $catalog=$this->catalog(2);$now=($this->clock)();$receipt=InboxRules::replay($catalog,$operation,InboxRules::hash($content,'broadcast:'.$catalog['apiKeyId'],$issued));
            if($receipt!==null)return $this->broadcastView($catalog,$now,['operationId'=>$operation,'entryId'=>$receipt['entryId'],'replayed'=>true]);
            $id='broadcast:'.InboxStorage::namedUuid($catalog['apiKeyId'].':'.$operation.':'.$expected);
            foreach($catalog['entries']as$e)if($e['id']===$id){if($e['content']!=$content)InboxRules::fail('OPERATION_CONFLICT','Original publication differs');return $this->broadcastView($catalog,$now,['operationId'=>$operation,'entryId'=>$id,'replayed'=>true]);}
            self::writable($catalog);if($catalog['revision']!==$expected)InboxRules::fail('REVISION_CONFLICT','Reload the catalogue');if($issued>$now+30000)InboxRules::invalid('Publication is in the future');
            InboxRules::validate($content,$now);$this->validateRewards($content);
            // Valuable records never expire from shared storage: no slot reuse, no separate lifetime cap.
            $catalog['entries']=array_values(array_filter($catalog['entries'],static function($e)use($now){return InboxRules::valuable($e)||InboxRules::retained($e,$now);}));
            if(count($catalog['entries'])>=InboxLimits::current()['broadcasts'])InboxRules::fail('BROADCAST_LIMIT_REACHED','Retained broadcast capacity reached');
            $message=InboxRules::message($id,$content,$now,'','');$message['sourceOperationId']=null;$message['payloadHash']=null;
            InboxRules::storedMessage($message);$catalog['entries'][]=$message;$catalog=self::bump($catalog);$this->saveCatalog($catalog);return $this->broadcastView($catalog,$now,['operationId'=>$operation,'entryId'=>$id,'replayed'=>false]);
        });
    }
    public function removeBroadcast(string $id,int $expected): array {
        InboxRules::entry($id);PlayerValuesLedger::integer($expected,0);return $this->transaction(function()use($id,$expected){
            $catalog=$this->catalog(2);$now=($this->clock)();$found=null;foreach($catalog['entries']as$e)if($e['id']===$id)$found=$e;
            if($found===null)return $this->broadcastView($catalog,$now);self::writable($catalog);if($catalog['revision']!==$expected)InboxRules::fail('REVISION_CONFLICT','Reload the catalogue');
            if(InboxRules::valuable($found))InboxRules::fail('UNCLAIMED_REWARD','Valuable broadcasts remain retained');
            $catalog['entries']=array_values(array_filter($catalog['entries'],static function($e)use($id){return $e['id']!==$id;}));$catalog=self::bump($catalog);$this->saveCatalog($catalog);return $this->broadcastView($catalog,$now);
        });
    }
    /** Indexed, bounded physical cleanup. Revision floors survive even an entirely empty mailbox. */
    public function cleanup(int $limit=100): int {
        if($limit<1||$limit>1000)InboxRules::invalid('Invalid cleanup batch');$now=($this->clock)();$this->transaction(function()use($now){$catalog=$this->catalog(2);if(!$catalog['_compact'])return;$next=$catalog;$next['entries']=array_values(array_filter($catalog['entries'],static function($e)use($now){return InboxRules::valuable($e)||InboxRules::retained($e,$now);}));if($next!==$catalog)$this->saveCatalog(self::bump($next));});$q=$this->pdo->prepare('SELECT user_id FROM inbox_state WHERE next_expiry<=? ORDER BY next_expiry,user_id LIMIT '.$limit);$q->execute([$now]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$done=0;
        foreach($ids as$user)$this->transaction(function()use($user,$now,&$done){$this->player($user,true);$catalog=$this->catalog(1);$state=$this->state($user,true);self::writable($state);self::writable($state);$next=$this->prune($user,$state,$catalog,$now);if($next!==$state)$next=self::bump($next);$this->save($user,$next,$catalog);$done++;});return $done;
    }
}
