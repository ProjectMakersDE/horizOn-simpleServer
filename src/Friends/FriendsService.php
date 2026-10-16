<?php

declare(strict_types=1);
require_once __DIR__ . '/FriendsRules.php';
require_once __DIR__ . '/../Inbox/InboxService.php';
require_once __DIR__ . '/../PlayerProfile/PublicPlayerDisplayService.php';

interface FriendsBlockPolicy
{
    public function blocked(string $first, string $second): bool;
}

/** Single installation/project. Both relationship CAS and Inbox use this exact PDO scope. */
class FriendsService implements FriendsBlockPolicy
{
    private PDO $pdo;
    private bool $mysql;
    private SqlTransaction $transactions;
    private InboxService $inbox;
    private PublicPlayerDisplayService $profiles;
    private $clock;
    private int $maxFriends;
    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null, int $maxFriends = FriendsRules::FRIENDS)
    {
        if ($maxFriends < 1 || $maxFriends > FriendsRules::FRIENDS) throw new InvalidArgumentException('Invalid friend bound');
        $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->transactions = $transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Foreign transaction connection');
        $this->clock = $clock ?? static function () { return Database::nowMillis(); };
        $this->maxFriends = $maxFriends;
        $this->inbox = new InboxService($pdo, $this->clock, $this->transactions);
        $this->profiles = new PublicPlayerDisplayService($pdo);
        if (!$this->mysql) $pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function transaction(callable $work)
    {
        try { return $this->transactions->run($work); }
        catch (SqlTransactionUncertain $error) { FriendsRules::conflict(); }
        catch (SqlTransactionRollbackOnly $error) { FriendsRules::conflict(); }
        catch (PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [5, 6, 19, 1062, 1205, 1213, 2006, 2013], true)) FriendsRules::conflict();
            throw $error;
        }
    }
    /** Sorted row locks fence concurrent user removal without copying private account fields. */
    private function players(array $ids, bool $lock = false): array
    {
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING);
        $query = $this->pdo->prepare('SELECT id FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute($ids); return $query->fetchAll(PDO::FETCH_COLUMN);
    }
    private function state(string $user, bool $lock = false): array
    {
        $query = $this->pdo->prepare('SELECT revision,active_user_id,friends,incoming,outgoing,blocked FROM friends_state WHERE user_id=?' . ($lock && $this->mysql ? ' FOR UPDATE' : ''));
        $query->execute([$user]); $row = $query->fetch();
        if ($row === false) return FriendsRules::emptyState();
        $state = ['revision' => (int)$row['revision']];
        foreach (['friends', 'incoming', 'outgoing', 'blocked'] as $key) $state[$key] = json_decode($row[$key], true, 512, JSON_THROW_ON_ERROR);
        $state['exists'] = true; return $state;
    }
    private function save(string $user, array $before, array $after): array
    {
        if ($before === $after) return $before;
        if ($before['revision'] >= FriendsRules::MAX) FriendsRules::fail('STATE_CONFLICT', 'Relationship revision exhausted');
        $after['revision'] = $before['revision'] + 1;
        $data = [$after['revision']];
        foreach (['friends', 'incoming', 'outgoing', 'blocked'] as $field) $data[] = InboxRules::json($after[$field]);
        if ($before['exists']) {
            $query = $this->pdo->prepare('UPDATE friends_state SET revision=?,friends=?,incoming=?,outgoing=?,blocked=? WHERE user_id=? AND revision=?');
            $query->execute(array_merge($data, [$user, $before['revision']]));
            if ($query->rowCount() !== 1) FriendsRules::conflict();
        } else $this->pdo->prepare('INSERT INTO friends_state(revision,friends,incoming,outgoing,blocked,user_id,active_user_id) VALUES(?,?,?,?,?,?,?)')->execute(array_merge($data, [$user, $user]));
        $this->pdo->prepare('DELETE FROM friends_references WHERE owner_user_id=?')->execute([$user]);
        $references = [];
        foreach (['friends', 'incoming', 'outgoing', 'blocked'] as $field) foreach ($after[$field] as $entry) $references[$entry['userId']] = true;
        if (count($references) > 340) FriendsRules::fail('STATE_CONFLICT', 'Relationship reference bound exceeded');
        if ($references) {
            $args = []; foreach (array_keys($references) as $id) { $args[] = $user; $args[] = $id; }
            $this->pdo->prepare('INSERT INTO friends_references(owner_user_id,related_user_id) VALUES ' . implode(',', array_fill(0, count($references), '(?,?)')))->execute($args);
        }
        $after['exists'] = true; return $after;
    }
    private function anchor(string $user): void
    {
        $this->pdo->prepare("INSERT INTO friends_state(user_id,active_user_id,revision,friends,incoming,outgoing,blocked) VALUES(?,?,0,'[]','[]','[]','[]')")->execute([$user, $user]);
    }
    public function limits(): array { return ['maxFriends' => $this->maxFriends, 'maxIncomingRequests' => 20, 'maxOutgoingRequests' => 20, 'maxBlocked' => 100]; }
    public function get(string $user): array
    {
        $user = FriendsRules::uuid($user);
        if (!$this->players([$user])) FriendsRules::missing();
        return $this->view($user, $this->state($user));
    }
    private function view(string $user, array $state): array
    {
        $ids = [];
        foreach (['friends', 'incoming', 'outgoing'] as $field) foreach ($state[$field] as $entry) $ids[] = $entry['userId'];
        $profiles = $this->profiles->load($ids);
        $result = ['userId' => $user, 'revision' => $state['revision'], 'friendCode' => FriendsRules::code($user)];
        foreach (['friends', 'incoming', 'outgoing'] as $field) {
            $result[$field] = [];
            foreach ($state[$field] as $entry) {
                $display = $profiles[$entry['userId']] ?? ['display' => PublicPlayerDisplayService::emptyDisplay(), 'lastActiveAt' => null];
                $entry['display'] = $display['display'];
                if ($field === 'friends') $entry['lastActiveAt'] = $display['lastActiveAt'];
                $result[$field][] = $entry;
            }
        }
        return $result + ['blocked' => $state['blocked'], 'limits' => $this->limits(), 'serverTime' => InboxRules::instant(($this->clock)())];
    }
    /** Same-scope policy; one statement sees both projected blocklists, never two unrelated reads. */
    public function blocked(string $first, string $second): bool
    {
        $first = FriendsRules::uuid($first); $second = FriendsRules::uuid($second);
        return $this->transaction(function () use ($first, $second) {
            return $this->blockedRows($first, $second);
        });
    }
    private function blockedRows(string $first, string $second): bool
    {
        $query = $this->pdo->prepare('SELECT user_id,blocked FROM friends_state WHERE user_id IN (?,?)');
        $query->execute([$first, $second]);
        foreach ($query->fetchAll() as $row) if (FriendsRules::has(json_decode($row['blocked'], true, 512, JSON_THROW_ON_ERROR), $row['user_id'] === $first ? $second : $first)) return true;
        return false;
    }
    public function find(string $user, $query): array
    {
        $user = FriendsRules::uuid($user); $target = FriendsRules::decode($query);
        return $this->transaction(function () use ($user, $target) {
            $present = $this->players([$user, $target], true);
            if (!in_array($user, $present, true) || !in_array($target, $present, true) || $this->blockedRows($user, $target)) FriendsRules::missing();
            $profile = $this->profiles->load([$target]);
            if (!isset($profile[$target])) FriendsRules::missing();
            return ['userId' => $target, 'friendCode' => FriendsRules::code($target), 'display' => $profile[$target]['display']];
        });
    }
    public function change(string $action, string $user, string $target, $expectedRevision, $requestId = null): array
    {
        $user = FriendsRules::uuid($user); $target = FriendsRules::uuid($target); $expected = FriendsRules::revision($expectedRevision);
        if ($user === $target || !in_array($action, FriendsRules::ACTIONS, true)) FriendsRules::invalid('Invalid social action');
        $request = in_array($action, ['accept', 'decline', 'cancel'], true) ? FriendsRules::uuid($requestId) : null;
        $changed = $this->transaction(function () use ($action, $user, $target, $expected, $request) {
            $present = $this->players([$user, $target], true);
            if (!in_array($user, $present, true)) FriendsRules::missing();
            $a = $this->state($user, true); $b = $this->state($target, true);
            if ($a['revision'] !== $expected) FriendsRules::conflict();
            $now = ($this->clock)();
            [$nextA, $nextB, $notice] = FriendsRules::transition($action, $user, $target, $a, $b, $request, InboxRules::instant($now), $this->maxFriends);
            if ($a === $nextA && $b === $nextB) return $a;
            $addsReference = in_array($action, ['request', 'accept'], true) || ($action === 'block' && !FriendsRules::has($a['blocked'], $target));
            if ($addsReference && !in_array($target, $present, true)) FriendsRules::missing();
            // A block-only target also needs a deletion anchor, while its public revision stays zero.
            if ($addsReference && !$b['exists'] && $b === $nextB) $this->anchor($target);
            $next = $this->save($user, $a, $nextA); $this->save($target, $b, $nextB);
            if ($notice !== null) {
                $source = 'friends:' . $action . ':' . $notice;
                $this->inbox->deliver($target, ['type' => 'friend_' . $action, 'reference' => $notice, 'textKey' => 'horizon.friends.' . $action,
                    'parameters' => ['userId' => $user], 'expiresAt' => InboxRules::instant($now + InboxRules::RETENTION)], $source, $now, $source);
            }
            return $next;
        });
        return $this->view($user, $changed);
    }
    /** ON DELETE SET NULL retains the source row as a retry anchor. At most50 peers per call. */
    public function cleanupDeletedPlayer(string $user): array
    {
        $user = FriendsRules::uuid($user);
        if ($this->players([$user])) FriendsRules::fail('STATE_CONFLICT', 'Only an absent player can be cleaned');
        $query = $this->pdo->prepare('SELECT owner_user_id FROM friends_references WHERE related_user_id=? ORDER BY owner_user_id LIMIT 51');
        $query->execute([$user]); $owners = $query->fetchAll(PDO::FETCH_COLUMN); $updated = 0;
        foreach (array_slice($owners, 0, 50) as $owner) {
            $this->transaction(function () use ($owner, $user) {
                $present = $this->players([$user, $owner], true);
                if (in_array($user, $present, true)) FriendsRules::fail('STATE_CONFLICT', 'Deleted player was restored');
                $before = $this->state($owner, true); $this->save($owner, $before, FriendsRules::without($before, $user, true));
            });
            $updated++;
        }
        $query->execute([$user]); $remaining = $query->fetchColumn() !== false;
        if (!$remaining) $this->transaction(function () use ($user) {
            if ($this->players([$user], true)) FriendsRules::fail('STATE_CONFLICT', 'Deleted player was restored');
            $this->pdo->prepare('DELETE FROM friends_state WHERE user_id=?')->execute([$user]);
        });
        return ['userId' => $user, 'updated' => $updated, 'complete' => !$remaining];
    }
    public function cleanupDeleted(): array
    {
        $query = $this->pdo->query('SELECT user_id FROM friends_state WHERE active_user_id IS NULL ORDER BY user_id LIMIT 10');
        $results = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            // A deliberate operator restore must not poison every later cleanup page.
            // Rebind only the FK anchor, keeping all relationship data and its revision.
            $restored = $this->transaction(function () use ($id) {
                if (!$this->players([$id], true)) return false;
                $this->pdo->prepare('UPDATE friends_state SET active_user_id=? WHERE user_id=? AND active_user_id IS NULL')->execute([$id, $id]);
                return true;
            });
            $results[] = $restored ? ['userId' => $id, 'updated' => 0, 'complete' => false, 'restored' => true] : $this->cleanupDeletedPlayer($id);
        }
        return $results;
    }
}
