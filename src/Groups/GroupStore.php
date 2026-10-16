<?php

declare(strict_types=1);
require_once __DIR__ . '/GroupRules.php';

/** SQL stays bounded by one group, five memberships or explicit keyset pages. */
class GroupStore
{
    private PDO $pdo;
    private bool $mysql;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
    private function lock(bool $lock): string { return $lock && $this->mysql ? ' FOR UPDATE' : ''; }
    public function players(array $ids, bool $lock = false): array
    {
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); if (!$ids) return [];
        $query = $this->pdo->prepare('SELECT id FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id' . $this->lock($lock));
        $query->execute($ids); return $query->fetchAll(PDO::FETCH_COLUMN);
    }
    public function player(string $user, bool $lock = false): array
    {
        if ($lock && $this->mysql) {
            // InnoDB locking reads can see a newer row than an already established outer
            // snapshot. Reject that mix instead of combining old policy/roles with new state.
            $snapshot = $this->player($user);
            $current = $this->pdo->prepare('SELECT revision FROM player_groups WHERE user_id=? FOR UPDATE'); $current->execute([$user]); $row = $current->fetch();
            if (($row !== false) !== $snapshot['exists'] || ($row !== false && (int)$row['revision'] !== $snapshot['revision'])) GroupRules::conflict();
            return $snapshot;
        }
        $query = $this->pdo->prepare('SELECT revision,data FROM player_groups WHERE user_id=?' . $this->lock($lock)); $query->execute([$user]); $row = $query->fetch();
        if ($row === false) return GroupRules::emptyPlayer();
        return ['revision' => (int)$row['revision']] + json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR) + ['exists' => true];
    }
    public function savePlayer(string $user, array $before, array $after, bool $active, bool $forced = false): array
    {
        if (!$forced && $before === $after) return $before;
        if ($before['revision'] >= GroupRules::MAX) GroupRules::conflict();
        $after['revision'] = $before['revision'] + 1;
        $data = GroupRules::json(['groupIds' => array_values($after['groupIds']), 'incomingInvites' => array_values($after['incomingInvites']), 'outgoingRequests' => array_values($after['outgoingRequests'])]);
        if ($before['exists']) {
            $query = $this->pdo->prepare('UPDATE player_groups SET revision=?,data=?,active_user_id=? WHERE user_id=? AND revision=?');
            $query->execute([$after['revision'], $data, $active ? $user : null, $user, $before['revision']]);
            if ($query->rowCount() !== 1) GroupRules::conflict();
        } else $this->pdo->prepare('INSERT INTO player_groups(user_id,active_user_id,revision,data) VALUES(?,?,?,?)')->execute([$user, $active ? $user : null, $after['revision'], $data]);
        $after['exists'] = true; return $after;
    }
    public function group(string $id, bool $lock = false): ?array
    {
        if ($lock && $this->mysql) {
            $snapshot = $this->group($id);
            $current = $this->pdo->prepare('SELECT revision FROM groups_state WHERE id=? FOR UPDATE'); $current->execute([$id]); $row = $current->fetch();
            if (($row !== false) !== ($snapshot !== null) || ($row !== false && (int)$row['revision'] !== $snapshot['revision'])) GroupRules::conflict();
            return $snapshot;
        }
        $query = $this->pdo->prepare('SELECT data FROM groups_state WHERE id=?' . $this->lock($lock)); $query->execute([$id]); $row = $query->fetch();
        return $row === false ? null : json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
    }
    public function groups(array $ids): array
    {
        $ids = array_values(array_unique($ids)); if (count($ids) > 45) throw new LogicException('Group snapshot bound exceeded'); if (!$ids) return [];
        $query = $this->pdo->prepare('SELECT id,data FROM groups_state WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'); $query->execute($ids);
        $result = []; foreach ($query->fetchAll() as $row) $result[$row['id']] = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR); return $result;
    }
    public function insertGroup(array $group): void { $this->pdo->prepare('INSERT INTO groups_state(id,revision,data) VALUES(?,?,?)')->execute([$group['id'], $group['revision'], GroupRules::json($group)]); }
    public function saveGroup(array $before, array $after, string $now): array
    {
        if ($before['revision'] >= GroupRules::MAX) GroupRules::conflict();
        $after['revision'] = $before['revision'] + 1; $after['updatedAt'] = $now;
        $query = $this->pdo->prepare('UPDATE groups_state SET revision=?,data=? WHERE id=? AND revision=?');
        $query->execute([$after['revision'], GroupRules::json($after), $before['id'], $before['revision']]); if ($query->rowCount() !== 1) GroupRules::conflict();
        $this->pdo->prepare('DELETE FROM group_pending_references WHERE group_id=?')->execute([$before['id']]);
        $ids = []; foreach (array_merge($after['invitations'], $after['joinRequests']) as $entry) { $ids[$entry['userId']] = true; $ids[$entry['issuerId']] = true; }
        if (count($ids) > 80) throw new LogicException('Group pending bound exceeded');
        if ($ids) { $args = []; foreach (array_keys($ids) as $id) { $args[] = $id; $args[] = $before['id']; }
            $this->pdo->prepare('INSERT INTO group_pending_references(user_id,group_id) VALUES ' . implode(',', array_fill(0, count($ids), '(?,?)')))->execute($args); }
        return $after;
    }
    public function deleteGroup(array $before): void
    {
        $query = $this->pdo->prepare('DELETE FROM groups_state WHERE id=? AND revision=?'); $query->execute([$before['id'], $before['revision']]); if ($query->rowCount() !== 1) GroupRules::conflict();
    }
    public function member(string $group, string $user): ?array
    {
        $query = $this->pdo->prepare('SELECT group_id,user_id,role,joined_millis FROM group_members WHERE group_id=? AND user_id=?'); $query->execute([$group, $user]); $row = $query->fetch(); return $row === false ? null : $row;
    }
    public function memberships(string $user): array
    {
        $query = $this->pdo->prepare('SELECT group_id,role FROM group_members WHERE user_id=? ORDER BY group_id LIMIT 5'); $query->execute([$user]); return $query->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    public function members(string $group, ?string $after, int $limit): array
    {
        $query = $this->pdo->prepare('SELECT user_id,role,joined_millis FROM group_members WHERE group_id=?' . ($after === null ? '' : ' AND user_id>?') . ' ORDER BY user_id LIMIT ' . ($limit + 1));
        $query->execute($after === null ? [$group] : [$group, $after]); return $query->fetchAll();
    }
    public function addMember(string $group, string $user, string $role, int $now): void { $this->pdo->prepare('INSERT INTO group_members(group_id,user_id,role,joined_millis) VALUES(?,?,?,?)')->execute([$group,$user,$role,$now]); }
    public function setRole(array $member, string $role): void
    {
        $query = $this->pdo->prepare('UPDATE group_members SET role=? WHERE group_id=? AND user_id=? AND role=?'); $query->execute([$role,$member['group_id'],$member['user_id'],$member['role']]); if ($query->rowCount() !== 1) GroupRules::conflict();
    }
    public function removeMember(array $member): void
    {
        $query = $this->pdo->prepare('DELETE FROM group_members WHERE group_id=? AND user_id=? AND role=?'); $query->execute([$member['group_id'],$member['user_id'],$member['role']]); if ($query->rowCount() !== 1) GroupRules::conflict();
    }
    public function successor(string $group, string $deleted): ?array
    {
        foreach (['officer','member'] as $role) { $query = $this->pdo->prepare('SELECT group_id,user_id,role,joined_millis FROM group_members WHERE group_id=? AND role=? AND user_id<>? ORDER BY joined_millis,user_id LIMIT 1'); $query->execute([$group,$role,$deleted]); $row = $query->fetch(); if ($row !== false) return $row; } return null;
    }
    public function referencing(string $user, int $limit): array { $query = $this->pdo->prepare('SELECT group_id FROM group_pending_references WHERE user_id=? ORDER BY group_id LIMIT ' . $limit); $query->execute([$user]); return $query->fetchAll(PDO::FETCH_COLUMN); }
    public function deletedPlayers(?string $after): array
    {
        $query = $this->pdo->prepare('SELECT user_id FROM player_groups WHERE active_user_id IS NULL' . ($after === null ? '' : ' AND user_id>?') . ' ORDER BY user_id LIMIT 11');
        $query->execute($after === null ? [] : [$after]); return $query->fetchAll(PDO::FETCH_COLUMN);
    }
    public function rebind(string $user): void { $this->pdo->prepare('UPDATE player_groups SET active_user_id=? WHERE user_id=? AND active_user_id IS NULL')->execute([$user,$user]); }
    public function deletePlayer(string $user): void { $this->pdo->prepare('DELETE FROM player_groups WHERE user_id=?')->execute([$user]); }
}
