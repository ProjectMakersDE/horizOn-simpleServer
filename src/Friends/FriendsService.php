<?php

declare(strict_types=1);
require_once __DIR__ . '/FriendsRules.php';
require_once __DIR__ . '/FriendsLimits.php';
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
    private array $limits;
    public function __construct(PDO $pdo, ?callable $clock = null, ?SqlTransaction $transactions = null, ?int $maxFriends = null, ?array $limits = null)
    {
        $this->limits = $limits ?? FriendsLimits::values();
        if ($maxFriends !== null) $this->limits['maxFriends'] = $maxFriends;
        $this->limits = FriendsLimits::validate($this->limits);
        $this->pdo = $pdo; $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->transactions = $transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Foreign transaction connection');
        $this->clock = $clock ?? static function () { return Database::nowMillis(); };
        $this->maxFriends = $this->limits['maxFriends'];
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
    private function requireWriteScope(): void
    {
        if($this->pdo->inTransaction()&&!$this->transactions->isRunning())
            FriendsRules::fail('STATE_CONFLICT','Mutations require the caller to share this explicit SqlTransaction scope');
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
        return FriendsStorage::state($row,$user);
    }
    private function save(string $user, array $before, array $after): array
    {
        if ($before === $after) return $before;
        if ($before['revision'] >= FriendsRules::MAX) FriendsRules::fail('STATE_CONFLICT', 'Relationship revision exhausted');
        $after['revision'] = $before['revision'] + 1;
        $data = [$after['revision']];
        foreach (FriendsStorage::FIELDS as $field) {
            $encoded = ($before['_legacy']??false)?InboxRules::json($after[$field]):FriendsStorage::encode($after[$field],$field);
            $oldEncoded = ($before['_legacy']??false)?InboxRules::json($before[$field]):FriendsStorage::encode($before[$field],$field);
            if (strlen($encoded)>FriendsStorage::FIELD_BYTES && ($this->mysql || strlen($encoded)>strlen($oldEncoded)))
                FriendsRules::fail('STATE_CONFLICT','Relationship exceeds portable SQL TEXT frame');
            $data[] = $encoded;
        }
        if ($before['exists']) {
            $query = $this->pdo->prepare('UPDATE friends_state SET revision=?,friends=?,incoming=?,outgoing=?,blocked=? WHERE user_id=? AND revision=?');
            $query->execute(array_merge($data, [$user, $before['revision']]));
            if ($query->rowCount() !== 1) FriendsRules::conflict();
        } else $this->pdo->prepare('INSERT INTO friends_state(revision,friends,incoming,outgoing,blocked,user_id,active_user_id) VALUES(?,?,?,?,?,?,?)')->execute(array_merge($data, [$user, $user]));
        $this->references($user,$before,$after);
        $after['exists'] = true;
        // Legacy mutations retain their union index until an explicit bilateral cold proof permits removal.
        if (!($before['_legacy']??false)) foreach (FriendsStorage::FIELDS as $field) $after[$field]=FriendsStorage::field(FriendsStorage::encode($after[$field],$field),$field);
        return $after;
    }
    /** Only reverse blocks need an index; bilateral edges are their own deletion retry anchor. */
    private function references(string $user,array $before,array $after): void
    {
        $old=FriendsStorage::references($before,$before['_legacy']??false);$new=FriendsStorage::references($after,$after['_legacy']??false);
        if ($before['_legacy']??false) {
            $q=$this->pdo->prepare('SELECT related_user_id FROM friends_references WHERE owner_user_id=? ORDER BY related_user_id');$q->execute([$user]);
            $actual=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();
            if ($actual!==$old) FriendsStorage::required();
        }
        foreach (array_chunk(array_values(array_diff($old,$new)),200) as $batch) {
            $q=$this->pdo->prepare('DELETE FROM friends_references WHERE owner_user_id=? AND related_user_id IN ('.implode(',',array_fill(0,count($batch),'?')).')');$q->execute(array_merge([$user],$batch));
            if ($q->rowCount()!==count($batch)) FriendsStorage::required();
        }
        foreach (array_chunk(array_values(array_diff($new,$old)),200) as $batch) {
            $args=[];foreach($batch as $id){$args[]=$user;$args[]=$id;}
            $this->pdo->prepare('INSERT INTO friends_references(owner_user_id,related_user_id) VALUES '.implode(',',array_fill(0,count($batch),'(?,?)')))->execute($args);
        }
    }
    private function anchor(string $user): void
    {
        $empty=FriendsStorage::encode([],'friends');
        $this->pdo->prepare('INSERT INTO friends_state(user_id,active_user_id,revision,friends,incoming,outgoing,blocked) VALUES(?,?,0,?,?,?,?)')->execute([$user,$user,$empty,$empty,$empty,$empty]);
    }
    public function limits(): array { return $this->limits; }
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
        foreach ($query->fetchAll() as $row) if (FriendsRules::has(FriendsStorage::field($row['blocked'],'blocked'), $row['user_id'] === $first ? $second : $first)) return true;
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
        $pendingAction=in_array($action, ['accept', 'decline', 'cancel'], true);
        if (!$pendingAction&&$requestId!==null) FriendsRules::invalid('requestId belongs only to an exact pending decision');
        $request = $pendingAction ? FriendsRules::uuid($requestId) : null;
        $this->requireWriteScope();
        $changed = $this->transaction(function () use ($action, $user, $target, $expected, $request) {
            $present = $this->players([$user, $target], true);
            if (!in_array($user, $present, true)) FriendsRules::missing();
            $a = $this->state($user, true); $b = $this->state($target, true);
            if ($a['revision'] !== $expected) FriendsRules::conflict();
            $now = ($this->clock)();
            [$nextA, $nextB, $notice] = FriendsRules::transition($action, $user, $target, $a, $b, $request, InboxRules::instant($now), $this->maxFriends, $this->limits);
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
    /** ON DELETE SET NULL retains a retry anchor. At most50 bilateral/reverse peers per call. */
    public function cleanupDeletedPlayer(string $user): array
    {
        $user=FriendsRules::uuid($user);$this->requireWriteScope();
        if ($this->players([$user])) FriendsRules::fail('STATE_CONFLICT','Only an absent player can be cleaned');
        $own=$this->state($user);$peers=FriendsStorage::references($own,true);$updated=0;
        $clean=function(string $owner)use($user):void {
            $this->transaction(function()use($owner,$user){
                $present=$this->players([$user,$owner],true);
                if (in_array($user,$present,true)) FriendsRules::fail('STATE_CONFLICT','Deleted player was restored');
                $source=$this->state($user,true);$peer=$this->state($owner,true);
                $nextSource=FriendsRules::without($source,$owner,true);$nextPeer=FriendsRules::without($peer,$user,true);
                if ($source===$nextSource&&$peer===$nextPeer) FriendsStorage::required();
                $this->save($user,$source,$nextSource);$this->save($owner,$peer,$nextPeer);
            });
        };
        foreach(array_slice($peers,0,50)as $peer){$clean($peer);$updated++;}
        if ($updated<50) {
            $q=$this->pdo->prepare('SELECT owner_user_id FROM friends_references WHERE related_user_id=? ORDER BY owner_user_id LIMIT '.(51-$updated));$q->execute([$user]);$owners=$q->fetchAll(PDO::FETCH_COLUMN);
            foreach(array_slice($owners,0,50-$updated)as $owner){$clean($owner);$updated++;}
        }
        $complete=$this->transaction(function()use($user){
            if ($this->players([$user],true)) FriendsRules::fail('STATE_CONFLICT','Deleted player was restored');
            $remaining=FriendsStorage::references($this->state($user,true),true);
            $q=$this->pdo->prepare('SELECT owner_user_id FROM friends_references WHERE related_user_id=? ORDER BY owner_user_id LIMIT 1');$q->execute([$user]);
            if ($remaining||$q->fetchColumn()!==false)return false;
            $this->pdo->prepare('DELETE FROM friends_state WHERE user_id=?')->execute([$user]);return true;
        });
        return ['userId'=>$user,'updated'=>$updated,'complete'=>$complete];
    }
    /** Before removing a legacy reverse union, every bilateral edge must have its exact other side. */
    private function requireLegacyBilateral(string $user,array $state): void
    {
        $ids=[];foreach(['friends','incoming','outgoing']as$field)foreach($state[$field]as$entry)$ids[$entry['userId']]=true;
        $rows=[];
        foreach(array_chunk(array_keys($ids),200)as$batch){
            $q=$this->pdo->prepare('SELECT user_id,friends,incoming,outgoing FROM friends_state WHERE user_id IN ('.implode(',',array_fill(0,count($batch),'?')).')');$q->execute($batch);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$row)$rows[$row['user_id']]=$row;$q->closeCursor();
        }
        foreach(['friends'=>'friends','incoming'=>'outgoing','outgoing'=>'incoming']as$field=>$other)foreach($state[$field]as$entry){
            $row=$rows[$entry['userId']]??null;if($row===null)FriendsStorage::required();
            $matching=array_values(array_filter(FriendsStorage::field($row[$other],$other),static function($v)use($user){return $v['userId']===$user;}));
            if(count($matching)!==1||($field!=='friends'&&$matching[0]['requestId']!==$entry['requestId']))FriendsStorage::required();
        }
    }
    /** Explicit cold conversion only. Each unchanged original and reverse index share one transaction. */
    public function compactPage(?string $after=null,int $limit=10,bool $apply=false): array
    {
        if($apply)$this->requireWriteScope();
        if($after!==null)$after=FriendsRules::uuid($after);
        if($limit<1||$limit>50)throw new InvalidArgumentException('Cold relationship page must contain1..50 rows');
        $q=$this->pdo->prepare('SELECT user_id FROM friends_state'.($after===null?'':' WHERE user_id>?').' ORDER BY user_id LIMIT '.$limit);$q->execute($after===null?[]:[$after]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();$converted=0;
        foreach($ids as $user)$converted+=$this->transaction(function()use($user,$apply){
            $this->players([$user],$apply);
            $q=$this->pdo->prepare('SELECT revision,active_user_id,friends,incoming,outgoing,blocked FROM friends_state WHERE user_id=?'.($apply&&$this->mysql?' FOR UPDATE':''));$q->execute([$user]);$row=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if($row===false)return 0;
            $state=FriendsStorage::state($row,$user);$expected=FriendsStorage::references($state,$state['_legacy']);
            $q=$this->pdo->prepare('SELECT related_user_id FROM friends_references WHERE owner_user_id=? ORDER BY related_user_id');$q->execute([$user]);$actual=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();if($actual!==$expected)FriendsStorage::required();
            $encoded=[];foreach(FriendsStorage::FIELDS as $field){$encoded[$field]=FriendsStorage::encode($state[$field],$field);if($this->mysql&&strlen($encoded[$field])>FriendsStorage::FIELD_BYTES)FriendsStorage::required();}
            if(!$state['_legacy'])return 0;
            $this->requireLegacyBilateral($user,$state);
            if($apply){
                $where='';foreach(FriendsStorage::FIELDS as $field)$where.=' AND '.($this->mysql?'BINARY '.$field.'=BINARY ?':$field.'=? COLLATE BINARY');
                $q=$this->pdo->prepare('UPDATE friends_state SET friends=?,incoming=?,outgoing=?,blocked=? WHERE user_id=? AND revision=?'.$where);
                $args=array_merge(array_values($encoded),[$user,$state['revision']],array_map(static function($field)use($row){return $row[$field];},FriendsStorage::FIELDS));$q->execute($args);if($q->rowCount()!==1)FriendsRules::conflict();
                $compact=$state;$compact['_legacy']=false;$this->references($user,$state,$compact);
            }
            return 1;
        });
        return ['examined'=>count($ids),'legacyPlayers'=>$converted,'applied'=>$apply,'nextCursor'=>$ids?end($ids):$after,'complete'=>count($ids)<$limit];
    }
    public function cleanupDeleted(): array
    {
        $this->requireWriteScope();
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
