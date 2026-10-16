<?php

declare(strict_types=1);
require_once __DIR__.'/SharedObjectRules.php';
require_once __DIR__.'/../Inbox/InboxService.php';
/** Local trusted operator only, ordinary content, unchanged source/UUID/issued time, no pending-source bypass. */
class TrustedNoticeService
{
    private InboxService $inbox;private SqlTransaction $scope;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $scope=null){$this->scope=$scope??new SqlTransaction($pdo);if(!$this->scope->owns($pdo))throw new InvalidArgumentException('Foreign transaction connection');$this->inbox=new InboxService($pdo,$clock,$this->scope);}
    /** Durable file recipes may mark success only after an independently committed SQL operation. */
    public function sendCommitted($raw): array
    {
        if($this->scope->isActive())SharedObjectRules::fail('STATE_CONFLICT');
        $result=$this->send($raw);
        if($this->scope->isActive())SharedObjectRules::fail('STATE_CONFLICT');
        return $result;
    }
    public function send($raw): array
    {
        $in=self::validateInput($raw);$issued=self::instant($in['issuedAt']);
        $content=InboxRules::content(['type'=>'game-notice','textKey'=>$in['textKey'],'reference'=>$in['reference'],'parameters'=>$in['parameters'],'expiresAt'=>$in['expiresAt']]);
        if($this->inbox->broadcasts()['apiKeyId']!==$in['apiKeyId'])SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);
        return $this->inbox->deliver($in['userId'],$content,'game-notice:'.$in['operationId'],$issued,'game-notice');
    }
    public static function validateInput($raw): array
    {
        $in=SharedObjectRules::fields($raw,['apiKeyId','userId','operationId','issuedAt','expiresAt','textKey','reference','parameters']);
        foreach(['apiKeyId','userId','operationId'] as $key)$in[$key]=SharedObjectRules::uuid($in[$key]??null);
        $issued=self::instant($in['issuedAt']??null);$expires=self::instant($in['expiresAt']??null);if($issued<0||$expires<=$issued||$expires-$issued>604800000)SharedObjectRules::invalid();
        if(!isset($in['parameters'])||!$in['parameters'] instanceof stdClass)SharedObjectRules::invalid();
        $content=InboxRules::content(['type'=>'game-notice','textKey'=>$in['textKey']??null,'reference'=>$in['reference']??null,'parameters'=>$in['parameters'],'expiresAt'=>$in['expiresAt']]);
        return $in;
    }
    private static function instant($value): int
    {
        if(!is_string($value))SharedObjectRules::invalid();if(preg_match('/\.([0-9]+)/',$value,$m)&&strlen($m[1])>3&&trim(substr($m[1],3),'0')!=='')SharedObjectRules::invalid();return InboxRules::millis($value);
    }
}
