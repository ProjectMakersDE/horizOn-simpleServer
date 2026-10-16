<?php

declare(strict_types=1);
require_once __DIR__ . '/InboxRules.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';
require_once __DIR__ . '/../PlayerValues/PlayerValuesService.php';

/** One installation/project, one bounded catalogue and one row per interacting player. */
class InboxService
{
    private PDO $pdo;
    private bool $mysql;
    private $clock;
    private SqlTransaction $transactions;
    private PlayerValuesService $values;
    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null)
    {
        $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->clock = $clock ?? static function () { return (int)floor(microtime(true) * 1000); };
        $this->transactions = $transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Transaction scope belongs to another connection');
        $this->values = new PlayerValuesService($pdo, $this->clock, $this->transactions);
        if (!$this->mysql) $pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function transaction(callable $work)
    {
        try { return $this->transactions->run($work); }
        catch (SqlTransactionUncertain $error) { InboxRules::fail('STATE_CONFLICT', 'Commit result is uncertain; retry the original action', 409); }
        catch (SqlTransactionRollbackOnly $error) { InboxRules::fail('STATE_CONFLICT', 'Nested operation failed; retry the original entry'); }
        catch (PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [5, 6, 1205, 1213, 2006, 2013], true)) InboxRules::fail('STATE_CONFLICT', 'Concurrent write; retry the original entry');
            throw $error;
        }
    }
    private function player(string $user, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT id, created_at, created_at_millis FROM users WHERE id = ?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$user]); $row = $query->fetch();
        if ($row === false) InboxRules::fail('PLAYER_NOT_FOUND', 'Player not found', 404);
        return $row;
    }
    private function catalog(int $lock = 0): array
    {
        $suffix = $this->mysql ? ($lock === 2 ? ' FOR UPDATE' : ($lock === 1 ? ' LOCK IN SHARE MODE' : '')) : '';
        $row = $this->pdo->query('SELECT data FROM inbox_catalog WHERE id = 1' . $suffix)->fetch();
        if ($row === false) throw new RuntimeException('Inbox schema is not initialized');
        return json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    private function state(string $user, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT data FROM inbox_state WHERE user_id = ?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$user]); $row = $query->fetch();
        return $row === false ? InboxRules::emptyState() : json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    private function save(string $user, array $state): void
    {
        $sql = $this->mysql ? 'INSERT INTO inbox_state(user_id,data) VALUES (?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)'
            : 'INSERT INTO inbox_state(user_id,data) VALUES (?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data';
        $this->pdo->prepare($sql)->execute([$user, InboxRules::json($state)]);
    }
    private function saveCatalog(array $catalog): void { $this->pdo->prepare('UPDATE inbox_catalog SET data = ? WHERE id = 1')->execute([InboxRules::json($catalog)]); }
    private function entries(array $player, array $state, array $catalog, int $now): array
    {
        $entries = [];
        foreach ($state['entries'] as $message) if (InboxRules::retained($message, $now)) $entries[] = InboxRules::viewEntry($message, $now);
        $registered = $player['created_at_millis'] === null
            ? (new DateTimeImmutable($player['created_at'], new DateTimeZone('UTC')))->getTimestamp() * 1000 : (int)$player['created_at_millis'];
        $markers = array_column($state['markers'], null, 'id');
        foreach ($catalog['entries'] as $message) {
            if ($registered > InboxRules::millis($message['createdAt']) || !InboxRules::retained($message, $now)) continue;
            $marker = $markers[$message['id']] ?? null;
            if ($marker && ($marker['removed'] || ($marker['claimed'] && InboxRules::millis($message['content']['expiresAt']) <= $now))) continue;
            $entries[] = InboxRules::viewEntry($message, $now, $marker);
        }
        usort($entries, static function ($a, $b) { return strcmp($b['createdAt'], $a['createdAt']) ?: strcmp($a['id'], $b['id']); });
        return $entries;
    }
    private function prune(array $state, array $catalog, int $now): array
    {
        $state['entries'] = array_values(array_filter($state['entries'], static function ($row) use ($now) { return InboxRules::retained($row, $now); }));
        $retained = []; foreach ($catalog['entries'] as $message) if (InboxRules::retained($message, $now)) $retained[$message['id']] = true;
        // Reward broadcasts never leave the retained catalogue, so their markers remain pinned.
        $state['markers'] = array_values(array_filter($state['markers'], static function ($marker) use ($retained) { return isset($retained[$marker['id']]); }));
        return $state;
    }
    private function view(array $player, array $state, array $catalog, int $now, ?string $since = null): array
    {
        $entries = $this->entries($player, $state, $catalog, $now); $lastExpiry = 0; $counts = [];
        foreach (array_merge($state['entries'], $catalog['entries']) as $message) { $expiry = InboxRules::millis($message['content']['expiresAt']); if ($expiry <= $now) $lastExpiry = max($lastExpiry, $expiry); }
        foreach ($entries as &$entry) {
            $entry['parameters'] = (object)$entry['parameters'];
            $type = $entry['type']; if (!isset($counts[$type])) $counts[$type] = ['total' => 0, 'unread' => 0, 'claimable' => 0];
            $counts[$type]['total']++; if (!$entry['read']) $counts[$type]['unread']++;
            if (!$entry['claimed'] && InboxRules::attachmentValuable($entry['attachment'])) $counts[$type]['claimable']++;
        }
        unset($entry);
        $cursor = hash('sha256', implode(':', [$catalog['apiKeyId'], $player['id'], $state['revision'], $catalog['revision'], $lastExpiry]));
        return ['userId' => $player['id'], 'cursor' => $cursor, 'revision' => $state['revision'], 'broadcastRevision' => $catalog['revision'],
            'serverTime' => InboxRules::instant($now), 'pollAfterSeconds' => 120, 'unchanged' => $cursor === $since,
            'entries' => $cursor === $since ? [] : $entries, 'counts' => (object)$counts,
            'limits' => ['targeted' => InboxRules::TARGETED, 'broadcasts' => InboxRules::BROADCASTS]];
    }
    public function get(string $user, ?string $since = null): array
    {
        if ($since !== null && (!preg_match('/^[A-Za-z0-9:_-]{0,128}$/D', $since))) InboxRules::invalid('Invalid since cursor');
        $player = $this->player($user); $catalog = $this->catalog(); $state = $this->state($user);
        return $this->view($player, $state, $catalog, ($this->clock)(), $since);
    }
    public function markRead(string $user, string $entry): array { return $this->change($user, InboxRules::entry($entry), false); }
    public function remove(string $user, string $entry): array { return $this->change($user, InboxRules::entry($entry), true); }
    private function change(string $user, string $entry, bool $remove): array
    {
        return $this->transaction(function () use ($user, $entry, $remove) {
            $player = $this->player($user, true); $catalog = $this->catalog(1); $state = $this->state($user, true); $now = ($this->clock)();
            $found = null; foreach ($this->entries($player, $state, $catalog, $now) as $row) if ($row['id'] === $entry) $found = $row;
            if ($found === null) { if ($remove) return $this->view($player, $state, $catalog, $now); InboxRules::fail('ENTRY_NOT_FOUND', 'Inbox entry not found', 404); }
            if ($remove && InboxRules::attachmentValuable($found['attachment']) && !$found['claimed']) InboxRules::fail('UNCLAIMED_REWARD', 'Claim this reward before removing it');
            if (!$remove && $found['read']) return $this->view($player, $state, $catalog, $now);
            $next = $this->prune($state, $catalog, $now);
            if ($found['kind'] === 'message') {
                $next['entries'] = array_values(array_filter($next['entries'], static function ($row) use ($entry, $remove) { return !$remove || $row['id'] !== $entry; }));
                if (!$remove) foreach ($next['entries'] as &$row) if ($row['id'] === $entry) $row['read'] = true;
                unset($row);
            } else {
                $old = null; foreach ($next['markers'] as $marker) if ($marker['id'] === $entry) $old = $marker;
                $next['markers'] = array_values(array_filter($next['markers'], static function ($marker) use ($entry) { return $marker['id'] !== $entry; }));
                $next['markers'][] = ['id' => $entry, 'read' => true, 'claimed' => $old['claimed'] ?? false, 'removed' => $remove];
            }
            $next = InboxRules::bump($next); $this->save($user, $next); return $this->view($player, $next, $catalog, $now);
        });
    }
    public function claim(string $user, string $entry): array
    {
        InboxRules::entry($entry);
        return $this->transaction(function () use ($user, $entry) {
            $player = $this->player($user, true); $catalog = $this->catalog(1); $state = $this->state($user, true); $now = ($this->clock)();
            $found = null; foreach ($this->entries($player, $state, $catalog, $now) as $row) if ($row['id'] === $entry) $found = $row;
            if ($found === null) {
                foreach ($state['entries'] as $row) if ($row['id'] === $entry && $row['claimed'] && InboxRules::valuable($row)) $found = InboxRules::viewEntry($row, $now);
                $marker = null; foreach ($state['markers'] as $row) if ($row['id'] === $entry && $row['claimed'] && !$row['removed']) $marker = $row;
                if ($marker !== null) {
                    // Eligibility was established by the committed claim; registration never changes.
                    foreach ($catalog['entries'] as $row) if ($row['id'] === $entry && InboxRules::valuable($row)) $found = InboxRules::viewEntry($row, $now, $marker);
                }
            }
            if ($found === null) InboxRules::fail('ENTRY_NOT_FOUND', 'Inbox entry not found', 404);
            if (!InboxRules::attachmentValuable($found['attachment'])) InboxRules::fail('NO_ATTACHMENT', 'This entry has no reward', 422);
            $itemReturn=$found['attachment']['itemReturn']??null;
            $references=$itemReturn===null?[]:[['instanceId'=>$itemReturn['instanceId'],'itemKey'=>$itemReturn['itemKey']]];
            if ($found['claimed']) return ['entryId' => $entry, 'replayed' => true, 'inbox' => $this->view($player, $state, $catalog, $now), 'playerValues' => $this->values->get($user),'items'=>$references];
            $next = $this->prune($state, $catalog, $now);
            if ($found['kind'] === 'message') {
                foreach ($next['entries'] as &$row) if ($row['id'] === $entry) { $row['read'] = true; $row['claimed'] = true; }
                unset($row);
            } else {
                $next['markers'] = array_values(array_filter($next['markers'], static function ($row) use ($entry) { return $row['id'] !== $entry; }));
                $next['markers'][] = ['id' => $entry, 'read' => true, 'claimed' => true, 'removed' => false];
            }
            $next = InboxRules::bump($next); $this->save($user, $next);
            if ($itemReturn!==null) {
                require_once __DIR__.'/../Marketplace/MarketService.php';
                try { $references=(new MarketService($this->pdo,$this->clock,$this->transactions))->claimReturn($user,$entry,$itemReturn); }
                catch (MarketFailure $failure) { InboxRules::fail($failure->errorCode,$failure->getMessage(),$failure->status); }
                $values=$this->values->get($user);
            } else $values = $this->values->grant($user, $found['attachment']['values'], 'inbox:' . $entry, $now, 'inbox:' . $entry);
            return ['entryId' => $entry, 'replayed' => false, 'inbox' => $this->view($player, $next, $catalog, $now), 'playerValues' => $values,'items'=>$references];
        });
    }
    private function validateRewards(array $content): void
    {
        if (empty($content['attachment']['values'])) return;
        $definitions = array_column($this->values->config(false)['definitions'], null, 'key');
        foreach ($content['attachment']['values'] as $value) if (!isset($definitions[$value['key']]) || $value['amount'] > $definitions[$value['key']]['maxBalance']) InboxRules::invalid('Rewards need configured keys and must fit their maximum balances');
    }
    /** Producers persist the immutable source/operation/issued time and join source writes to this scope. */
    public function deliver(string $user, array $input, string $operation, int $issued, string $source): array
    {
        return $this->transaction(function () use ($user, $input, $operation, $issued, $source) { return $this->delivery($user, $input, $operation, $issued, $source, false); });
    }
    /** The adapter MUST conditionally consume its own pending source using this exact PDO and recipient. */
    public function deliverPending(string $user, array $input, string $operation, int $issued, string $source, callable $complete): array
    {
        return $this->transaction(function () use ($user, $input, $operation, $issued, $source, $complete) {
            $result = $this->delivery($user, $input, $operation, $issued, $source, true);
            if (!$complete($this->pdo, $result['entryId'])) InboxRules::fail('SOURCE_NOT_PENDING', 'Source is no longer pending; delivery rolled back');
            return $result;
        });
    }
    /** Marketplace's designated pending-source adapter, never public/admin composition. */
    public function deliverItemReturnPending(string $user,array $input,string $operation,int $issued,string $source,callable $complete): array
    {
        if (!$this->transactions->isRunning()) throw new LogicException('Return delivery requires the source SQL scope');
        return $this->transaction(function()use($user,$input,$operation,$issued,$source,$complete){
            $result=$this->delivery($user,$input,$operation,$issued,$source,true,true);
            if (!$complete($this->pdo,$result['entryId'])) InboxRules::fail('SOURCE_NOT_PENDING','Return source changed; delivery rolled back');
            return $result;
        });
    }
    private function delivery(string $user, array $input, string $operation, int $issued, string $source, bool $pending,bool $trustedReturn=false): array
    {
        InboxRules::text($operation, 128, false); InboxRules::text($source, 200, false);
        $this->player($user, true); $state = $this->state($user, true); $content = InboxRules::content($input,$trustedReturn);
        $hash = InboxRules::hash($content, $source, $issued); $receipt = InboxRules::replay($state, $operation, $hash);
        if ($receipt !== null) return ['entryId' => $receipt['entryId'], 'replayed' => true];
        $now = ($this->clock)();
        if (!$pending) InboxRules::window($state, $issued, $now); elseif ($issued > $now + 30000) InboxRules::invalid('Intent is in the future');
        InboxRules::validate($content, $now, $pending); $this->validateRewards($content);
        $state['entries'] = array_values(array_filter($state['entries'], static function ($row) use ($now) { return InboxRules::retained($row, $now); }));
        if (count($state['entries']) >= InboxRules::TARGETED) InboxRules::fail('INBOX_FULL', 'Inbox is full; keep the source pending');
        $id = 'message:' . Database::uuid(); $message = InboxRules::message($id, $content, $now, $operation, $hash);
        InboxRules::storedMessage($message); $state['entries'][] = $message;
        $state = InboxRules::bump(InboxRules::record($state, $operation, $hash, $issued, $id)); $this->save($user, $state);
        return ['entryId' => $id, 'replayed' => false];
    }
    private function broadcastView(array $catalog, int $now, ?array $operation = null): array
    {
        $entries = []; foreach ($catalog['entries'] as $row) if (InboxRules::retained($row, $now)) { $entry = InboxRules::viewEntry($row, $now); $entry['parameters'] = (object)$entry['parameters']; $entries[] = $entry; }
        return ['apiKeyId' => $catalog['apiKeyId'], 'revision' => $catalog['revision'], 'serverTime' => InboxRules::instant($now),
            'entries' => $entries, 'limits' => ['targeted' => InboxRules::TARGETED, 'broadcasts' => InboxRules::BROADCASTS], 'operation' => $operation];
    }
    public function broadcasts(): array { return $this->broadcastView($this->catalog(), ($this->clock)()); }
    /** CLI operator only; public project keys never authorize publication. */
    public function publish(array $input, string $operation, int $expected): array
    {
        $issued = InboxRules::issued($operation); $content = InboxRules::content($input);
        return $this->transaction(function () use ($content, $operation, $issued, $expected) {
            $catalog = $this->catalog(2); $now = ($this->clock)(); $hash = InboxRules::hash($content, 'broadcast:' . $catalog['apiKeyId'], $issued);
            $receipt = InboxRules::replay($catalog, $operation, $hash);
            if ($receipt !== null) return $this->broadcastView($catalog, $now, ['operationId' => $operation, 'entryId' => $receipt['entryId'], 'replayed' => true]);
            if ($catalog['revision'] !== $expected) InboxRules::fail('REVISION_CONFLICT', 'Reload the broadcast catalogue');
            InboxRules::window($catalog, $issued, $now); InboxRules::validate($content, $now); $this->validateRewards($content);
            $catalog['entries'] = array_values(array_filter($catalog['entries'], static function ($row) use ($now) { return InboxRules::retained($row, $now); }));
            if (count($catalog['entries']) >= InboxRules::BROADCASTS) InboxRules::fail('BROADCAST_LIMIT_REACHED', 'Retained broadcast limit reached');
            $id = 'broadcast:' . Database::uuid(); $message = InboxRules::message($id, $content, $now, $operation, $hash);
            InboxRules::storedMessage($message); $catalog['entries'][] = $message;
            $catalog = InboxRules::bump(InboxRules::record($catalog, $operation, $hash, $issued, $id)); $this->saveCatalog($catalog);
            return $this->broadcastView($catalog, $now, ['operationId' => $operation, 'entryId' => $id, 'replayed' => false]);
        });
    }
    public function removeBroadcast(string $id, int $expected): array
    {
        InboxRules::entry($id);
        return $this->transaction(function () use ($id, $expected) {
            $catalog = $this->catalog(2); $now = ($this->clock)(); $found = null;
            foreach ($catalog['entries'] as $row) if ($row['id'] === $id) $found = $row;
            if ($found === null) return $this->broadcastView($catalog, $now);
            if ($catalog['revision'] !== $expected) InboxRules::fail('REVISION_CONFLICT', 'Reload the broadcast catalogue');
            if (InboxRules::valuable($found)) InboxRules::fail('UNCLAIMED_REWARD', 'Reward broadcasts remain retained and cannot be deleted');
            $catalog['entries'] = array_values(array_filter($catalog['entries'], static function ($row) use ($id) { return $row['id'] !== $id; }));
            $catalog = InboxRules::bump($catalog); $this->saveCatalog($catalog); return $this->broadcastView($catalog, $now);
        });
    }
}
