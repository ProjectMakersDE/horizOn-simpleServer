<?php

declare(strict_types=1);
require_once __DIR__ . '/AchievementRules.php';

/** Stable catalogue slots. Only set bits are stored, never dates or reward copies. */
class AchievementStorage
{
    public static function required(): void { AchievementRules::fail('MIGRATION_REQUIRED', 'Original achievement state requires explicit reconciliation'); }
    private static function integer($value): int {
        if (!is_int($value) || $value < 0 || $value > PlayerValuesLedger::MAX) self::required();
        return $value;
    }
    public static function empty(): array { return ['revision'=>0,'catalogRevision'=>0,'unlocks'=>[],'compact'=>true]; }
    public static function decode(string $json,array $keys): array {
        $row=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($row)) self::required();
        if (!array_key_exists('s',$row)) { $row['compact']=false; return $row; }
        if ($row['s']!==2 || array_diff(array_keys($row),['s','r','c','b']) || count($row)!==4 ||
            !is_string($row['b']) || ($bits=base64_decode($row['b'],true))===false || base64_encode($bits)!==$row['b'] ||
            strlen($bits)>intdiv(count($keys)+7,8) || count(array_unique($keys))!==count($keys)) self::required();
        $state=['revision'=>self::integer($row['r']),'catalogRevision'=>self::integer($row['c']),'unlocks'=>[],'compact'=>true];
        for($slot=0;$slot<strlen($bits)*8;$slot++) if ((ord($bits[intdiv($slot,8)]) & (1<<($slot%8)))!==0) {
            if(!isset($keys[$slot])) self::required();
            $state['unlocks'][]=['key'=>$keys[$slot],'rewardState'=>'complete','issuedAt'=>null,'pending'=>null,'inboxEntryId'=>null];
        }
        return $state;
    }
    public static function encode(array $state,array $keys): string {
        if (!$state['compact'] || count(array_unique($keys))!==count($keys)) self::required();
        $slots=array_flip($keys);$bits='';$seen=[];
        foreach($state['unlocks'] as $record) {
            $key=$record['key'];if(!array_key_exists($key,$slots)||isset($seen[$key])||$record['issuedAt']!==null||$record['rewardState']!=='complete'||$record['pending']!==null||$record['inboxEntryId']!==null)self::required();
            $seen[$key]=true;$slot=$slots[$key];$byte=intdiv($slot,8);
            if(strlen($bits)<=$byte)$bits=str_pad($bits,$byte+1,"\0");
            $bits[$byte]=chr(ord($bits[$byte])|(1<<($slot%8)));
        }
        return InboxRules::json(['s'=>2,'r'=>self::integer($state['revision']),'c'=>self::integer($state['catalogRevision']),'b'=>base64_encode($bits)]);
    }
}
