<?php

declare(strict_types=1);

/** Every candidate owns a transaction; a full Inbox cannot stall the persistent sweep. */
trait MatchMaintenance
{
    public function cleanup(string $kind = 'admin'): array
    {
        if (!in_array($kind, ['admin', 'scheduled'], true)) MatchRules::invalid();
        $cursor = $this->atomic(function () use ($kind) {
            return $this->store->maintenance($kind, $this->now(), true);
        });
        if ($cursor['afterId'] === null) $cursor['cutoff'] = $this->now();
        $rows = $this->snapshot(function () use ($cursor) { return $this->store->due($cursor); });
        $result = ['examined'=>count($rows), 'advanced'=>0, 'notified'=>0, 'removed'=>0, 'deferred'=>0];
        foreach ($rows as $row) {
            try {
                $outcome = $this->atomic(function () use ($row) {
                    $old = $this->store->get($row['id'], true);
                    if ($old === null || $old['revision'] !== (int)$row['revision']) return ['kind'=>'unchanged','pending'=>false];
                    $now = $this->now();
                    if ($old['purgeAt'] !== null && $old['purgeAt'] <= $now) {
                        $this->updateLedgers($old, null);
                        $this->store->remove($old);
                        return ['kind'=>'removed','pending'=>false];
                    }
                    $next = $old;
                    if ($old['status'] === 'lobby' && $old['lobbyDeadline'] <= $now) $next = MatchTransitions::close($old, 'cancelled', $now);
                    elseif ($old['status'] === 'active' && $old['absoluteDeadline'] <= $now) $next = MatchTransitions::close($old, 'unconfirmed', $now);
                    elseif ($old['status'] === 'active' && $old['turnDeadline'] <= $now) $next = MatchTransitions::timeout($old, $now);
                    elseif ($old['status'] === 'reporting' && $old['reportDeadline'] <= $now) $next = MatchTransitions::close($old, 'unconfirmed', $now);
                    $changed = $next !== $old;
                    if ($changed) $this->persist($old, $next);
                    return ['kind'=>$changed?'advanced':'unchanged', 'pending'=>$next['notice'] !== null];
                });
                if ($outcome['kind'] === 'removed') $result['removed']++;
                if ($outcome['kind'] === 'advanced') $result['advanced']++;
                if ($outcome['pending']) {
                    if ($this->deliverNotice($row['id'])) $result['notified']++;
                    else $result['deferred']++;
                }
            } catch (Throwable $e) {
                $result['deferred']++;
                error_log('Match cleanup deferred: '.get_class($e));
            }
        }
        $next = $cursor;
        if (count($rows) === 20) {
            $last = $rows[count($rows)-1];
            $next['afterDue'] = (int)$last['due_millis'];
            $next['afterId'] = $last['id'];
        } else {
            $next['cutoff'] = $this->now();
            $next['afterDue'] = null;
            $next['afterId'] = null;
        }
        $this->atomic(function () use ($kind, $cursor, $next) { $this->store->saveMaintenance($kind, $cursor, $next); });
        return $result + ['serverTime'=>MatchRules::instant($this->now())];
    }

    /** A deleted identity has at most120 retained/invited references, resumed in20-row steps. */
    public function cleanupDeletedPlayer(string $user): array
    {
        $user = MatchRules::uuid($user);
        return $this->atomic(function () use ($user) {
            $player = $this->store->player($user, true);
            if (!$player['exists']) return ['userId'=>$user,'examined'=>0,'complete'=>true];
            if ($this->store->users([$user], true)) {
                $this->store->rebind($user);
                return ['userId'=>$user,'examined'=>0,'complete'=>true,'restored'=>true];
            }
            $ids = array_values(array_unique(array_merge($player['historyIds'], $player['invitedIds'])));
            sort($ids, SORT_STRING);
            $ids = array_values(array_filter($ids, function ($id) use ($player) {
                return $player['cleanupAfter'] === null || strcmp($id, $player['cleanupAfter']) > 0;
            }));
            $batch = array_slice($ids, 0, 20);
            foreach ($batch as $id) {
                $old = $this->store->get($id, true);
                if ($old === null) continue;
                $next = $old;
                if (in_array($user, $old['invitedIds'], true)) {
                    $next['invitedIds'] = array_values(array_diff($old['invitedIds'], [$user]));
                    $next = MatchTransitions::changed($next);
                }
                if (in_array($user, $old['participantIds'], true)) {
                    if ($old['status'] === 'lobby') {
                        if ($old['creatorId'] === $user) $next = MatchTransitions::close($old, 'cancelled', $this->now());
                        else {
                            $next['participantIds'] = array_values(array_diff($old['participantIds'], [$user]));
                            $next = MatchTransitions::changed($next);
                        }
                    } elseif ($old['status'] === 'active' && !in_array($user, $old['forfeitedIds'], true)) {
                        $next = MatchTransitions::forfeit($old, $user, $this->now());
                    }
                }
                if ($next !== $old) $this->persist($old, $next);
            }
            $latest = $this->store->player($user, true);
            $complete = count($ids) <= 20;
            if ($complete) $this->store->removePlayer($user, $latest['revision']);
            else {
                $after = $latest;
                $after['cleanupAfter'] = $batch[count($batch)-1];
                $this->store->savePlayer($user, $latest, $after);
            }
            return ['userId'=>$user,'examined'=>count($batch),'complete'=>$complete];
        });
    }

    public function cleanupDeleted(?string $after = null): array
    {
        if ($after !== null) $after = MatchRules::uuid($after);
        $ids = $this->snapshot(function () use ($after) { return $this->store->deleted($after); });
        $results = [];
        $next = null;
        if ($ids) {
            $result = $this->cleanupDeletedPlayer($ids[0]);
            $results[] = $result;
            $next = !$result['complete'] ? $after : (count($ids)>1 ? $ids[0] : null);
        }
        return ['results'=>$results,'nextAfterUserId'=>$next,'serverTime'=>MatchRules::instant($this->now())];
    }
}
