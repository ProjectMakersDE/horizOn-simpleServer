<?php

declare(strict_types=1);
require_once __DIR__ . '/StatisticsRules.php';

/** Shared catalog slots; 29-byte counters and 56-byte original operation guards. */
class StatisticsStorage
{
    private static function invalid(): void { StatisticsRules::fail('MIGRATION_REQUIRED', 'Original statistic state needs reconciliation', 409); }
    private static function fields(array $row, array $keys): void {
        if (array_diff(array_keys($row), $keys) || array_diff($keys, array_keys($row))) self::invalid();
    }
    private static function integer($value, int $min = 0, int $max = StatisticsRules::MAX): int {
        if (!is_int($value) || $value < $min || $value > $max) self::invalid();
        return $value;
    }
    private static function binary($value, int $tuple): string {
        if (!is_string($value) || ($raw = base64_decode($value, true)) === false || base64_encode($raw) !== $value || strlen($raw) % $tuple !== 0) self::invalid();
        return $raw;
    }
    /** Stable for retained definitions. A reused slot always has a new catalog generation. */
    public static function slots(array $definitions): array {
        $used = []; foreach ($definitions as $definition) if (array_key_exists('slot', $definition)) {
            $slot = self::integer($definition['slot'], 0, 2147483647);
            if (isset($used[$slot])) self::invalid(); $used[$slot] = true;
        }
        usort($definitions, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
        $slot = 0;
        foreach ($definitions as &$definition) if (!array_key_exists('slot', $definition)) {
            while (isset($used[$slot])) $slot++;
            if ($slot > 2147483647) self::invalid(); $definition['slot'] = $slot; $used[$slot] = true;
        }
        unset($definition); return $definitions;
    }
    private static function original(array $state): array {
        self::fields($state, ['revision','catalogVersion','values','receipts','floor']);
        foreach (['revision','catalogVersion','floor'] as $key) self::integer($state[$key]);
        if (!is_array($state['values']) || !is_array($state['receipts']) || count($state['receipts']) > StatisticsRules::RECEIPTS) self::invalid();
        foreach ($state['values'] as $key => $cell) {
            StatisticsRules::key($key); if (!is_array($cell)) self::invalid();
            self::fields($cell, ['g','p','v']); self::integer($cell['g']); self::integer($cell['p'], -StatisticsRules::MAX); self::integer($cell['v'], -StatisticsRules::MAX);
        }
        $seen = [];
        foreach ($state['receipts'] as &$guard) {
            if (!is_array($guard)) self::invalid(); self::fields($guard, ['id','hash','at']);
            try { $issued = StatisticsRules::operation($guard['id']); } catch (StatisticsFailure $error) { self::invalid(); }
            if ($issued !== self::integer($guard['at']) || !is_string($guard['hash']) || !preg_match('/^[a-f0-9]{64}$/D', $guard['hash'])) self::invalid();
            $guard['id'] = strtolower($guard['id']);
            if (isset($seen[$guard['id']])) self::invalid(); $seen[$guard['id']] = true;
        }
        unset($guard); return $state;
    }
    public static function encode(array $state, array $definitions): string {
        $state = self::original($state); $byKey = array_column(self::slots($definitions), null, 'key');
        $rows = [];
        foreach ($state['values'] as $key => $cell) {
            if (!isset($byKey[$key])) self::invalid();
            $rows[$byKey[$key]['slot']] = $cell;
        }
        ksort($rows, SORT_NUMERIC); $counters = '';
        foreach ($rows as $slot => $cell) $counters .= pack('NJJJC', $slot, $cell['g'], $cell['p'], $cell['v'], 1);
        $guards = '';
        foreach ($state['receipts'] as $guard) $guards .= hex2bin(str_replace('-', '', substr($guard['id'], 14))) . pack('J', $guard['at']) . hex2bin($guard['hash']);
        return json_encode(['s'=>1,'r'=>$state['revision'],'v'=>$state['catalogVersion'],'f'=>$state['floor'],
            'c'=>base64_encode($counters),'o'=>base64_encode($guards)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    public static function decode(string $json, array $catalog): array {
        $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR); if (!is_array($row)) self::invalid();
        if (!array_key_exists('s', $row)) return self::original($row); // No read-time migration write.
        self::fields($row, ['s','r','v','f','c','o']); if ($row['s'] !== 1) self::invalid();
        $state = StatisticsRules::emptyState(); $state['revision'] = self::integer($row['r']);
        $state['catalogVersion'] = self::integer($row['v']); $state['floor'] = self::integer($row['f']);
        if ($state['catalogVersion'] > $catalog['version']) StatisticsRules::fail('STATE_CONFLICT', 'Catalog is older than player state', 409);
        $bySlot = array_column(self::slots($catalog['definitions']), null, 'slot');
        $raw = self::binary($row['c'], 29); $seen = [];
        for ($i=0; $i<strlen($raw); $i+=29) {
            $cell = unpack('Nslot/Jg/Jp/Jv/Ch', substr($raw,$i,29)); $slot = self::integer($cell['slot'],0,2147483647);
            if (isset($seen[$slot]) || !in_array($cell['h'],[0,1],true)) self::invalid(); $seen[$slot]=true;
            self::integer($cell['g']); self::integer($cell['p'],-StatisticsRules::MAX); self::integer($cell['v'],-StatisticsRules::MAX);
            if (isset($bySlot[$slot]) && $cell['h']===1) $state['values'][$bySlot[$slot]['key']] = ['g'=>$cell['g'],'p'=>$cell['p'],'v'=>$cell['v']];
        }
        $raw = self::binary($row['o'],56); if (strlen($raw)>StatisticsRules::RECEIPTS*56) self::invalid();
        for ($i=0; $i<strlen($raw); $i+=56) {
            $hex=bin2hex(substr($raw,$i,16)); $uuid=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
            $at=self::integer(unpack('J',substr($raw,$i+16,8))[1]);
            $state['receipts'][]=['id'=>$at.':'.$uuid,'at'=>$at,'hash'=>bin2hex(substr($raw,$i+24,32))];
        }
        return self::original($state);
    }
}
