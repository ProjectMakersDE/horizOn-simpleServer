<?php

declare(strict_types=1);
require_once __DIR__.'/InboxService.php';

/** Explicit cold conversion. Unknown historical payloads remain byte-for-byte unchanged on failure. */
class InboxMigration
{
    private PDO $pdo; private SqlTransaction $scope; private bool $mysql;
    public function __construct(PDO $pdo) { $this->pdo=$pdo;$this->scope=new SqlTransaction($pdo);$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    private static function fields(array $value,array $fields): void { if(array_diff(array_keys($value),$fields)||array_diff($fields,array_keys($value)))InboxStorage::required(); }
    private static function message(array $message): void {
        self::fields($message,['id','content','createdAt','read','claimed','sourceOperationId','payloadHash']);InboxRules::entry($message['id']);
        if(!is_bool($message['read'])||!is_bool($message['claimed'])||!is_string($message['sourceOperationId'])||!is_string($message['payloadHash'])||!preg_match('/^[a-f0-9]{64}$/D',$message['payloadHash']))InboxStorage::required();
        InboxRules::text($message['sourceOperationId'],128,false);$created=InboxRules::millis($message['createdAt']);if($created<0||$created>PlayerValuesLedger::MAX)InboxStorage::required();
        $input=$message['content'];if(!is_array($input))InboxStorage::required();self::fields($input,['type','reference','textKey','parameters','expiresAt','attachment']);
        if($input['attachment']!==null){
            if(!is_array($input['attachment'])||array_diff(array_keys($input['attachment']),['values','itemReturn']))InboxStorage::required();
            foreach($input['attachment']['values']??[]as$value){if(!is_array($value))InboxStorage::required();self::fields($value,['key','amount']);}
        }
        $normalized=InboxRules::content($input,isset($input['attachment']['itemReturn']));
        // Canonical normalization must not discard unknown fields or change the original value payload.
        $normalized['parameters']=(array)$normalized['parameters'];$input['parameters']=(array)$input['parameters'];if($normalized!=$input)InboxStorage::required();
    }
    private static function history(array $state): void {
        $ids=[];$operations=[];
        foreach($state['entries']as$message){if(!is_array($message))InboxStorage::required();self::message($message);if(isset($ids[$message['id']])||isset($operations[$message['sourceOperationId']]))InboxStorage::required();$ids[$message['id']]=$message;$operations[$message['sourceOperationId']]=$message;}
        $seen=[];foreach($state['receipts']as$receipt){
            if(!is_array($receipt))InboxStorage::required();self::fields($receipt,['operationId','payloadHash','issuedAtMillis','entryId']);
            InboxRules::text($receipt['operationId'],128,false);InboxRules::entry($receipt['entryId']);PlayerValuesLedger::integer($receipt['issuedAtMillis'],0);
            if(!is_string($receipt['payloadHash'])||!preg_match('/^[a-f0-9]{64}$/D',$receipt['payloadHash'])||isset($seen[$receipt['operationId']]))InboxStorage::required();$seen[$receipt['operationId']]=true;
            if(isset($operations[$receipt['operationId']])){$e=$operations[$receipt['operationId']];if($e['id']!==$receipt['entryId']||!hash_equals($e['payloadHash'],$receipt['payloadHash']))InboxStorage::required();}
        }
    }
    public function catalog(): bool {
        return $this->scope->run(function(){
            $raw=$this->pdo->query('SELECT data FROM inbox_catalog WHERE id=1'.($this->mysql?' FOR UPDATE':''))->fetchColumn();$state=InboxStorage::catalog($raw);if($state['_compact'])return false;
            try{self::history($state);}catch(Throwable $e){InboxStorage::required();}
            $state['_compact']=true;$state=InboxRules::bump($state);$encoded=InboxStorage::encodeCatalog($state);
            $q=$this->pdo->prepare('UPDATE inbox_catalog SET data=? WHERE id=1 AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY'));$q->execute([$encoded,$raw]);if($q->rowCount()!==1)InboxRules::fail('STATE_CONFLICT','Original catalogue changed');return true;
        });
    }
    public function player(string $user): bool {
        InboxRules::uuid($user);return $this->scope->run(function()use($user){
            $lock=$this->mysql?' FOR UPDATE':'';$q=$this->pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$q->execute([$user]);if($q->fetchColumn()===false)InboxStorage::required();
            $catalog=InboxStorage::catalog($this->pdo->query('SELECT data FROM inbox_catalog WHERE id=1'.($this->mysql?' LOCK IN SHARE MODE':''))->fetchColumn());if(!$catalog['_compact'])InboxStorage::required();
            $q=$this->pdo->prepare('SELECT data FROM inbox_state WHERE user_id=?'.$lock);$q->execute([$user]);$raw=$q->fetchColumn();if($raw===false)return false;$state=InboxStorage::state($raw);if($state['_compact'])return false;
            try{
                self::history($state);$seen=[];$state['_compact']=true;$legacy=[];
                foreach($state['markers']as$m){if(!is_array($m))InboxStorage::required();self::fields($m,['id','read','claimed','removed']);InboxRules::entry($m['id']);
                    if(isset($seen[$m['id']])||!is_bool($m['read'])||!is_bool($m['claimed'])||!is_bool($m['removed'])||($m['claimed']&&!$m['read']))InboxStorage::required();$seen[$m['id']]=true;
                    $slot=InboxStorage::rewardSlot($catalog,$m['id']);
                    if($slot>=0){if(!$m['read']&&!$m['removed'])continue;$state['rewardStates']=InboxStorage::setStatus($state['rewardStates'],$slot,$m['removed']?3:($m['claimed']?2:1));}
                    elseif($m['id']==='broadcast:'.strtolower(substr($m['id'],10)))$state['ordinaryMarkers'][]=$m;else $legacy[]=$m;
                }$state['markers']=$legacy;$state=InboxRules::bump($state);$encoded=InboxStorage::encodeState($state);
            }catch(Throwable $e){InboxStorage::required();}
            $q=$this->pdo->prepare('UPDATE inbox_state SET data=?,next_expiry=? WHERE user_id=? AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY'));$q->execute([$encoded,InboxService::earliestExpiry($state,$catalog),$user,$raw]);if($q->rowCount()!==1)InboxRules::fail('STATE_CONFLICT','Original Inbox changed');return true;
        });
    }
    public function page(?string $after=null,int $limit=10,bool $apply=false): array {
        if($limit<1||$limit>100)InboxRules::invalid('Migration page1..100');if($after!==null)InboxRules::uuid($after);
        $q=$this->pdo->prepare('SELECT user_id FROM inbox_state'.($after===null?'':' WHERE user_id>?').' ORDER BY user_id LIMIT '.$limit);$q->execute($after===null?[]:[$after]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$converted=0;
        if($apply){$this->catalog();foreach($ids as$user)if($this->player($user))$converted++;}
        return ['examined'=>count($ids),'converted'=>$converted,'nextCursor'=>$ids?end($ids):$after,'complete'=>count($ids)<$limit];
    }
    /** Backfill only the authoritative replay projection, under stopped writers with original JSON CAS. */
    public function sourcePage(?string $after=null,int $limit=100,bool $apply=false): array {
        if($limit<1||$limit>100)InboxRules::invalid('Source page1..100');if($after!==null)InboxRules::uuid($after);
        $q=$this->pdo->prepare('SELECT id,data,return_owner,return_entry FROM market_listings'.($after===null?'':' WHERE id>?').' ORDER BY id LIMIT '.$limit);$q->execute($after===null?[]:[$after]);$rows=$q->fetchAll();$converted=0;
        if($apply)foreach($rows as$row)$this->scope->run(function()use($row,&$converted){
            try{$v=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);if(!is_array($v)||$v['id']!==$row['id']||$v['returnOwnerId']!==$row['return_owner'])InboxStorage::required();$entry=$v['returnEntryId'];if($entry!==null)InboxRules::entry($entry);if($row['return_entry']!==null&&$row['return_entry']!==$entry)InboxStorage::required();}catch(Throwable $e){InboxStorage::required();}
            if($entry===$row['return_entry'])return;$q=$this->pdo->prepare('UPDATE market_listings SET return_entry=? WHERE id=? AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY').' AND return_entry IS NULL');$q->execute([$entry,$row['id'],$row['data']]);if($q->rowCount()!==1)InboxRules::fail('STATE_CONFLICT','Original return source changed');$converted++;
        });
        return ['examined'=>count($rows),'converted'=>$converted,'nextCursor'=>$rows?end($rows)['id']:$after,'complete'=>count($rows)<$limit];
    }
}
