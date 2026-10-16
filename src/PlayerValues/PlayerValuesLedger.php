<?php

declare(strict_types=1);

class PlayerValuesFailure extends RuntimeException
{
    public string $errorCode;
    public int $status;
    public ?array $state = null;

    public function __construct(string $code, string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->errorCode = $code;
        $this->status = $status;
    }
}

/** Integer-only, bounded ledger shared by HTTP, local administration and internal grants. */
class PlayerValuesLedger
{
    public const MAX = 9007199254740991;
    public const RETENTION_MS = 2592000000;
    public const RECEIPTS = 50;

    public static function fail(string $code, string $message, int $status = 422): void
    {
        throw new PlayerValuesFailure($code, $message, $status);
    }

    public static function integer($value, int $min = 0, int $max = self::MAX): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            self::fail('INVALID_REQUEST', 'Expected an exact integer in the allowed range', 400);
        }
        return $value;
    }

    public static function text($value, int $bytes, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $bytes) {
            self::fail('INVALID_REQUEST', "Invalid {$label}", 400);
        }
        return $value;
    }

    public static function key($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,23}$/D', $value)) {
            self::fail('INVALID_REQUEST', 'Invalid value or offer key', 400);
        }
        return $value;
    }

    public static function list($value, int $max): array
    {
        if (!is_array($value) || count($value) > $max || ($value !== [] && array_keys($value) !== range(0, count($value) - 1))) {
            self::fail('INVALID_REQUEST', 'Expected a bounded array', 400);
        }
        return $value;
    }

    private static function boolean($value): bool
    {
        if (!is_bool($value)) self::fail('INVALID_REQUEST', 'Expected a boolean', 400);
        return $value;
    }

    private static function field(array $data, string $key, $default)
    {
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public static function config(array $request): array
    {
        if (strlen(json_encode($request, JSON_THROW_ON_ERROR)) > 1048576) {
            self::fail('INVALID_REQUEST', 'Configuration exceeds 1 MiB', 400);
        }
        $definitions = [];
        foreach (self::list(self::field($request, 'definitions', []), 64) as $definition) {
            if (!is_array($definition)) self::fail('INVALID_REQUEST', 'Invalid definition', 400);
            $key = self::key($definition['key'] ?? null);
            if (isset($definitions[$key])) self::fail('INVALID_REQUEST', 'Duplicate value key', 400);
            $max = self::integer(self::field($definition, 'maxBalance', self::MAX));
            $adjustment = self::field($definition, 'adjustment', []);
            if (!is_array($adjustment)) self::fail('INVALID_REQUEST', 'Invalid adjustment rule', 400);
            $rule = [
                'enabled' => self::boolean(self::field($adjustment, 'enabled', false)),
                'maxPerCall' => self::integer(self::field($adjustment, 'maxPerCall', 0)),
                'dailyLimit' => isset($adjustment['dailyLimit']) ? self::integer($adjustment['dailyLimit']) : null,
                'minimumBalance' => self::integer(self::field($adjustment, 'minimumBalance', 0), 0, $max),
            ];
            if ($rule['enabled'] && $rule['maxPerCall'] === 0) self::fail('INVALID_REQUEST', 'Enabled adjustment requires maxPerCall', 400);
            $definitions[$key] = [
                'key' => $key, 'name' => self::text($definition['name'] ?? null, 120, 'name'),
                'initialBalance' => self::integer(self::field($definition, 'initialBalance', 0), 0, $max),
                'maxBalance' => $max,
                'dailyCap' => isset($definition['dailyCap']) ? self::integer($definition['dailyCap']) : null,
                'adjustment' => $rule,
            ];
        }
        $offers = [];
        foreach (self::list(self::field($request, 'offers', []), 1000) as $offer) {
            if (!is_array($offer)) self::fail('INVALID_REQUEST', 'Invalid offer', 400);
            $key = self::key($offer['key'] ?? null);
            if (isset($offers[$key])) self::fail('INVALID_REQUEST', 'Duplicate offer key', 400);
            $normalized = [
                'key' => $key, 'name' => self::text($offer['name'] ?? null, 120, 'name'),
                'active' => self::boolean(self::field($offer, 'active', true)),
                'purchasesPerDay' => isset($offer['purchasesPerDay']) ? self::integer($offer['purchasesPerDay'], 1, 1000000) : null,
            ];
            foreach (['costs', 'rewards'] as $side) {
                $entries = self::list(self::field($offer, $side, $side === 'rewards' ? [] : null), 8);
                if (!$entries && $side === 'costs') self::fail('INVALID_REQUEST', 'Offers need positive costs', 400);
                $normalized[$side] = [];
                $seen = [];
                foreach ($entries as $entry) {
                    if (!is_array($entry)) self::fail('INVALID_REQUEST', 'Invalid offer value', 400);
                    $valueKey = self::key($entry['key'] ?? null);
                    if (!isset($definitions[$valueKey]) || isset($seen[$valueKey])) self::fail('INVALID_REQUEST', 'Unknown or duplicate offer value', 400);
                    $seen[$valueKey] = true;
                    $normalized[$side][] = ['key' => $valueKey, 'amount' => self::integer($entry['amount'] ?? null, 1)];
                }
            }
            $normalized['itemRewards'] = self::itemRewards(self::field($offer, 'itemRewards', []));
            if (!$normalized['rewards'] && !$normalized['itemRewards']) self::fail('INVALID_REQUEST', 'Offers need value or item rewards', 400);
            $offers[$key] = $normalized;
        }
        return ['definitions' => array_values($definitions), 'offers' => array_values($offers)];
    }

    /** Additive item rewards; absent on legacy offers. */
    public static function itemRewards($items): array
    {
        $items = self::list($items, 4); $seen = []; $total = 0; $result = [];
        foreach ($items as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['itemKey', 'quantity'])) self::fail('INVALID_REQUEST', 'Invalid item reward', 400);
            $key = $item['itemKey'] ?? null;
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || isset($seen[$key])) self::fail('INVALID_REQUEST', 'Invalid or duplicate item key', 400);
            $quantity = self::integer($item['quantity'] ?? null, 1, 4); $total += $quantity; $seen[$key] = true;
            $result[] = ['itemKey' => $key, 'quantity' => $quantity];
        }
        if ($total > 4) self::fail('INVALID_REQUEST', 'At most four item instances per purchase', 400);
        return $result;
    }

    public static function emptyState(): array
    {
        return ['values' => [], 'initializedKeys' => [], 'day' => '', 'today' => [],
            'adjustedToday' => [], 'offerPurchases' => [], 'receipts' => [], 'evictedThroughMillis' => 0, 'revision' => 0];
    }

    public static function initialize(array $state, array $definitions, int $now): array
    {
        $day = gmdate('Y-m-d', intdiv($now, 1000));
        if ($state['day'] > $day) self::fail('STATE_CONFLICT', 'Stored counters are ahead of this server UTC day', 409);
        $keys = array_fill_keys(array_merge($state['initializedKeys'], array_keys($state['values'])), true);
        foreach ($definitions as $definition) {
            $key = $definition['key'];
            if (!isset($keys[$key])) {
                if (count($keys) >= 256) self::fail('VALUE_KEY_LIMIT_REACHED', 'Lifetime value-key limit reached; balances are preserved', 409);
                $keys[$key] = true;
                $state['values'][$key] = $definition['initialBalance'];
            }
        }
        $state['initializedKeys'] = array_map('strval', array_keys($keys));
        if ($state['day'] !== $day) {
            $state['today'] = $state['adjustedToday'] = $state['offerPurchases'] = [];
            $state['day'] = $day;
        }
        return $state;
    }

    public static function issuedAt($operationId): int
    {
        if (!is_string($operationId) || !preg_match('/^([0-9]{1,16}):[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $operationId, $match)) {
            self::fail('INVALID_REQUEST', 'operationId must be unixMillis:UUID', 400);
        }
        return (int)$match[1];
    }

    public static function hash(string ...$parts): string
    {
        $context = hash_init('sha256');
        foreach ($parts as $part) hash_update($context, strlen($part) . ':' . $part);
        return hash_final($context);
    }

    public static function receipt(array $state, string $id, string $hash): ?array
    {
        foreach ($state['receipts'] as $receipt) {
            if ($receipt['operationId'] !== $id) continue;
            if (!hash_equals($receipt['payloadHash'], $hash)) self::fail('OPERATION_CONFLICT', 'operationId already used for a different request', 409);
            return $receipt;
        }
        return null;
    }

    public static function checkWindow(array $state, int $issuedAt, int $now): void
    {
        if ($issuedAt > $now + 30000) self::fail('INVALID_REQUEST', 'operationId is ahead of server time', 400);
        if ($issuedAt < $now - self::RETENTION_MS || $issuedAt <= $state['evictedThroughMillis']) {
            self::fail('OPERATION_EXPIRED', 'Operation is outside the safe replay window; refresh state', 409);
        }
    }

    public static function purchase(array $state, array $definitions, ?array $offer): array
    {
        if ($offer === null || !$offer['active']) self::fail('OFFER_NOT_FOUND', 'Offer not found', 404);
        $count = $state['offerPurchases'][$offer['key']] ?? 0;
        if (($count === 0 && count($state['offerPurchases']) >= 1000) || $count >= 2147483647 ||
            ($offer['purchasesPerDay'] !== null && $count >= $offer['purchasesPerDay'])) self::fail('DAILY_LIMIT_REACHED', 'Daily offer limit reached');
        $deltas = [];
        foreach ($offer['costs'] as $cost) {
            $key = $cost['key']; $balance = $state['values'][$key] ?? 0;
            if ($balance < $cost['amount']) self::fail('INSUFFICIENT_BALANCE', 'Not enough balance for this offer');
            $state['values'][$key] = $balance - $cost['amount'];
            $deltas[$key] = -$cost['amount'];
        }
        foreach ($offer['rewards'] as $reward) {
            self::credit($state, $definitions, $reward['key'], $reward['amount']);
            $deltas[$reward['key']] = ($deltas[$reward['key']] ?? 0) + $reward['amount'];
        }
        $state['offerPurchases'][$offer['key']] = $count + 1;
        return [$state, self::amounts($deltas)];
    }

    private static function credit(array &$state, array $definitions, string $key, int $amount): void
    {
        if (!isset($definitions[$key])) self::fail('INVALID_REQUEST', 'Unknown value', 400);
        $definition = $definitions[$key];
        $balance = $state['values'][$key] ?? 0; $earned = $state['today'][$key] ?? 0;
        if ($amount > $definition['maxBalance'] - $balance) self::fail('MAX_BALANCE_REACHED', 'Credit exceeds balance limit');
        if ($amount > ($definition['dailyCap'] ?? self::MAX) - $earned) self::fail('DAILY_LIMIT_REACHED', 'Credit exceeds daily limit');
        $state['values'][$key] = $balance + $amount;
        $state['today'][$key] = $earned + $amount;
    }

    public static function adjust(array $state, array $definition, int $amount): array
    {
        $rule = $definition['adjustment']; $key = $definition['key'];
        if (!$rule['enabled']) self::fail('ADJUST_NOT_ALLOWED', 'Direct adjustments are disabled');
        self::integer($amount, -self::MAX);
        if ($amount === 0) self::fail('INVALID_REQUEST', 'Adjustment cannot be zero', 400);
        $magnitude = abs($amount); $used = $state['adjustedToday'][$key] ?? 0;
        if ($magnitude > $rule['maxPerCall']) self::fail('ADJUST_ABOVE_LIMIT', 'Adjustment exceeds per-call limit');
        if ($magnitude > ($rule['dailyLimit'] ?? self::MAX) - $used) self::fail('DAILY_LIMIT_REACHED', 'Adjustment daily limit reached');
        if ($amount > 0) self::credit($state, [$key => $definition], $key, $amount);
        else {
            $balance = $state['values'][$key] ?? 0;
            if ($amount < $rule['minimumBalance'] - $balance) self::fail('INSUFFICIENT_BALANCE', 'Adjustment falls below minimum balance');
            $state['values'][$key] = $balance + $amount;
        }
        $state['adjustedToday'][$key] = $used + $magnitude;
        return [$state, [['key' => $key, 'amount' => $amount]]];
    }

    public static function grant(array $state, array $definitions, array $values): array
    {
        $values = self::list($values, 64); $seen = [];
        if (!$values) self::fail('INVALID_REQUEST', 'Grant needs values', 400);
        foreach ($values as $value) {
            if (!is_array($value)) self::fail('INVALID_REQUEST', 'Invalid grant value', 400);
            $key = self::key($value['key'] ?? null);
            if (isset($seen[$key])) self::fail('INVALID_REQUEST', 'Duplicate grant value', 400);
            $seen[$key] = true;
            self::credit($state, $definitions, $key, self::integer($value['amount'] ?? null, 1));
        }
        return [$state, $values];
    }

    public static function record(array $state, array $receipt, int $now): array
    {
        $all = array_merge($state['receipts'], [$receipt]);
        $fresh = array_values(array_filter($all, static function (array $entry) use ($now) {
            return $entry['recordedAtMillis'] >= $now - self::RETENTION_MS;
        }));
        $retained = array_slice($fresh, -self::RECEIPTS);
        $ids = array_fill_keys(array_column($retained, 'operationId'), true);
        foreach ($all as $entry) {
            if (!isset($ids[$entry['operationId']])) $state['evictedThroughMillis'] = max($state['evictedThroughMillis'], $entry['issuedAtMillis']);
        }
        $state['receipts'] = $retained;
        $state['revision'] = self::integer($state['revision'], 0, self::MAX - 1) + 1;
        return $state;
    }

    private static function amounts(array $values): array
    {
        $out = [];
        foreach ($values as $key => $amount) $out[] = ['key' => (string)$key, 'amount' => $amount];
        return $out;
    }

    public static function instant(int $millis): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($millis, 1000)) . sprintf('.%03dZ', $millis % 1000);
    }

    public static function view(string $userId, array $config, array $state, int $now, ?array $operation = null): array
    {
        $day = max(gmdate('Y-m-d', intdiv($now, 1000)), $state['day']);
        $definitions = $config['definitions'];
        usort($definitions, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
        $values = [];
        foreach ($definitions as $d) $values[] = ['key' => $d['key'], 'balance' => $state['values'][$d['key']] ?? 0,
            'earnedToday' => $day === $state['day'] ? ($state['today'][$d['key']] ?? 0) : 0, 'dailyCap' => $d['dailyCap']];
        return ['userId' => $userId, 'definitionVersion' => $config['revision'], 'revision' => $state['revision'],
            'day' => $day, 'values' => $values, 'operationWindow' => ['serverTime' => self::instant($now),
                'oldestAcceptedAt' => self::instant(max($now - self::RETENTION_MS, $state['evictedThroughMillis'] + 1))], 'operation' => $operation];
    }
}
