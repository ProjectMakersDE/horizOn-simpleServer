<?php

declare(strict_types=1);
require_once __DIR__.'/../Items/ItemJson.php';
require_once __DIR__.'/../Inbox/InboxRules.php';
class SharedObjectFailure extends RuntimeException
{
    public string $errorCode; public int $status;
    public function __construct(string $code,int $status=409) { parent::__construct(str_replace('_',' ',strtolower($code)));$this->errorCode=$code;$this->status=$status; }
}
class SharedObjectRules
{
    public const MAX=9007199254740991; public const METADATA=8192; public const OWNER=512; public const CANDIDATES=256; public const PAGE_BYTES=1048576;
    public static function fail(string $code,int $status=409): void { throw new SharedObjectFailure($code,$status); }
    public static function invalid(): void { self::fail('INVALID_REQUEST',400); }
    public static function integer($value,int $minimum=0,int $maximum=self::MAX): int { if(!is_int($value)||$value<$minimum||$value>$maximum)self::invalid();return $value; }
    public static function next(int $value): int { self::integer($value);if($value===self::MAX)self::fail('STATE_CONFLICT');return $value+1; }
    public static function key($value): string { if(!is_string($value)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$value))self::invalid();return $value; }
    public static function uuid($value): string { if(!is_string($value)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$value))self::invalid();return strtolower($value); }
    public static function text($value,int $bytes,bool $nonblank=false): string
    { if(!is_string($value)||!preg_match('//u',$value)||strlen($value)>$bytes||($nonblank&&preg_match('/^[\p{Z}\s]*$/uD',$value)))self::invalid();return $value; }
    public static function boolean($value): bool { if(!is_bool($value))self::invalid();return $value; }
    public static function fields($value,array $allowed): array
    { if($value instanceof stdClass)$value=get_object_vars($value);if(!is_array($value)||array_diff(array_keys($value),$allowed))self::invalid();return $value; }
    public static function json($value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    public static function decode(string $raw): stdClass
    { try{return ItemJson::decode($raw,100000);}catch(ItemFailure $error){self::invalid();} }
    public static function revision($header): int
    {
        if(!is_string($header))self::invalid();
        if(strlen($header)>=2&&$header[0]==='"'&&substr($header,-1)==='"')$header=substr($header,1,-1);
        if(!preg_match('/^[0-9]+$/D',$header))self::invalid();$digits=ltrim($header,'0');$digits=$digits===''?'0':$digits;
        if(strlen($digits)>16||(strlen($digits)===16&&strcmp($digits,(string)self::MAX)>0))self::invalid();return (int)$digits;
    }
    public static function definition($raw,array $limits): array
    {
        $in=self::fields($raw,['name','active','readPolicy','writePolicy','maxObjectBytes','maxObjectsPerOwner','ttlSeconds','searchFields','positionEnabled']);
        if(!array_key_exists('ttlSeconds',$in))self::invalid();$ttl=$in['ttlSeconds'];
        if($ttl!==null)self::integer($ttl,60,2592000);
        $read=$in['readPolicy']??null;$write=$in['writePolicy']??null;
        if(!in_array($read,['owner','friends','group','all','dashboard'],true)||!in_array($write,['owner','group','dashboard'],true)||($read==='dashboard'&&$write!=='dashboard'))self::invalid();
        $search=$in['searchFields']??null;if(!is_array($search)||count($search)>3||($search&&array_keys($search)!==range(0,count($search)-1)))self::invalid();
        $fields=[];$seen=[];foreach($search as $entry){$entry=self::fields($entry,['key','type']);$key=self::key($entry['key']??null);$type=$entry['type']??null;if(isset($seen[$key])||!in_array($type,['integer','string'],true))self::invalid();$seen[$key]=true;$fields[]=['key'=>$key,'type'=>$type];}
        return ['name'=>self::text($in['name']??null,120,true),'active'=>self::boolean($in['active']??null),'readPolicy'=>$read,'writePolicy'=>$write,
            'maxObjectBytes'=>self::integer($in['maxObjectBytes']??null,1,$limits['objectBytes']),'maxObjectsPerOwner'=>self::integer($in['maxObjectsPerOwner']??null,1,100),
            'ttlSeconds'=>$ttl,'searchFields'=>$fields,'positionEnabled'=>self::boolean($in['positionEnabled']??null)];
    }
    public static function schema(array $definition): array { return array_intersect_key($definition,array_flip(['readPolicy','writePolicy','ttlSeconds','searchFields','positionEnabled'])); }
    public static function groupRequired(array $definition): bool { return $definition['readPolicy']==='group'||$definition['writePolicy']==='group'; }
    public static function bytes($value,int $maximum): string
    {
        if(!is_string($value))self::invalid();if(strlen($value)>intdiv($maximum+2,3)*4)self::fail('OBJECT_SIZE_LIMIT_REACHED',422);
        $bytes=base64_decode($value,true);if($bytes===false||base64_encode($bytes)!==$value)self::invalid();if(strlen($bytes)>$maximum)self::fail('OBJECT_SIZE_LIMIT_REACHED',422);return $bytes;
    }
    public static function sort($value,string $type): string
    { if($type==='integer')return str_pad((string)(self::integer($value,-self::MAX)+self::MAX),17,'0',STR_PAD_LEFT);if($type!=='string')self::invalid();return bin2hex(self::text($value,96)); }
    public static function metadata(array $input,array $definition): array
    {
        $search=$input['search']??null;if(!$search instanceof stdClass)self::invalid();$actual=array_keys(get_object_vars($search));$expected=array_column($definition['searchFields'],'key');sort($actual,SORT_STRING);sort($expected,SORT_STRING);if($actual!==$expected)self::invalid();
        $slots=[null,null,null];foreach($definition['searchFields'] as $i=>$field)$slots[$i]=self::sort($search->{$field['key']},$field['type']);
        $position=$input['position']??null;if($definition['positionEnabled']!==($position!==null))self::invalid();
        if($position!==null){$position=self::fields($position,['x','y']);$position=['x'=>self::integer($position['x']??null,-self::MAX),'y'=>self::integer($position['y']??null,-self::MAX)];}
        return ['search'=>$search,'position'=>$position,'slots'=>$slots,'positionX'=>$position===null?null:self::sort($position['x'],'integer')];
    }
    public static function instant(int $millis): string { return InboxRules::instant($millis); }
}
