<?php

declare(strict_types=1);
require_once __DIR__ . '/InboxStorage.php';
require_once __DIR__ . '/../PlayerValues/PlayerValuesService.php';

/** Cold migration only. The original source and every credit share the caller's SQL transaction. */
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
    public function settle(string $user,string $entryId,string $source,int $issued,string $achievementKey): void {
        [$raw,$state]=$this->state($user);$matches=[];
        foreach(['entries','legacyClaims']as$bucket)foreach($state[$bucket]??[]as$i=>$entry)if(($entry['id']??null)===$entryId||($entry['sourceOperationId']??null)===$source)$matches[$bucket.':'.$i]=[$bucket,$i,$entry];
        if(count($matches)!==1)$this->required();[$bucket,$index,$entry]=array_values($matches)[0];
        if(array_diff(array_keys($entry),['id','content','createdAt','read','claimed','sourceOperationId','payloadHash'])||count($entry)!==7)$this->required();
        $content=self::content($entry['content']);
        if($content['type']!=='achievement'||$content['reference']!==$achievementKey)$this->required();
        if($entry['id']!==$entryId||$entry['sourceOperationId']!==$source||!is_bool($entry['claimed'])||
            !hash_equals(InboxRules::hash($content,$source,$issued),$entry['payloadHash']))$this->required();
        foreach($state['receipts'] as $receipt)if($receipt['operationId']===$source&&($receipt['entryId']!==$entryId||!hash_equals($receipt['payloadHash'],$entry['payloadHash'])))$this->required();
        $amounts=$content['attachment']['values'];
        if(!$amounts||isset($entry['content']['attachment']['itemReturn']))$this->required();
        $config=$this->values->config(false,true);
        $q=$this->pdo->prepare('SELECT data FROM player_values WHERE user_id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$user]);$valueRaw=$q->fetchColumn();
        $valueState=$valueRaw===false?PlayerValuesLedger::emptyState():PlayerValuesStorage::decode($valueRaw,$config['knownKeys']);
        $operation='inbox:'.$entryId;$found=[];
        foreach($valueState['receipts'] as $i=>$guard)if($guard['operationId']===$operation){
            $parts=['grant',$operation,(string)$guard['issuedAtMillis']];$sorted=$amounts;
            usort($sorted,static function($a,$b){return strcmp($a['key'],$b['key']);});
            foreach($sorted as $amount){$parts[]=$amount['key'];$parts[]=(string)$amount['amount'];}
            if(!hash_equals(PlayerValuesLedger::hash(...$parts),$guard['payloadHash']))$this->required();$found[]=$i;
        }
        if(count($found)>1||(!$entry['claimed']&&$found))$this->required();
        if(!$entry['claimed']){
            $state[$bucket][$index]['claimed']=true;$state[$bucket][$index]['read']=true;$state=InboxRules::bump($state);
            $q=$this->pdo->prepare('UPDATE inbox_state SET data=? WHERE user_id=? AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY'));$q->execute([$state['_compact']?InboxStorage::encodeState($state):InboxRules::json(array_diff_key($state,array_flip(['_compact','sourceEntries','freeTextEntries','rewardStates','ordinaryMarkers','legacyClaims']))),$user,$raw]);
            if($q->rowCount()!==1)InboxRules::fail('STATE_CONFLICT','Original Inbox reward changed');
            $this->values->grantFromSource($user,$amounts,$operation,($this->clock)(),$operation);
        } elseif($found) {
            // Only the exact guard moves to the durable source; never reopen its acceptance time.
            foreach($found as$i)$valueState['evictedThroughMillis']=max($valueState['evictedThroughMillis'],$valueState['receipts'][$i]['issuedAtMillis']);
            $valueState['revision']=PlayerValuesLedger::integer($valueState['revision'],0,PlayerValuesLedger::MAX-1)+1;
            $valueState['receipts']=array_values(array_filter($valueState['receipts'],static function($r)use($operation){return $r['operationId']!==$operation;}));
            $q=$this->pdo->prepare('UPDATE player_values SET data=? WHERE user_id=? AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY'));
            $q->execute([PlayerValuesStorage::encode($valueState,$config['knownKeys']),$user,$valueRaw]);
            if($q->rowCount()!==1)InboxRules::fail('STATE_CONFLICT','Original value guard changed');
        }
    }
}
