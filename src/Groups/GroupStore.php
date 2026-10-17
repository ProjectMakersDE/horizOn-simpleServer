<?php

declare(strict_types=1);
require_once __DIR__ . '/GroupStorage.php';

/** Canonical SQL memberships and directed source references; product limits never bound old reads. */
class GroupStore
{
    private PDO $pdo;
    private bool $mysql;
    private bool $ready = false;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
    private function lock(bool $lock): string { return $lock && $this->mysql ? ' FOR UPDATE' : ''; }
    public function ready(): bool
    {
        if (!$this->ready) $this->ready = (int)$this->pdo->query('SELECT ready FROM group_storage WHERE id=1')->fetchColumn() === 1;
        return $this->ready;
    }
    public function writable(): void { if (!$this->ready()) GroupStorage::required(); }
    public function players(array $ids, bool $lock = false): array
    {
        $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING); if (!$ids) return [];
        $result = [];
        foreach (array_chunk($ids,200) as $batch) {
            $query = $this->pdo->prepare('SELECT id FROM users WHERE id IN (' . implode(',', array_fill(0, count($batch), '?')) . ') ORDER BY id' . $this->lock($lock));
            $query->execute($batch); $result = array_merge($result,$query->fetchAll(PDO::FETCH_COLUMN));
        }
        return $result;
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
        $value = $row === false ? null : GroupStorage::player($row['data']);
        if (!$this->ready()) {
            if ($value !== null && $value['_compact']) GroupStorage::required();
            return $row === false ? GroupRules::emptyPlayer() : ['revision'=>(int)$row['revision'],'exists'=>true] + $value;
        }
        $counts = $value === null ? ['memberships'=>0,'invitations'=>0,'requests'=>0] :
            ($value['_compact'] ? $value['_counts'] : ['memberships'=>count($value['groupIds']),'invitations'=>count($value['incomingInvites']),'requests'=>count($value['outgoingRequests'])]);
        $roles = $this->memberships($user,$counts['memberships'] + 1);$memberships = array_keys($roles);
        [$incoming,$outgoing,$groups] = $this->targetPending($user,$counts['invitations']+$counts['requests']+1);
        if (count($memberships) !== $counts['memberships'] || count($incoming) !== $counts['invitations'] || count($outgoing) !== $counts['requests']) GroupStorage::required();
        if(array_intersect($memberships,array_column(array_merge($incoming,$outgoing),'groupId')))GroupStorage::required();
        if ($value !== null && !$value['_compact']) {
            $expected = $value['groupIds']; sort($expected,SORT_STRING);
            if ($expected !== $memberships || !$this->samePending($value['incomingInvites'],$incoming) || !$this->samePending($value['outgoingRequests'],$outgoing)) GroupStorage::required();
        }
        return ['revision'=>$row === false ? 0 : (int)$row['revision'],'exists'=>$row !== false,'groupIds'=>$memberships,'incomingInvites'=>$incoming,'outgoingRequests'=>$outgoing,'_roles'=>$roles,'_pendingGroups'=>$groups];
    }
    private function samePending(array $expected,array $actual): bool
    {
        $map = static function(array $entries): array { $result=[]; foreach($entries as $entry) $result[$entry['groupId']]=GroupStorage::identity($entry['groupId'],$entry); ksort($result,SORT_STRING);return $result; };
        return $map($expected) === $map($actual);
    }
    private function targetPending(string $user,int $limit): array
    {
        $q=$this->pdo->prepare('SELECT group_id,target_kind FROM group_pending_references WHERE user_id=? AND target_kind IN (1,2) ORDER BY target_kind,group_id LIMIT '.$limit);$q->execute([$user]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $groups=$this->groups(array_column($rows,'group_id'));$result=[1=>[],2=>[]];
        foreach($rows as $row) {
            $id=$row['group_id'];$kind=(int)$row['target_kind'];
            if(!isset($groups[$id]))GroupStorage::required();
            $match=null;foreach($groups[$id][$kind===1?'invitations':'joinRequests'] as $entry) if($entry['userId']===$user) {$match=$entry;break;}
            if($match===null)GroupStorage::required();unset($match['userId']);$result[$kind][]=['groupId'=>$id]+$match;
        }
        return [$result[1],$result[2],$groups];
    }
    public function savePlayer(string $user, array $before, array $after, bool $active, bool $forced = false): array
    {
        $this->writable();
        if (!$forced && $before === $after) return $before;
        if ($before['revision'] >= GroupRules::MAX) GroupRules::conflict();
        unset($after['_roles'],$after['_pendingGroups']);
        $after['revision'] = $before['revision'] + 1;
        $data = GroupStorage::encodePlayer($after);
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
        $query = $this->pdo->prepare('SELECT revision,data FROM groups_state WHERE id=?' . $this->lock($lock)); $query->execute([$id]); $row = $query->fetch();
        return $row === false ? null : $this->decodeGroup($id,$row);
    }
    private function decodeGroup(string $id,array $row): array {
        $group=GroupStorage::group($row['data'],$id,(int)$row['revision']);if($group['id']!==$id||$group['revision']!==(int)$row['revision'])GroupStorage::required();return $group;
    }
    public function groups(array $ids): array
    {
        $result=[];
        foreach(array_chunk(array_values(array_unique($ids)),200) as $batch) {
            $query = $this->pdo->prepare('SELECT id,revision,data FROM groups_state WHERE id IN (' . implode(',', array_fill(0, count($batch), '?')) . ')'); $query->execute($batch);
            foreach($query->fetchAll() as $row)$result[$row['id']]=$this->decodeGroup($row['id'],$row);
        }
        return $result;
    }
    public function insertGroup(array $group): void { $this->writable();$this->pdo->prepare('INSERT INTO groups_state(id,revision,data) VALUES(?,?,?)')->execute([$group['id'], $group['revision'], GroupStorage::encodeGroup($group)]); }
    public function saveGroup(array $before, array $after, string $now): array
    {
        $this->writable();if ($before['revision'] >= GroupRules::MAX) GroupRules::conflict();
        $after['revision'] = $before['revision'] + 1; $after['updatedAt'] = $now;
        $query = $this->pdo->prepare('UPDATE groups_state SET revision=?,data=? WHERE id=? AND revision=?');
        $query->execute([$after['revision'], GroupStorage::encodeGroup($after), $before['id'], $before['revision']]); if ($query->rowCount() !== 1) GroupRules::conflict();
        $this->indexGroup($after);
        return $after;
    }
    /** Rebuild secondary identities from the single canonical source, in this same transaction. */
    public function indexGroup(array $after): void
    {
        $this->pdo->prepare('DELETE FROM group_pending_references WHERE group_id=?')->execute([$after['id']]);
        $this->pdo->prepare('DELETE FROM group_inbox_sources WHERE group_id=?')->execute([$after['id']]);
        $ids=[];$sources=[];
        foreach(['invitations'=>1,'joinRequests'=>2] as $field=>$kind)foreach($after[$field] as $entry) {
            if(!array_key_exists($entry['issuerId'],$ids))$ids[$entry['issuerId']]=null;$ids[$entry['userId']]=$kind;
            $sources[]=[$entry['requestId'],$after['id']];
        }
        foreach(array_chunk($sources,200)as $batch)$this->pdo->prepare('INSERT INTO group_inbox_sources(request_id,group_id) VALUES '.implode(',',array_fill(0,count($batch),'(?,?)')))->execute(array_merge(...$batch));
        $rows=[];foreach($ids as $id=>$kind)$rows[]=[$id,$after['id'],$kind];
        foreach(array_chunk($rows,200)as $batch)$this->pdo->prepare('INSERT INTO group_pending_references(user_id,group_id,target_kind) VALUES '.implode(',',array_fill(0,count($batch),'(?,?,?)')))->execute(array_merge(...$batch));
    }
    public function deleteGroup(array $before): void
    {
        $query = $this->pdo->prepare('DELETE FROM groups_state WHERE id=? AND revision=?'); $query->execute([$before['id'], $before['revision']]); if ($query->rowCount() !== 1) GroupRules::conflict();
    }
    public function member(string $group, string $user): ?array
    {
        $query = $this->pdo->prepare('SELECT group_id,user_id,role,joined_millis FROM group_members WHERE group_id=? AND user_id=?'); $query->execute([$group, $user]); $row = $query->fetch(); return $row === false ? null : $row;
    }
    public function memberships(string $user,?int $limit = null): array
    {
        $query = $this->pdo->prepare('SELECT group_id,role FROM group_members WHERE user_id=? ORDER BY group_id'.($limit===null?'':' LIMIT '.$limit)); $query->execute([$user]); return $query->fetchAll(PDO::FETCH_KEY_PAIR);
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
