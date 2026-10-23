<?php

declare(strict_types=1);
require_once __DIR__ . '/StatisticsRules.php';
require_once __DIR__ . '/StatisticsStorage.php';
require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';

/** One state row per player. Config/player/value writes share the caller's PDO transaction. */
class StatisticsService
{
    private PDO $pdo;
    private bool $mysql;
    private $clock;
    private SqlTransaction $transactions;
    private int $maxDefinitions;
    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null, ?int $maxDefinitions = null)
    {
        if (PHP_INT_SIZE < 8) throw new RuntimeException('Statistics requires 64-bit PHP integers');
        $this->maxDefinitions = $maxDefinitions ?? Config::operatorLimit('STATISTICS_DEFINITIONS', 100);
        if ($this->maxDefinitions < 1 || $this->maxDefinitions > 2147483647) throw new InvalidArgumentException('Invalid statistics definition limit');
        $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->clock = $clock ?? static function () { return (int)floor(microtime(true) * 1000); };
        $this->transactions = $transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Transaction scope belongs to another connection');
        if (!$this->mysql) $pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function transaction(callable $work)
    {
        try { return $this->transactions->run($work); }
        catch (SqlTransactionUncertain $error) { StatisticsRules::fail('STATE_CONFLICT', 'Commit uncertain; retry the original operationId', 409); }
        catch (SqlTransactionRollbackOnly $error) { StatisticsRules::fail('STATE_CONFLICT', 'Nested operation failed; retry the original operationId', 409); }
        catch (PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [5,6,1205,1213,2006,2013], true)) StatisticsRules::fail('STATE_CONFLICT', 'Concurrent write; retry the original operationId', 409);
            throw $error;
        }
    }
    private function player(string $user, bool $lock = false): void
    {
        $query = $this->pdo->prepare('SELECT id FROM users WHERE id = ?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$user]); if ($query->fetch() === false) StatisticsRules::fail('PLAYER_NOT_FOUND', 'Player not found', 404);
    }
    public function config(bool $exclusive = false, bool $shared = false): array
    {
        $lock = $this->mysql ? ($exclusive ? ' FOR UPDATE' : (($shared || $this->pdo->inTransaction()) ? ' LOCK IN SHARE MODE' : '')) : '';
        $row = $this->pdo->query('SELECT version, definitions FROM statistics_catalog WHERE id = 1' . $lock)->fetch();
        if ($row === false) throw new RuntimeException('Statistics catalog schema is not initialized');
        return ['version' => (int)$row['version'], 'definitions' => StatisticsStorage::slots(json_decode($row['definitions'], true, 512, JSON_THROW_ON_ERROR))];
    }
    /** Local operator API only. Catalog changes derive generations; HTTP exposes no admin writes. */
    public function configure(array $definitions, int $expectedVersion): array
    {
        return $this->transaction(function () use ($definitions, $expectedVersion) {
            $previous = $this->config(true);
            if ($previous['version'] !== $expectedVersion) StatisticsRules::fail('REVISION_CONFLICT', 'Reload the catalog before editing', 409);
            if (count($definitions) > $this->maxDefinitions) StatisticsRules::fail('STATISTIC_LIMIT_REACHED', 'Configured definition limit reached', 403);
            $next = StatisticsRules::configure($definitions, $previous);
            $next['definitions'] = StatisticsStorage::slots($next['definitions']);
            $write = $this->pdo->prepare('UPDATE statistics_catalog SET version = ?, definitions = ? WHERE id = 1 AND version = ?');
            $write->execute([$next['version'], json_encode($next['definitions'], JSON_THROW_ON_ERROR), $expectedVersion]);
            if ($write->rowCount() !== 1) StatisticsRules::fail('REVISION_CONFLICT', 'Catalog changed', 409);
            return StatisticsRules::definitions($next);
        });
    }
    private function state(string $user, array $catalog, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT data FROM statistics_state WHERE user_id = ?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$user]); $row = $query->fetch();
        return $row === false ? StatisticsRules::emptyState() : StatisticsStorage::decode($row['data'], $catalog);
    }
    public function definitions(?int $known = null): array { return StatisticsRules::definitions($this->config(), $known); }
    public function get(string $user, ?int $known = null): array
    {
        $this->player($user); $catalog = $this->config();
        try {
            $state = $this->state($user, $catalog);
            return StatisticsRules::view($user, $state, $catalog, ($this->clock)(), $known);
        } catch (StatisticsFailure $failure) {
            if ($failure->errorCode !== 'STATE_CONFLICT') throw $failure;
            $catalog = $this->config(false, true); $state = $this->state($user, $catalog);
            return StatisticsRules::view($user, $state, $catalog, ($this->clock)(), $known);
        }
    }
    public function update(string $user, string $operation, array $updates): array
    {
        // Syntax is checked before opening a write transaction, without policy-dependent rejection.
        StatisticsRules::operation($operation); StatisticsRules::updates($updates);
        return $this->transaction(function () use ($user, $operation, $updates) {
            // Locking the existing user also serializes initialization of an absent state row.
            $this->player($user, true); $catalog = $this->config(false, true); $state = $this->state($user, $catalog, true);
            [$changed, $next, $view] = StatisticsRules::apply($user, $state, $catalog, $operation, $updates, ($this->clock)());
            if ($changed) {
                $sql = $this->mysql ? 'INSERT INTO statistics_state(user_id, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)'
                    : 'INSERT INTO statistics_state(user_id, data) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET data = excluded.data';
                $this->pdo->prepare($sql)->execute([$user, StatisticsStorage::encode($next, $catalog['definitions'])]);
            }
            return $view;
        });
    }
    /** Offline operator page after a checked backup, with old writers stopped. No read/auth hook. */
    public function migrateStatePage(?string $after = null, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 50 || ($after !== null && !preg_match('/^[a-f0-9-]{36}$/D', $after))) throw new InvalidArgumentException('Invalid migration cursor/page');
        return $this->transaction(function () use ($after, $limit) {
            $catalog = $this->config(false, true);
            $q = $this->pdo->prepare('SELECT user_id,data FROM statistics_state' . ($after === null ? '' : ' WHERE user_id > ?') . ' ORDER BY user_id LIMIT ' . $limit . ($this->mysql ? ' FOR UPDATE' : ''));
            $q->execute($after === null ? [] : [$after]); $rows = $q->fetchAll(); $q->closeCursor(); $changed = 0; $last = $after;
            foreach ($rows as $row) {
                $last = $row['user_id']; $original = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                $state = StatisticsStorage::decode($row['data'], $catalog);
                if (array_key_exists('s', $original)) continue;
                if ($state['catalogVersion'] > $catalog['version']) StatisticsRules::fail('STATE_CONFLICT', 'Stored catalog is newer', 409);
                $compact = StatisticsStorage::encode($state, $catalog['definitions']);
                $write = $this->pdo->prepare('UPDATE statistics_state SET data = ? WHERE user_id = ? AND data = ?');
                $write->execute([$compact, $row['user_id'], $row['data']]);
                if ($write->rowCount() !== 1) StatisticsRules::fail('STATE_CONFLICT', 'Original state changed', 409);
                $changed++;
            }
            return ['converted'=>$changed,'examined'=>count($rows),'after'=>$last,'complete'=>count($rows)<$limit];
        });
    }

}
