<?php

declare(strict_types=1);
require_once __DIR__ . '/../PlayerValues/PlayerValuesLedger.php';

class ItemFailure extends PlayerValuesFailure {}

/** Exact JSON/property contract; one installation is one project. */
class ItemRules
{
    public const MAX = 9007199254740991;
    public const RETENTION = 2592000000;
    public static function fail(string $code, int $status = 409): void { throw new ItemFailure($code, str_replace('_', ' ', strtolower($code)), $status); }
    public static function invalid(): void { self::fail('INVALID_REQUEST', 400); }
    public static function integer($value, int $min = 0, int $max = self::MAX): int
    { if (!is_int($value) || $value < $min || $value > $max) self::invalid(); return $value; }
    public static function next(int $revision): int { self::integer($revision); if ($revision === self::MAX) self::fail('STATE_CONFLICT'); return $revision + 1; }
    public static function key($key): string { if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key)) self::invalid(); return $key; }
    public static function uuid($value): string
    { if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) self::invalid(); return strtolower($value); }
    public static function fields($value, array $names): array
    {
        if ($value instanceof stdClass) $value = get_object_vars($value);
        if (!is_array($value) || array_diff(array_keys($value), $names)) self::invalid(); return $value;
    }
    public static function list($value, int $limit): array
    { if (!is_array($value) || count($value) > $limit || ($value && array_keys($value) !== range(0, count($value)-1))) self::invalid(); return $value; }
    public static function text($value, int $bytes): string
    { if (!is_string($value) || preg_match('/^\s*$/uD', $value) !== 0 || strlen($value) > $bytes || !preg_match('//u', $value)) self::invalid(); return $value; }
    public static function boolean($value): bool { if (!is_bool($value)) self::invalid(); return $value; }
    /** Compact sorted ASCII keys; literal Unicode/slash and exact integer/control encoding. */
    public static function canonical($value): string
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value); ksort($fields, SORT_STRING); $parts = [];
            foreach ($fields as $key => $entry) $parts[] = self::canonical((string)$key) . ':' . self::canonical($entry);
            return '{' . implode(',', $parts) . '}';
        }
        if (is_array($value)) {
            if ($value && array_keys($value) !== range(0,count($value)-1)) return self::canonical((object)$value);
            return '[' . implode(',',array_map([self::class,'canonical'],$value)) . ']';
        }
        if (!is_string($value) && !is_bool($value) && !is_int($value)) self::invalid();
        if (is_int($value)) self::integer($value, -self::MAX);
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS); }
        catch (JsonException $error) { self::invalid(); }
    }
    public static function properties($properties): stdClass
    {
        if (!$properties instanceof stdClass || count(get_object_vars($properties)) > 16) self::invalid();
        foreach ($properties as $key => $value) {
            self::key($key); if (!is_string($value) && !is_bool($value) && !is_int($value)) self::invalid();
            if (is_int($value)) self::integer($value,-self::MAX);
        }
        if (strlen(self::canonical($properties)) > 256) self::invalid(); return $properties;
    }
    public static function definition(string $key, $input): array
    {
        self::key($key); $input = self::fields($input,['name','type','rarity','active','baseProperties','propertyRules']);
        $properties = self::properties($input['baseProperties'] ?? null); $rules = []; $seen = [];
        foreach (self::list($input['propertyRules'] ?? null,16) as $row) {
            $row = self::fields($row,['key','min','max','maxChangePerCall']); $name = self::key($row['key'] ?? null);
            $min = self::integer($row['min'] ?? null,-self::MAX); $max = self::integer($row['max'] ?? null,-self::MAX);
            $change = self::integer($row['maxChangePerCall'] ?? null,1);
            if (isset($seen[$name]) || $min > $max || !property_exists($properties,$name) || !is_int($properties->{$name}) || $properties->{$name} < $min || $properties->{$name} > $max) self::invalid();
            $seen[$name] = true; $rules[] = ['key'=>$name,'min'=>$min,'max'=>$max,'maxChangePerCall'=>$change];
        }
        $definition = ['key'=>$key,'name'=>self::text($input['name'] ?? null,120),'type'=>self::key($input['type'] ?? null),
            'rarity'=>self::key($input['rarity'] ?? null),'active'=>self::boolean($input['active'] ?? null),'baseProperties'=>$properties,'propertyRules'=>$rules];
        if (strlen(self::canonical((object)$definition)) > 2048) self::invalid(); return $definition;
    }
    public static function rewards($items): array
    {
        $items = self::list($items,4); if (!$items) self::invalid(); $total=0; $seen=[]; $result=[];
        foreach ($items as $item) {
            $item=self::fields($item,['itemKey','quantity']); $key=self::key($item['itemKey'] ?? null); $quantity=self::integer($item['quantity'] ?? null,1,4);
            if (isset($seen[$key])) self::invalid(); $seen[$key]=true; $total+=$quantity; $result[]=['itemKey'=>$key,'quantity'=>$quantity];
        }
        if ($total>4) self::invalid(); return $result;
    }
    public static function adjust(array $instance,array $template,$changes): array
    {
        if (!$template['active']) self::fail('ITEM_PROPERTY_NOT_ALLOWED',422);
        $changes=self::list($changes,8); if (!$changes) self::invalid(); $seen=[]; $properties=clone $instance['properties']; $rules=array_column($template['propertyRules'],null,'key');
        foreach ($changes as $change) {
            $change=self::fields($change,['key','amount']); $key=self::key($change['key'] ?? null); $amount=self::integer($change['amount'] ?? null,-self::MAX);
            if ($amount===0 || isset($seen[$key])) self::invalid(); $seen[$key]=true;
            if (!isset($rules[$key]) || !property_exists($properties,$key) || !is_int($properties->{$key})) self::fail('ITEM_PROPERTY_NOT_ALLOWED',422);
            $next=$properties->{$key}+$amount; $rule=$rules[$key];
            if (abs($amount)>$rule['maxChangePerCall'] || $next<$rule['min'] || $next>$rule['max'] || $next < -self::MAX || $next>self::MAX) self::fail('ITEM_PROPERTY_LIMIT_REACHED',422);
            $properties->{$key}=$next;
        }
        $instance['properties']=self::properties($properties); return $instance;
    }
    public static function decodeState(string $json): array
    {
        $state=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        foreach ($state['instances'] as &$instance) $instance['properties']=(object)$instance['properties']; unset($instance);
        return $state;
    }
}
