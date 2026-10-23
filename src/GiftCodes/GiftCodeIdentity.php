<?php

declare(strict_types=1);
require_once __DIR__.'/GiftCodeProtocol.php';
require_once __DIR__.'/../Core/SqlTransaction.php';
/** One installation-wide mutex prevents UUID/raw-id/alias cross-column collisions. */
class GiftCodeIdentity
{
    private PDO $pdo; private SqlTransaction $scope; private bool $mysql;
    public function __construct(PDO $pdo,SqlTransaction $scope){if(!$scope->owns($pdo))throw new LogicException('Identity scope mismatch');$this->pdo=$pdo;$this->scope=$scope;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    public static function fromBytes($bytes): string
    {
        if(!is_string($bytes)||strlen($bytes)!==16)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Invalid campaign UUID encoding');
        $h=bin2hex($bytes);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    public static function canonical(array $gift): string
    {
        $alias=$gift['v2_id']??null;
        try{$original=GiftCodeProtocol::uuid($gift['id']);}catch(GiftCodesFailure $e){$original=null;}
        if($original!==null&&$gift['id']!==$original)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Noncanonical original UUID requires explicit recovery');
        if($alias!==null){if($original!==null)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Regular UUID has an unexpected alias');return self::fromBytes($alias);}
        if($original!==null&&$gift['id']===$original)return$original;
        GiftCodeProtocol::fail('MIGRATION_REQUIRED','Legacy identity requires a sealed UUID mapping');
    }
    public function available(string $uuid,?string $sourceId=null): void
    {
        if(!$this->scope->isRunning())throw new LogicException('Identity reservation requires owning scope');
        $lock=$this->pdo->query('SELECT id FROM gift_code_identity_lock WHERE id=1'.($this->mysql?' FOR UPDATE':''));if($lock->fetchColumn()===false)throw new RuntimeException('Gift identity lock not initialized');$lock->closeCursor();
        $q=$this->pdo->prepare('SELECT id FROM gift_codes WHERE LOWER(id)=:uuid OR v2_id=:binary'.($this->mysql?' FOR UPDATE':''));$q->bindValue(':uuid',GiftCodeProtocol::uuid($uuid));$q->bindValue(':binary',GiftCodeProtocol::binary($uuid),PDO::PARAM_LOB);$q->execute();
        $owned=false;while(($id=$q->fetchColumn())!==false){if($id!==$sourceId)GiftCodeProtocol::fail('STATE_CONFLICT','Campaign UUID or legacy alias is already reserved');$owned=true;}$q->closeCursor();
        // Permanent counters also reserve an identity after its campaign row was removed.
        if(!$owned){$q=$this->pdo->prepare('SELECT 1 FROM gift_code_counts WHERE campaign_id=:binary LIMIT 1'.($this->mysql?' FOR UPDATE':''));$q->bindValue(':binary',GiftCodeProtocol::binary($uuid),PDO::PARAM_LOB);$q->execute();$retired=$q->fetchColumn()!==false;$q->closeCursor();if($retired)GiftCodeProtocol::fail('STATE_CONFLICT','Retired campaign UUID remains permanently reserved');}
    }
    public function assign(string $sourceId,string $uuid): void
    {
        $this->available($uuid,$sourceId);$uuid=GiftCodeProtocol::uuid($uuid);
        try{$original=GiftCodeProtocol::uuid($sourceId);}catch(GiftCodesFailure $e){$original=null;}
        if($original!==null&&$sourceId!==$original)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Noncanonical original UUID requires explicit recovery');
        if($original!==null&&$uuid!==$original)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Existing UUID cannot be reassigned');
        $q=$this->pdo->prepare('SELECT v2_id FROM gift_codes WHERE id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$sourceId]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if($row===false)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Missing original campaign');
        if($sourceId===$uuid){if($row['v2_id']!==null)GiftCodeProtocol::fail('MIGRATION_REQUIRED','Regular UUID has an unexpected alias');return;}
        $bytes=GiftCodeProtocol::binary($uuid);if($row['v2_id']!==null){if(!hash_equals($bytes,$row['v2_id']))GiftCodeProtocol::fail('STATE_CONFLICT','Sealed identity differs');return;}
        $q=$this->pdo->prepare('UPDATE gift_codes SET v2_id=:binary WHERE id=:source AND v2_id IS NULL');$q->bindValue(':binary',$bytes,PDO::PARAM_LOB);$q->bindValue(':source',$sourceId);$q->execute();if($q->rowCount()!==1)GiftCodeProtocol::fail('STATE_CONFLICT','Original identity changed');
    }
}
