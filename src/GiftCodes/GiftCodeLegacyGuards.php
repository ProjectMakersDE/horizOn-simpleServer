<?php

declare(strict_types=1);
require_once __DIR__.'/GiftCodeProtocol.php';
require_once __DIR__.'/../Core/SqlTransaction.php';
require_once __DIR__.'/../PlayerValues/PlayerValuesStorage.php';

/** Offline Gift-only bridge. Other origin guards and every authoritative balance remain intact. */
class GiftCodeLegacyGuards
{
    private PDO $pdo;private SqlTransaction $scope;private bool $mysql;
    public function __construct(PDO $pdo,SqlTransaction $scope){if(!$scope->owns($pdo))throw new LogicException('Legacy guard owner mismatch');$this->pdo=$pdo;$this->scope=$scope;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    private function keys(): array
    {
        $value=$this->pdo->query('SELECT known_keys FROM player_values_config WHERE id=1')->fetchColumn();if($value===false)throw new RuntimeException('Player-values catalog missing');
        $keys=json_decode($value,true,512,JSON_THROW_ON_ERROR);if(!is_array($keys))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Value slots need reconciliation');return$keys;
    }
    public static function selected(array $state,string $sourceId): array
    {
        $prefix='giftcode:'.$sourceId.':';$out=[];
        if(!isset($state['receipts'])||!is_array($state['receipts']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Unrecognized original value state');
        foreach($state['receipts']as$receipt){
            $op=$receipt['operationId']??null;if(!is_string($op)||strpos($op,$prefix)!==0)continue;
            $id=substr($op,strlen($prefix));try{$canonical=GiftCodeProtocol::uuid($id);}catch(GiftCodesFailure $e){GiftCodeProtocol::fail('MIGRATION_REQUIRED','Unrecognized original gift source identity');}
            if($id!==$canonical||!isset($receipt['payloadHash'])||!is_string($receipt['payloadHash'])||!preg_match('/^[a-f0-9]{64}$/D',$receipt['payloadHash']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Ambiguous gift source guard');
            $issued=GiftCodeProtocol::stored($receipt['issuedAtMillis']??null);
            if(($receipt['source']??'')!==''&&$receipt['source']!=='giftcode:'.$sourceId)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Gift source ownership differs');
            if(isset($out[$id]))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Duplicate gift guard');
            $out[$id]=['operationId'=>$op,'payloadHash'=>$receipt['payloadHash'],'issuedAtMillis'=>$issued];
        }
        return$out;
    }
    /** Bounded keyset reads, with no login/startup scan and no historical delta output. */
    public function scan(string $sourceId): Generator
    {
        $keys=$this->keys();$after='';
        do{
            $q=$this->pdo->prepare('SELECT user_id,data FROM player_values WHERE user_id>? ORDER BY user_id LIMIT 100');$q->execute([$after]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
            foreach($rows as$row){$after=$row['user_id'];$guards=self::selected(PlayerValuesStorage::decode($row['data'],$keys),$sourceId);if($guards)yield[$row['user_id'],$guards];}
        }while(count($rows)===100);
    }
    /** Caller has already verified the completed source plan and its installed permanent pair. */
    public function acknowledge(string $user,string $sourceId,?array $originals=null): int
    {
        if(!$this->scope->isRunning())throw new LogicException('Gift acknowledgement requires owning transaction');
        $q=$this->pdo->prepare('SELECT id FROM users WHERE id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$user]);if($q->fetchColumn()===false)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original gift player missing');$q->closeCursor();
        $q=$this->pdo->prepare('SELECT data FROM player_values WHERE user_id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$user]);$raw=$q->fetchColumn();$q->closeCursor();if($raw===false)return 0;
        $keys=$this->keys();$state=PlayerValuesStorage::decode($raw,$keys);$guards=self::selected($state,$sourceId);$remove=[];
        foreach($guards as$id=>$guard){
            if($originals!==null&&!array_key_exists($id,$originals))continue;
            if($originals!==null){$expected=$originals[$id];if($expected===null||$expected['issuedAtMillis']!==$guard['issuedAtMillis']||!hash_equals($expected['payloadHash'],$guard['payloadHash']))GiftCodeProtocol::fail('MIGRATION_REQUIRED','Original grant proof conflicts with retained guard');}
            $remove[$guard['operationId']]=true;$state['evictedThroughMillis']=max($state['evictedThroughMillis'],$guard['issuedAtMillis']);
        }
        if(!$remove)return 0;
        $state['receipts']=array_values(array_filter($state['receipts'],static function($r)use($remove){return!isset($remove[$r['operationId']]);}));
        $state['revision']=PlayerValuesLedger::integer($state['revision'],0,PlayerValuesLedger::MAX-1)+1;
        $next=PlayerValuesStorage::encode($state,$keys);$equals=$this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY';
        $q=$this->pdo->prepare('UPDATE player_values SET data=? WHERE user_id=? AND '.$equals);$q->execute([$next,$user,$raw]);if($q->rowCount()!==1)GiftCodeProtocol::fail('STATE_CONFLICT','Original value state changed during guard transfer');
        return count($remove);
    }
}
