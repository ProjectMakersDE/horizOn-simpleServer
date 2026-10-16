<?php

declare(strict_types=1);
require_once __DIR__.'/SharedObjectRules.php';
/** One installation/project. Every seek reads metadata only; payloads are fetched by selected IDs. */
class SharedObjectStore
{
    private PDO $pdo; private bool $mysql;
    private const COLUMNS='id,collection_key,partition_key,owner_id,group_id,revision,payload_bytes,charged_bytes,search_json,position_json,slot0,slot1,slot2,position_x,created_millis,updated_millis,expires_millis';
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    private function lock(bool $lock): string{return $lock&&$this->mysql?' FOR UPDATE':'';}
    private function one(string $sql,array $args=[],bool $lock=false): ?array{$q=$this->pdo->prepare($sql.$this->lock($lock));$q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;}
    public function players(array $ids,bool $lock=false): array
    {
        $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);if(!$ids)return [];if(count($ids)>256)throw new LogicException('Player projection bound');
        $q=$this->pdo->prepare('SELECT id FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.$this->lock($lock));$q->execute($ids);return $q->fetchAll(PDO::FETCH_COLUMN);
    }
    public function catalog(bool $lock=false): array{$r=$this->one('SELECT revision,total FROM shared_object_catalog WHERE id=1',[],$lock);return $r===null?['revision'=>0,'total'=>0,'exists'=>false]:['revision'=>(int)$r['revision'],'total'=>(int)$r['total'],'exists'=>true];}
    public function saveCatalog(array $before,array $next): void
    {
        if(!$before['exists'])$this->pdo->prepare('INSERT INTO shared_object_catalog(id,revision,total) VALUES(1,?,?)')->execute([$next['revision'],$next['total']]);
        else{$q=$this->pdo->prepare('UPDATE shared_object_catalog SET revision=?,total=? WHERE id=1 AND revision=?');$q->execute([$next['revision'],$next['total'],$before['revision']]);if($q->rowCount()!==1)SharedObjectRules::fail('COLLECTION_REVISION_CONFLICT');}
    }
    public function collection(string $key,bool $lock=false): ?array{$r=$this->one('SELECT data FROM shared_object_collections WHERE collection_key=?',[$key],$lock);return $r===null?null:json_decode($r['data'],true,32,JSON_THROW_ON_ERROR);}
    public function saveCollection(?array $before,array $next): void
    {
        $data=SharedObjectRules::json($next);
        if($before===null)$this->pdo->prepare('INSERT INTO shared_object_collections(collection_key,revision,data) VALUES(?,?,?)')->execute([$next['key'],$next['revision'],$data]);
        else{$q=$this->pdo->prepare('UPDATE shared_object_collections SET revision=?,data=? WHERE collection_key=? AND revision=?');$q->execute([$next['revision'],$data,$next['key'],$before['revision']]);if($q->rowCount()!==1)SharedObjectRules::fail('COLLECTION_REVISION_CONFLICT');}
    }
    public function collections(?string $after,int $limit): array
    {$q=$this->pdo->prepare('SELECT data FROM shared_object_collections'.($after===null?'':' WHERE collection_key>?').' ORDER BY collection_key LIMIT '.($limit+1));$q->execute($after===null?[]:[$after]);return array_map(static function($r){return json_decode($r['data'],true,32,JSON_THROW_ON_ERROR);},$q->fetchAll(PDO::FETCH_ASSOC));}
    public function usage(string $collection,string $user,bool $lock=false): array
    {$r=$this->one('SELECT revision,count,charged_bytes FROM shared_object_usage WHERE collection_key=? AND user_id=?',[$collection,$user],$lock);return $r===null?['revision'=>0,'count'=>0,'chargedBytes'=>SharedObjectRules::OWNER,'exists'=>false]:['revision'=>(int)$r['revision'],'count'=>(int)$r['count'],'chargedBytes'=>(int)$r['charged_bytes'],'exists'=>true];}
    public function saveUsage(string $collection,string $user,array $before,int $count): array
    {
        if($count<0)SharedObjectRules::fail('STATE_CONFLICT');$next=$before;$next['revision']=SharedObjectRules::next($before['revision']);$next['count']=$count;
        if(!$before['exists'])$this->pdo->prepare('INSERT INTO shared_object_usage(collection_key,user_id,active_user_id,revision,count,charged_bytes) VALUES(?,?,?,?,?,?)')->execute([$collection,$user,$user,$next['revision'],$count,SharedObjectRules::OWNER]);
        else{$q=$this->pdo->prepare('UPDATE shared_object_usage SET revision=?,count=? WHERE collection_key=? AND user_id=? AND revision=?');$q->execute([$next['revision'],$count,$collection,$user,$before['revision']]);if($q->rowCount()!==1)SharedObjectRules::fail('STATE_CONFLICT');}
        $next['exists']=true;return $next;
    }
    public function quota(bool $lock=false): array{$r=$this->one('SELECT revision,charged_bytes FROM shared_object_quota WHERE id=1',[],$lock);return $r===null?['revision'=>0,'chargedBytes'=>0,'exists'=>false]:['revision'=>(int)$r['revision'],'chargedBytes'=>(int)$r['charged_bytes'],'exists'=>true];}
    public function saveQuota(array $before,int $bytes): void
    {
        $revision=SharedObjectRules::next($before['revision']);
        if(!$before['exists'])$this->pdo->prepare('INSERT INTO shared_object_quota(id,revision,charged_bytes) VALUES(1,?,?)')->execute([$revision,$bytes]);
        else{$q=$this->pdo->prepare('UPDATE shared_object_quota SET revision=?,charged_bytes=? WHERE id=1 AND revision=?');$q->execute([$revision,$bytes,$before['revision']]);if($q->rowCount()!==1)SharedObjectRules::fail('STATE_CONFLICT');}
    }
    private function row(array $r): array
    {
        foreach(['revision','payload_bytes','charged_bytes','created_millis','updated_millis'] as $f)$r[$f]=(int)$r[$f];$r['expires_millis']=$r['expires_millis']===null?null:(int)$r['expires_millis'];
        $r['search']=json_decode($r['search_json'],false,16,JSON_THROW_ON_ERROR);$r['position']=$r['position_json']===null?null:json_decode($r['position_json'],true,8,JSON_THROW_ON_ERROR);return $r;
    }
    public function object(string $collection,string $id,bool $lock=false): ?array{$r=$this->one('SELECT '.self::COLUMNS.' FROM shared_objects WHERE id=? AND collection_key=?',[$id,$collection],$lock);return $r===null?null:$this->row($r);}
    private function metadata(array $r): array{return [$r['id'],$r['collection_key'],$r['partition_key'],$r['owner_id'],$r['group_id'],$r['revision'],$r['payload_bytes'],$r['charged_bytes'],SharedObjectRules::json($r['search']),$r['position']===null?null:SharedObjectRules::json($r['position']),$r['slot0'],$r['slot1'],$r['slot2'],$r['position_x'],$r['created_millis'],$r['updated_millis'],$r['expires_millis']];}
    private function payloadWrite(string $sql,array $r,?int $expected=null): int
    {
        // Older PDO SQLite returns an empty PARAM_LOB as null. Bind the empty byte sequence
        // explicitly as a string; nonempty binary data still uses LOB binding unchanged.
        $q=$this->pdo->prepare($sql);$q->bindValue(1,$r['revision'],PDO::PARAM_INT);$q->bindValue(2,$r['payload'],$r['payload']===''?PDO::PARAM_STR:PDO::PARAM_LOB);$q->bindValue(3,$r['id']);$q->bindValue(4,$r['collection_key']);if($expected!==null)$q->bindValue(5,$expected,PDO::PARAM_INT);$q->execute();return $q->rowCount();
    }
    public function insert(array $r): void
    {
        $this->pdo->prepare('INSERT INTO shared_objects('.self::COLUMNS.') VALUES('.implode(',',array_fill(0,17,'?')).')')->execute($this->metadata($r));
        $this->payloadWrite('INSERT INTO shared_object_payloads(revision,payload,id,collection_key) VALUES(?,?,?,?)',$r);
    }
    public function save(array $before,array $r): void
    {
        $values=$this->metadata($r);$columns=explode(',',self::COLUMNS);$assign=[];foreach($columns as $column)$assign[]=$column.'=?';
        $q=$this->pdo->prepare('UPDATE shared_objects SET '.implode(',',$assign).' WHERE id=? AND collection_key=? AND revision=?');$q->execute(array_merge($values,[$before['id'],$before['collection_key'],$before['revision']]));
        if($q->rowCount()!==1||$this->payloadWrite('UPDATE shared_object_payloads SET revision=?,payload=? WHERE id=? AND collection_key=? AND revision=?',$r,$before['revision'])!==1)SharedObjectRules::fail('REVISION_CONFLICT');
    }
    public function remove(array $r): void
    {
        foreach(['shared_object_payloads','shared_objects'] as $table){$q=$this->pdo->prepare('DELETE FROM '.$table.' WHERE id=? AND collection_key=? AND revision=?');$q->execute([$r['id'],$r['collection_key'],$r['revision']]);if($q->rowCount()!==1)SharedObjectRules::fail('REVISION_CONFLICT');}
    }
    public function payloads(string $collection,array $rows): array
    {
        if(count($rows)>50)throw new LogicException('Payload selection bound');if(!$rows)return [];
        $ids=array_column($rows,'id');$q=$this->pdo->prepare('SELECT id,revision,payload FROM shared_object_payloads WHERE collection_key=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute(array_merge([$collection],$ids));$result=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $p)$result[$p['id']]=$p;
        foreach($rows as $r)if(!isset($result[$r['id']])||(int)$result[$r['id']]['revision']!==$r['revision']||strlen($result[$r['id']]['payload'])!==$r['payload_bytes'])SharedObjectRules::fail('STATE_CONFLICT');
        return $result;
    }
    public function seekSql(string $collection,array $seek,int $limit): array
    {
        if($limit<1||$limit>256)throw new LogicException('Metadata candidate bound');$field=$seek['field'];if(!in_array($field,['id','slot0','slot1','slot2','position_x'],true))throw new LogicException('Invalid sort column');
        $index=$seek['owner']!==null?'idx_shared_owner_page':'idx_shared_'.$field.'_page';$where=['collection_key=?','partition_key=?'];$args=[$collection,$seek['partition']];
        if($seek['owner']!==null){if($field!=='id')throw new LogicException('Owner seek order');$where[]='owner_id=?';$args[]=$seek['owner'];}
        if($seek['lower']!==null&&!(!$this->mysql&&$seek['afterId']!==null&&$field!=='id')){$where[]=$field.(!empty($seek['lowerExclusive'])?'>':'>=') .'?';$args[]=$seek['lower'];}if($seek['upper']!==null){$where[]=$field.($seek['exclusive']?'<':'<=') .'?';$args[]=$seek['upper'];}
        if($seek['afterId']!==null){if($field==='id'){$where[]='id>?';$args[]=$seek['afterId'];}else{if($this->mysql&&!empty($seek['equalResume'])){$where[]=$field.'=? AND id>?';array_push($args,$seek['afterSort'],$seek['afterId']);}elseif($this->mysql){$where[]='('.$field.'>? OR ('.$field.'=? AND id>?))';array_push($args,$seek['afterSort'],$seek['afterSort'],$seek['afterId']);}else{$where[]='('.$field.',id)>(?,?)';array_push($args,$seek['afterSort'],$seek['afterId']);}}}
        $hint=$this->mysql?' FORCE INDEX ('.$index.')':' INDEXED BY '.$index;
        return ['SELECT '.self::COLUMNS.' FROM shared_objects'.$hint.' WHERE '.implode(' AND ',$where).' ORDER BY '.($field==='id'?'id':$field.',id').' LIMIT '.$limit,$args];
    }
    public function candidates(string $collection,array $seek,int $limit): array
    {
        // InnoDB does not reliably turn row-constructor inequalities into a full compound range.
        // Resume the equal sort value by ID, then strictly later values, with one shared row budget.
        if($this->mysql&&$seek['afterId']!==null&&$seek['field']!=='id'){
            $equal=$seek;$equal['lower']=$seek['afterSort'];$equal['upper']=$seek['afterSort'];$equal['exclusive']=false;$equal['equalResume']=true;
            $rows=$this->seekRows($collection,$equal,$limit);
            if(count($rows)<$limit&&($seek['upper']===null||strcmp($seek['afterSort'],$seek['upper'])<0)){$tail=$seek;$tail['lower']=$seek['afterSort'];$tail['lowerExclusive']=true;$tail['afterId']=null;$tail['afterSort']=null;$rows=array_merge($rows,$this->seekRows($collection,$tail,$limit-count($rows)));}
            return $rows;
        }
        return $this->seekRows($collection,$seek,$limit);
    }
    private function seekRows(string $collection,array $seek,int $limit): array
    {[$sql,$args]=$this->seekSql($collection,$seek,$limit);$q=$this->pdo->prepare($sql);$q->execute($args);return array_map([$this,'row'],$q->fetchAll(PDO::FETCH_ASSOC));}
    private function batch(string $where,array $args,string $order,string $index,int $limit=20): array
    {$hint=$this->mysql?' FORCE INDEX ('.$index.')':' INDEXED BY '.$index;$q=$this->pdo->prepare('SELECT '.self::COLUMNS.' FROM shared_objects'.$hint.' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$limit);$q->execute($args);return array_map([$this,'row'],$q->fetchAll(PDO::FETCH_ASSOC));}
    public function expired(int $now): array{return $this->batch('expires_millis IS NOT NULL AND expires_millis<=?',[$now],'expires_millis,id','idx_shared_expiry');}
    public function owned(string $user): array{return $this->batch('owner_id=?',[$user],'id','idx_shared_deleted_owner');}
    public function ownerUsages(string $user): array{$q=$this->pdo->prepare('SELECT collection_key FROM shared_object_usage WHERE user_id=? ORDER BY collection_key LIMIT 20');$q->execute([$user]);return $q->fetchAll(PDO::FETCH_COLUMN);}
    public function removeUsage(string $collection,string $user,array $usage): void{$q=$this->pdo->prepare('DELETE FROM shared_object_usage WHERE collection_key=? AND user_id=? AND revision=? AND count=0');$q->execute([$collection,$user,$usage['revision']]);if($q->rowCount()!==1)SharedObjectRules::fail('STATE_CONFLICT');}
    public function deletedOwners(?string $after): array{$q=$this->pdo->prepare('SELECT DISTINCT user_id FROM shared_object_usage WHERE active_user_id IS NULL'.($after===null?'':' AND user_id>?').' ORDER BY user_id LIMIT 11');$q->execute($after===null?[]:[$after]);return $q->fetchAll(PDO::FETCH_COLUMN);}
    public function rebind(string $user): void
    {$q=$this->pdo->prepare('SELECT collection_key FROM shared_object_usage WHERE active_user_id IS NULL AND user_id=? ORDER BY collection_key LIMIT 20');$q->execute([$user]);$keys=$q->fetchAll(PDO::FETCH_COLUMN);if($keys)$this->pdo->prepare('UPDATE shared_object_usage SET active_user_id=? WHERE user_id=? AND active_user_id IS NULL AND collection_key IN ('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_merge([$user,$user],$keys));}
}
