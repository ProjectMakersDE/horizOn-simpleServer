<?php

declare(strict_types=1);
require_once __DIR__.'/MatchTransitions.php';
require_once __DIR__.'/MatchStorage.php';
/** One installation. All page queries select summaries, never opaque payloads or reports. */
class MatchStore
{
    private PDO $pdo; private bool $mysql;
    private const COLUMNS='id,revision,store_revision,payload_revision,payload_bytes,creator_id,status,visibility,strength,open_for_join,lobby_deadline,due_millis,data,summary';
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    private function lock(bool $lock): string{return $lock&&$this->mysql?' FOR UPDATE':'';}
    private function one(string $sql,array $args=[],bool $lock=false): ?array{$q=$this->pdo->prepare($sql.$this->lock($lock));$q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;}
    public function users(array $ids,bool $lock=false): array
    {$ids=array_values(array_unique($ids));sort($ids,SORT_STRING);if(!$ids)return[];if(count($ids)>256)throw new LogicException('User projection bound');$q=$this->pdo->prepare('SELECT id FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.$this->lock($lock));$q->execute($ids);return $q->fetchAll(PDO::FETCH_COLUMN);}
    public function blocked(string $actor,array $peers,bool $lock=false): array
    {$ids=array_values(array_unique(array_merge([$actor],$peers)));sort($ids,SORT_STRING);if(count($ids)>2049)throw new LogicException('Block projection bound');$q=$this->pdo->prepare('SELECT user_id,blocked FROM friends_state WHERE user_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY user_id'.$this->lock($lock));$q->execute($ids);$blocked=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r){$list=array_column(json_decode($r['blocked'],true,512,JSON_THROW_ON_ERROR),'userId');if($r['user_id']===$actor)$blocked=array_merge($blocked,array_intersect($list,$peers));elseif(in_array($actor,$list,true))$blocked[]=$r['user_id'];}return array_values(array_unique($blocked));}
    public function player(string $user,bool $lock=false): array
    {
        if($lock&&$this->mysql){$snapshot=$this->player($user);$current=$this->one('SELECT revision FROM player_matches WHERE user_id=?',[$user],true);if(($current!==null)!==$snapshot['exists']||($current!==null&&(int)$current['revision']!==$snapshot['revision']))MatchRules::fail('STATE_CONFLICT');return $snapshot;}
        $r=$this->one('SELECT revision,data,cleanup_after FROM player_matches WHERE user_id=?',[$user],$lock);if($r===null)return MatchRules::emptyPlayer();$v=MatchStorage::json($r['data']);
        if(array_key_exists('v',$v))$v=MatchStorage::playerRead($v);
        else{
            if(array_diff(array_keys($v),['activeIds','historyIds','invitedIds']))MatchStorage::required();
            foreach(['activeIds','historyIds','invitedIds'] as $key){if(!isset($v[$key])||!is_array($v[$key])||($v[$key]&&array_keys($v[$key])!==range(0,count($v[$key])-1))||count(array_unique($v[$key],SORT_REGULAR))!==count($v[$key]))MatchStorage::required();foreach($v[$key] as $id)MatchStorage::uuid($id);}
            $v+=['settlementIds'=>[],'closedIds'=>[],'closedAt'=>[]];$records=$this->many($v['historyIds']);foreach($v['historyIds'] as $id){$record=$records[$id]??null;if($record===null)MatchStorage::required();if(!in_array($user,$record['participantIds'],true))MatchStorage::required();if(in_array($record['status'],MatchRules::TERMINAL,true)){$v['closedIds'][]=$id;$v['closedAt'][$id]=MatchStorage::number($record['finishedAt']);}else$v['settlementIds'][]=$id;}
        }
        if($r['cleanup_after']!==null)MatchStorage::uuid($r['cleanup_after']);return array_replace($v,['revision'=>MatchStorage::sqlNumber($r['revision']),'exists'=>true,'cleanupAfter'=>$r['cleanup_after'],'_storedBytes'=>strlen($r['data'])]);
    }
    private function playerData(array $before,array $after,bool $reserveCompletion):string
    {$data=MatchStorage::playerWrite($after);if((strlen($data)>65535&&($this->mysql||strlen($data)>($before['_storedBytes']??0)))||($reserveCompletion&&MatchStorage::completionBytes($after)>65535))MatchRules::fail('STATE_SIZE_LIMIT_REACHED',422);return $data;}
    private function writePlayer(string $user,array $before,array $after,string $data):array
    {$after['revision']=MatchRules::next($before['revision']);if(!$before['exists'])$this->pdo->prepare('INSERT INTO player_matches(user_id,active_user_id,revision,data,cleanup_after) VALUES(?,?,?,?,?)')->execute([$user,$user,$after['revision'],$data,$after['cleanupAfter']]);else{$q=$this->pdo->prepare('UPDATE player_matches SET revision=?,data=?,cleanup_after=? WHERE user_id=? AND revision=?');$q->execute([$after['revision'],$data,$after['cleanupAfter'],$user,$before['revision']]);if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');}$after['exists']=true;return $after;}
    public function savePlayer(string $user,array $before,array $after):array{return $this->writePlayer($user,$before,$after,$this->playerData($before,$after,false));}
    /** Validate every changed player before the first DML; no repeated reads or serialization. */
    public function savePlayers(array $changes):void
    {$plans=[];foreach($changes as [$user,$before,$after,$reserve])$plans[]=[$user,$before,$after,$this->playerData($before,$after,$reserve)];foreach($plans as [$user,$before,$after,$data])$this->writePlayer($user,$before,$after,$data);}
    public function removePlayer(string $user,int $revision): void{$q=$this->pdo->prepare('DELETE FROM player_matches WHERE user_id=? AND revision=?');$q->execute([$user,$revision]);if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');}
    public function rebind(string $user): void{$this->pdo->prepare('UPDATE player_matches SET active_user_id=?,cleanup_after=NULL WHERE user_id=? AND active_user_id IS NULL')->execute([$user,$user]);}
    public function deleted(?string $after): array{$q=$this->pdo->prepare('SELECT user_id FROM player_matches WHERE active_user_id IS NULL'.($after===null?'':' AND user_id>?').' ORDER BY user_id LIMIT 11');$q->execute($after===null?[]:[$after]);return $q->fetchAll(PDO::FETCH_COLUMN);}
    public function get(string $id,bool $lock=false): ?array
    {
        if($lock&&$this->mysql){$snapshot=$this->get($id);$current=$this->one('SELECT store_revision FROM matches_state WHERE id=?',[$id],true);if(($current!==null)!==($snapshot!==null)||($current!==null&&(int)$current['store_revision']!==$snapshot['_version']))MatchRules::fail('STATE_CONFLICT');return $snapshot;}
        $r=$this->one('SELECT '.self::COLUMNS.' FROM matches_state WHERE id=?',[$id],$lock);return $r===null?null:MatchStorage::read($r);
    }
    public static function summary(array $v): array
    {return array_intersect_key($v,array_flip(['id','name','revision','creatorId','capacity','visibility','strength','status','participantIds','invitedIds','forfeitedIds','currentPlayerId','turnDeadline','createdAt','startedAt','finishedAt','purgeAt','finalStateRevision','hasDisagreement','ranked','absoluteDeadline','lobbyDeadline']));}
    private function values(array $v,int $version): array
    {[$header,$reports]=MatchStorage::write($v);return[$v['id'],$v['revision'],$version,$v['payloadRevision'],$v['payloadBytes'],$v['creatorId'],$v['status'],$v['visibility'],$v['strength'],(int)$v['openForJoin'],$v['lobbyDeadline'],$v['dueAt'],$reports,$header];}
    private function payloadWrite(string $sql,array $args,string $bytes): int
    {$q=$this->pdo->prepare($sql);$q->bindValue(1,$bytes,$bytes===''?PDO::PARAM_STR:PDO::PARAM_LOB);foreach($args as$i=>$arg)$q->bindValue($i+2,$arg,is_int($arg)?PDO::PARAM_INT:PDO::PARAM_STR);$q->execute();return $q->rowCount();}
    public function insert(array $v,string $bytes): void
    {$this->pdo->prepare('INSERT INTO matches_state('.self::COLUMNS.') VALUES('.implode(',',array_fill(0,14,'?')).')')->execute($this->values($v,1));$this->payloadWrite('INSERT INTO match_payloads(payload,id,revision) VALUES(?,?,?)',[$v['id'],$v['payloadRevision']],$bytes);}
    public function save(array $before,array $v,?string $bytes=null): void
    {$sets=array_map(static function($column){return $column.'=?';},explode(',',self::COLUMNS));$q=$this->pdo->prepare('UPDATE matches_state SET '.implode(',',$sets).' WHERE id=? AND store_revision=?');$q->execute(array_merge($this->values($v,MatchRules::next($before['_version'])),[$before['id'],$before['_version']]));if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');if($bytes!==null&&$this->payloadWrite('UPDATE match_payloads SET payload=?,revision=? WHERE id=? AND revision=? AND '.$this->payloadLength().'=?'.$this->payloadTypes(),[$v['payloadRevision'],$v['id'],$before['payloadRevision'],$before['payloadBytes']],$bytes)!==1)MatchRules::fail('STATE_CONFLICT');}
    private function payloadLength():string{return $this->mysql?'LENGTH(payload)':'LENGTH(CAST(payload AS BLOB))';}
    private function payloadTypes():string{return $this->mysql?'':" AND typeof(revision)='integer' AND (typeof(payload)='blob' OR (typeof(payload)='text' AND LENGTH(CAST(payload AS BLOB))=0))";}
    public function payload(array $v): string
    {$r=$this->one('SELECT revision,payload,'.($this->mysql?"'blob'":'typeof(payload)').' AS storage_type FROM match_payloads WHERE id=?',[$v['id']]);if($r===null)MatchRules::fail('STATE_CONFLICT');if(!is_string($r['payload'])||($r['storage_type']!=='blob'&&!($r['storage_type']==='text'&&$r['payload']==='')))MatchStorage::required();if(MatchStorage::sqlNumber($r['revision'])!==$v['payloadRevision']||strlen($r['payload'])!==$v['payloadBytes'])MatchRules::fail('STATE_CONFLICT');return $r['payload'];}
    public function remove(array $v): void
    {$q=$this->pdo->prepare('DELETE FROM match_payloads WHERE id=? AND revision=? AND '.$this->payloadLength().'=?'.$this->payloadTypes());$q->bindValue(1,$v['id'],PDO::PARAM_STR);$q->bindValue(2,$v['payloadRevision'],PDO::PARAM_INT);$q->bindValue(3,$v['payloadBytes'],PDO::PARAM_INT);$q->execute();if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');$q=$this->pdo->prepare('DELETE FROM matches_state WHERE id=? AND store_revision=?');$q->execute([$v['id'],$v['_version']]);if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');}
    public function many(array $ids): array
    {$rows=[];foreach(array_chunk(array_values(array_unique($ids)),128) as $batch){$q=$this->pdo->prepare('SELECT '.MatchStorage::SQL_FIELDS.',summary FROM matches_state WHERE id IN ('.implode(',',array_fill(0,count($batch),'?')).')');$q->execute($batch);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)$rows[$r['id']]=MatchStorage::summary($r);}return $rows;}
    public function config(bool $lock=false): array{$r=$this->one('SELECT revision,board_key FROM match_config WHERE id=1',[],$lock);return$r===null?['revision'=>0,'rankedLeaderboardKey'=>null,'exists'=>false]:['revision'=>(int)$r['revision'],'rankedLeaderboardKey'=>$r['board_key'],'exists'=>true];}
    public function saveConfig(array $before,?string $key): array
    {$revision=MatchRules::next($before['revision']);if(!$before['exists'])$this->pdo->prepare('INSERT INTO match_config(id,revision,board_key) VALUES(1,?,?)')->execute([$revision,$key]);else{$q=$this->pdo->prepare('UPDATE match_config SET revision=?,board_key=? WHERE id=1 AND revision=?');$q->execute([$revision,$key,$before['revision']]);if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');}return['revision'=>$revision,'rankedLeaderboardKey'=>$key];}
    public function openSql(int $min,int $max,?array $after,int $limit=256,bool $equal=false): array
    {
        if($limit<1||$limit>256)throw new LogicException('Candidate bound');$where=['open_for_join=1'];$args=[];
        if($after===null){$where[]='strength>=?';$args[]=$min;}elseif($this->mysql){if($equal){$where[]='strength=? AND id>?';array_push($args,$after['strength'],$after['id']);}else{$where[]='strength>?';$args[]=$after['strength'];}}else{$where[]='(strength,id)>(?,?)';array_push($args,$after['strength'],$after['id']);}
        $where[]='strength<=?';$args[]=$max;$hint=$this->mysql?' FORCE INDEX (idx_matches_open)':' INDEXED BY idx_matches_open';return['SELECT '.MatchStorage::SQL_FIELDS.',summary FROM matches_state'.$hint.' WHERE '.implode(' AND ',$where).' ORDER BY strength,id LIMIT '.$limit,$args];
    }
    private function summaries(string $sql,array $args): array{$q=$this->pdo->prepare($sql);$q->execute($args);return array_map([MatchStorage::class,'summary'],$q->fetchAll(PDO::FETCH_ASSOC));}
    public function open(int $min,int $max,?array $after): array
    {if($this->mysql&&$after!==null){[$sql,$args]=$this->openSql($min,$max,$after,256,true);$rows=$this->summaries($sql,$args);if(count($rows)<256){[$sql,$args]=$this->openSql($min,$max,$after,256-count($rows));$rows=array_merge($rows,$this->summaries($sql,$args));}return $rows;}[$sql,$args]=$this->openSql($min,$max,$after);return $this->summaries($sql,$args);}
    public function list(?string $status,?string $after,int $limit): array
    {$where=[];$args=[];if($status!==null){$where[]='status=?';$args[]=$status;}if($after!==null){$where[]='id>?';$args[]=$after;}$hint=$status===null?'':($this->mysql?' FORCE INDEX (idx_matches_status)':' INDEXED BY idx_matches_status');return $this->summaries('SELECT '.MatchStorage::SQL_FIELDS.',summary FROM matches_state'.$hint.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id LIMIT '.($limit+1),$args);}
    public function maintenance(string $kind,int $now,bool $lock=false): array
    {$r=$this->one('SELECT revision,cutoff_millis,after_due,after_id FROM match_maintenance WHERE kind=?',[$kind],$lock);return$r===null?['revision'=>0,'cutoff'=>$now,'afterDue'=>null,'afterId'=>null,'exists'=>false]:['revision'=>(int)$r['revision'],'cutoff'=>(int)$r['cutoff_millis'],'afterDue'=>$r['after_due']===null?null:(int)$r['after_due'],'afterId'=>$r['after_id'],'exists'=>true];}
    public function saveMaintenance(string $kind,array $before,array $after): void
    {$rev=MatchRules::next($before['revision']);if(!$before['exists'])$this->pdo->prepare('INSERT INTO match_maintenance(kind,revision,cutoff_millis,after_due,after_id) VALUES(?,?,?,?,?)')->execute([$kind,$rev,$after['cutoff'],$after['afterDue'],$after['afterId']]);else{$q=$this->pdo->prepare('UPDATE match_maintenance SET revision=?,cutoff_millis=?,after_due=?,after_id=? WHERE kind=? AND revision=?');$q->execute([$rev,$after['cutoff'],$after['afterDue'],$after['afterId'],$kind,$before['revision']]);if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');}}
    public function dueSql(array $cursor,int $limit=20,bool $equal=false): array
    {
        if($limit<1||$limit>20)throw new LogicException('Maintenance bound');
        $where=['due_millis<=?'];$args=[$cursor['cutoff']];
        if($cursor['afterId']!==null){
            if($this->mysql){if($equal){$where[]='due_millis=? AND id>?';array_push($args,$cursor['afterDue'],$cursor['afterId']);}else{$where[]='due_millis>?';$args[]=$cursor['afterDue'];}}
            else{$where[]='(due_millis,id)>(?,?)';array_push($args,$cursor['afterDue'],$cursor['afterId']);}
        }
        $hint=$this->mysql?' FORCE INDEX (idx_matches_due)':' INDEXED BY idx_matches_due';
        return['SELECT id,revision,due_millis FROM matches_state'.$hint.' WHERE '.implode(' AND ',$where).' ORDER BY due_millis,id LIMIT '.$limit,$args];
    }
    private function dueRows(array $query): array{[$sql,$args]=$query;$q=$this->pdo->prepare($sql);$q->execute($args);return$q->fetchAll(PDO::FETCH_ASSOC);}
    public function due(array $cursor): array
    {return $this->dueBounded($cursor,20);}
    public function dueBounded(array $cursor,int $limit): array
    {
        if($this->mysql&&$cursor['afterId']!==null){$rows=$this->dueRows($this->dueSql($cursor,$limit,true));if(count($rows)<$limit)$rows=array_merge($rows,$this->dueRows($this->dueSql($cursor,$limit-count($rows))));return$rows;}
        return$this->dueRows($this->dueSql($cursor,$limit));
    }
    /** At most20 terminal metadata candidates, each phase uses (status,id), never OFFSET/JSON scans. */
    public function historyPage(array $cursor,int $limit):array
    {
        if($limit<1||$limit>20)throw new LogicException('History candidate bound');$status=$cursor['afterDue']??0;$after=$cursor['afterId'];$rows=[];
        while($status<count(MatchRules::TERMINAL)&&count($rows)<$limit){$args=[MatchRules::TERMINAL[$status]];if($after!==null)$args[]=$after;$remaining=$limit-count($rows);$q=$this->pdo->prepare('SELECT id,revision FROM matches_state'.($this->mysql?' FORCE INDEX (idx_matches_status)':' INDEXED BY idx_matches_status').' WHERE status=?'.($after===null?'':' AND id>?').' ORDER BY id LIMIT '.$remaining);$q->execute($args);$batch=$q->fetchAll(PDO::FETCH_ASSOC);$rows=array_merge($rows,$batch);
            if(count($batch)===$remaining){$after=$batch[count($batch)-1]['id'];break;}$status++;$after=null;
        }
        if($status>=count(MatchRules::TERMINAL)){$status=0;$after=null;}return ['rows'=>$rows,'afterDue'=>$status,'afterId'=>$after];
    }
}
