<?php

declare(strict_types=1);
require_once __DIR__ . '/InboxStorage.php';
require_once __DIR__ . '/../PlayerValues/PlayerValuesService.php';

/** Legacy reward proof and settlement share the source owner's SQL transaction. */
class InboxLegacyRewards
{
    private PDO $pdo;
    private SqlTransaction $transactions;
    private PlayerValuesService $values;
    private bool $mysql;
    private $clock;
    public function __construct(PDO $pdo,callable $clock,SqlTransaction $transactions) {
        if(!$transactions->owns($pdo))throw new InvalidArgumentException('Foreign migration connection');
        $this->pdo=$pdo;$this->transactions=$transactions;$this->clock=$clock;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->values=new PlayerValuesService($pdo,$clock,$transactions);
    }
    private function required(): void { InboxRules::fail('MIGRATION_REQUIRED','Original Inbox reward needs reconciliation; no values were discarded'); }
    /** Reject unknown valuable shapes before any irreversible compact replacement. */
    public static function content(array $input): array {
        $fields=['type','reference','textKey','parameters','expiresAt','attachment'];
        if(array_diff(array_keys($input),$fields)||array_diff($fields,array_keys($input))||
            !is_array($input['attachment'])||array_keys($input['attachment'])!==['values']||
            !is_array($input['attachment']['values'])||!$input['attachment']['values'])
            InboxRules::fail('MIGRATION_REQUIRED','Unknown original reward content is preserved');
        foreach($input['attachment']['values'] as $value)if(!is_array($value)||count($value)!==2||array_diff(array_keys($value),['key','amount']))
            InboxRules::fail('MIGRATION_REQUIRED','Unknown original reward fields are preserved');
        try{return InboxRules::content($input);}
        catch(Throwable $failure){InboxRules::fail('MIGRATION_REQUIRED','Malformed original reward is preserved');}
    }
    private function state(string $user): array {
        if(!$this->transactions->isRunning())throw new LogicException('Legacy settlement requires the source SQL transaction');
        $q=$this->pdo->prepare('SELECT data FROM inbox_state WHERE user_id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$user]);$raw=$q->fetchColumn();
        if($raw===false)return [null,InboxRules::emptyState()];
        $state=InboxStorage::state($raw);
        return [$raw,$state];
    }
    public function requireUndelivered(string $user,string $source): void {
        [, $state]=$this->state($user);
        foreach($state['entries'] as $entry)if($entry['sourceOperationId']===$source)$this->required();
        foreach($state['receipts'] as $receipt)if($receipt['operationId']===$source)$this->required();
        foreach($state['legacyClaims']??[]as$entry)if(($entry['sourceOperationId']??null)===$source)$this->required();
    }
    /** Read only the exact old receipt under the caller's existing source transaction. */
    private function proof(string $user,string $entryId,?array $amounts): array {
        if(!$this->transactions->isRunning())throw new LogicException('Legacy receipt proof requires the source SQL transaction');
        InboxRules::uuid($user);InboxRules::entry($entryId);$config=$this->values->config(false,true);
        $q=$this->pdo->prepare('SELECT data FROM player_values WHERE user_id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$user]);$raw=$q->fetchColumn();
        try{$state=$raw===false?PlayerValuesLedger::emptyState():PlayerValuesStorage::decode($raw,$config['knownKeys']);}catch(PlayerValuesFailure $error){$this->required();}
        $operation='inbox:'.$entryId;$found=[];$sorted=$amounts??[];usort($sorted,static function($a,$b){return strcmp($a['key'],$b['key']);});
        foreach($state['receipts']as$i=>$guard)if($guard['operationId']===$operation){
            $parts=['grant',$operation,(string)$guard['issuedAtMillis']];foreach($sorted as$amount){$parts[]=$amount['key'];$parts[]=(string)$amount['amount'];}
            if($amounts!==null&&!hash_equals(PlayerValuesLedger::hash(...$parts),$guard['payloadHash']))$this->required();$found[]=$i;
        }
        if(count($found)>1)$this->required();return [$raw,$state,$config,$found];
    }
    public function wasApplied(string $user,string $entryId,array $originalValues): bool { return (bool)$this->proof($user,$entryId,$originalValues)[3]; }
    /** Caller already proved/persisted the durable claimed Inbox guard in this same source transaction. */
    public function acknowledge(string $user,string $entryId,?array $originalValues=null): void {
        [$raw,$state,$config,$found]=$this->proof($user,$entryId,$originalValues);if(!$found)return;
        foreach($found as$i)$state['evictedThroughMillis']=max($state['evictedThroughMillis'],$state['receipts'][$i]['issuedAtMillis']);
        $state['revision']=PlayerValuesLedger::integer($state['revision'],0,PlayerValuesLedger::MAX-1)+1;
        $operation='inbox:'.$entryId;$state['receipts']=array_values(array_filter($state['receipts'],static function($r)use($operation){return $r['operationId']!==$operation;}));
        $q=$this->pdo->prepare('UPDATE player_values SET data=? WHERE user_id=? AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY'));
        $q->execute([PlayerValuesStorage::encode($state,$config['knownKeys']),$user,$raw]);if($q->rowCount()!==1)InboxRules::fail('STATE_CONFLICT','Original value guard changed');
    }
    public function settle(string $user,string $entryId,string $source,int $issued,string $achievementKey): void {
        [$raw,$state]=$this->state($user);$matches=[];
        foreach(['entries','legacyClaims']as$bucket)foreach($state[$bucket]??[]as$i=>$entry)if(($entry['id']??null)===$entryId||($entry['sourceOperationId']??null)===$source)$matches[$bucket.':'.$i]=[$bucket,$i,$entry];
        if(count($matches)!==1)$this->required();[$bucket,$index,$entry]=array_values($matches)[0];
        if($bucket==='legacyClaims'&&count($entry)===2&&!array_diff(array_keys($entry),['id','sourceOperationId'])){
            if($entry['id']!==$entryId||$entry['sourceOperationId']!==$source)$this->required();
            $this->acknowledge($user,$entryId);return; // The durable consumed source authorizes replay only, never another grant.
        }
        if(array_diff(array_keys($entry),['id','content','createdAt','read','claimed','sourceOperationId','payloadHash'])||count($entry)!==7)$this->required();
        $content=self::content($entry['content']);
        if($content['type']!=='achievement'||$content['reference']!==$achievementKey)$this->required();
        if($entry['id']!==$entryId||$entry['sourceOperationId']!==$source||!is_bool($entry['claimed'])||
            !hash_equals(InboxRules::hash($content,$source,$issued),$entry['payloadHash']))$this->required();
        foreach($state['receipts'] as $receipt)if($receipt['operationId']===$source&&($receipt['entryId']!==$entryId||!hash_equals($receipt['payloadHash'],$entry['payloadHash'])))$this->required();
        $amounts=$content['attachment']['values'];
        if(!$amounts||isset($entry['content']['attachment']['itemReturn']))$this->required();
        $operation='inbox:'.$entryId;$applied=$this->wasApplied($user,$entryId,$amounts);
        if(!$entry['claimed']&&$applied)$this->required();
        if(!$entry['claimed']){
            $state[$bucket][$index]['claimed']=true;$state[$bucket][$index]['read']=true;$state=InboxRules::bump($state);
            $q=$this->pdo->prepare('UPDATE inbox_state SET data=? WHERE user_id=? AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY'));$q->execute([$state['_compact']?InboxStorage::encodeState($state):InboxRules::json(array_diff_key($state,array_flip(['_compact','sourceEntries','freeTextEntries','rewardStates','ordinaryMarkers','legacyClaims']))),$user,$raw]);
            if($q->rowCount()!==1)InboxRules::fail('STATE_CONFLICT','Original Inbox reward changed');
            $this->values->grantFromSource($user,$amounts,$operation,($this->clock)(),$operation);
        } elseif($applied) $this->acknowledge($user,$entryId,$amounts);
    }
}
