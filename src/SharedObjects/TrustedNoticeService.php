<?php

declare(strict_types=1);
require_once __DIR__.'/SharedObjectRules.php';
require_once __DIR__.'/../Inbox/InboxService.php';
/** Local trusted operator only. Replays keep the original ID, revision, issued time and plain body. */
class TrustedNoticeService
{
    private InboxService $inbox;private SqlTransaction $scope;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $scope=null){$this->scope=$scope??new SqlTransaction($pdo);if(!$this->scope->owns($pdo))throw new InvalidArgumentException('Foreign transaction connection');$this->inbox=new InboxService($pdo,$clock,$this->scope);}
    public function sendCommitted($raw): array {
        if($this->scope->isActive())SharedObjectRules::fail('STATE_CONFLICT');$result=$this->send($raw);
        if($this->scope->isActive())SharedObjectRules::fail('STATE_CONFLICT');return $result;
    }
    public function send($raw): array {
        $shape=$raw instanceof stdClass?get_object_vars($raw):$raw;
        if(is_array($shape)&&array_key_exists('textKey',$shape)){
            $in=self::validateStoredInput($raw);
            foreach(['apiKeyId','userId','operationId']as$key)$in[$key]=SharedObjectRules::uuid($in[$key]??null);
            if($this->inbox->broadcasts()['apiKeyId']!==$in['apiKeyId'])SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);
            $content=InboxRules::content(['type'=>'game-notice','textKey'=>$in['textKey']??null,'reference'=>$in['reference']??null,'parameters'=>$in['parameters']??null,'expiresAt'=>$in['expiresAt']??null]);
            return $this->inbox->replayLegacy($in['userId'],$content,'game-notice:'.$in['operationId'],self::instant($in['issuedAt']??null),'game-notice');
        }
        $in=self::validateInput($raw);
        if($this->inbox->broadcasts()['apiKeyId']!==$in['apiKeyId'])SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);
        return $this->inbox->freeText($in['userId'],$in['operationId'],$in['expectedRevision'],self::instant($in['issuedAt']),self::instant($in['expiresAt']),$in['freeText']);
    }
    /** Structural reader for already persisted legacy files; send only proves an accepted legacy operation. */
    public static function validateStoredInput($raw): array {
        $in=$raw instanceof stdClass?get_object_vars($raw):$raw;
        if(!is_array($in)||!array_key_exists('textKey',$in))return self::validateInput($raw);
        $fields=['apiKeyId','userId','operationId','issuedAt','expiresAt','textKey','reference','parameters'];$in=SharedObjectRules::fields($raw,$fields);
        if(array_diff($fields,array_keys($in)))SharedObjectRules::invalid();foreach(['apiKeyId','userId','operationId']as$key)$in[$key]=SharedObjectRules::uuid($in[$key]);
        if(self::instant($in['expiresAt'])<=self::instant($in['issuedAt']))SharedObjectRules::invalid();
        InboxRules::content(['type'=>'game-notice','textKey'=>$in['textKey'],'reference'=>$in['reference'],'parameters'=>$in['parameters'],'expiresAt'=>$in['expiresAt']]);return $in;
    }
    public static function validateInput($raw): array {
        $fields=['apiKeyId','userId','operationId','issuedAt','expiresAt','freeText','expectedRevision'];$in=SharedObjectRules::fields($raw,$fields);
        if(array_diff($fields,array_keys($in)))SharedObjectRules::invalid();
        foreach(['apiKeyId','userId','operationId']as$key)$in[$key]=SharedObjectRules::uuid($in[$key]);
        $issued=self::instant($in['issuedAt']);$expires=self::instant($in['expiresAt']);if($expires<=$issued)SharedObjectRules::invalid();
        SharedObjectRules::integer($in['expectedRevision']);if(!is_string($in['freeText'])||!preg_match('//u',$in['freeText'])||preg_match('/^[\p{Z}\s]*$/uD',$in['freeText']))SharedObjectRules::invalid();return $in;
    }
    private static function instant($value): int {
        if(!is_string($value))SharedObjectRules::invalid();if(preg_match('/\.([0-9]+)/',$value,$m)&&strlen($m[1])>3&&trim(substr($m[1],3),'0')!=='')SharedObjectRules::invalid();
        $time=InboxRules::millis($value);return SharedObjectRules::integer($time);
    }
}
