<?php

declare(strict_types=1);
require_once __DIR__ . '/PlayerValuesLedger.php';

/** Stable project slots, sparse integer fields and replay identities without purchase payload copies. */
class PlayerValuesStorage
{
    private static function invalid(): void { PlayerValuesLedger::fail('MIGRATION_REQUIRED', 'Original value state needs reconciliation', 409); }
    private static function binary($value): string {
        if (!is_string($value) || ($raw = base64_decode($value, true)) === false || base64_encode($raw) !== $value) self::invalid();
        return $raw;
    }
    private static function keys(array $keys): array {
        if (count($keys) > 256 || count(array_unique($keys)) !== count($keys)) self::invalid();
        foreach ($keys as $key) PlayerValuesLedger::key($key);
        return array_flip($keys);
    }
    private static function integer($number): int {
        if (!is_int($number) || $number < 0 || $number > PlayerValuesLedger::MAX) self::invalid();
        return $number;
    }
    private static function number(string $raw): int {
        $value = unpack('Jvalue', $raw)['value'];
        return self::integer($value);
    }
    private static function packed(array $values, array $slots): string {
        $rows = [];
        foreach ($values as $key => $value) {
            self::integer($value); if ($value === 0) continue;
            if (!array_key_exists($key, $slots)) self::invalid();
            $rows[$slots[$key]] = $value;
        }
        ksort($rows, SORT_NUMERIC); $raw = '';
        foreach ($rows as $slot => $value) $raw .= chr($slot) . pack('J', $value);
        return base64_encode($raw);
    }
    private static function unpacked($encoded, array $keys): array {
        $raw = self::binary($encoded); if (strlen($raw) % 9 !== 0) self::invalid();
        $result = [];
        for ($i = 0; $i < strlen($raw); $i += 9) {
            $slot = ord($raw[$i]); if (!isset($keys[$slot]) || isset($result[$keys[$slot]])) self::invalid();
            $result[$keys[$slot]] = self::number(substr($raw, $i + 1, 8));
        }
        return $result;
    }
    public static function encode(array $state, array $keys): string {
        if (array_diff(array_keys($state), array_keys(PlayerValuesLedger::emptyState())) ||
            array_diff(array_keys(PlayerValuesLedger::emptyState()), array_keys($state))) self::invalid();
        $slots = self::keys($keys); $initialized = '';
        foreach (array_unique(array_merge($state['initializedKeys'], array_keys($state['values']), array_keys($state['today']), array_keys($state['adjustedToday']))) as $key) {
            if (!array_key_exists($key, $slots)) self::invalid();
            $slot = $slots[$key]; $byte = intdiv($slot, 8);
            if (strlen($initialized) <= $byte) $initialized = str_pad($initialized, $byte + 1, "\0");
            $initialized[$byte] = chr(ord($initialized[$byte]) | (1 << ($slot % 8)));
        }
        $guards = ''; $seen = [];
        foreach ($state['receipts'] as $receipt) {
            $id = PlayerValuesLedger::canonicalOperation($receipt['operationId']);
            if (isset($seen[$id]) || !preg_match('/^[a-f0-9]{64}$/D', $receipt['payloadHash'])) self::invalid();
            $seen[$id] = true; $issued = self::integer($receipt['issuedAtMillis']);
            $hash = hex2bin($receipt['payloadHash']);
            if (PlayerValuesLedger::isPublicOperation($id)) {
                if (PlayerValuesLedger::issuedAt($id) !== $issued) self::invalid();
                $guards .= chr(0) . hex2bin(str_replace('-', '', substr($id, strpos($id, ':') + 1))) . pack('J', $issued) . $hash;
            } else {
                if (strlen($id) < 1 || strlen($id) > 128) self::invalid();
                $guards .= chr(1) . chr(strlen($id)) . $id . pack('J', $issued) . $hash;
            }
        }
        $next = ['s' => 2, 'r' => self::integer($state['revision']), 'd' => $state['day'],
            'f' => self::integer($state['evictedThroughMillis']), 'i' => base64_encode($initialized),
            'b' => self::packed($state['values'], $slots), 't' => self::packed($state['today'], $slots),
            'a' => self::packed($state['adjustedToday'], $slots), 'q' => base64_encode($guards)];
        return json_encode($next, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    public static function decode(string $json, array $keys): array {
        $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($row)) self::invalid();
        if (!array_key_exists('s', $row)) return $row; // Existing JSON is read without a write or guessed migration.
        if ($row['s'] !== 2 || array_diff(array_keys($row), ['s','r','d','f','i','b','t','a','q']) || array_diff(['s','r','d','f','i','b','t','a','q'], array_keys($row))) self::invalid();
        self::keys($keys); $state = PlayerValuesLedger::emptyState();
        $state['revision'] = self::integer($row['r']); $state['evictedThroughMillis'] = self::integer($row['f']);
        if (!is_string($row['d']) || ($row['d'] !== '' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $row['d']))) self::invalid();
        $state['day'] = $row['d']; $bits = self::binary($row['i']); if (strlen($bits) > 32) self::invalid();
        for ($slot = 0; $slot < strlen($bits) * 8; $slot++) if ((ord($bits[intdiv($slot, 8)]) & (1 << ($slot % 8))) !== 0) {
            if (!isset($keys[$slot])) self::invalid(); $state['initializedKeys'][] = $keys[$slot];
        }
        foreach (['b'=>'values','t'=>'today','a'=>'adjustedToday'] as $packed => $name) $state[$name] = self::unpacked($row[$packed], $keys);
        $seen = [];
        $add = static function (string $id, int $issued, string $hash) use (&$state, &$seen) {
            if (isset($seen[$id])) self::invalid();
            $seen[$id] = true;
            $state['receipts'][] = ['operationId'=>$id, 'payloadHash'=>bin2hex($hash), 'issuedAtMillis'=>$issued,
                'recordedAtMillis'=>$issued, 'issuedAt'=>PlayerValuesLedger::instant($issued), 'recordedAt'=>PlayerValuesLedger::instant($issued),
                'source'=>'', 'applied'=>[], 'items'=>[], 'note'=>null];
        };
        $guards = self::binary($row['q']);
        for ($i = 0; $i < strlen($guards);) {
            $type = ord($guards[$i++]);
            if ($type === 0) {
                if (strlen($guards)-$i < 56) self::invalid();
                $hex = bin2hex(substr($guards,$i,16)); $i += 16;
                $id = substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20,12);
            } elseif ($type === 1) {
                if ($i >= strlen($guards)) self::invalid();
                $length = ord($guards[$i++]);
                if ($length < 1 || $length > 128 || strlen($guards)-$i < $length+40) self::invalid();
                $id = substr($guards,$i,$length); $i += $length;
                if (PlayerValuesLedger::isPublicOperation($id)) self::invalid();
            } else self::invalid();
            $issued = self::number(substr($guards,$i,8)); $i += 8;
            $add($type === 0 ? $issued.':'.$id : $id,$issued,substr($guards,$i,32)); $i += 32;
        }
        return $state;
    }
}
