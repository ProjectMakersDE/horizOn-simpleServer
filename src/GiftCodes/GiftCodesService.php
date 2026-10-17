<?php

declare(strict_types=1);
require_once __DIR__ . '/GiftCodeProtocol.php';
require_once __DIR__ . '/GiftCodeCounterStore.php';
require_once __DIR__ . '/GiftCodeIdentity.php';
require_once __DIR__ . '/../PlayerValues/PlayerValuesService.php';
require_once __DIR__ . '/../PlayerProfile/PlayerProfileController.php';

/** One installation/project: source counters and every reward share the explicit PDO owner. */
class GiftCodesService
{
    private PDO $pdo;
    private bool $mysql;
    private SqlTransaction $transactions;
    private GiftCodeCounterStore $counters;
    private ?PlayerValuesService $values = null;
    private $clock;
    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null)
    {
        $this->pdo=$pdo; $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->transactions=$transactions??new SqlTransaction($pdo);
        if(!$this->transactions->owns($pdo))throw new InvalidArgumentException('Transaction scope belongs to another connection');
        $this->counters=new GiftCodeCounterStore($pdo,$this->transactions);
        $this->clock=$clock??static function(){return (int)floor(microtime(true)*1000);};
        if(!$this->mysql)$pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function values(): PlayerValuesService
    { return $this->values??($this->values=new PlayerValuesService($this->pdo,$this->clock,$this->transactions)); }
    private function transaction(callable $work)
    {
        if($this->pdo->inTransaction()&&!$this->transactions->isRunning())throw new LogicException('Gift writes require the explicit owning SQL scope');
        try{return $this->transactions->run($work);}
        catch(SqlTransactionUncertain $error){GiftCodeProtocol::fail('STATE_CONFLICT','Commit outcome uncertain; retry the original tuple');}
        catch(SqlTransactionRollbackOnly $error){GiftCodeProtocol::fail('STATE_CONFLICT','Nested operation failed; retain the original tuple');}
        catch(PDOException $error){
            if(in_array((int)($error->errorInfo[1]??0),[5,6,19,1062,1205,1213,2006,2013],true))GiftCodeProtocol::fail('STATE_CONFLICT','Concurrent operation; retry the original tuple');
            throw $error;
        }
    }
    private function code(string $value,bool $lock=false,bool $byId=false): array
    {
        $q=$this->pdo->prepare('SELECT * FROM gift_codes WHERE '.($byId?'id=:id OR v2_id=:binary':'code=:id').($lock&&$this->mysql?' FOR UPDATE':''));
        $q->bindValue(':id',$value);if($byId)$q->bindValue(':binary',GiftCodeProtocol::binary($value),PDO::PARAM_LOB);$q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        if(!$rows)GiftCodeProtocol::fail('INVALID_GIFT_CODE','Gift campaign is unavailable',404);
        if(count($rows)!==1)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Campaign identity is ambiguous');return$rows[0];
    }
    private function active(array $gift): void
    {
        if(GiftCodeProtocol::stored($gift['protocol_version'])!==2)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Campaign needs verified operator migration');
        GiftCodeIdentity::canonical($gift);GiftCodeProtocol::stored($gift['current_redemptions']);GiftCodeProtocol::stored($gift['config_revision']);
    }
    private function available(array $gift,int $personal,int $now): void
    {
        if($gift['expires_at']!==null&&(new DateTimeImmutable($gift['expires_at'],new DateTimeZone('UTC')))->getTimestamp()*1000<$now)
            GiftCodeProtocol::fail('INVALID_GIFT_CODE','Gift code has expired',400);
        $total=GiftCodeProtocol::stored($gift['current_redemptions']);
        $unlimited=GiftCodeProtocol::stored($gift['unlimited_total']);
        if($unlimited>1)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Campaign limit flag needs reconciliation');
        if(!$unlimited&&$total>=GiftCodeProtocol::stored($gift['max_redemptions']))GiftCodeProtocol::fail('INVALID_GIFT_CODE','Maximum total redemptions reached',400);
        if($gift['max_per_user']!==null&&$personal>=GiftCodeProtocol::stored($gift['max_per_user']))GiftCodeProtocol::fail('INVALID_GIFT_CODE','Maximum player redemptions reached',400);
    }
    public static function rewardValues($values): array
    {
        $values = PlayerValuesLedger::list($values, 8); $seen = [];
        foreach ($values as $value) {
            if (!is_array($value)) PlayerValuesLedger::fail('INVALID_REQUEST', 'Invalid gift value reward', 400);
            $key = PlayerValuesLedger::key($value['key'] ?? null); PlayerValuesLedger::integer($value['amount'] ?? null, 1);
            if (isset($seen[$key])) PlayerValuesLedger::fail('INVALID_REQUEST', 'Duplicate gift value key', 400);
            $seen[$key] = true;
        }
        return array_map(static function ($value) { return ['key' => $value['key'], 'amount' => $value['amount']]; }, $values);
    }
    private static function storedValues(array $code): array
    { return self::rewardValues($code['values_json'] === null ? [] : json_decode($code['values_json'], true, 512, JSON_THROW_ON_ERROR)); }

    public function validate(string $code,string $user): bool
    {
        try{
            $gift=$this->code($code);$this->active($gift);
            $this->available($gift,$this->counters->read(GiftCodeIdentity::canonical($gift),GiftCodeProtocol::uuid($user)),($this->clock)());return true;
        }catch(GiftCodesFailure $error){if(in_array($error->errorCode,['INVALID_GIFT_CODE','MIGRATION_REQUIRED'],true))return false;throw $error;}
    }
    /** Read-only, without a server-side intent/reservation. Controller requires the original player's Bearer. */
    public function prepare(string $code,string $user): array
    {
        try{$gift=$this->code($code);}catch(GiftCodesFailure $error){if($error->errorCode==='INVALID_GIFT_CODE')return ['valid'=>false];throw $error;}
        $this->active($gift);$count=$this->counters->read(GiftCodeIdentity::canonical($gift),GiftCodeProtocol::uuid($user));
        try{$this->available($gift,$count,($this->clock)());}
        catch(GiftCodesFailure $error){if($error->errorCode==='INVALID_GIFT_CODE')return ['valid'=>false];throw $error;}
        return ['valid'=>true,'protocolVersion'=>2,'campaignId'=>GiftCodeIdentity::canonical($gift),'expectedCount'=>(string)$count,'configRevision'=>(string)GiftCodeProtocol::stored($gift['config_revision'])];
    }
    /** V1 IDs never become V2 sequence counts, including old replay attempts. */
    public function redeem(string $code,string $user,$idempotencyId=null): array
    { GiftCodeProtocol::fail('UPGRADE_REQUIRED','V1 redemption and replay are closed; resolve old outcomes before V2'); }
    public function redeemPrepared(string $code,string $user,array $request): array
    {
        $intent=GiftCodeProtocol::intent($request);$user=GiftCodeProtocol::uuid($user);
        return $this->transaction(function()use($user,$intent){
            $q=$this->pdo->prepare('SELECT id FROM users WHERE id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$user]);
            if($q->fetch()===false)GiftCodeProtocol::fail('PLAYER_NOT_FOUND','Player not found',404);$q->closeCursor();
            // The immutable UUID, never the currently visible code, owns this tuple.
            $gift=$this->code($intent['campaignId'],true,true);$this->active($gift);
            $current=$this->counters->read(GiftCodeIdentity::canonical($gift),$user,true);
            if($current>$intent['expectedCount'])return $this->result($gift,$current,null,[],[],$this->values()->get($user),true);
            if($current<$intent['expectedCount'])GiftCodeProtocol::fail('COUNT_AHEAD','Original expected count is ahead; retain it for reconciliation');
            $revision=GiftCodeProtocol::stored($gift['config_revision']);
            if($revision!==$intent['configRevision'])GiftCodeProtocol::fail('CAMPAIGN_CHANGED','Original campaign revision changed; do not prepare automatically');
            $this->available($gift,$current,($this->clock)());
            $total=GiftCodeProtocol::stored($gift['current_redemptions']);GiftCodeProtocol::next($total);GiftCodeProtocol::next($current);
            $q=$this->pdo->prepare('UPDATE gift_codes SET current_redemptions=current_redemptions+1 WHERE id=? AND current_redemptions=? AND config_revision=? AND protocol_version=2');
            $q->execute([$gift['id'],(string)$total,(string)$revision]);if($q->rowCount()!==1)GiftCodeProtocol::fail('STATE_CONFLICT','Campaign changed; retry the original tuple');
            $next=$this->counters->advance(GiftCodeIdentity::canonical($gift),$user,$current);$rewards=self::storedValues($gift);$now=($this->clock)();
            $state=$rewards?$this->values()->grantFromSource($user,$rewards,'giftcode:'.GiftCodeIdentity::canonical($gift).':'.$next,$now,'giftcode:'.GiftCodeIdentity::canonical($gift)):$this->values()->get($user);
            $grants=$this->applyGrants($user,PlayerProfileController::parseGrants($gift['reward_data']));
            return $this->result($gift,$next,$gift['reward_data'],$grants,$rewards,$state,false);
        });
    }
    private function applyGrants(string $user, array $requested): array
    {
        $grants = PlayerProfileController::existingCosmeticIds($this->pdo, $requested);
        if (!$grants) return [];
        $query = $this->pdo->prepare('SELECT unlocks FROM users WHERE id=?' . ($this->mysql ? ' FOR UPDATE' : '')); $query->execute([$user]);
        $owned = PlayerProfileController::decodeList($query->fetch()['unlocks']); $next = $owned;
        foreach ($grants as $key) if (!in_array($key, $next, true)) $next[] = $key;
        if (count($next) > PlayerProfileController::MAX_UNLOCKS) throw new GiftCodesFailure('A player can hold at most ' . PlayerProfileController::MAX_UNLOCKS . ' unlocks', 'UNLOCK_LIMIT_REACHED', 409);
        if ($next !== $owned) $this->pdo->prepare('UPDATE users SET unlocks=? WHERE id=?')->execute([json_encode($next, JSON_THROW_ON_ERROR), $user]);
        return $grants;
    }

    private function result(array $gift,int $count,?string $data,array $unlocks,array $rewards,?array $state,bool $replayed): array
    { return ['success'=>true,'message'=>$replayed?'Sequence already consumed; current state returned':'Gift code redeemed successfully',
        'campaignId'=>GiftCodeIdentity::canonical($gift),'count'=>(string)$count,'configRevision'=>(string)GiftCodeProtocol::stored($gift['config_revision']),
        'alreadyApplied'=>$replayed,'replayed'=>$replayed,'giftData'=>$data,'grantedUnlocks'=>$unlocks,'grantedValues'=>$rewards,'playerValues'=>$state]; }

    public function configureValues(string $code,array $request): array
    { return $this->configure($code,$request,null); }
    public function configure(string $code,array $request,?string $expectedRevision): array
    {
        return $this->transaction(function()use($code,$request,$expectedRevision){
            $gift=$this->code($code,true);if((int)$gift['protocol_version']===0)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Frozen migration must finish before configuration changes');
            $revision=GiftCodeProtocol::stored($gift['config_revision']);
            if($expectedRevision!==null&&GiftCodeProtocol::count($expectedRevision)!==$revision)GiftCodeProtocol::fail('REVISION_CONFLICT','Reload campaign configuration');
            $next=$this->settings($gift,$request);
            if($next!==$gift){$next['config_revision']=GiftCodeProtocol::next($revision);$this->saveConfiguration($next);}
            return $this->view($next);
        });
    }
    private function settings(array $gift,array $request): array
    {
        if(array_diff(array_keys($request),['values','giftData','expiresAt','maxTotalRedemptions','maxRedemptionsPerUser']))GiftCodeProtocol::fail('INVALID_REQUEST','Unsupported campaign configuration field',400);
        if(array_key_exists('values',$request)){
            $values=self::rewardValues($request['values']);
            if($values){$defs=array_column($this->values()->config(false,true)['definitions'],null,'key');foreach($values as$value)
                if(!isset($defs[$value['key']])||$value['amount']>$defs[$value['key']]['maxBalance'])GiftCodeProtocol::fail('INVALID_REQUEST','Reward requires a configured value and valid amount',400);}
            $gift['values_json']=json_encode($values,JSON_THROW_ON_ERROR);
        }
        if(array_key_exists('giftData',$request)){
            if($request['giftData']!==null&&!is_string($request['giftData']))GiftCodeProtocol::fail('INVALID_REQUEST','giftData must be a string or null',400);
            $requested=PlayerProfileController::parseGrants($request['giftData']);
            if(count(PlayerProfileController::existingCosmeticIds($this->pdo,$requested))!==count($requested))GiftCodeProtocol::fail('INVALID_REQUEST','Unknown cosmetic reward',400);
            $gift['reward_data']=$request['giftData'];
        }
        if(array_key_exists('expiresAt',$request)){
            $value=$request['expiresAt'];
            if($value!==null&&(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z$/D',$value)))GiftCodeProtocol::fail('INVALID_REQUEST','expiresAt must be a UTC instant or null',400);
            try{$date=$value===null?null:new DateTimeImmutable($value);$errors=DateTimeImmutable::getLastErrors();if($date!==null&&$errors!==false&&($errors['warning_count']||$errors['error_count']))throw new InvalidArgumentException('Invalid date');$gift['expires_at']=$date===null?null:$date->format('Y-m-d H:i:s');}catch(Throwable $error){GiftCodeProtocol::fail('INVALID_REQUEST','Invalid expiry',400);}
        }
        foreach(['maxTotalRedemptions','maxRedemptionsPerUser']as$key)if(array_key_exists($key,$request)){
            $limit=$request[$key];if($limit!==null&&(!is_int($limit)||$limit<1||$limit>2147483647))GiftCodeProtocol::fail('INVALID_REQUEST','Limits must be positive Int32 or null',400);
            if($key==='maxTotalRedemptions'){$gift['unlimited_total']=$limit===null?1:0;if($limit!==null)$gift['max_redemptions']=$limit;}
            else $gift['max_per_user']=$limit;
        }
        return $gift;
    }
    private function saveConfiguration(array $gift): void
    {
        $this->pdo->prepare('UPDATE gift_codes SET values_json=?,reward_data=?,expires_at=?,max_redemptions=?,max_per_user=?,unlimited_total=?,config_revision=? WHERE id=?')
            ->execute([$gift['values_json'],$gift['reward_data'],$gift['expires_at'],$gift['max_redemptions'],$gift['max_per_user'],$gift['unlimited_total'],(string)$gift['config_revision'],$gift['id']]);
    }
    public function create(array $request): array
    {
        $code=$request['code']??null;unset($request['code']);
        if(!is_string($code)||!preg_match('/^[A-Z0-9]{3,50}$/D',$code))GiftCodeProtocol::fail('INVALID_REQUEST','Expected uppercase code of3..50 letters/digits',400);
        return $this->transaction(function()use($code,$request){
            $gift=['id'=>Database::uuid(),'code'=>$code,'reward_data'=>null,'values_json'=>null,'expires_at'=>null,
                'max_redemptions'=>1,'max_per_user'=>1,'unlimited_total'=>0,'current_redemptions'=>0,'config_revision'=>1,'protocol_version'=>2,'migration_hash'=>null,'v2_id'=>null];
            $gift=$this->settings($gift,$request);(new GiftCodeIdentity($this->pdo,$this->transactions))->available($gift['id']);
            $this->pdo->prepare('INSERT INTO gift_codes(id,code,protocol_version) VALUES(?,?,2)')->execute([$gift['id'],$code]);$this->saveConfiguration($gift);return $this->view($gift);
        });
    }
    public function statistics(): array
    {
        $q=$this->pdo->query('SELECT current_redemptions FROM gift_codes');$sum='0';$codes=0;
        while(($row=$q->fetch(PDO::FETCH_ASSOC))!==false){$sum=GiftCodeProtocol::sum($sum,(string)GiftCodeProtocol::stored($row['current_redemptions']));$codes++;}$q->closeCursor();
        return ['totalCodes'=>$codes,'exactTotalRedemptions'=>$sum];
    }
    public function view(array $gift): array
    {
        try{$campaign=GiftCodeIdentity::canonical($gift);}catch(GiftCodesFailure $error){$campaign=null;}
        $current=GiftCodeProtocol::stored($gift['current_redemptions']);$remaining=(int)$gift['unlimited_total']===1?null:max(0,GiftCodeProtocol::stored($gift['max_redemptions'])-$current);
        return ['id'=>$gift['id'],'campaignId'=>$campaign,'code'=>$gift['code'],'protocolVersion'=>(int)$gift['protocol_version'],'configRevision'=>(string)GiftCodeProtocol::stored($gift['config_revision']),
            'giftData'=>$gift['reward_data'],'values'=>self::storedValues($gift),'expiresAt'=>$gift['expires_at'],
            'maxTotalRedemptions'=>(int)$gift['unlimited_total']===1?null:(int)$gift['max_redemptions'],'maxRedemptionsPerUser'=>$gift['max_per_user']===null?null:(int)$gift['max_per_user'],
            'currentRedemptions'=>$current,'currentTotalRedemptions'=>$current,'remainingRedemptions'=>$remaining,
            'exactCurrentTotalRedemptions'=>(string)$current,'exactRemainingRedemptions'=>$remaining===null?null:(string)$remaining];
    }
}
