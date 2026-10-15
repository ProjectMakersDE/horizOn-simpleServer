<?php

declare(strict_types=1);
require_once __DIR__ . '/StatisticsRules.php';

/** One state row per player. Config/player/value writes share the caller's PDO transaction. */
class StatisticsService
{
    private PDO $pdo;
    private bool $mysql;
    private $clock;
    public function __construct(PDO $pdo, ?callable $clock = null)
    {
        if (PHP_INT_SIZE < 8) throw new RuntimeException('Statistics requires 64-bit PHP integers');
        $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->clock = $clock ?? static function () { return (int)floor(microtime(true) * 1000); };
        if (!$this->mysql) $pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function transaction(callable $work)
    {
        $owner = !$this->pdo->inTransaction(); $began = false;
        try {
            if ($owner) { if ($this->mysql) $this->pdo->beginTransaction(); else $this->pdo->exec('BEGIN IMMEDIATE'); $began = true; }
            $result = $work();
            if ($owner) { if ($this->mysql) $this->pdo->commit(); else $this->pdo->exec('COMMIT'); }
            return $result;
        } catch (Throwable $error) {
            if ($owner && $began) { try { if ($this->mysql) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); } else $this->pdo->exec('ROLLBACK'); } catch (Throwable $ignored) {} }
            if ($error instanceof PDOException && in_array((int)($error->errorInfo[1] ?? 0), [5, 6, 1205, 1213], true)) StatisticsRules::fail('STATE_CONFLICT', 'Concurrent write; retry the original operationId', 409);
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
        $lock = $this->mysql ? ($exclusive ? ' FOR UPDATE' : ($shared ? ' LOCK IN SHARE MODE' : '')) : '';
        $row = $this->pdo->query('SELECT version, definitions FROM statistics_catalog WHERE id = 1' . $lock)->fetch();
        if ($row === false) throw new RuntimeException('Statistics catalog schema is not initialized');
        return ['version' => (int)$row['version'], 'definitions' => json_decode($row['definitions'], true, 512, JSON_THROW_ON_ERROR)];
    }
    /** Local operator API only. Catalog changes derive generations; HTTP exposes no admin writes. */
    public function configure(array $definitions, int $expectedVersion): array
    {
        return $this->transaction(function () use ($definitions, $expectedVersion) {
            $previous = $this->config(true);
            if ($previous['version'] !== $expectedVersion) StatisticsRules::fail('REVISION_CONFLICT', 'Reload the catalog before editing', 409);
            $next = StatisticsRules::configure($definitions, $previous);
            $write = $this->pdo->prepare('UPDATE statistics_catalog SET version = ?, definitions = ? WHERE id = 1 AND version = ?');
            $write->execute([$next['version'], json_encode($next['definitions'], JSON_THROW_ON_ERROR), $expectedVersion]);
            if ($write->rowCount() !== 1) StatisticsRules::fail('REVISION_CONFLICT', 'Catalog changed', 409);
            return StatisticsRules::definitions($next);
        });
    }
    private function state(string $user, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT data FROM statistics_state WHERE user_id = ?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$user]); $row = $query->fetch();
        return $row === false ? StatisticsRules::emptyState() : json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    public function definitions(?int $known = null): array { return StatisticsRules::definitions($this->config(), $known); }
    public function get(string $user, ?int $known = null): array
    {
        $this->player($user); $catalog = $this->config(); $state = $this->state($user);
        // A catalog read preceding a newer player write must never project an older generation.
        if ($state['catalogVersion'] > $catalog['version']) $catalog = $this->config();
        return StatisticsRules::view($user, $state, $catalog, ($this->clock)(), $known);
    }
    public function update(string $user, string $operation, array $updates): array
    {
        // Syntax is checked before opening a write transaction, without policy-dependent rejection.
        StatisticsRules::operation($operation); StatisticsRules::updates($updates);
        return $this->transaction(function () use ($user, $operation, $updates) {
            // Locking the existing user also serializes initialization of an absent state row.
            $this->player($user, true); $catalog = $this->config(false, true); $state = $this->state($user, true);
            [$changed, $next, $view] = StatisticsRules::apply($user, $state, $catalog, $operation, $updates, ($this->clock)());
            if ($changed) {
                $sql = $this->mysql ? 'INSERT INTO statistics_state(user_id, data) VALUES (?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data)'
                    : 'INSERT INTO statistics_state(user_id, data) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET data = excluded.data';
                $this->pdo->prepare($sql)->execute([$user, json_encode($next, JSON_THROW_ON_ERROR)]);
            }
            return $view;
        });
    }
}
