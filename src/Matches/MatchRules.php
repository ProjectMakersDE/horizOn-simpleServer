<?php

declare(strict_types=1);
require_once __DIR__.'/../Items/ItemJson.php';
require_once __DIR__.'/../Inbox/InboxRules.php';
class MatchFailure extends RuntimeException
{
    public string $errorCode; public int $status;
    public function __construct(string $code,int $status=409){parent::__construct(strtolower(str_replace('_',' ',$code)));$this->errorCode=$code;$this->status=$status;}
}
class MatchRules
{
    public const MAX=9007199254740991;
    public const STATUSES=['lobby','active','reporting','confirmed','disputed','unconfirmed','cancelled'];
    public const TERMINAL=['confirmed','disputed','unconfirmed','cancelled'];
    public const ACTIONS=['join','decline','start','turn','timeout','leave','cancel'];
    public static function fail(string $code,int $status=409): void{throw new MatchFailure($code,$status);}
    public static function invalid(): void{self::fail('INVALID_REQUEST',400);}
    public static function missing(): void{self::fail('MATCH_NOT_FOUND',404);}
    public static function integer($v,int $min=0,int $max=self::MAX): int{if(!is_int($v)||$v<$min||$v>$max)self::invalid();return $v;}
    public static function next(int $v): int{self::integer($v);if($v===self::MAX)self::fail('STATE_CONFLICT');return $v+1;}
    public static function expected(int $actual,$expected): void{self::integer($expected);if($actual!==$expected)self::fail('REVISION_CONFLICT');}
    public static function uuid($v): string{if(!is_string($v)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di',$v))self::invalid();return strtolower($v);}
    public static function ids($v,int $max=8): array{if(!is_array($v)||($v&&array_keys($v)!==range(0,count($v)-1))||count($v)>$max)self::invalid();$v=array_map([self::class,'uuid'],$v);if(count(array_unique($v))!==count($v))self::invalid();return $v;}
    public static function fields($v,array $allowed,bool $exact=true): array
    {if($v instanceof stdClass)$v=get_object_vars($v);if(!is_array($v)||array_diff(array_keys($v),$allowed)||($exact&&array_diff($allowed,array_keys($v))))self::invalid();return $v;}
    public static function text($v,int $bytes,bool $nonblank=false): string
    {if(!is_string($v)||!preg_match('//u',$v)||strlen($v)>$bytes||($nonblank&&preg_match('/^[\p{Z}\s\x{001c}-\x{001f}]*$/uD',$v)))self::invalid();return $v;}
    public static function boolean($v): bool{if(!is_bool($v))self::invalid();return $v;}
    public static function json($v): string{return json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    public static function decode(string $raw): stdClass{try{return ItemJson::decode($raw,20000);}catch(ItemFailure $e){self::invalid();}}
    public static function revision($v): int
    {if(!is_string($v))self::invalid();if(strlen($v)>=2&&$v[0]==='"'&&substr($v,-1)==='"')$v=substr($v,1,-1);if(!preg_match('/^[0-9]+$/D',$v))self::invalid();$v=ltrim($v,'0');$v=$v===''?'0':$v;if(strlen($v)>16||(strlen($v)===16&&strcmp($v,(string)self::MAX)>0))self::invalid();return(int)$v;}
    public static function signed($v): int
    {if(!is_string($v)||!preg_match('/^-?(0|[1-9][0-9]*)$/D',$v)||$v==='-0')self::invalid();$n=json_decode($v,false,4,JSON_THROW_ON_ERROR);return self::integer($n,-self::MAX);}
    public static function bytes($v,int $limit): string
    {if(!is_string($v))self::invalid();if(strlen($v)>intdiv($limit+2,3)*4)self::fail('STATE_SIZE_LIMIT_REACHED',422);$bytes=base64_decode($v,true);if($bytes===false||base64_encode($bytes)!==$v)self::invalid();if(strlen($bytes)>$limit)self::fail('STATE_SIZE_LIMIT_REACHED',422);return $bytes;}
    public static function limits(string $tier): array
    {$tiers=['FREE'=>[2,1024,20],'BASIC'=>[3,2048,30],'PLUS'=>[4,3072,40],'PRO'=>[5,4096,50],'ENTERPRISE'=>[10,8192,100]];if(!isset($tiers[$tier]))self::invalid();[$a,$b,$r]=$tiers[$tier];return['activeMatches'=>$a,'stateBytes'=>$b,'retainedMatches'=>$r,'pendingInvites'=>20];}
    public static function create($raw,array $limits): array
    {$r=self::fields($raw,['userId','name','capacity','visibility','strength','turnSeconds','timeoutPolicy','ranked','dataBase64','inviteeIds']);$r['userId']=self::uuid($r['userId']);$r['name']=self::text($r['name'],120,true);self::integer($r['capacity'],2,8);self::integer($r['strength'],-self::MAX);self::integer($r['turnSeconds'],60,604800);self::boolean($r['ranked']);if(!in_array($r['visibility'],['open','private'],true)||!in_array($r['timeoutPolicy'],['skip','forfeit'],true))self::invalid();$r['inviteeIds']=self::ids($r['inviteeIds'],7);if(count($r['inviteeIds'])>=$r['capacity']||in_array($r['userId'],$r['inviteeIds'],true))self::invalid();$r['bytes']=self::bytes($r['dataBase64'],$limits['stateBytes']);return $r;}
    public static function instant(?int $v): ?string{return $v===null?null:InboxRules::instant($v);}
    public static function emptyPlayer(): array{return['revision'=>0,'activeIds'=>[],'historyIds'=>[],'invitedIds'=>[],'exists'=>false,'cleanupAfter'=>null];}
}
