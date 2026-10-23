<?php

declare(strict_types=1);
require_once __DIR__.'/ItemRules.php';

/** Storage only. UUID16 + basis8 + milliseconds8 + flags1 + count1 + (slot1,value8)*. */
class ItemStorage
{
    public const SLOTS=65536;
    public static function corrupt():void { ItemRules::fail('STATE_CONFLICT'); }
    public static function required():void { ItemRules::fail('MIGRATION_REQUIRED'); }
    public static function wire(array $item):array { unset($item['_basis']);return $item; }
    public static function properties(string $json):stdClass {
        try{$value=json_decode($json,false,32,JSON_THROW_ON_ERROR);ItemRules::properties($value);}
        catch(Throwable $e){self::corrupt();}
        if(ItemRules::canonical($value)!==$json)self::corrupt();return $value;
    }
    public static function compatible(stdClass $base,stdClass $actual):bool {
        $a=get_object_vars($base);$b=get_object_vars($actual);ksort($a);ksort($b);
        if(array_keys($a)!==array_keys($b))return false;
        foreach($a as $k=>$v)if(is_int($v)?!is_int($b[$k]):$b[$k]!==$v)return false;
        return true;
    }
    public static function shape(stdClass $properties):string {
        $shape=[];foreach($properties as $key=>$value)$shape[$key]=is_int($value)?['n']:(is_bool($value)?['b',$value]:['s',$value]);
        return hash('sha256',ItemRules::canonical((object)$shape),true);
    }
    public static function binary(string $base64):string {
        $bytes=base64_decode($base64,true);if($bytes===false||base64_encode($bytes)!==$base64)self::corrupt();return $bytes;
    }
    private static function number(int $n):string { return pack('P',$n); }
    private static function read(string $bytes,int &$offset):int {
        $n=unpack('P',substr($bytes,$offset,8))[1];$offset+=8;
        if(!is_int($n)||$n < -ItemRules::MAX||$n>ItemRules::MAX)self::corrupt();return $n;
    }
    public static function millis(string $time):int {
        try{$date=new DateTimeImmutable($time);}catch(Throwable $e){self::corrupt();}
        $value=(int)$date->format('U')*1000+(int)$date->format('v');
        if($value<0||$value>ItemRules::MAX||PlayerValuesLedger::instant($value)!==$time)self::corrupt();return $value;
    }
    public static function pack(array $items):string {
        $bytes='';$seen=[];
        foreach($items as $item){
            $id=ItemRules::uuid($item['instanceId']);if(isset($seen[$id]))self::corrupt();$seen[$id]=true;
            $base=$item['_basis']??null;if(!$base||!self::compatible($base['properties'],$item['properties']))self::corrupt();
            ItemRules::properties($item['properties']);$keys=array_keys(get_object_vars($base['properties']));sort($keys,SORT_STRING);$changes='';$count=0;
            foreach($keys as $slot=>$key){$value=$item['properties']->{$key};if(is_int($value)&&$value!==$base['properties']->{$key}){$changes.=chr($slot).self::number($value);$count++;}}
            if($base['version']<=0||$base['version']>ItemRules::MAX||!is_bool($item['equipped']))self::corrupt();
            $bytes.=hex2bin(str_replace('-','',$id)).self::number($base['version']).self::number(self::millis($item['createdAt'])).chr($item['equipped']?1:0).chr($count).$changes;
        }
        return $bytes;
    }
    public static function unpack(string $bytes):array {
        $items=[];$seen=[];$offset=0;$length=strlen($bytes);
        while($offset<$length){
            if($length-$offset<34)self::corrupt();$hex=bin2hex(substr($bytes,$offset,16));$offset+=16;
            $id=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $version=self::read($bytes,$offset);$time=self::read($bytes,$offset);$flags=ord($bytes[$offset++]);$count=ord($bytes[$offset++]);
            if(isset($seen[$id])||$version<=0||$time<0||$flags>1||$count>16||$length-$offset<$count*9)self::corrupt();$seen[$id]=true;
            $deltas=[];$previous=-1;
            for($i=0;$i<$count;$i++){$slot=ord($bytes[$offset++]);if($slot>15||$slot<=$previous)self::corrupt();$previous=$slot;$deltas[$slot]=self::read($bytes,$offset);}
            $items[]=['instanceId'=>$id,'itemKey'=>'','properties'=>(object)[],'equipped'=>$flags===1,'createdAt'=>PlayerValuesLedger::instant($time),'_basis'=>['version'=>$version,'deltas'=>$deltas]];
        }return $items;
    }
    /** Pure escrow identity proof. Compact basis hydration remains the transfer owner's responsibility. */
    public static function escrowMatches(?array $raw,array $reference):bool {
        try {
            if($raw===null||!isset($reference['instanceId'],$reference['itemKey']))return false;
            if(isset($raw['s'])){
                if($raw['s']!==1||!is_string($raw['b']??null))return false;
                $items=self::unpack(self::binary($raw['b']));
                return count($items)===1&&$items[0]['instanceId']===$reference['instanceId']&&!$items[0]['equipped'];
            }
            return ($raw['instanceId']??null)===$reference['instanceId']&&($raw['itemKey']??null)===$reference['itemKey']&&($raw['equipped']??false)===false;
        } catch(Throwable $failure){return false;}
    }
    public static function hydrate(array $item,array $basis):array {
        if($item['_basis']['version']!==$basis['version'])self::corrupt();$properties=clone $basis['properties'];
        $keys=array_keys(get_object_vars($properties));sort($keys,SORT_STRING);
        foreach($item['_basis']['deltas']??[] as $slot=>$number){$key=$keys[$slot]??null;if($key===null||!is_int($properties->{$key})||$properties->{$key}===$number)self::corrupt();$properties->{$key}=$number;}
        ItemRules::properties($properties);$item['properties']=$properties;$item['itemKey']=$basis['key'];$item['_basis']=$basis;return $item;
    }
    public static function rules(array $definition):string {
        $keys=array_keys(get_object_vars($definition['baseProperties']));sort($keys,SORT_STRING);$bytes='';
        foreach($definition['propertyRules'] as $rule)$bytes.=chr(array_search($rule['key'],$keys,true)).self::number($rule['min']).self::number($rule['max']).self::number($rule['maxChangePerCall']);
        return $bytes;
    }
    public static function readRules(string $bytes,stdClass $properties):array {
        if(strlen($bytes)%25!==0||strlen($bytes)>400)self::corrupt();$keys=array_keys(get_object_vars($properties));sort($keys,SORT_STRING);$rules=[];$seen=[];$offset=0;
        while($offset<strlen($bytes)){$slot=ord($bytes[$offset++]);if(!isset($keys[$slot])||isset($seen[$slot]))self::corrupt();$seen[$slot]=true;
            $rules[]=['key'=>$keys[$slot],'min'=>self::read($bytes,$offset),'max'=>self::read($bytes,$offset),'maxChangePerCall'=>self::read($bytes,$offset)];}
        return $rules;
    }
    public static function versions(array $items):array {
        $counts=[];foreach($items as $item)if(isset($item['_basis'])){$version=$item['_basis']['version'];$counts[$version]=($counts[$version]??0)+1;}return $counts;
    }
}
