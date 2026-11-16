<?php

declare(strict_types=1);
require_once __DIR__.'/../Items/ItemJson.php';
require_once __DIR__.'/../Items/ItemRules.php';
require_once __DIR__.'/../Inbox/InboxRules.php';
require_once __DIR__.'/../Core/Config.php';

class GiftFailure extends RuntimeException
{
    public string $errorCode; public int $status; public ?int $retryAfter;
    public function __construct(string $code,int $status=409,?int $retryAfter=null)
    { parent::__construct(strtolower(str_replace('_',' ',$code))); $this->errorCode=$code; $this->status=$status; $this->retryAfter=$retryAfter; }
}

/** Item gifts (TASK-1140). One installation is one project; all numbers remain exact on a 64-bit PHP runtime. */
class GiftRules
{
    public const MAX=9007199254740991;
    /** Product rule: seven days of server time from the successful send. */
    public const EXPIRY=604800000;
    /** Acceptance window of an original send intent, as for marketplace operations. */
    public const RETENTION=2592000000;
    /** Terminal gifts stay readable for one week, then the floor guards their operation IDs. */
    public const TERMINAL=604800000;
    /** Retry delay when the return owner's Inbox is full. */
    public const RETRY=1800000;
    public const SOURCE='item-gift:';
    public const STATUSES=['offered','claimed','return_pending','return_delivered','returned'];
    /** SQL due kinds: 0 return work (offer expiry or pending return), 1 terminal removal, 2 nothing scheduled. */
    public const DUE_RETURN=0; public const DUE_TERMINAL=1; public const DUE_NONE=2;
    public static function fail(string $code,int $status=409,?int $retry=null): void { throw new GiftFailure($code,$status,$retry); }
    public static function invalid(): void { self::fail('INVALID_REQUEST',400); }
    public static function integer($v,int $min=0,int $max=self::MAX): int { if (!is_int($v)||$v<$min||$v>$max) self::invalid(); return $v; }
    public static function next(int $v): int { if ($v>=self::MAX) self::fail('STATE_CONFLICT'); return self::integer($v)+1; }
    public static function uuid($v): string
    { if (!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$v)) self::invalid(); return $v; }
    public static function currency($v): string { try { return PlayerValuesLedger::key($v); } catch (PlayerValuesFailure $e) { self::invalid(); } }
    public static function boolean($v): bool { if (!is_bool($v)) self::invalid(); return $v; }
    public static function fields($v,array $keys): array
    { if ($v instanceof stdClass) $v=get_object_vars($v); if (!is_array($v)||array_diff(array_keys($v),$keys)||array_diff($keys,array_keys($v))) self::invalid(); return $v; }
    public static function decode(string $raw): stdClass { try { return ItemJson::decode($raw,16384); } catch (ItemFailure $e) { self::invalid(); } }
    public static function json($v): string { return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public static function instant(?int $v): ?string { return $v===null?null:InboxRules::instant($v); }
    public static function millis($v): int
    { if (!is_string($v)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z$/D',$v)) self::invalid(); try { $ms=InboxRules::millis($v); } catch (InboxFailure $e) { self::invalid(); } if (self::instant($ms)!==$v) self::invalid(); return self::integer($ms); }
    public static function revision($v): int
    { if (!is_string($v)) self::invalid(); if (strlen($v)>=2&&$v[0]==='"'&&substr($v,-1)==='"') $v=substr($v,1,-1); if (!preg_match('/^[0-9]{1,16}$/D',$v)) self::invalid(); $n=ltrim($v,'0');$n=$n===''?'0':$n; if (strlen($n)===16&&strcmp($n,(string)self::MAX)>0) self::invalid(); return (int)$n; }
    public static function note($v): string
    { if (!is_string($v)||!preg_match('//u',$v)||strlen($v)>960||preg_match_all('/./us',$v)>240||preg_match('/^[\p{Z}\s]*$/uD',$v)||preg_match('/[\x{0000}-\x{0008}\x{000b}-\x{001f}\x{007f}-\x{009f}]/u',$v)) self::invalid(); return $v; }
    public static function expected(int $actual,int $expected): void { if ($actual!==$expected) self::fail('REVISION_CONFLICT'); }
    /** Contract defaults per tier FREE/BASIC/PLUS/PRO/ENTERPRISE: open gifts, retained gifts, sends per project minute. */
    public static function limits(string $tier): array
    {
        $tiers=['FREE'=>[3,50,10],'BASIC'=>[5,500,30],'PLUS'=>[10,1000,45],'PRO'=>[20,2500,60],'ENTERPRISE'=>[50,10000,120]];
        if (!isset($tiers[$tier])) self::invalid(); [$open,$rows,$rate]=$tiers[$tier];
        return ['openGiftsPerPlayer'=>$open,'retainedGiftsPerProject'=>$rows,'projectWritesPerMinute'=>$rate,'historyPerPlayer'=>20,'receivedPageSize'=>50,
            'playerWriteIntervalMillis'=>1000,'expirySeconds'=>604800,'terminalRetentionSeconds'=>604800,'operationRetentionSeconds'=>2592000];
    }
    /** Installation quotas are operator policy; the nominal Enterprise defaults are not maximums. */
    public static function operatorLimits(): array
    {
        $limits=self::limits('ENTERPRISE');
        foreach(self::operatorKeys() as $field=>$key)$limits[$field]=Config::operatorLimit($key,$limits[$field]);
        return $limits;
    }
    public static function operatorKeys(): array
    { return ['openGiftsPerPlayer'=>'ITEM_GIFTS_OPEN_GIFTS','retainedGiftsPerProject'=>'ITEM_GIFTS_RETAINED_GIFTS','projectWritesPerMinute'=>'ITEM_GIFTS_WRITES_PER_MINUTE']; }
    public static function validateLimits(array $limits): void
    {
        $policy=self::operatorKeys();$fixed=self::limits('ENTERPRISE');if(array_keys($limits)!==array_keys($fixed))self::invalid();
        foreach($fixed as $field=>$value)if(isset($policy[$field]))self::integer($limits[$field],1,2147483647);elseif($limits[$field]!==$value)self::invalid();
    }
    public static function emptyHeader(): array { return ['version'=>0,'revision'=>0,'enabled'=>false,'currencyKey'=>null,'fee'=>0,'escrowCount'=>0,'retainedCount'=>0,'bucket'=>0,'writes'=>0,'exists'=>false]; }
    public static function emptyPlayer(): array { return ['revision'=>0,'openIds'=>[],'historyIds'=>[],'floor'=>0,'nextAllowedAt'=>0,'exists'=>false]; }
    public static function window(array $player,int $issued,int $now): void
    { if ($issued>$now+30000) self::invalid(); if ($issued<$now-self::RETENTION||$issued<=$player['floor']) self::fail('OPERATION_EXPIRED'); }
    public static function terminal(array $g): bool { return in_array($g['status'],['claimed','returned'],true); }
    public static function expiredAudit(array $g,int $now): bool { return self::terminal($g)&&($g['terminalAt']??0)+self::TERMINAL<=$now; }
    /** An offer is claimable only while offered and strictly before its deadline, independent of maintenance. */
    public static function claimable(array $g,int $now): bool { return $g['status']==='offered'&&$g['expiresAt']>$now; }
    /** Decline, expiry and recipient deletion all revoke the recipient's claim and prepare the return. */
    public static function prepareReturn(array $g,int $now,bool $declined=false): array
    {
        $g['status']='return_pending';if($declined)$g['declinedAt']=$now;$g['returnIssuedAt']=$now;$g['returnEntryId']=null;
        $g['dueKind']=self::DUE_RETURN;$g['dueAt']=$now;return $g;
    }
    public static function view(array $g): array
    {
        return ['id'=>$g['id'],'revision'=>$g['revision'],'senderId'=>$g['senderId'],'recipientId'=>$g['recipientId'],'returnOwnerId'=>$g['returnOwnerId'],
            'itemReference'=>$g['itemReference'],'currencyKey'=>$g['currencyKey'],'fee'=>$g['fee'],'configRevision'=>$g['configRevision'],'status'=>$g['status'],
            'createdAt'=>self::instant($g['createdAt']),'expiresAt'=>self::instant($g['expiresAt']),'terminalAt'=>self::instant($g['terminalAt']),
            'declinedAt'=>self::instant($g['declinedAt']),'offerEntryId'=>$g['offerEntryId'],'returnEntryId'=>$g['returnEntryId'],'ownerUnavailable'=>$g['ownerUnavailable']];
    }
    public static function receipt(array $op,array $g): array
    {
        return ['operationId'=>$op['o'],'issuedAt'=>self::instant($op['i']),'giftId'=>$g['id'],'giftRevision'=>$op['r'],'recordedAt'=>self::instant($op['t']),
            'recipientId'=>$g['recipientId'],'itemReference'=>$g['itemReference'],'currencyKey'=>$g['currencyKey'],'fee'=>$g['fee'],'configRevision'=>$g['configRevision']];
    }
    public static function config(array $h,bool $admin=false): array
    {
        $out=['revision'=>$h['revision'],'enabled'=>$h['enabled'],'currencyKey'=>$h['currencyKey'],'fee'=>$h['fee']];
        return $admin?$out+['escrowCount'=>$h['escrowCount'],'retainedCount'=>$h['retainedCount']]:$out;
    }
    /** Open ID leaves, the newest terminal references stay within the history bound. */
    public static function history(array $p,string $id,int $limit): array
    { $p['openIds']=array_values(array_diff($p['openIds'],[$id]));$p['historyIds']=array_slice(array_values(array_merge(array_diff($p['historyIds'],[$id]),[$id])),-$limit);return $p; }
    public static function cursor(array $v): string { return rtrim(strtr(base64_encode(self::json($v)),'+/','-_'),'='); }
    public static function unCursor(?string $v): ?array
    { if ($v===null) return null; if ($v===''||strlen($v)>512||!preg_match('/^[A-Za-z0-9_-]+$/D',$v)) self::invalid(); $raw=base64_decode(strtr($v,'-_','+/'),true); if ($raw===false) self::invalid(); try { $r=json_decode($raw,true,16,JSON_THROW_ON_ERROR); } catch (JsonException $e) { self::invalid(); } if (!is_array($r)||self::cursor($r)!==$v) self::invalid(); return $r; }
}
