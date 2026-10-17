<?php

declare(strict_types=1);
require_once __DIR__.'/GiftCodesService.php';
require_once __DIR__.'/GiftCodeLegacyGuards.php';

/** Explicit offline operator work. No constructor/startup/login scan and no historical award. */
class GiftCodeMigration
{
    private PDO $pdo;private SqlTransaction $scope;private GiftCodeCounterStore $counters;private GiftCodeLegacyGuards $guards;private bool $mysql;
    public function __construct(PDO $pdo,?SqlTransaction $scope=null)
    { $this->pdo=$pdo;$this->scope=$scope??new SqlTransaction($pdo);if(!$this->scope->owns($pdo))throw new LogicException('Migration scope mismatch');$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->counters=new GiftCodeCounterStore($pdo,$this->scope);$this->guards=new GiftCodeLegacyGuards($pdo,$this->scope); }
    private function gift(string $id,bool $lock=false): array
    {
        $q=$this->pdo->prepare('SELECT * FROM gift_codes WHERE id=?'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($row===false)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original campaign missing');return$row;
    }
    public static function verifyEvidence(array $evidence,string $sourceId,string $used): void
    {
        foreach(['backup','restoreProof','provenance']as$key){
            $file=$evidence[$key]??null;
            if(!is_array($file)||!isset($file['path'],$file['sha256'])||!is_string($file['path'])||$file['path']===''||$file['path'][0]!=='/'||!is_file($file['path'])||filesize($file['path'])===0||!is_string($file['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Missing verified backup/restore/provenance artifact');
            $actual=hash_file('sha256',$file['path']);if($actual===false||!hash_equals($file['sha256'],$actual))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Backup/restore/provenance digest changed');
        }
        $proof=json_decode(file_get_contents($evidence['provenance']['path']),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($proof)||($proof['sourceCampaignId']??null)!==$sourceId||($proof['usedCount']??null)!==$used||($proof['monotoneHistoryVerified']??null)!==true||($proof['writersStopped']??null)!==true||!is_string($proof['sourceCommit']??null)||!preg_match('/^[a-f0-9]{40}$/D',$proof['sourceCommit']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Independent source/writer/monotone-count proof is incomplete');
        GiftCodeProtocol::count($used);
    }
    public static function originalGrant(array $row): ?array
    {
        $values=$row['granted_values']===null?[]:json_decode($row['granted_values'],true,512,JSON_THROW_ON_ERROR);
        $values=GiftCodesService::rewardValues($values);
        if($row['value_granted_at']===null){if($values)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Pending original value source requires reconciliation');return null;}
        if(!$values)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Value marker without original amounts');
        $issued=GiftCodeProtocol::stored($row['value_granted_at']);usort($values,static function($a,$b){return strcmp($a['key'],$b['key']);});$parts=['grant','giftcode:'.$row['gift_code_id'],(string)$issued];
        foreach($values as$value){$parts[]=$value['key'];$parts[]=(string)$value['amount'];}
        return ['issuedAtMillis'=>$issued,'payloadHash'=>PlayerValuesLedger::hash(...$parts)];
    }
    private static function user($id): string
    { $uuid=GiftCodeProtocol::uuid($id);if($uuid!==$id)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original player UUID is not canonical');return$uuid; }
    /** Bounded reads; only identities, hashes and final counts enter the sealed plan. */
    private function inventory(array $gift): array
    {
        $sourceId=$gift['id'];$sources=[];$after='';$rawRows=0;
        do{
            $q=$this->pdo->prepare('SELECT * FROM gift_code_redemptions WHERE gift_code_id=? AND id>? ORDER BY id LIMIT 100');$q->execute([$sourceId,$after]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            foreach($rows as$row){
                $after=$row['id'];$id=GiftCodeProtocol::uuid($row['id']);if($id!==$row['id']||$row['gift_code_id']!==$sourceId||isset($sources[$id]))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Ambiguous original redemption identity');$user=self::user($row['user_id']);
                $proof=self::originalGrant($row);$record=[$id,$sourceId,$user,$row['redeemed_at'],$row['idempotency_id'],$row['gift_data'],$row['granted_values'],$row['value_granted_at']===null?null:(string)GiftCodeProtocol::stored($row['value_granted_at'])];
                $sources[$id]=['user'=>$user,'rowHash'=>hash('sha256',json_encode($record,JSON_THROW_ON_ERROR)),'proof'=>$proof,'guard'=>null];$rawRows++;
            }
        }while(count($rows)===100);
        $orphans=0;
        foreach($this->guards->scan($sourceId)as[$user,$guards]){
            $user=self::user($user);
            foreach($guards as$id=>$guard){
                if(isset($sources[$id])){
                    if($sources[$id]['user']!==$user||$sources[$id]['proof']===null||$sources[$id]['proof']['issuedAtMillis']!==$guard['issuedAtMillis']||!hash_equals($sources[$id]['proof']['payloadHash'],$guard['payloadHash']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original source/retained value guard conflicts');
                }else{$sources[$id]=['user'=>$user,'rowHash'=>null,'proof'=>null,'guard'=>null];$orphans++;}
                if($sources[$id]['guard']!==null)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Gift source appears in multiple players');$sources[$id]['guard']=$guard;
            }
        }
        $pairs=[];ksort($sources,SORT_STRING);$context=hash_init('sha256');
        foreach($sources as$id=>$entry){
            // An orphan must not really belong to another campaign/user's retained source row.
            if($entry['rowHash']===null){$q=$this->pdo->prepare('SELECT gift_code_id,user_id FROM gift_code_redemptions WHERE id=?');$q->execute([$id]);$foreign=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if($foreign!==false)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Orphan source maps to another retained redemption');}
            $user=$entry['user'];$pairs[$user]=GiftCodeProtocol::next($pairs[$user]??0);$line=json_encode([$id,$entry],JSON_THROW_ON_ERROR);hash_update($context,strlen($line).':'.$line);
        }
        foreach(array_chunk(array_keys($pairs),100)as$batch){$q=$this->pdo->prepare('SELECT id FROM users WHERE id IN ('.implode(',',array_fill(0,count($batch),'?')).')');$q->execute($batch);$found=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();if(count($found)!==count($batch))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original source player missing');}
        $used=GiftCodeProtocol::stored($gift['current_redemptions']);if((string)count($sources)!==(string)$used)GiftCodeProtocol::fail('MIGRATION_REQUIRED','History is incomplete; restore original identities, never guess missing ordinary redemptions');
        ksort($pairs,SORT_STRING);foreach($pairs as&$count)$count=(string)$count;unset($count);
        return ['sourceDigest'=>hash_final($context),'pairs'=>$pairs,'sourceCount'=>(string)count($sources),'originalRows'=>$rawRows,'orphanValueGuards'=>$orphans];
    }
    public function inspect(string $code,array $evidence): array
    {
        $q=$this->pdo->prepare('SELECT * FROM gift_codes WHERE code=?');$q->execute([$code]);$gift=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if($gift===false)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original campaign missing');
        if((int)$gift['protocol_version']===2)GiftCodeProtocol::fail('INVALID_REQUEST','Campaign already uses V2; use its original sealed plan for cleanup',400);
        if(!is_string($gift['id'])||$gift['id']==='')GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original campaign identity missing');
        $used=(string)GiftCodeProtocol::stored($gift['current_redemptions']);self::verifyEvidence($evidence,$gift['id'],$used);
        try{$uuid=GiftCodeProtocol::uuid($gift['id']);if($gift['id']!==$uuid)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Noncanonical original UUID requires explicit recovery');}catch(GiftCodesFailure $error){if($error->errorCode!=='INVALID_REQUEST')throw$error;$uuid=($gift['v2_id']??null)===null?Database::uuid():GiftCodeIdentity::fromBytes($gift['v2_id']);}
        if(($gift['v2_id']??null)!==null&&GiftCodeIdentity::fromBytes($gift['v2_id'])!==$uuid)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original campaign mapping changed');
        $inventory=$this->inventory($gift);
        return ['schema'=>1,'sourceId'=>$gift['id'],'code'=>$gift['code'],'campaignId'=>$uuid,'usedCount'=>$used,'configRevision'=>(string)GiftCodeProtocol::stored($gift['config_revision']),
            'configurationDigest'=>self::configurationDigest($gift),
            'inventory'=>$inventory,'evidence'=>$evidence];
    }
    public static function serialize(array $plan): string
    { return json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; }
    private static function verifyPlan(array $plan,string $sha): void
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||!hash_equals($sha,hash('sha256',self::serialize($plan))))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Plan is not the original canonical sealed file');
        self::verifyEvidence($plan['evidence'],$plan['sourceId'],$plan['usedCount']);
    }
    public static function load(string $path,string $sha): array
    {
        if($path===''||$path[0]!=='/'||!is_file($path)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||!hash_equals($sha,hash_file('sha256',$path)))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Sealed plan is missing or changed');
        $plan=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(!is_array($plan)||($plan['schema']??null)!==1||!is_string($plan['sourceId']??null)||!is_array($plan['inventory']['pairs']??null)||!is_string($plan['configurationDigest']??null)||!preg_match('/^[a-f0-9]{64}$/D',$plan['configurationDigest']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Unrecognized sealed plan');
        GiftCodeProtocol::uuid($plan['campaignId']);GiftCodeProtocol::count($plan['configRevision']);$sum='0';foreach($plan['inventory']['pairs']as$user=>$count){self::user($user);if(GiftCodeProtocol::count($count)===0)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Zero pair in original plan');$sum=GiftCodeProtocol::sum($sum,$count);}
        if($sum!==$plan['usedCount'])GiftCodeProtocol::fail('MIGRATION_REQUIRED','Plan count does not match proven global usage');self::verifyPlan($plan,$sha);return$plan;
    }
    private static function configurationDigest(array $gift): string
    { return hash('sha256',json_encode([$gift['reward_data'],$gift['values_json'],$gift['expires_at'],(string)$gift['max_redemptions'],$gift['max_per_user']===null?null:(string)$gift['max_per_user'],(string)$gift['unlimited_total']],JSON_THROW_ON_ERROR)); }
    private function matching(array $gift,array $plan,string $sha,bool $active): void
    {
        if(!$active&&(GiftCodeProtocol::stored($gift['config_revision'])!==GiftCodeProtocol::count($plan['configRevision'])||!hash_equals($plan['configurationDigest'],self::configurationDigest($gift))))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Frozen original configuration changed');
        if($active&&(GiftCodeProtocol::stored($gift['current_redemptions'])<GiftCodeProtocol::count($plan['usedCount'])||GiftCodeProtocol::stored($gift['config_revision'])<=GiftCodeProtocol::count($plan['configRevision'])))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Completed source counter/revision was rewound');
        if($gift['migration_hash']!==$sha||($active&&(int)$gift['protocol_version']!==2)||(!$active&&(int)$gift['protocol_version']!==0)||GiftCodeIdentity::canonical($gift)!==$plan['campaignId'])GiftCodeProtocol::fail('MIGRATION_REQUIRED','Campaign does not own this sealed source transfer');
    }
    public function applyPage(array $plan,string $sha,string $after='',int $limit=50): array
    {
        self::verifyPlan($plan,$sha);
        if($limit<1||$limit>100||($after!==''&&self::user($after)!==$after))throw new InvalidArgumentException('Expected player cursor and page size1..100');
        $this->scope->run(function()use($plan,$sha){
            // The same mutex as creation also covers raw-id versus alias collisions.
            $identity=new GiftCodeIdentity($this->pdo,$this->scope);$identity->available($plan['campaignId'],$plan['sourceId']);$gift=$this->gift($plan['sourceId'],true);
            if((int)$gift['protocol_version']===2){$this->matching($gift,$plan,$sha,true);return;}
            if((int)$gift['protocol_version']===0){$this->matching($gift,$plan,$sha,false);if((string)GiftCodeProtocol::stored($gift['current_redemptions'])!==$plan['usedCount'])GiftCodeProtocol::fail('MIGRATION_REQUIRED','Frozen source counter changed');return;}
            $fresh=$this->inspect($gift['code'],$plan['evidence']);$fresh['campaignId']=$plan['campaignId'];if($fresh!==$plan)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Source inventory/configuration changed since sealing');
            $identity->assign($plan['sourceId'],$plan['campaignId']);$q=$this->pdo->prepare('UPDATE gift_codes SET protocol_version=0,migration_hash=? WHERE id=? AND protocol_version=1 AND current_redemptions=? AND config_revision=?');$q->execute([$sha,$plan['sourceId'],$plan['usedCount'],$plan['configRevision']]);if($q->rowCount()!==1)GiftCodeProtocol::fail('STATE_CONFLICT','Source changed before freeze');
        });
        $gift=$this->gift($plan['sourceId']);if((int)$gift['protocol_version']===2){$this->matching($gift,$plan,$sha,true);return['changed'=>0,'after'=>$after,'more'=>false,'activated'=>true];}
        $selected=[];foreach($plan['inventory']['pairs']as$user=>$count)if(strcmp($user,$after)>0){$selected[$user]=$count;if(count($selected)===$limit)break;}
        $changed=0;foreach($selected as$user=>$count){$this->scope->run(function()use($plan,$sha,$user,$count){$gift=$this->gift($plan['sourceId'],true);$this->matching($gift,$plan,$sha,false);$this->counters->install($plan['campaignId'],$user,GiftCodeProtocol::count($count));});$after=$user;$changed++;}
        $more=false;foreach($plan['inventory']['pairs']as$user=>$ignored)if(strcmp($user,$after)>0){$more=true;break;}
        if(!$more)$this->scope->run(function()use($plan,$sha){$gift=$this->gift($plan['sourceId'],true);$this->matching($gift,$plan,$sha,false);$sum='0';$seen=0;foreach($this->counters->all($plan['campaignId'])as[$user,$actual]){if(!array_key_exists($user,$plan['inventory']['pairs'])||(string)$actual!==$plan['inventory']['pairs'][$user])GiftCodeProtocol::fail('MIGRATION_REQUIRED','Unexpected installed source pair');$sum=GiftCodeProtocol::sum($sum,(string)$actual);$seen++;}if($seen!==count($plan['inventory']['pairs']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Installed source count is missing');if($sum!==$plan['usedCount']||(string)GiftCodeProtocol::stored($gift['current_redemptions'])!==$plan['usedCount'])GiftCodeProtocol::fail('MIGRATION_REQUIRED','Installed aggregate differs');$revision=GiftCodeProtocol::next(GiftCodeProtocol::stored($gift['config_revision']));$activate=$this->pdo->prepare('UPDATE gift_codes SET protocol_version=2,config_revision=? WHERE id=? AND protocol_version=0 AND migration_hash=?');$activate->execute([(string)$revision,$plan['sourceId'],$sha]);if($activate->rowCount()!==1)GiftCodeProtocol::fail('STATE_CONFLICT','Activation changed concurrently');});
        return['changed'=>$changed,'after'=>$after,'more'=>$more,'activated'=>!$more];
    }
    public function cleanupPage(array $plan,string $sha,int $limit=50): array
    {
        self::verifyPlan($plan,$sha);
        if($limit<1||$limit>100)throw new InvalidArgumentException('Page size must be1..100');
        return$this->scope->run(function()use($plan,$sha,$limit){
            $gift=$this->gift($plan['sourceId'],true);$this->matching($gift,$plan,$sha,true);
            $q=$this->pdo->prepare('SELECT * FROM gift_code_redemptions WHERE gift_code_id=? ORDER BY id LIMIT '.$limit.($this->mysql?' FOR UPDATE':''));$q->execute([$plan['sourceId']]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();$removed=0;$guards=0;
            foreach($rows as$row){$user=self::user($row['user_id']);$base=$plan['inventory']['pairs'][$user]??null;if($base===null||$this->counters->read($plan['campaignId'],$user,true)<GiftCodeProtocol::count($base))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Permanent original pair missing');$proof=self::originalGrant($row);$guards+=$this->guards->acknowledge($user,$plan['sourceId'],[$row['id']=>$proof]);$q=$this->pdo->prepare('DELETE FROM gift_code_redemptions WHERE id=? AND gift_code_id=? AND user_id=?');$q->execute([$row['id'],$plan['sourceId'],$user]);if($q->rowCount()!==1)GiftCodeProtocol::fail('STATE_CONFLICT','Original source changed during cleanup');$removed++;}
            // Remaining orphan guards are from the sealed complete inventory; V1/old writers are closed.
            if(!$rows){$users=0;foreach($this->guards->scan($plan['sourceId'])as[$user,$originals]){if($users++===$limit)break;$base=$plan['inventory']['pairs'][$user]??null;if($base===null||$this->counters->read($plan['campaignId'],$user,true)<GiftCodeProtocol::count($base))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Orphan value source has no installed pair');$guards+=$this->guards->acknowledge($user,$plan['sourceId']);}}
            $q=$this->pdo->prepare('SELECT 1 FROM gift_code_redemptions WHERE gift_code_id=? LIMIT 1');$q->execute([$plan['sourceId']]);$more=$q->fetchColumn()!==false;$q->closeCursor();if(!$more)foreach($this->guards->scan($plan['sourceId'])as$ignored){$more=true;break;}
            return['removedOriginalRows'=>$removed,'removedValueGuards'=>$guards,'more'=>$more];
        });
    }
}
