<?php

declare(strict_types=1);
require_once __DIR__ . '/../Core/SqlTransaction.php';
require_once __DIR__ . '/../PlayerValues/PlayerValuesService.php';
require_once __DIR__ . '/../PlayerProfile/PlayerProfileController.php';

class GiftCodesFailure extends RuntimeException
{
    public string $errorCode;
    public int $httpStatus;
    public function __construct(string $message, string $code = 'INVALID_GIFT_CODE', int $status = 200)
    { parent::__construct($message); $this->errorCode = $code; $this->httpStatus = $status; }
}

/** Single-installation project. Code reservation, source receipt, values and cosmetics share one PDO scope. */
class GiftCodesService
{
    private PDO $pdo;
    private bool $mysql;
    private SqlTransaction $transactions;
    private ?PlayerValuesService $values = null;
    private $clock;
    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null)
    {
        $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->transactions = $transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Transaction scope belongs to another connection');
        $this->clock = $clock ?? static function () { return (int)floor(microtime(true) * 1000); };
        if (!$this->mysql) $pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function values(): PlayerValuesService
    { return $this->values ?? ($this->values = new PlayerValuesService($this->pdo, $this->clock, $this->transactions)); }
    private function transaction(callable $work)
    {
        try { return $this->transactions->run($work); }
        catch (SqlTransactionUncertain $error) { throw new GiftCodesFailure('Commit result uncertain; retry the original idempotencyId', 'STATE_CONFLICT', 409); }
        catch (SqlTransactionRollbackOnly $error) { throw new GiftCodesFailure('Nested operation failed; retry the original intent', 'STATE_CONFLICT', 409); }
        catch (PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [5, 6, 19, 1062, 1205, 1213, 2006, 2013], true))
                throw new GiftCodesFailure('Concurrent redemption; retry the same idempotencyId', 'STATE_CONFLICT', 409);
            throw $error;
        }
    }
    private function code(string $code, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT * FROM gift_codes WHERE code=?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$code]); $row = $query->fetch();
        if ($row === false) throw new GiftCodesFailure('Gift code not found');
        return $row;
    }
    private static function operation($value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || !preg_match('//u', $value)) throw new GiftCodesFailure('Invalid idempotencyId', 'INVALID_REQUEST', 400);
        $value = preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value);
        $length = count(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY)) + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($length < 1 || $length > 128) throw new GiftCodesFailure('idempotencyId must contain1..128 characters', 'INVALID_REQUEST', 400);
        return $value;
    }
    public static function rewardValues($values): array
    {
        $values = PlayerValuesLedger::list($values, 8); $seen = [];
        foreach ($values as $value) {
            if (!is_array($value)) PlayerValuesLedger::fail('INVALID_REQUEST', 'Invalid gift value reward', 400);
            $key = PlayerValuesLedger::key($value['key'] ?? null); PlayerValuesLedger::integer($value['amount'] ?? null, 1);
            if (isset($seen[$key])) PlayerValuesLedger::fail('INVALID_REQUEST', 'Duplicate gift value key', 400);
            $seen[$key] = true;
        }
        return array_map(static function ($value) { return ['key' => $value['key'], 'amount' => $value['amount']]; }, $values);
    }
    private static function storedValues(array $code): array
    { return self::rewardValues($code['values_json'] === null ? [] : json_decode($code['values_json'], true, 512, JSON_THROW_ON_ERROR)); }
    private function checkAvailable(array $code, int $now): void
    {
        if ($code['expires_at'] !== null && (new DateTimeImmutable($code['expires_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000 < $now)
            throw new GiftCodesFailure('Gift code has expired');
        if ((int)$code['current_redemptions'] >= (int)$code['max_redemptions']) throw new GiftCodesFailure('Gift code has reached maximum redemptions');
    }
    public function validate(string $code, string $user): bool
    {
        try {
            $gift = $this->code($code); $this->checkAvailable($gift, ($this->clock)());
            $query = $this->pdo->prepare('SELECT id FROM gift_code_redemptions WHERE gift_code_id=? AND user_id=?');
            $query->execute([$gift['id'], $user]); return $query->fetch() === false;
        } catch (GiftCodesFailure $failure) { if ($failure->httpStatus === 200) return false; throw $failure; }
    }
    public function redeem(string $code, string $user, $idempotencyId = null): array
    {
        $operation = self::operation($idempotencyId);
        return $this->transaction(function () use ($code, $user, $operation) {
            $query = $this->pdo->prepare('SELECT id,unlocks FROM users WHERE id=?' . ($this->mysql ? ' FOR UPDATE' : ''));
            $query->execute([$user]); $player = $query->fetch();
            if ($player === false) throw new GiftCodesFailure('Player not found', 'PLAYER_NOT_FOUND', 404);
            $gift = $this->code($code, true);
            if ($operation !== null) {
                $query = $this->pdo->prepare('SELECT * FROM gift_code_redemptions WHERE idempotency_id=?' . ($this->mysql ? ' FOR UPDATE' : ''));
                $query->execute([$operation]); $receipt = $query->fetch();
                if ($receipt !== false) {
                    if ($receipt['gift_code_id'] !== $gift['id'] || $receipt['user_id'] !== $user)
                        throw new GiftCodesFailure('Idempotency ID already belongs to another redemption', 'OPERATION_CONFLICT', 409);
                    // NULL is an original payload too. Migrated pre-feature rows have no replay ID.
                    $data = $receipt['gift_data'];
                    $owned = PlayerProfileController::decodeList($player['unlocks']);
                    $grants = array_values(array_filter(PlayerProfileController::parseGrants($data), static function ($key) use ($owned) { return in_array($key, $owned, true); }));
                    return self::result($data, $grants, $receipt['granted_values'] === null ? [] : json_decode($receipt['granted_values'], true, 512, JSON_THROW_ON_ERROR),
                        $receipt['value_granted_at'] === null ? null : $this->values()->get($user), true);
                }
            }
            $now = ($this->clock)(); $this->checkAvailable($gift, $now);
            $query = $this->pdo->prepare('SELECT id FROM gift_code_redemptions WHERE gift_code_id=? AND user_id=?');
            $query->execute([$gift['id'], $user]);
            if ($query->fetch() !== false) throw new GiftCodesFailure('You have already redeemed this gift code');
            $rewards = self::storedValues($gift);
            $query = $this->pdo->prepare('UPDATE gift_codes SET current_redemptions=current_redemptions+1 WHERE id=? AND current_redemptions<max_redemptions');
            $query->execute([$gift['id']]); if ($query->rowCount() !== 1) throw new GiftCodesFailure('Gift code has reached maximum redemptions');
            $redemption = Database::uuid(); $date = gmdate('Y-m-d H:i:s', intdiv($now, 1000));
            $this->pdo->prepare('INSERT INTO gift_code_redemptions(id,gift_code_id,user_id,redeemed_at,idempotency_id,gift_data) VALUES(?,?,?,?,?,?)')
                ->execute([$redemption, $gift['id'], $user, $date, $operation, $gift['reward_data']]);
            $state = $rewards ? $this->values()->grantFromSource($user, $rewards, 'giftcode:' . $gift['id'] . ':' . $redemption, $now, 'giftcode:' . $gift['id']) : null;
            $grants = $this->applyGrants($user, PlayerProfileController::parseGrants($gift['reward_data']));
            $this->pdo->prepare('UPDATE gift_code_redemptions SET granted_values=?,value_granted_at=? WHERE id=?')
                ->execute([json_encode($rewards, JSON_THROW_ON_ERROR), $rewards ? $now : null, $redemption]);
            return self::result($gift['reward_data'], $grants, $rewards, $state, false);
        });
    }
    private function applyGrants(string $user, array $requested): array
    {
        $grants = PlayerProfileController::existingCosmeticIds($this->pdo, $requested);
        if (!$grants) return [];
        $query = $this->pdo->prepare('SELECT unlocks FROM users WHERE id=?' . ($this->mysql ? ' FOR UPDATE' : '')); $query->execute([$user]);
        $owned = PlayerProfileController::decodeList($query->fetch()['unlocks']); $next = $owned;
        foreach ($grants as $key) if (!in_array($key, $next, true)) $next[] = $key;
        if (count($next) > PlayerProfileController::MAX_UNLOCKS) throw new GiftCodesFailure('A player can hold at most ' . PlayerProfileController::MAX_UNLOCKS . ' unlocks', 'UNLOCK_LIMIT_REACHED', 409);
        if ($next !== $owned) $this->pdo->prepare('UPDATE users SET unlocks=? WHERE id=?')->execute([json_encode($next, JSON_THROW_ON_ERROR), $user]);
        return $grants;
    }
    private static function result(?string $data, array $unlocks, array $values, ?array $state, bool $replayed): array
    { return ['success' => true, 'message' => 'Gift code redeemed successfully', 'giftData' => $data, 'grantedUnlocks' => $unlocks,
        'grantedValues' => $values, 'playerValues' => $state, 'replayed' => $replayed]; }

    /** Local operator only; partial values update never writes current_redemptions. */
    public function configureValues(string $code, array $request): array
    {
        return $this->transaction(function () use ($code, $request) {
            $gift = $this->code($code, true);
            if (array_key_exists('values', $request)) {
                $values = self::rewardValues($request['values']);
                if ($values) {
                    $definitions = array_column($this->values()->config(false, true)['definitions'], null, 'key');
                    foreach ($values as $value) if (!isset($definitions[$value['key']]) || $value['amount'] > $definitions[$value['key']]['maxBalance'])
                        throw new GiftCodesFailure('Gift value requires a configured key and valid maximum amount', 'INVALID_REQUEST', 400);
                }
                $this->pdo->prepare('UPDATE gift_codes SET values_json=? WHERE id=?')->execute([json_encode($values, JSON_THROW_ON_ERROR), $gift['id']]);
                $gift['values_json'] = json_encode($values, JSON_THROW_ON_ERROR);
            }
            return ['id' => $gift['id'], 'code' => $gift['code'], 'values' => self::storedValues($gift), 'currentRedemptions' => (int)$gift['current_redemptions']];
        });
    }
}
