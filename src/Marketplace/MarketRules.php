<?php

declare(strict_types=1);
require_once __DIR__.'/../Items/ItemJson.php';
require_once __DIR__.'/../Inbox/InboxRules.php';

class MarketFailure extends RuntimeException
{
    public string $errorCode; public int $status; public ?int $retryAfter;
    public function __construct(string $code,int $status=409,?int $retryAfter=null)
    { parent::__construct(strtolower(str_replace('_',' ',$code))); $this->errorCode=$code; $this->status=$status; $this->retryAfter=$retryAfter; }
}

/** One installation is one project; all numbers remain exact on a 64-bit PHP runtime. */
class MarketRules
{
    public const MAX=9007199254740991;
    public const RETENTION=2592000000;
    public const TERMINAL=604800000;
    public const STATUSES=['active','return_pending','return_delivered','sold','returned'];
    public static function fail(string $code,int $status=409,?int $retry=null): void { throw new MarketFailure($code,$status,$retry); }
    public static function invalid(): void { self::fail('INVALID_REQUEST',400); }
    public static function integer($v,int $min=0,int $max=self::MAX): int { if (!is_int($v)||$v<$min||$v>$max) self::invalid(); return $v; }
    public static function next(int $v): int { return self::integer($v,0,self::MAX-1)+1; }
    public static function uuid($v): string
    { if (!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$v)) self::invalid(); return $v; }
    public static function itemKey($v): string { try { return ItemRules::key($v); } catch (ItemFailure $e) { self::invalid(); } }
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
    { if (!is_string($v)) self::invalid(); if (strlen($v)>=2&&$v[0]==='"'&&substr($v,-1)==='"') $v=substr($v,1,-1); if (!preg_match('/^[0-9]{1,16}$/D',$v)) self::invalid(); $n=ltrim($v,'0');$n=$n===''?'0':$n; if (strlen($n)>16||(strlen($n)===16&&strcmp($n,(string)self::MAX)>0)) self::invalid(); return (int)$n; }
    public static function note($v,bool $required=true): string
    { if (!is_string($v)||!preg_match('//u',$v)||strlen($v)>960||preg_match_all('/./us',$v)>240||($required&&preg_match('/^[\p{Z}\s\x{001c}-\x{001f}]*$/uD',$v))) self::invalid(); return $v; }
    public static function expected(int $actual,int $expected): void { if ($actual!==$expected) self::fail('REVISION_CONFLICT'); }
    public static function fee(int $price,int $bps): int { return intdiv($price,10000)*$bps+intdiv(($price%10000)*$bps,10000); }
    public static function limits(string $tier): array
    {
        $tiers=['FREE'=>[1,50,20,10],'BASIC'=>[3,500,30,30],'PLUS'=>[5,1000,40,45],'PRO'=>[10,2500,50,60],'ENTERPRISE'=>[25,10000,100,120]];
        if (!isset($tiers[$tier])) self::invalid(); [$open,$rows,$receipts,$rate]=$tiers[$tier];
        return ['openListingsPerPlayer'=>$open,'retainedListingsPerProject'=>$rows,'receiptsPerPlayer'=>$receipts,'historyPerPlayer'=>$receipts,'projectWritesPerMinute'=>$rate,
            'playerWriteIntervalMillis'=>1000,'minDurationSeconds'=>300,'maxDurationSeconds'=>604800,'terminalRetentionSeconds'=>604800,
            'operationRetentionSeconds'=>2592000,'queryCandidates'=>256,'pageSize'=>50];
    }
    public static function emptyHeader(): array { return ['version'=>0,'revision'=>0,'enabled'=>false,'currencyKey'=>null,'feeBps'=>null,'ruleCount'=>0,'activeRuleCount'=>0,'projectId'=>null,'escrowCount'=>0,'retainedCount'=>0,'bucket'=>0,'writes'=>0,'exists'=>false]; }
    public static function emptyPlayer(): array { return ['revision'=>0,'openIds'=>[],'historyIds'=>[],'receipts'=>[],'floor'=>0,'nextAllowedAt'=>0,'banUntil'=>null,'reason'=>'','deleteAfter'=>null,'deletionComplete'=>false,'exists'=>false]; }
    public static function window(array $player,int $issued,int $now): void
    { if ($issued>$now+30000) self::invalid(); if ($issued<$now-self::RETENTION||$issued<=$player['floor']) self::fail('OPERATION_EXPIRED'); }
    public static function receipt(array $player,string $id,string $hash): ?array
    { foreach ($player['receipts'] as $r) if ($r['receipt']['operationId']===$id) { if (!hash_equals($r['hash'],$hash)) self::fail('OPERATION_CONFLICT'); return $r['receipt']; } return null; }
    public static function record(array $p,array $receipt,string $hash,int $issued,int $now,int $limit): array
    {
        $all=array_merge($p['receipts'],[['hash'=>$hash,'issued'=>$issued,'recorded'=>$now,'receipt'=>$receipt]]);
        $p['receipts']=array_slice(array_values(array_filter($all,static function($r)use($now){return $r['recorded']>=$now-self::RETENTION;})),-$limit);
        $ids=array_column(array_column($p['receipts'],'receipt'),'operationId');
        foreach ($all as $r) if (!in_array($r['receipt']['operationId'],$ids,true)) $p['floor']=max($p['floor'],$r['issued']); return $p;
    }
    public static function cursor(array $v): string { $s=rtrim(strtr(base64_encode(self::json($v)),'+/','-_'),'='); if (strlen($s)>512) self::invalid(); return $s; }
    public static function unCursor(?string $v): ?array
    { if ($v===null) return null; if ($v===''||strlen($v)>512||!preg_match('/^[A-Za-z0-9_-]+$/D',$v)) self::invalid(); $raw=base64_decode(strtr($v,'-_','+/'),true); if ($raw===false) self::invalid(); try { $r=json_decode($raw,true,16,JSON_THROW_ON_ERROR); } catch (JsonException $e) { self::invalid(); } if (!is_array($r)||self::cursor($r)!==$v) self::invalid(); return $r; }
}
