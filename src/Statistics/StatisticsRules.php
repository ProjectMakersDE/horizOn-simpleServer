<?php

declare(strict_types=1);

class StatisticsFailure extends RuntimeException
{
    public string $errorCode;
    public int $status;
    public function __construct(string $code, string $message, int $status) { parent::__construct($message); $this->errorCode = $code; $this->status = $status; }
}

/** Pure statistics contract; no connection to spendable player values. */
class StatisticsRules
{
    public const MAX = 9007199254740991;
    public const RECEIPTS = 8;
    public static function fail(string $code, string $message, int $status = 400): void { throw new StatisticsFailure($code, $message, $status); }
    public static function integer($value, int $min = -self::MAX, int $max = self::MAX): int
    {
        if (!is_int($value) || $value < $min || $value > $max) self::fail('INVALID_REQUEST', 'A safe JSON integer is required');
        return $value;
    }
    public static function key($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,23}$/D', $value)) self::fail('INVALID_REQUEST', 'Invalid statistic key');
        return $value;
    }
    public static function operation($value): int
    {
        if (!is_string($value) || !preg_match('/^([0-9]{13}):[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value, $match)) self::fail('INVALID_REQUEST', 'operationId must be unixMillis:UUID');
        return (int)$match[1];
    }
    public static function list($value, int $min = 0, int $max = 100): array
    {
        if (!is_array($value) || ($value && array_keys($value) !== range(0, count($value) - 1)) || count($value) < $min || count($value) > $max) self::fail('INVALID_REQUEST', 'Invalid list size or shape');
        return $value;
    }
    public static function configure(array $input, array $previous): array
    {
        $input = self::list($input); $old = array_column($previous['definitions'], null, 'key'); $next = []; $keys = [];
        foreach ($input as $definition) {
            if (!is_array($definition)) self::fail('INVALID_REQUEST', 'Invalid definition');
            $key = self::key($definition['key'] ?? null); $name = $definition['name'] ?? null;
            if (isset($keys[$key]) || !is_string($name) || preg_match('/^[\p{Z}\s]*$/u', $name) || strlen($name) > 80 || !preg_match('//u', $name)) self::fail('INVALID_REQUEST', 'Duplicate key or invalid UTF-8 name');
            $mode = $definition['mode'] ?? null; $reset = $definition['reset'] ?? 'none';
            if (!in_array($mode, ['sum', 'max', 'min', 'last'], true) || !in_array($reset, ['none', 'daily', 'weekly'], true)) self::fail('INVALID_REQUEST', 'Invalid mode/reset');
            $bound = array_key_exists('maxPerUpdate', $definition) ? self::integer($definition['maxPerUpdate'], 1) : null;
            $prior = $old[$key] ?? null;
            $generation = $prior && $prior['mode'] === $mode && $prior['reset'] === $reset ? $prior['generation'] : $previous['version'] + 1;
            $next[] = ['key' => $key, 'name' => $name, 'mode' => $mode, 'reset' => $reset, 'generation' => $generation] + ($bound === null ? [] : ['maxPerUpdate' => $bound]);
            $keys[$key] = true;
        }
        usort($next, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
        return ['version' => self::integer($previous['version'], 0, self::MAX - 1) + 1, 'definitions' => $next];
    }
    public static function updates($input): array
    {
        $input = self::list($input, 1); $keys = [];
        foreach ($input as &$update) {
            if (!is_array($update)) self::fail('INVALID_REQUEST', 'Invalid update');
            $key = self::key($update['key'] ?? null); $value = self::integer($update['value'] ?? null);
            if (isset($keys[$key])) self::fail('INVALID_REQUEST', 'Duplicate statistic key');
            $keys[$key] = true; $update = ['key' => $key, 'value' => $value];
        }
        unset($update);
        usort($input, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
        return $input;
    }
    public static function emptyState(): array { return ['revision' => 0, 'catalogVersion' => 0, 'values' => [], 'receipts' => [], 'floor' => 0]; }
    public static function period(string $reset, int $now): int
    {
        $day = (int)floor($now / 86400000);
        return $reset === 'daily' ? $day : ($reset === 'weekly' ? (int)floor(($day + 3) / 7) : 0);
    }
    private static function cells(array $state, array $catalog, int $now): array
    {
        if ($state['catalogVersion'] > $catalog['version']) self::fail('STATE_CONFLICT', 'Catalog is older than the player state', 409);
        $cells = [];
        foreach ($catalog['definitions'] as $definition) {
            $old = $state['values'][$definition['key']] ?? null; $period = self::period($definition['reset'], $now);
            if ($old !== null && $old['g'] === $definition['generation']) {
                if ($old['p'] > $period) self::fail('STATE_CONFLICT', 'Server clock is behind the stored UTC period', 409);
                if ($old['p'] === $period) $cells[$definition['key']] = $old;
            }
        }
        return $cells;
    }
    public static function definitions(array $catalog, ?int $known = null): array
    {
        $definitions = array_map(static function ($definition) { unset($definition['generation']); return $definition; }, $catalog['definitions']);
        return ['definitionVersion' => $catalog['version'], 'definitions' => $known === $catalog['version'] ? null : $definitions];
    }
    public static function view(string $user, array $state, array $catalog, int $now, ?int $known = null, ?string $operation = null, bool $replayed = false): array
    {
        $cells = self::cells($state, $catalog, $now); $values = [];
        foreach ($catalog['definitions'] as $definition) {
            $key = $definition['key']; $present = isset($cells[$key]); $reset = $definition['reset'];
            $label = $reset === 'daily' ? gmdate('Y-m-d', (int)floor($now / 1000)) : ($reset === 'weekly' ? gmdate('o-\WW', (int)floor($now / 1000)) : null);
            $values[] = ['key' => $key, 'value' => $present ? $cells[$key]['v'] : 0, 'hasValue' => $present, 'period' => $label];
        }
        return ['userId' => $user] + self::definitions($catalog, $known) + ['revision' => $state['revision'], 'values' => $values,
            'operationWindow' => ['serverTimeUnixMillis' => $now, 'maxAgeSeconds' => 86400, 'maxFutureSkewSeconds' => 30],
            'operationId' => $operation, 'replayed' => $replayed, 'newlyUnlocked' => []];
    }
    /** Receipt replay is checked before current policy, age and eviction, exactly as in Cloud. */
    public static function apply(string $user, array $state, array $catalog, string $operation, array $updates, int $now): array
    {
        $issued = self::operation($operation); $updates = self::updates($updates);
        $hash = hash('sha256', implode("\n", array_map(static function ($update) { return $update['key'] . ':' . $update['value']; }, $updates)));
        foreach ($state['receipts'] as $receipt) {
            if ($receipt['id'] !== $operation) continue;
            if (!hash_equals($receipt['hash'], $hash)) self::fail('OPERATION_CONFLICT', 'Operation ID already has another payload', 409);
            return [false, $state, self::view($user, $state, $catalog, $now, null, $operation, true)];
        }
        if ($issued > $now + 30000) self::fail('OPERATION_IN_FUTURE', 'Operation timestamp is in the future', 422);
        if ($issued < $now - 86400000 || $issued <= $state['floor']) self::fail('OPERATION_EXPIRED', 'Unknown operation is expired or evicted', 410);
        $cells = self::cells($state, $catalog, $now); $definitions = array_column($catalog['definitions'], null, 'key');
        foreach ($updates as $update) {
            $key = $update['key']; $value = $update['value'];
            if (!isset($definitions[$key])) self::fail('UNKNOWN_STATISTIC', 'Statistic is not defined', 422);
            $definition = $definitions[$key];
            if (isset($definition['maxPerUpdate']) && ($value > $definition['maxPerUpdate'] || $value < -$definition['maxPerUpdate'])) self::fail('UPDATE_ABOVE_LIMIT', 'Update exceeds its report limit', 422);
            $present = isset($cells[$key]); $old = $present ? $cells[$key]['v'] : 0;
            switch ($definition['mode']) {
                case 'sum': $next = $old + $value; break;
                case 'min': $next = $present ? min($old, $value) : $value; break;
                case 'max': $next = $present ? max($old, $value) : $value; break;
                default: $next = $value;
            }
            if ($next < -self::MAX || $next > self::MAX) self::fail('VALUE_OVERFLOW', 'Whole batch exceeds the safe integer range', 422);
            $cells[$key] = ['g' => $definition['generation'], 'p' => self::period($definition['reset'], $now), 'v' => $next];
        }
        $state['revision'] = self::integer($state['revision'], 0, self::MAX - 1) + 1; $state['catalogVersion'] = $catalog['version']; $state['values'] = $cells;
        $state['receipts'][] = ['id' => $operation, 'hash' => $hash, 'at' => $issued];
        while (count($state['receipts']) > self::RECEIPTS) { $evicted = array_shift($state['receipts']); $state['floor'] = max($state['floor'], $evicted['at']); }
        return [true, $state, self::view($user, $state, $catalog, $now, null, $operation)];
    }
}
