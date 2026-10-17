<?php

declare(strict_types=1);
require_once __DIR__.'/FriendsRules.php';
require_once __DIR__.'/../Items/ItemJson.php';

/** SQL TEXT keeps one canonical Base64 UUID vector; indexed CHAR(36) identities stay unchanged. */
class FriendsStorage
{
    public const FIELD_BYTES = 65535;
    public const FIELDS = ['friends','incoming','outgoing','blocked'];
    public static function required(): void { FriendsRules::fail('MIGRATION_REQUIRED','Original relationship data needs reconciliation; nothing was discarded'); }
    public static function capacity(string $field): int { return intdiv(3 * intdiv(self::FIELD_BYTES - 6,4), self::pending($field) ? 32 : 16); }
    private static function pending(string $field): bool
    { if (!in_array($field,self::FIELDS,true)) throw new LogicException('Unknown relationship field'); return $field==='incoming'||$field==='outgoing'; }
    private static function uuid($value): string
    { try { return FriendsRules::uuid($value); } catch (FriendsFailure $e) { self::required(); } }
    private static function id(string $bytes): string
    { $h=bin2hex($bytes);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
    private static function bytes(string $id): string { return hex2bin(str_replace('-','',self::uuid($id))); }
    public static function revision($value): int
    {
        if (is_int($value)) $n=$value;
        elseif (is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,15})$/D',$value)) $n=(int)$value;
        else self::required();
        if ($n<0||$n>FriendsRules::MAX) self::required();return $n;
    }
    private static function timestamp($value): void
    {
        if ($value===null) return;
        try { if (!is_string($value)||InboxRules::instant(InboxRules::millis($value))!==$value) self::required(); }
        catch (InboxFailure $e) { self::required(); }
    }
    /** This accessor is also used by Inbox, Matches and Shared Objects, without profile reads. */
    public static function field(string $raw,string $field): array
    {
        $pending=self::pending($field);$time=$pending?'createdAt':($field==='friends'?'since':'blockedAt');
        try { $root=ItemJson::decode('{"list":'.$raw.'}',strlen($raw)+9);$value=$root->list; }
        catch (Throwable $e) { self::required(); }
        if (!is_array($value)) self::required();$entries=[];
        if (isset($value[0])&&is_int($value[0])) {
            if (count($value)!==2||$value[0]!==1||!is_string($value[1])) self::required();
            $bytes=base64_decode($value[1],true);$width=$pending?32:16;
            if ($bytes===false||base64_encode($bytes)!==$value[1]||strlen($bytes)%$width!==0) self::required();
            for ($i=0,$n=strlen($bytes);$i<$n;$i+=$width) {
                $entry=['userId'=>self::id(substr($bytes,$i,16))];
                if ($pending) $entry['requestId']=self::id(substr($bytes,$i+16,16));
                $entry[$time]=null;$entries[]=$entry;
            }
        } else {
            foreach ($value as $item) {
                if (!$item instanceof stdClass) self::required();$entry=get_object_vars($item);
                $allowed=$pending?['userId','requestId','createdAt']:['userId',$time];
                if (array_diff(array_keys($entry),$allowed)) self::required();
                $entry['userId']=self::uuid($entry['userId']??null);
                if ($pending) $entry['requestId']=self::uuid($entry['requestId']??null);
                self::timestamp($entry[$time]??null);$entry[$time]=$entry[$time]??null;$entries[]=$entry;
            }
        }
        $peers=[];$requests=[];
        foreach ($entries as $entry) {
            if (isset($peers[$entry['userId']])) self::required();$peers[$entry['userId']]=true;
            if ($pending) { if (isset($requests[$entry['requestId']])) self::required();$requests[$entry['requestId']]=true; }
        }
        return $entries;
    }
    public static function compact(string $raw): bool { return preg_match('/^\s*\[\s*1\s*,/D',$raw)===1; }
    public static function state(array $row,string $owner): array
    {
        if (array_key_exists('active_user_id',$row)&&$row['active_user_id']!==null&&$row['active_user_id']!==$owner) self::required();
        $state=['revision'=>self::revision($row['revision']),'exists'=>true,'_legacy'=>false];
        foreach (self::FIELDS as $field) { $state[$field]=self::field($row[$field],$field);$state['_legacy']=$state['_legacy']||!self::compact($row[$field]); }
        $friends=array_column($state['friends'],'userId');$incoming=array_column($state['incoming'],'userId');$outgoing=array_column($state['outgoing'],'userId');$blocked=array_column($state['blocked'],'userId');
        if (in_array($owner,array_merge($friends,$incoming,$outgoing,$blocked),true)||array_intersect($friends,array_merge($incoming,$outgoing,$blocked))||array_intersect($blocked,array_merge($incoming,$outgoing))) self::required();
        $requests=array_merge(array_column($state['incoming'],'requestId'),array_column($state['outgoing'],'requestId'));
        if (count(array_unique($requests))!==count($requests)) self::required();return $state;
    }
    public static function encode(array $entries,string $field): string
    {
        $bytes='';foreach ($entries as $entry) { $bytes.=self::bytes($entry['userId']);if (self::pending($field)) $bytes.=self::bytes($entry['requestId']); }
        return InboxRules::json([1,base64_encode($bytes)]);
    }
    public static function references(array $state,bool $legacy=false): array
    {
        $ids=[];foreach ($legacy?self::FIELDS:['blocked'] as $field) foreach ($state[$field] as $entry) $ids[$entry['userId']]=true;
        $result=array_keys($ids);sort($result,SORT_STRING);return $result;
    }
}
