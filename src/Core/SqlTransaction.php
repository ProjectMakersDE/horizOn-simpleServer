<?php

declare(strict_types=1);

class SqlTransactionRollbackOnly extends RuntimeException {}
class SqlTransactionUncertain extends RuntimeException {}

/** Explicit connection-bound scope shared by cooperating feature services, never a global manager. */
class SqlTransaction
{
    private PDO $pdo;
    private bool $mysql;
    private int $depth = 0;
    private bool $rollbackOnly = false;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
    public function owns(PDO $pdo): bool { return $this->pdo === $pdo; }
    public function run(callable $work)
    {
        if ($this->depth > 0) {
            $this->depth++;
            try { return $work(); }
            catch (Throwable $error) { $this->rollbackOnly = true; throw $error; }
            finally { $this->depth--; }
        }
        $owner = !$this->pdo->inTransaction(); $started = false; $committing = false;
        $this->depth = 1; $this->rollbackOnly = false;
        try {
            if ($owner) {
                if ($this->mysql) $this->pdo->beginTransaction(); else $this->pdo->exec('BEGIN IMMEDIATE');
                $started = true;
            }
            $result = $work();
            if ($this->rollbackOnly) throw new SqlTransactionRollbackOnly('A nested feature operation failed');
            if ($owner) {
                $committing = true;
                if ($this->mysql) $this->pdo->commit(); else $this->pdo->exec('COMMIT');
                $started = false; $committing = false;
            }
            return $result;
        } catch (Throwable $error) {
            if ($owner && $started) {
                try { if ($this->mysql) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); } else $this->pdo->exec('ROLLBACK'); }
                catch (Throwable $ignored) {}
            }
            if ($committing) throw new SqlTransactionUncertain('Commit result is uncertain; retry the original action', 0, $error);
            throw $error;
        } finally { $this->depth = 0; $this->rollbackOnly = false; }
    }
}
