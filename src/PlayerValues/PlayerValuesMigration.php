<?php

declare(strict_types=1);
require_once __DIR__ . '/PlayerValuesStorage.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';

/** Explicit bounded operator migration. Never called by login, schema bootstrap or a feature read. */
class PlayerValuesMigration
{
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    public function page(string $after = '', int $limit = 100, bool $apply = false): array
    {
        if ($after !== '' && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $after)) {
            throw new InvalidArgumentException('Expected canonical player cursor');
        }
        if ($limit < 1 || $limit > 100) throw new InvalidArgumentException('Page size must be 1..100');
        return (new SqlTransaction($this->pdo))->run(function () use ($after, $limit, $apply) {
            $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $lock = $apply && $mysql ? ' FOR UPDATE' : '';
            $config = $this->pdo->query('SELECT known_keys FROM player_values_config WHERE id = 1' . $lock)->fetchColumn();
            if ($config === false) throw new RuntimeException('Player-values schema is not initialized');
            $keys = json_decode($config, true, 512, JSON_THROW_ON_ERROR);
            $query = $this->pdo->prepare('SELECT user_id, data FROM player_values WHERE user_id > ? ORDER BY user_id LIMIT ' . $limit . $lock);
            $query->execute([$after]); $rows = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
            $result = ['scanned'=>count($rows), 'changed'=>0, 'beforeBytes'=>0, 'afterBytes'=>0, 'after'=>$after, 'applied'=>$apply];
            foreach ($rows as $row) {
                $state = PlayerValuesStorage::decode($row['data'], $keys);
                $next = PlayerValuesStorage::encode($state, $keys);
                $result['beforeBytes'] += strlen($row['data']); $result['afterBytes'] += strlen($next);
                if ($next !== $row['data']) {
                    if ($apply) {
                        $equals = $mysql ? 'BINARY data = BINARY ?' : 'data = ? COLLATE BINARY';
                        $update = $this->pdo->prepare('UPDATE player_values SET data = ? WHERE user_id = ? AND ' . $equals);
                        $update->execute([$next, $row['user_id'], $row['data']]);
                        if ($update->rowCount() !== 1) PlayerValuesLedger::fail('STATE_CONFLICT', 'Source changed during migration', 409);
                    }
                    $result['changed']++;
                }
                $result['after'] = $row['user_id'];
            }
            return $result;
        });
    }
}
