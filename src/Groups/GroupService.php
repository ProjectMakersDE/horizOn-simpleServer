<?php

declare(strict_types=1);
require_once __DIR__ . '/GroupStore.php';
require_once __DIR__ . '/../Friends/FriendsService.php';

/** Read projection only. A future group-owned write must also fence membership revocation. */
interface GroupMembershipPolicy { public function role(string $group, string $user): ?string; }

class GroupService implements GroupMembershipPolicy
{
    private PDO $pdo;
    private SqlTransaction $transactions;
    private GroupStore $store;
    private FriendsBlockPolicy $blocks;
    private InboxService $inbox;
    private PublicPlayerDisplayService $profiles;
    private $clock;
    private int $maxMembers;
    private int $maxGroups;
    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null, int $maxMembers = 500, int $maxGroups = 5)
    {
        if ($maxMembers < 1 || $maxMembers > 500 || $maxGroups < 1 || $maxGroups > 5) throw new InvalidArgumentException('Invalid group bound');
        $this->pdo = $pdo; $this->clock = $clock ?? static function () { return Database::nowMillis(); };
        $this->transactions = $transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Foreign transaction connection');
        $this->store = new GroupStore($pdo); $this->blocks = new FriendsService($pdo, $this->clock, $this->transactions);
        $this->inbox = new InboxService($pdo, $this->clock, $this->transactions); $this->profiles = new PublicPlayerDisplayService($pdo);
        $this->maxMembers = $maxMembers; $this->maxGroups = $maxGroups;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') $pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function transaction(callable $work)
    {
        try { return $this->transactions->run($work); }
        catch (SqlTransactionUncertain $error) { GroupRules::conflict(); }
        catch (SqlTransactionRollbackOnly $error) { GroupRules::conflict(); }
        catch (PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0), [5,6,19,1062,1205,1213,1452,2006,2013], true)) GroupRules::conflict();
            throw $error;
        }
    }
    private function active(string $user, bool $lock = false): void { if (!$this->store->players([$user], $lock)) GroupRules::fail('PLAYER_NOT_FOUND', 404); }
    private function group(string $id, bool $lock = false): array { $group = $this->store->group($id, $lock); if ($group === null) GroupRules::missing(); return $group; }
    public function limits(): array { return ['maxMembers' => $this->maxMembers, 'maxGroupsPerPlayer' => $this->maxGroups, 'maxInvitations' => 20, 'maxJoinRequests' => 20, 'maxDataBytes' => 2048, 'memberPageSize' => 50]; }
    public function role(string $group, string $user): ?string { $member = $this->store->member(GroupRules::uuid($group), GroupRules::uuid($user)); return $member['role'] ?? null; }
    private function view(array $group, ?string $role = null, bool $admin = false): array
    {
        $result = []; foreach (['id','revision','name','description','joinMode','leaderId','memberCount','createdAt','updatedAt'] as $key) $result[$key] = $group[$key];
        if ($role !== null || $admin) $result['data'] = json_decode($group['dataJson'], false, 512, JSON_THROW_ON_ERROR);
        if ($role !== null) $result['myRole'] = $role;
        return $result;
    }
    private function mine(string $user, array $state): array
    {
        $ids = $state['groupIds']; foreach (array_merge($state['incomingInvites'], $state['outgoingRequests']) as $entry) $ids[] = $entry['groupId'];
        $records = $this->store->groups($ids); $roles = $this->store->memberships($user); $groups = [];
        foreach ($state['groupIds'] as $id) if (isset($records[$id], $roles[$id])) $groups[] = $this->view($records[$id], $roles[$id]);
        $result = ['userId' => $user, 'revision' => $state['revision'], 'groups' => $groups];
        foreach (['incomingInvites','outgoingRequests'] as $field) {
            $result[$field] = []; foreach ($state[$field] as $entry) $result[$field][] = $entry + ['group' => isset($records[$entry['groupId']]) ? $this->view($records[$entry['groupId']]) : null];
        }
        return $result + ['limits' => $this->limits(), 'serverTime' => InboxRules::instant(($this->clock)())];
    }
    public function get(string $user): array
    {
        $user = GroupRules::uuid($user);
        return $this->transaction(function () use ($user) { $this->active($user); return $this->mine($user, $this->store->player($user)); });
    }
    public function detail(string $user, string $id): array
    {
        $user = GroupRules::uuid($user); $id = GroupRules::uuid($id);
        return $this->transaction(function () use ($user,$id) { $this->active($user); return $this->view($this->group($id), $this->role($id,$user)); });
    }
    public function members(?string $user, string $id, ?string $after, $limit): array
    {
        $id = GroupRules::uuid($id); $limit = GroupRules::page($limit); if ($after !== null) $after = GroupRules::uuid($after, 'INVALID_PAGE'); if ($user !== null) $user = GroupRules::uuid($user);
        return $this->transaction(function () use ($user,$id,$after,$limit) {
            $group = $this->group($id); if ($user !== null) { $this->active($user); if ($this->role($id,$user) === null) GroupRules::denied(); }
            $rows = $this->store->members($id,$after,$limit); $page = array_slice($rows,0,$limit); $profiles = $this->profiles->load(array_column($page,'user_id'));
            $members = []; foreach ($page as $row) $members[] = ['userId' => $row['user_id'], 'role' => $row['role'], 'joinedAt' => InboxRules::instant((int)$row['joined_millis']), 'display' => $profiles[$row['user_id']]['display'] ?? PublicPlayerDisplayService::emptyDisplay()];
            return ['groupId' => $id, 'revision' => $group['revision'], 'members' => $members, 'nextAfterUserId' => count($rows) > $limit ? $page[count($page)-1]['user_id'] : null, 'serverTime' => InboxRules::instant(($this->clock)())];
        });
    }
    public function pending(?string $user, string $id): array
    {
        $id = GroupRules::uuid($id); if ($user !== null) $user = GroupRules::uuid($user);
        return $this->transaction(function () use ($user,$id) {
            $group = $this->group($id); if ($user !== null) { $this->active($user); GroupRules::officer($this->store->member($id,$user)); }
            return ['groupId' => $id, 'revision' => $group['revision'], 'invitations' => $group['invitations'], 'joinRequests' => $group['joinRequests'], 'serverTime' => InboxRules::instant(($this->clock)())];
        });
    }
    public function inspect(string $id): array { return $this->view($this->group(GroupRules::uuid($id)), null, true); }
    public function create(string $user, $expected, $name, $description, $mode, $data): array
    {
        $user = GroupRules::uuid($user); $expected = GroupRules::revision($expected); $metadata = GroupRules::metadata($name,$description,$mode,$data);
        return $this->transaction(function () use ($user,$expected,$metadata) {
            $this->active($user,true); $before = $this->store->player($user,true);
            if ($before['revision'] !== $expected) GroupRules::conflict(); if (count($before['groupIds']) >= $this->maxGroups) GroupRules::fail('PLAYER_GROUP_LIMIT');
            $now = ($this->clock)(); $id = Database::uuid(); $group = ['id' => $id, 'revision' => 1, 'leaderId' => $user, 'memberCount' => 1, 'createdAt' => InboxRules::instant($now), 'updatedAt' => InboxRules::instant($now), 'invitations' => [], 'joinRequests' => []] + $metadata;
            $this->store->insertGroup($group); $this->store->addMember($id,$user,'leader',$now);
            $after = $before; $after['groupIds'][] = $id; $after = $this->store->savePlayer($user,$before,$after,true);
            return ['groups' => $this->mine($user,$after), 'group' => $this->view($group,'leader')];
        });
    }
    public function update(string $user, string $id, $expected, $name, $description, $mode, $data): array
    {
        $user = GroupRules::uuid($user); $id = GroupRules::uuid($id); $expected = GroupRules::revision($expected); $metadata = GroupRules::metadata($name,$description,$mode,$data);
        return $this->transaction(function () use ($user,$id,$expected,$metadata) {
            $old = $this->group($id,true); if ($old['revision'] !== $expected) GroupRules::conflict(); $this->active($user,true);
            $member = $this->store->member($id,$user); GroupRules::officer($member); $next = array_merge($old,$metadata);
            if ($next !== $old) $next = $this->store->saveGroup($old,$next,InboxRules::instant(($this->clock)()));
            return ['groups' => $this->mine($user,$this->store->player($user)), 'group' => $this->view($next,$member['role'])];
        });
    }
    private function loadPlayer(array &$command, string $user): array
    {
        if (!isset($command['before'][$user])) { $command['before'][$user] = $this->store->player($user,true); $command['after'][$user] = $command['before'][$user]; }
        return $command['after'][$user];
    }
    private function fence(array &$command, array $users): void
    {
        foreach (array_unique($users) as $user) if (!in_array($user,$command['present'],true)) GroupRules::fail('PLAYER_NOT_FOUND',404);
    }
    private function checkBlocks(string $target, array $others): void
    {
        foreach (array_unique($others) as $other) if ($target !== $other && $this->blocks->blocked($target,$other)) GroupRules::fail('GROUP_RELATION_BLOCKED',403);
    }
    private function clearPending(array &$command, string $user): void
    {
        $state = $this->loadPlayer($command,$user); $id = $command['old']['id'];
        foreach (['incomingInvites','outgoingRequests'] as $field) $state[$field] = array_values(array_filter($state[$field], static function ($entry) use ($id) { return $entry['groupId'] !== $id; }));
        $command['after'][$user] = $state;
        foreach (['invitations','joinRequests'] as $field) $command['next'][$field] = array_values(array_filter($command['next'][$field], static function ($entry) use ($user) { return $entry['userId'] !== $user; }));
    }
    private function add(array &$command, string $user, string $deciding): void
    {
        if ($command['next']['memberCount'] >= $this->maxMembers) GroupRules::fail('GROUP_MEMBER_LIMIT');
        $player = $this->loadPlayer($command,$user); if (count($player['groupIds']) >= $this->maxGroups) GroupRules::fail('PLAYER_GROUP_LIMIT');
        $leader = $command['next']['leaderId']; $this->fence($command,[$user,$deciding,$leader]); $this->checkBlocks($user,[$deciding,$leader]);
        $this->clearPending($command,$user); $player = $this->loadPlayer($command,$user); $player['groupIds'][] = $command['old']['id']; $command['after'][$user] = $player;
        $this->store->addMember($command['old']['id'],$user,'member',($this->clock)()); $command['next']['memberCount']++; $command['dirty'] = true;
    }
    private function remove(array &$command, array $member): void
    {
        $this->store->removeMember($member); $user = $member['user_id']; $player = $this->loadPlayer($command,$user); $id = $command['old']['id'];
        $player['groupIds'] = array_values(array_filter($player['groupIds'],static function ($group) use ($id) { return $group !== $id; })); $command['after'][$user] = $player;
        $command['next']['memberCount']--; $command['dirty'] = true;
    }
    private function delete(array &$command, ?array $member): void
    {
        GroupRules::leader($member);
        if ($command['next']['memberCount'] !== 1 || $command['next']['invitations'] || $command['next']['joinRequests']) GroupRules::fail('GROUP_NOT_EMPTY');
        $this->remove($command,$member); $command['deleted'] = true;
    }
    public function change(string $action, string $user, string $id, $expected, $target = null, $request = null, $role = null): array
    {
        $user = GroupRules::uuid($user); $id = GroupRules::uuid($id); $input = GroupRules::action($action,$user,$expected,$target,$request,$role);
        return $this->transaction(function () use ($action,$user,$id,$input) {
            $old = $this->group($id,true); if ($old['revision'] !== $input['expectedRevision']) GroupRules::conflict();
            $target = $input['targetUserId']; $participants = [$user,$target,$old['leaderId']];
            foreach (array_merge($old['invitations'],$old['joinRequests']) as $entry) if ($entry['userId'] === $target && $entry['requestId'] === $input['requestId']) $participants[] = $entry['issuerId'];
            $present = $this->store->players($participants,true); if (!in_array($user,$present,true)) GroupRules::fail('PLAYER_NOT_FOUND',404);
            $command = ['old' => $old, 'next' => $old, 'actor' => $user, 'present' => $present, 'before' => [], 'after' => [], 'forced' => [], 'dirty' => false, 'deleted' => false, 'notices' => []];
            $this->apply($command,$action,$input);
            $next = $this->commit($command);
            $view = $next === null || in_array($action,['leave','delete'],true) ? null : $this->view($next,$this->role($id,$user));
            return ['groups' => $this->mine($user,$this->store->player($user)), 'group' => $view];
        });
    }
    private function apply(array &$command, string $action, array $input): void
    {
        $id = $command['old']['id']; $actor = $command['actor']; $target = $input['targetUserId']; $member = $this->store->member($id,$actor);
        switch ($action) {
            case 'join':
                if ($member !== null) return;
                if ($command['next']['joinMode'] !== 'open') GroupRules::denied(); $this->add($command,$actor,$command['next']['leaderId']); return;
            case 'request': case 'invite':
                $invites = $action === 'invite'; if ($invites) GroupRules::officer($member); elseif ($command['next']['joinMode'] !== 'request') GroupRules::denied();
                if ($this->store->member($id,$target) !== null) GroupRules::fail('GROUP_PENDING_EXISTS');
                foreach (array_merge($command['next']['invitations'],$command['next']['joinRequests']) as $entry) if ($entry['userId'] === $target) GroupRules::fail('GROUP_PENDING_EXISTS');
                $field = $invites ? 'invitations' : 'joinRequests'; $playerField = $invites ? 'incomingInvites' : 'outgoingRequests';
                if (count($command['next'][$field]) >= 20) GroupRules::fail('GROUP_PENDING_LIMIT');
                $player = $this->loadPlayer($command,$target); if (count($player[$playerField]) >= 20) GroupRules::fail('PLAYER_PENDING_LIMIT');
                $this->fence($command,[$actor,$target,$command['next']['leaderId']]); $this->checkBlocks($target,[$actor,$command['next']['leaderId']]);
                $entry = ['userId' => $target,'requestId' => Database::uuid(),'issuerId' => $actor,'createdAt' => InboxRules::instant(($this->clock)())];
                $command['next'][$field][] = $entry; $player[$playerField][] = ['groupId' => $id,'requestId' => $entry['requestId'],'issuerId' => $actor,'createdAt' => $entry['createdAt']]; $command['after'][$target] = $player;
                $command['notices'][] = [$invites ? $target : $command['next']['leaderId'],$action,$entry['requestId']]; $command['dirty'] = true; return;
            case 'accept-invite': case 'decline-invite': case 'cancel-invite': case 'accept-request': case 'decline-request': case 'cancel-request':
                $invites = substr($action,-6) === 'invite'; if (in_array($action,['cancel-invite','accept-request','decline-request'],true)) GroupRules::officer($member);
                $pending = null; foreach ($command['next'][$invites ? 'invitations' : 'joinRequests'] as $entry) if ($entry['userId'] === $target && $entry['requestId'] === $input['requestId']) { $pending = $entry; break; }
                if ($pending === null) GroupRules::fail('REQUEST_NOT_FOUND',404);
                if (strpos($action,'accept') === 0) {
                    $this->add($command,$target,$invites ? $pending['issuerId'] : $actor); $recipient = $invites ? $pending['issuerId'] : $target;
                    if (in_array($recipient,$command['present'],true)) $command['notices'][] = [$recipient,$action,$pending['requestId']];
                } else { $this->clearPending($command,$target); $command['dirty'] = true; } return;
            case 'leave': case 'remove':
                $removed = $action === 'leave' ? $member : $this->store->member($id,$target);
                if ($action === 'remove') { GroupRules::officer($member); if ($removed !== null && ($removed['role'] === 'leader' || ($removed['role'] === 'officer' && $member['role'] !== 'leader'))) GroupRules::denied(); }
                if ($removed === null) return;
                if ($removed['role'] === 'leader') { if ($command['next']['memberCount'] !== 1) GroupRules::fail('LEADERSHIP_TRANSFER_REQUIRED'); $this->delete($command,$member); }
                else $this->remove($command,$removed); return;
            case 'role': case 'transfer':
                GroupRules::leader($member); $selected = $this->store->member($id,$target); if ($selected === null) GroupRules::fail('PLAYER_NOT_FOUND',404);
                $this->loadPlayer($command,$target); $command['forced'][$target] = true;
                if ($action === 'role') { if ($selected['role'] === $input['role']) return; $this->store->setRole($selected,$input['role']); }
                else { $this->fence($command,[$target]); $this->store->setRole($member,'member'); $this->store->setRole($selected,'leader'); $command['next']['leaderId'] = $target; $this->loadPlayer($command,$actor); $command['forced'][$actor] = true; }
                $command['dirty'] = true; return;
            case 'delete': $this->delete($command,$member); return;
        }
    }
    private function commit(array $command): ?array
    {
        if (!$command['dirty']) return $command['old'];
        foreach ($command['after'] as $user => $after) $this->store->savePlayer($user,$command['before'][$user],$after,in_array($user,$command['present'],true),isset($command['forced'][$user]));
        if ($command['deleted']) { $this->store->deleteGroup($command['old']); return null; }
        $saved=$this->store->saveGroup($command['old'],$command['next'],InboxRules::instant(($this->clock)()));
        foreach ($command['notices'] as [$recipient,$action,$request]) {
            $now = ($this->clock)(); $source = 'groups:' . $action . ':' . $request;
            $this->inbox->deliver($recipient,['type' => 'group_' . $action,'reference' => $request,'textKey' => 'horizon.groups.' . $action,'parameters' => ['groupId' => $command['old']['id']],'expiresAt' => InboxRules::instant($now + InboxRules::RETENTION)],$source,$now,$source);
        }
        return $saved;
    }
    /** At most ten indexed group references per invocation; keep the nullable FK retry anchor. */
    public function cleanupDeletedPlayer(string $user): array
    {
        $user = GroupRules::uuid($user); if ($this->store->players([$user])) GroupRules::fail('REVISION_CONFLICT');
        $state = $this->store->player($user); $ids = array_values(array_unique(array_merge($state['groupIds'],$this->store->referencing($user,11)))); sort($ids,SORT_STRING);
        $updated = 0;
        foreach (array_slice($ids,0,10) as $id) {
            try { $this->transaction(function () use ($user,$id) { $this->cleanupGroup($user,$id); }); }
            catch (GroupFailure $error) { if ($error->errorCode === 'REVISION_CONFLICT') return ['userId' => $user,'updated' => $updated,'complete' => false]; throw $error; }
            $updated++;
        }
        $complete = $this->transaction(function () use ($user) {
            if ($this->store->players([$user],true)) GroupRules::conflict();
            if ($this->store->player($user,true)['groupIds'] || $this->store->referencing($user,1)) return false;
            $this->store->deletePlayer($user); return true;
        });
        return ['userId' => $user,'updated' => $updated,'complete' => $complete];
    }
    private function cleanupGroup(string $user, string $id): void
    {
        $old = $this->store->group($id,true); if ($old === null) return;
        $member = $this->store->member($id,$user); $successor = $member !== null && $member['role'] === 'leader' && $old['memberCount'] > 1 ? $this->store->successor($id,$user) : null;
        $participants = [$user]; if ($successor !== null) $participants[] = $successor['user_id'];
        foreach (array_merge($old['invitations'],$old['joinRequests']) as $entry) $participants[] = $entry['userId'];
        $present = $this->store->players($participants,true); if (in_array($user,$present,true)) GroupRules::conflict();
        $command = ['old' => $old,'next' => $old,'actor' => $user,'present' => $present,'before' => [],'after' => [],'forced' => [],'dirty' => false,'deleted' => false,'notices' => []];
        if ($member !== null) {
            if ($member['role'] === 'leader' && $old['memberCount'] > 1) {
                if ($successor === null || !in_array($successor['user_id'],$present,true)) GroupRules::conflict();
                $this->store->setRole($successor,'leader'); $command['next']['leaderId'] = $successor['user_id']; $this->loadPlayer($command,$successor['user_id']); $command['forced'][$successor['user_id']] = true;
            }
            $this->remove($command,$member);
        }
        $deleting = $command['next']['memberCount'] === 0;
        foreach (array_merge($old['invitations'],$old['joinRequests']) as $entry) if ($deleting || $entry['userId'] === $user || $entry['issuerId'] === $user) { $this->clearPending($command,$entry['userId']); $command['dirty'] = true; }
        $command['deleted'] = $deleting; $this->commit($command);
    }
    public function cleanupDeleted(?string $after = null): array
    {
        if ($after !== null) $after = GroupRules::uuid($after,'INVALID_PAGE');
        $ids = $this->store->deletedPlayers($after); $results = [];
        foreach (array_slice($ids,0,10) as $user) {
            $restored = $this->transaction(function () use ($user) { if (!$this->store->players([$user],true)) return false; $this->store->rebind($user); return true; });
            $results[] = $restored ? ['userId' => $user,'updated' => 0,'complete' => false,'restored' => true] : $this->cleanupDeletedPlayer($user);
        }
        return ['results' => $results,'nextAfterUserId' => count($ids) > 10 ? $ids[9] : null];
    }
}
