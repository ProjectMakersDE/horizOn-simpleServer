<?php

declare(strict_types=1);
require_once __DIR__ . '/PlayerValuesLedger.php';
require_once __DIR__ . '/PlayerValuesStorage.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';
require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Items/ItemService.php';

/**
 * One installation is one project. No cloud-save or user-profile blobs are copied.
 * A mutation reads small config + one player row (+ one offer), then writes one player row.
 * SQLite serializes writers; MySQL locks only the target user row, including first initialization.
 */
class PlayerValuesService
{
    private PDO $pdo;
    private bool $mysql;
    private $clock;
    private SqlTransaction $transactions;
    private array $storageKeys = [];
    private array $limits;

    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null, array $limits = [])
    {
        $this->pdo = $pdo;
        $this->limits = array_replace([
            'definitions'=>Config::operatorLimit('PLAYER_VALUES_DEFINITIONS', 64, 256),
            'offers'=>Config::operatorLimit('PLAYER_VALUES_OFFERS', 1000),
            'receipts'=>Config::operatorLimit('PLAYER_VALUES_RECEIPTS', 50),
        ], $limits);
        foreach ($this->limits as $name=>$limit) if (!in_array($name,['definitions','offers','receipts'],true) || !is_int($limit) || $limit<1 || $limit>2147483647) throw new InvalidArgumentException('Invalid operator limit');
        if ($this->limits['definitions']>256) throw new InvalidArgumentException('The stable value-slot format supports 256 lifetime keys');
        $this->transactions = $transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException("Transaction scope belongs to another connection");
        $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->clock = $clock ?? static function () { return (int)floor(microtime(true) * 1000); };
        if (!$this->mysql) $pdo->exec('PRAGMA busy_timeout=5000');
    }

    private function transaction(callable $work)
    {
        try { return $this->transactions->run($work); }
        catch (SqlTransactionUncertain $error) { PlayerValuesLedger::fail('STATE_CONFLICT', 'Commit result is uncertain; retry the original action', 409); }
        catch (SqlTransactionRollbackOnly $error) { PlayerValuesLedger::fail('STATE_CONFLICT', 'A nested feature operation failed', 409); }
        catch (PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [5, 6, 1205, 1213, 2006, 2013], true)) PlayerValuesLedger::fail('STATE_CONFLICT', 'Concurrent write; retry with the same operationId', 409);
            throw $error;
        }
    }

    private function player(string $userId, bool $lock = false): void
    {
        $query = $this->pdo->prepare('SELECT id FROM users WHERE id = ?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$userId]);
        if ($query->fetch() === false) PlayerValuesLedger::fail('PLAYER_NOT_FOUND', 'Player not found', 404);
    }

    public function config(bool $offers = true, bool $current = false): array
    {
        // A locking player/state read must use the same current catalogue, even when
        // the caller's outer MySQL scope already established an older RR snapshot.
        // Read providers share the current catalogue lock; explicit configuration owns it exclusively.
        $lock = $this->mysql && ($current || $this->pdo->inTransaction()) ? ($current ? ' FOR UPDATE' : ' LOCK IN SHARE MODE') : '';
        $row = $this->pdo->query('SELECT revision, definitions, known_keys FROM player_values_config WHERE id = 1' . $lock)->fetch();
        if ($row === false) throw new RuntimeException('Player-values schema is not initialized');
        $config = ['revision' => (int)$row['revision'], 'definitions' => json_decode($row['definitions'], true, 512, JSON_THROW_ON_ERROR),
            'knownKeys' => json_decode($row['known_keys'], true, 512, JSON_THROW_ON_ERROR)];
        $this->storageKeys = $config['knownKeys'];
        if ($offers) {
            $config['offers'] = array_map(static function ($row) { $offer = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR); $offer['itemRewards'] = $offer['itemRewards'] ?? []; $offer['purchasesPerDay'] = null; return $offer; },
                $this->pdo->query('SELECT data FROM player_value_offers ORDER BY offer_key' . $lock)->fetchAll());
            $config['limits'] = $this->limits;
        }
        return $config;
    }

    /** Internal atomic marketplace settlement; source listing transition owns replay protection. */
    public function transferEscrow(string $buyer,string $seller,string $key,int $price,int $net,string $listing,int $issued): void
    {
        if (!$this->transactions->isRunning()) throw new LogicException('Settlement requires the owning SQL scope');
        $ids=[$buyer,$seller]; sort($ids,SORT_STRING);
        $actors=$this->pdo->prepare('SELECT id FROM users WHERE id IN (?,?) ORDER BY id'.($this->mysql?' FOR UPDATE':''));$actors->execute($ids);
        if(count($actors->fetchAll(PDO::FETCH_COLUMN))!==2)PlayerValuesLedger::fail('PLAYER_NOT_FOUND','Player not found',404);
        PlayerValuesLedger::key($key); PlayerValuesLedger::integer($price,1); PlayerValuesLedger::integer($net,0,$price);
        $config=$this->config(false,true); $definitions=array_column($config['definitions'],null,'key');
        if (!isset($definitions[$key])) PlayerValuesLedger::fail('INVALID_REQUEST','Currency requires a saved Player Values definition',400);
        $now=($this->clock)(); $debit=PlayerValuesLedger::initialize($this->state($buyer),$config['definitions'],$now);
        $credit=$net>0?PlayerValuesLedger::initialize($this->state($seller),$config['definitions'],$now):null;
        if (($debit['values'][$key]??0)<$price) PlayerValuesLedger::fail('INSUFFICIENT_BALANCE','Insufficient balance');
        $debit['values'][$key]-=$price;
        if ($net>0) [$credit,$ignored]=PlayerValuesLedger::grant($credit,$definitions,[['key'=>$key,'amount'=>$net]]);
        foreach ([[$buyer,$debit,-$price],[$seller,$credit,$net]] as [$user,$state,$amount]) {
            if ($amount===0) continue;
            $this->save($user,PlayerValuesLedger::advance($state));
        }
    }

    /** Local operator only. Expected revision is mandatory, including initial revision zero. */
    public function configure(array $request, int $expectedRevision): array
    {
        $next = PlayerValuesLedger::config($request, $this->limits['definitions'], $this->limits['offers']);
        return $this->transaction(function () use ($next, $expectedRevision) {
            $current = $this->config(false, true);
            if ($current['revision'] !== $expectedRevision) PlayerValuesLedger::fail('REVISION_CONFLICT', 'Configuration changed; reload it', 409);
            $known = array_values(array_unique(array_merge($current['knownKeys'], array_column($next['definitions'], 'key'))));
            if (count($known) > 256) PlayerValuesLedger::fail('INVALID_REQUEST', 'Project lifetime value-key limit reached', 400);
            $revision = PlayerValuesLedger::integer($expectedRevision, 0, PlayerValuesLedger::MAX - 1) + 1;
            $this->pdo->prepare('UPDATE player_values_config SET revision = ?, definitions = ?, known_keys = ? WHERE id = 1')
                ->execute([$revision, json_encode($next['definitions'], JSON_THROW_ON_ERROR), json_encode($known, JSON_THROW_ON_ERROR)]);
            $this->pdo->exec('DELETE FROM player_value_offers');
            $insert = $this->pdo->prepare('INSERT INTO player_value_offers(offer_key, data) VALUES (?, ?)');
            foreach ($next['offers'] as $offer) $insert->execute([$offer['key'], json_encode($offer, JSON_THROW_ON_ERROR)]);
            return $this->config();
        });
    }

    private function state(string $userId): array
    {
        // A caller's outer MySQL transaction may already have a repeatable-read snapshot.
        // The user-row lock prevents concurrent writes but does not refresh that snapshot.
        // Read current state under a locking read before any replacement or internal grant.
        $query = $this->pdo->prepare('SELECT data FROM player_values WHERE user_id = ?' .
            ($this->mysql && $this->pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $query->execute([$userId]);
        $row = $query->fetch();
        return $row === false ? PlayerValuesLedger::emptyState() : PlayerValuesStorage::decode($row['data'], $this->storageKeys);
    }

    private function save(string $userId, array $state): void
    {
        $sql = $this->mysql
            ? 'INSERT INTO player_values(user_id, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)'
            : 'INSERT INTO player_values(user_id, data) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET data = excluded.data';
        $this->pdo->prepare($sql)->execute([$userId, PlayerValuesStorage::encode($state, $this->storageKeys)]);
    }

    public function get(string $userId): array
    {
        $this->player($userId);
        $config = $this->config(false); $state = $this->state($userId); $now = ($this->clock)();
        $next = PlayerValuesLedger::initialize($state, $config['definitions'], $now);
        if ($next !== $state) {
            return $this->transaction(function () use ($userId) {
                $this->player($userId, true);
                $config = $this->config(false); $state = $this->state($userId); $now = ($this->clock)();
                $next = PlayerValuesLedger::initialize($state, $config['definitions'], $now);
                if ($next !== $state) {
                    $next['revision'] = PlayerValuesLedger::integer($state['revision'], 0, PlayerValuesLedger::MAX - 1) + 1;
                    $this->save($userId, $next);
                }
                return PlayerValuesLedger::view($userId, $config, $next, $now);
            });
        }
        return PlayerValuesLedger::view($userId, $config, $state, $now);
    }

    public function offers(string $userId): array
    {
        // One short read transaction gives catalogue and state the same configuration snapshot.
        return $this->transaction(function () use ($userId) {
            $this->player($userId, true);
            $state = $this->get($userId); $config = $this->config();
            return ['definitionVersion' => $config['revision'], 'serverTime' => $state['operationWindow']['serverTime'],
                'definitions' => $state['values'], 'offers' => array_values(array_filter($config['offers'], static function ($offer) { return $offer['active']; }))];
        });
    }

    public function purchase(string $userId, string $offerKey, string $operationId): array
    {
        return $this->mutate($userId, $operationId, PlayerValuesLedger::issuedAt($operationId),
            PlayerValuesLedger::hash('purchase', $userId, $offerKey), 'offer:' . $offerKey, null,
            function ($state, $definitions) use ($offerKey, $userId, $operationId) {
                $query = $this->pdo->prepare('SELECT data FROM player_value_offers WHERE offer_key = ?' . ($this->mysql ? ' LOCK IN SHARE MODE' : '')); $query->execute([$offerKey]);
                $row = $query->fetch(); $offer = $row === false ? null : json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                [$next, $applied] = PlayerValuesLedger::purchase($state, $definitions, $offer);
                $items = [];
                if (!empty($offer['itemRewards'])) {
                    $provider = new ItemService($this->pdo, $this->clock, $this->transactions);
                    $grant = $provider->grant($userId, $offer['itemRewards'], $operationId, PlayerValuesLedger::issuedAt($operationId), 'offer:' . $offerKey);
                    $items = $grant['items'];
                }
                return [$next, $applied, $items];
            });
    }

    public function adjust(string $userId, string $key, int $amount, string $reason, string $operationId): array
    {
        return $this->mutate($userId, $operationId, PlayerValuesLedger::issuedAt($operationId),
            PlayerValuesLedger::hash('adjust', $userId, $key, (string)$amount, $reason), 'adjust', $reason,
            static function ($state, $definitions) use ($key, $amount, $reason) {
                PlayerValuesLedger::text($reason, 200, 'reason');
                if (!isset($definitions[$key])) PlayerValuesLedger::fail('INVALID_REQUEST', 'Unknown value', 400);
                return PlayerValuesLedger::adjust($state, $definitions[$key], $amount);
            });
    }

    /** Server-only grant. The caller persists issuedAt with its source and owns any outer transaction. */
    public function grant(string $userId, array $values, string $operationId, int $issuedAt, string $source, bool $sourceOwned = false): array
    {
        if ($sourceOwned && !$this->transactions->isRunning()) throw new LogicException('Source-owned credit requires the existing SQL transaction');
        PlayerValuesLedger::text($operationId, 128, 'operationId'); PlayerValuesLedger::text($source, 200, 'source');
        $values = PlayerValuesLedger::list($values, 256);
        // Validate before sorting/hashing, keeping malformed server input from coercing JSON numbers.
        foreach ($values as $value) {
            if (!is_array($value)) PlayerValuesLedger::fail('INVALID_REQUEST', 'Invalid grant', 400);
            PlayerValuesLedger::key($value['key'] ?? null); PlayerValuesLedger::integer($value['amount'] ?? null, 1);
        }
        usort($values, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
        $parts = ['grant', $source, (string)$issuedAt];
        foreach ($values as $value) { $parts[] = $value['key']; $parts[] = (string)$value['amount']; }
        return $this->mutate($userId, $operationId, $issuedAt, $sourceOwned ? '' : PlayerValuesLedger::hash(...$parts), $source, null,
            static function ($state, $definitions) use ($values) { return PlayerValuesLedger::grant($state, $definitions, $values); }, $sourceOwned);
    }

    /** The caller commits its own durable guard in this same connection-bound transaction. */
    public function grantFromSource(string $userId, array $values, string $operationId, int $issuedAt, string $source): array
    { return $this->grant($userId,$values,$operationId,$issuedAt,$source,true); }

    /** Local operator only. Absolute correction, bounded receipt, stable ID and mandatory note. */
    public function correct(string $userId, array $values, string $note, string $operationId, string $actor = 'local-operator'): array
    {
        $values = PlayerValuesLedger::list($values, 256);
        if (!$values) PlayerValuesLedger::fail('INVALID_REQUEST', 'Correction requires values', 400);
        $seen = [];
        foreach ($values as $value) {
            if (!is_array($value)) PlayerValuesLedger::fail('INVALID_REQUEST', 'Invalid correction', 400);
            $key = PlayerValuesLedger::key($value['key'] ?? null); PlayerValuesLedger::integer($value['balance'] ?? null);
            if (isset($seen[$key])) PlayerValuesLedger::fail('INVALID_REQUEST', 'Duplicate correction key', 400);
            $seen[$key] = true;
        }
        usort($values, static function ($a, $b) { return strcmp($a['key'], $b['key']); });
        $parts = ['correct', $actor, $note];
        foreach ($values as $value) { $parts[] = $value['key']; $parts[] = (string)$value['balance']; }
        return $this->mutate($userId, $operationId, PlayerValuesLedger::issuedAt($operationId),
            PlayerValuesLedger::hash(...$parts), 'admin:' . $actor, $note,
            static function ($state, $definitions) use ($values, $note) {
                PlayerValuesLedger::text($note, 200, 'note'); $applied = [];
                foreach ($values as $value) {
                    $key = $value['key'];
                    if (!isset($definitions[$key]) || $value['balance'] > $definitions[$key]['maxBalance']) PlayerValuesLedger::fail('INVALID_REQUEST', 'Invalid correction balance', 400);
                    $applied[] = ['key' => $key, 'amount' => $value['balance'] - ($state['values'][$key] ?? 0)];
                    $state['values'][$key] = $value['balance'];
                }
                return [$state, $applied];
            });
    }

    public function history(string $userId): array
    {
        $view = $this->get($userId);
        return ['state' => $view, 'receipts' => []];
    }

    private function mutate(string $userId, string $operationId, int $issuedAt, string $hash, string $source, ?string $note, callable $change, bool $sourceOwned = false): array
    {
        return $this->transaction(function () use ($userId, $operationId, $issuedAt, $hash, $source, $note, $change, $sourceOwned) {
            $this->player($userId, true);
            $config = $this->config(false); $state = $this->state($userId); $now = ($this->clock)();
            try {
                $receipt = $sourceOwned ? null : PlayerValuesLedger::receipt($state, $operationId, $hash);
                if ($receipt !== null) return PlayerValuesLedger::view($userId, $config, $state, $now,
                    ['operationId' => $operationId, 'replayed' => true, 'applied' => [], 'items' => []]);
                if (!$sourceOwned) PlayerValuesLedger::checkWindow($state, $issuedAt, $now);
                $initialized = PlayerValuesLedger::initialize($state, $config['definitions'], $now);
                $changed = $change($initialized, array_column($config['definitions'], null, 'key'));
                [$next, $applied] = $changed; $items = $changed[2] ?? [];
                $next = $sourceOwned ? PlayerValuesLedger::advance($next) : PlayerValuesLedger::record($next,
                    ['operationId'=>$operationId, 'payloadHash'=>$hash, 'issuedAtMillis'=>$issuedAt], $now, $this->limits['receipts']);
                $this->save($userId, $next);
                return PlayerValuesLedger::view($userId, $config, $next, $now, ['operationId' => $operationId, 'replayed' => false, 'applied' => $applied, 'items' => $items]);
            } catch (PlayerValuesFailure $failure) {
                $failure->state = PlayerValuesLedger::view($userId, $config, $state, $now);
                throw $failure;
            }
        });
    }
}
