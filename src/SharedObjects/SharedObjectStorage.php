<?php
declare(strict_types=1);
require_once __DIR__.'/SharedObjectRules.php';
require_once __DIR__.'/SharedObjectSchema.php';
/** Storage codec only. SQL rows keep each search value, position and partition identity once. */
class SharedObjectStorage
{
    public const INT_MAX=2147483647;
    public static function corrupt(): void { SharedObjectRules::fail('STATE_CONFLICT'); }
    public static function required(): void { SharedObjectRules::fail('MIGRATION_REQUIRED'); }
    public static function number($value,int $min=0,int $max=SharedObjectRules::MAX): int {
        if(is_string($value)&&preg_match('/^-?(?:0|[1-9][0-9]*)$/D',$value)&&strlen($value)<=17&&(string)(int)$value===$value)$value=(int)$value;
        if(!is_int($value)||$value<$min||$value>$max)self::corrupt();return $value;
    }
    public static function uuid(string $uuid): string { return hex2bin(str_replace('-','',SharedObjectRules::uuid($uuid))); }
    public static function id($bytes): string {
        if(!is_string($bytes)||strlen($bytes)!==16)self::corrupt();$h=bin2hex($bytes);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    public static function fields(array $row,array $fields): void {
        if(array_diff(array_keys($row),$fields)||array_diff($fields,array_keys($row)))self::corrupt();
    }
    public static function wire(array $definition): array { unset($definition['_slot']);return $definition; }
    public static function definition(array $row): array {
        self::fields($row,['c','k','r','d']);$slot=self::number($row['c'],1,self::INT_MAX);$revision=self::number($row['r'],1);
        try {
            $key=SharedObjectRules::key($row['k']);$in=get_object_vars(ItemJson::decode($row['d'],4096));
            $required=['n','f','b','l','s'];if(array_diff(array_keys($in),array_merge($required,['t']))||array_diff($required,array_keys($in)))self::corrupt();
            if(!is_int($in['f']))self::corrupt();$flags=self::number($in['f'],0,255);$read=($flags>>3)&7;$write=($flags>>6)&3;
            if($read>4||$write>2)self::corrupt();$fields=[];
            if(!is_array($in['s']))self::corrupt();foreach($in['s']as$f){if(!is_array($f)||array_keys($f)!==[0,1]||!is_int($f[1])||$f[1]<0||$f[1]>1)self::corrupt();$fields[]=['key'=>$f[0],'type'=>$f[1]?'string':'integer'];}
            $input=['name'=>$in['n'],'active'=>(bool)($flags&1),'readPolicy'=>['owner','friends','group','all','dashboard'][$read],
                'writePolicy'=>['owner','group','dashboard'][$write],'maxObjectBytes'=>$in['b'],'maxObjectsPerOwner'=>$in['l'],'searchFields'=>$fields,'positionEnabled'=>(bool)($flags&4),'ttlSeconds'=>$in['t']??null];
            $validated=SharedObjectRules::definition($input,['objectBytes'=>SharedObjectRules::PAGE_BYTES]);
            return ['key'=>$key]+$validated+['used'=>(bool)($flags&2),'revision'=>$revision,'_slot'=>$slot];
        } catch(Throwable $error){self::corrupt();}
    }
    public static function definitionRow(array $value): array {
        $read=array_search($value['readPolicy'],['owner','friends','group','all','dashboard'],true);$write=array_search($value['writePolicy'],['owner','group','dashboard'],true);
        if($read===false||$write===false)self::corrupt();
        $body=['n'=>$value['name'],'f'=>($value['active']?1:0)|($value['used']?2:0)|($value['positionEnabled']?4:0)|($read<<3)|($write<<6),
            'b'=>$value['maxObjectBytes'],'l'=>$value['maxObjectsPerOwner'],'s'=>array_map(static function($f){return [$f['key'],$f['type']==='string'?1:0];},$value['searchFields'])];
        if($value['ttlSeconds']!==null)$body['t']=$value['ttlSeconds'];
        $row=['c'=>$value['_slot'],'k'=>$value['key'],'r'=>$value['revision'],'d'=>SharedObjectRules::json($body)];
        if(self::definition($row)!==$value)self::corrupt();return $row;
    }
    /** SQL keys are native bytes, while the public cursor preserves its original canonical sort text. */
    public static function keyBytes(string $sort,string $type): string {
        if($type==='integer'){
            if(!preg_match('/^[0-9]{17}$/D',$sort))self::corrupt();$number=(int)$sort-SharedObjectRules::MAX;
            if(SharedObjectRules::sort($number,'integer')!==$sort)self::corrupt();return pack('J',$number+SharedObjectRules::MAX);
        }
        if(strlen($sort)>192||!preg_match('/^(?:[0-9a-f]{2})*$/D',$sort))self::corrupt();$raw=hex2bin($sort);SharedObjectRules::text($raw,96);return $raw;
    }
    public static function sortText($raw,string $type): string {
        if(!is_string($raw))self::corrupt();
        if($type==='integer'){
            if(strlen($raw)!==8)self::corrupt();$value=unpack('J',$raw)[1];if(!is_int($value)||$value<0||$value>SharedObjectRules::MAX*2)self::corrupt();return SharedObjectRules::sort($value-SharedObjectRules::MAX,'integer');
        }
        return bin2hex(SharedObjectRules::text($raw,96));
    }
    public static function metadata(array $r,array $definition): array {
        $kind=$definition['readPolicy']==='all'?1:($definition['readPolicy']==='group'?2:0);
        $owner=self::uuid($r['owner_id']);$group=$r['group_id']===null?null:self::uuid($r['group_id']);
        if(SharedObjectRules::groupRequired($definition)!==($group!==null))self::corrupt();
        $row=['i'=>self::uuid($r['id']),'c'=>$definition['_slot'],'f'=>$kind,'q'=>$kind===1?'':($kind===2?$group:$owner),'o'=>$kind===0?null:$owner,'g'=>$kind===2?null:$group,
            'r'=>$r['revision'],'l'=>$r['payload_bytes'],'b'=>$r['charged_bytes']===$r['payload_bytes']+SharedObjectRules::METADATA?null:$r['charged_bytes'],
            's0'=>null,'s1'=>null,'s2'=>null,'x'=>$r['position']['x']??null,'y'=>$r['position']['y']??null,'a'=>$r['created_millis'],'u'=>$r['updated_millis'],'e'=>$r['expires_millis']];
        foreach($definition['searchFields']as$i=>$field)$row['s'.$i]=self::keyBytes($r['slot'.$i],$field['type']);return $row;
    }
    public static function row(array $r,array $definition): array {
        self::fields($r,['i','c','f','q','o','g','r','l','b','s0','s1','s2','x','y','a','u','e']);
        if(self::number($r['c'],1,self::INT_MAX)!==$definition['_slot'])self::corrupt();$kind=self::number($r['f'],0,2);
        $expected=$definition['readPolicy']==='all'?1:($definition['readPolicy']==='group'?2:0);if($kind!==$expected)self::corrupt();
        $owner=$kind===0?self::id($r['q']):self::id($r['o']);if($kind===0&&$r['o']!==null)self::corrupt();
        $group=$kind===2?self::id($r['q']):($r['g']===null?null:self::id($r['g']));
        if(($kind===1&&$r['q']!=='')||($kind===2&&$r['g']!==null)||SharedObjectRules::groupRequired($definition)!==($group!==null))self::corrupt();
        $search=new stdClass;$slots=[null,null,null];foreach($definition['searchFields']as$i=>$field){$sort=self::sortText($r['s'.$i],$field['type']);$slots[$i]=$sort;$search->{$field['key']}=$field['type']==='integer'?(int)$sort-SharedObjectRules::MAX:$r['s'.$i];}
        for($i=count($definition['searchFields']);$i<3;$i++)if($r['s'.$i]!==null)self::corrupt();
        $position=null;if($definition['positionEnabled'])$position=['x'=>self::number($r['x'],-SharedObjectRules::MAX),'y'=>self::number($r['y'],-SharedObjectRules::MAX)];elseif($r['x']!==null||$r['y']!==null)self::corrupt();
        $length=self::number($r['l'],0,SharedObjectRules::PAGE_BYTES);$a=self::number($r['a']);$u=self::number($r['u']);$e=$r['e']===null?null:self::number($r['e']);if($u<$a||($e!==null&&$e<$a))self::corrupt();
        return ['id'=>self::id($r['i']),'collection_key'=>$definition['key'],'partition_key'=>$kind===1?'all':($kind===2?'group:'.$group:'owner:'.$owner),'owner_id'=>$owner,'group_id'=>$group,'revision'=>self::number($r['r'],1),
            'payload_bytes'=>$length,'charged_bytes'=>$r['b']===null?$length+SharedObjectRules::METADATA:self::number($r['b']),
            'search'=>$search,'position'=>$position,'slot0'=>$slots[0],'slot1'=>$slots[1],'slot2'=>$slots[2],'position_x'=>$position===null?null:SharedObjectRules::sort($position['x'],'integer'),'created_millis'=>$a,'updated_millis'=>$u,'expires_millis'=>$e];
    }
}
