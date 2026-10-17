<?php

declare(strict_types=1);
require_once __DIR__.'/InboxStorage.php';
require_once __DIR__.'/../Items/ItemStorage.php';

/** Bounded source metadata only; opaque Match payloads and item catalogues are never loaded. */
class InboxSourceResolver
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; }
    private function rows(string $sql,array $args): array {
        $q=$this->pdo->prepare($sql);$q->execute($args);$result=[];
        while($row=$q->fetch(PDO::FETCH_ASSOC)) {
            try{$value=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){InboxStorage::required();}
            if(!is_array($value))InboxStorage::required();$result[$row['id']]=$value;
        }return $result;
    }
    private static function placeholders(array $ids): string { return implode(',',array_fill(0,count($ids),'?')); }
    private static function uuid($value): string { try{return InboxRules::uuid($value);}catch(Throwable $e){InboxStorage::required();} }
    private static function integer($value): int { if(!is_int($value)||$value<0||$value>PlayerValuesLedger::MAX)InboxStorage::required();return $value; }
    private static function members($value): array { if(!is_array($value)||($value!==[]&&array_keys($value)!==range(0,count($value)-1)))InboxStorage::required();return $value; }
    public static function intent(array $content,string $source,int $generation): array {
        $kind=InboxStorage::sourceKind($content);if($kind===null)InboxRules::invalid('A typed source adapter is required');
        $parameters=(array)$content['parameters'];
        $id=self::uuid($kind===1?($parameters['userId']??null):(in_array($kind,[4,5],true)?($parameters['groupId']??null):$content['reference']));
        $prefix=$kind<2?'friends:':($kind<6?'groups:':($kind===6?'match:':'marketplace:'));
        if(strpos($source,$prefix)!==0||($kind!==7&&InboxRules::attachmentValuable($content['attachment']))||
            ($kind===7&&($content['attachment']['itemReturn']['listingId']??null)!==$id))InboxRules::invalid('Delivery does not match its source');
        return ['sourceId'=>$id,'kind'=>$kind,'recipientBound'=>true,'issuedAtMillis'=>$generation,
            'expiresAtMillis'=>InboxRules::millis($content['expiresAt']),'read'=>false,'claimed'=>false];
    }
    public function resolve(string $user,array $entries): array {
        if(!$entries)return [];$result=[];
        $put=static function(array $entry,string $reference,array $parameters,$revision,?array $attachment=null)use(&$result,$user):void {
            $content=['type'=>InboxStorage::TYPES[$entry['kind']],'reference'=>$reference,'textKey'=>InboxStorage::KEYS[$entry['kind']],
                'parameters'=>$parameters,'expiresAt'=>InboxRules::instant($entry['expiresAtMillis']),'attachment'=>$attachment];
            $result[InboxStorage::sourceId($entry,$user)]=['content'=>$content,'version'=>(string)$revision];
        };
        $friends=array_filter($entries,static function($e){return $e['kind']<2;});
        if($friends){
            $rows=$this->rows("SELECT user_id AS id,JSON_OBJECT('revision',revision,'incoming',JSON_EXTRACT(incoming,'$'),'friends',JSON_EXTRACT(friends,'$'),'blocked',JSON_EXTRACT(blocked,'$')) AS data FROM friends_state WHERE user_id=?",[$user]);
            $row=$rows[$user]??null;
            if($row!==null){
                $incoming=[];$peers=[];$blocked=[];
                foreach(self::members($row['incoming'])as$v)$incoming[self::uuid($v['requestId']??null)]=self::uuid($v['userId']??null);
                foreach(self::members($row['friends'])as$v)$peers[self::uuid($v['userId']??null)]=true;
                foreach(self::members($row['blocked'])as$v)$blocked[self::uuid($v['userId']??null)]=true;
                foreach($friends as$e){$peer=$e['kind']===0?($incoming[$e['sourceId']]??null):(isset($peers[$e['sourceId']])?$e['sourceId']:null);
                    if($peer!==null&&!isset($blocked[$peer]))$put($e,$e['sourceId'],['userId'=>$peer],self::integer($row['revision']));}
            }
        }
        foreach(array_chunk(array_values(array_filter($entries,static function($e){return $e['kind']===2||$e['kind']===3;})),200)as$batch){
            $ids=array_values(array_unique(array_column($batch,'sourceId')));
            $rows=$this->rows("SELECT g.id,JSON_OBJECT('revision',g.revision,'leaderId',JSON_EXTRACT(g.data,'$.leaderId'),'invitations',JSON_EXTRACT(g.data,'$.invitations'),'joinRequests',JSON_EXTRACT(g.data,'$.joinRequests')) AS data FROM groups_state g WHERE g.id IN (SELECT group_id FROM group_inbox_sources WHERE request_id IN (".self::placeholders($ids).'))',$ids);
            foreach($rows as$id=>$row)foreach($batch as$e)foreach(self::members($row[$e['kind']===2?'invitations':'joinRequests'])as$v)
                if(self::uuid($v['requestId']??null)===$e['sourceId']&&($e['kind']===2?self::uuid($v['userId']??null)===$user:self::uuid($row['leaderId']??null)===$user))
                    $put($e,$e['sourceId'],['groupId'=>self::uuid($id)],self::integer($row['revision']));
        }
        foreach(array_chunk(array_values(array_filter($entries,static function($e){return $e['kind']===4||$e['kind']===5;})),200)as$batch){
            $ids=array_values(array_unique(array_column($batch,'sourceId')));
            $rows=$this->rows("SELECT g.id,JSON_OBJECT('revision',g.revision) AS data FROM groups_state g JOIN group_members m ON m.group_id=g.id AND m.user_id=? WHERE g.id IN (".self::placeholders($ids).')',array_merge([$user],$ids));
            foreach($batch as$e)if(isset($rows[$e['sourceId']]))$put($e,$e['sourceId'],['groupId'=>$e['sourceId']],self::integer($rows[$e['sourceId']]['revision']));
        }
        foreach(array_chunk(array_values(array_filter($entries,static function($e){return $e['kind']===6;})),200)as$batch){
            $ids=array_values(array_unique(array_column($batch,'sourceId')));
            $rows=$this->rows("SELECT id,JSON_OBJECT('revision',revision,'status',JSON_EXTRACT(data,'$.status'),'currentPlayerId',JSON_EXTRACT(data,'$.currentPlayerId'),'turnDeadline',JSON_EXTRACT(data,'$.turnDeadline'),'noticeGeneration',JSON_EXTRACT(data,'$.noticeGeneration')) AS data FROM matches_state WHERE id IN (".self::placeholders($ids).')',$ids);
            foreach($batch as$e){$row=$rows[$e['sourceId']]??null;if($row===null)continue;
                if($row['status']==='active'&&$row['currentPlayerId']===$user&&$row['turnDeadline']===$e['expiresAtMillis']&&$row['noticeGeneration']===$e['issuedAtMillis'])
                    $put($e,$e['sourceId'],['matchId'=>$e['sourceId'],'revision'=>(string)self::integer($row['revision'])],$row['revision']);}
        }
        foreach(array_chunk(array_values(array_filter($entries,static function($e){return $e['kind']===7;})),200)as$batch){
            $ids=array_values(array_unique(array_column($batch,'sourceId')));
            // Escrow has at most178 packed bytes (16 actual numeric deltas), no catalogue lookup.
            $rows=$this->rows("SELECT id,JSON_OBJECT('revision',revision,'status',status,'returnOwnerId',return_owner,'returnEntryId',return_entry,'ownerUnavailable',JSON_EXTRACT(data,'$.ownerUnavailable'),'itemReference',JSON_EXTRACT(data,'$.itemReference'),'item',JSON_OBJECT('s',JSON_EXTRACT(data,'$.item.s'),'b',JSON_EXTRACT(data,'$.item.b'),'instanceId',JSON_EXTRACT(data,'$.item.instanceId'),'itemKey',JSON_EXTRACT(data,'$.item.itemKey'),'equipped',JSON_EXTRACT(data,'$.item.equipped'))) AS data FROM market_listings WHERE id IN (".self::placeholders($ids).')',$ids);
            foreach($batch as$e){$row=$rows[$e['sourceId']]??null;if($row===null)InboxStorage::required();if($row['returnOwnerId']!==$user)continue;
                if($row['returnEntryId']!==InboxStorage::sourceId($e,$user)||$row['ownerUnavailable']||$row['status']!==($e['claimed']?'returned':'return_delivered'))InboxStorage::required();
                $ref=$this->reference($row['itemReference']);
                if(!$e['claimed']&&!ItemStorage::escrowMatches($row['item'],$ref))InboxStorage::required();
                $put($e,$e['sourceId'],[],self::integer($row['revision']),['values'=>[],'itemReturn'=>['listingId'=>$e['sourceId']]+$ref]);}
        }return $result;
    }
    private function reference($ref): array {
        if(!is_array($ref)||count($ref)!==2||array_diff(array_keys($ref),['instanceId','itemKey'])||!is_string($ref['itemKey'])||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$ref['itemKey']))InboxStorage::required();
        return ['instanceId'=>self::uuid($ref['instanceId']??null),'itemKey'=>$ref['itemKey']];
    }
    public function claimedReturn(string $user,string $entry): ?array {
        $q=$this->pdo->prepare("SELECT id,status,JSON_EXTRACT(data,'$.ownerUnavailable') AS unavailable,JSON_EXTRACT(data,'$.itemReference') AS reference_value FROM market_listings WHERE return_owner=? AND return_entry=? LIMIT 2");
        $q->execute([$user,$entry]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>1)InboxStorage::required();$row=$rows[0]??null;
        if($row===null||$row['status']!=='returned'||in_array($row['unavailable'],[1,'1',true,'true'],true))return null;
        try{$reference=json_decode($row['reference_value'],true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){InboxStorage::required();}
        return $this->reference($reference);
    }
}
