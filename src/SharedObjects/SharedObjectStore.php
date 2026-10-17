<?php
declare(strict_types=1);
require_once __DIR__.'/SharedObjectStorage.php';
/** Binary SQL parameters must use LOB binding on SQLite, including UUIDs without NUL. */
class SharedObjectSqlBytes { public string $value;public function __construct(string $value){$this->value=$value;} }
class SharedObjectStore
{
    private PDO $pdo;private bool $mysql;private array $definitions=[];private array $slots=[];
    public const COLUMNS='i,c,f,q,o,g,r,l,b,s0,s1,s2,x,y,a,u,e';
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    private function bytes(string $value): SharedObjectSqlBytes{return new SharedObjectSqlBytes($value);}
    private function lock(bool $lock): string{return $lock&&$this->mysql?' FOR UPDATE':'';}
    private function statement(string $sql,array $args=[]): PDOStatement {
        try{$q=$this->pdo->prepare($sql);foreach(array_values($args)as$i=>$value){$type=$value===null?PDO::PARAM_NULL:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);if($value instanceof SharedObjectSqlBytes){$value=$value->value;$type=$value===''?PDO::PARAM_STR:PDO::PARAM_LOB;}$q->bindValue($i+1,$value,$type);}$q->execute();return $q;}
        catch(PDOException $e){if(strpos($e->getMessage(),'no such column')!==false||strpos($e->getMessage(),'Unknown column')!==false)SharedObjectStorage::required();throw $e;}
    }
    private function one(string $sql,array $args=[],bool $lock=false): ?array{$r=$this->statement($sql.$this->lock($lock),$args)->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;}
    public function players(array $ids,bool $lock=false): array {
        $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);if(!$ids)return [];if(count($ids)>256)throw new LogicException('Player projection bound');
        return $this->statement('SELECT id FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.$this->lock($lock),$ids)->fetchAll(PDO::FETCH_COLUMN);
    }
    public function catalog(bool $lock=false): array {
        $r=$this->one('SELECT r,n,s FROM shared_object_catalog WHERE id=1',[],$lock);return $r===null?['revision'=>0,'total'=>0,'nextSlot'=>1,'exists'=>false]:['revision'=>SharedObjectStorage::number($r['r']),'total'=>SharedObjectStorage::number($r['n'],0,SharedObjectStorage::INT_MAX),'nextSlot'=>SharedObjectStorage::number($r['s'],1,SharedObjectStorage::INT_MAX),'exists'=>true];
    }
    public function saveCatalog(array $before,array $next): void {
        if(!$before['exists'])$this->statement('INSERT INTO shared_object_catalog(id,r,n,s) VALUES(1,?,?,?)',[$next['revision'],$next['total'],$next['nextSlot']]);
        else if($this->statement('UPDATE shared_object_catalog SET r=?,n=?,s=? WHERE id=1 AND r=?',[$next['revision'],$next['total'],$next['nextSlot'],$before['revision']])->rowCount()!==1)SharedObjectRules::fail('COLLECTION_REVISION_CONFLICT');
    }
    private function remember(array $row): array {$d=SharedObjectStorage::definition($row);$this->definitions[$d['key']]=$d;$this->slots[$d['_slot']]=$d;return $d;}
    public function collection(string $key,bool $lock=false): ?array {$r=$this->one('SELECT c,k,r,d FROM shared_object_collections WHERE k=?',[$key],$lock);return $r===null?null:$this->remember($r);}
    private function definition(string $key): array {$d=$this->definitions[$key]??$this->collection($key);if($d===null)SharedObjectStorage::corrupt();return $d;}
    public function saveCollection(?array $before,array $next): void {
        $r=SharedObjectStorage::definitionRow($next);
        if($before===null)$this->statement('INSERT INTO shared_object_collections(c,k,r,d) VALUES(?,?,?,?)',array_values($r));
        else if($this->statement('UPDATE shared_object_collections SET r=?,d=? WHERE c=? AND k=? AND r=?',[$r['r'],$r['d'],$r['c'],$r['k'],$before['revision']])->rowCount()!==1)SharedObjectRules::fail('COLLECTION_REVISION_CONFLICT');$this->remember($r);
    }
    public function collections(?string $after,int $limit): array {
        $rows=$this->statement('SELECT c,k,r,d FROM shared_object_collections'.($after===null?'':' WHERE k>?').' ORDER BY k LIMIT '.($limit+1),$after===null?[]:[$after])->fetchAll(PDO::FETCH_ASSOC);return array_map([$this,'remember'],$rows);
    }
    public function usage(string $collection,string $user,bool $lock=false): array {
        $r=$this->one('SELECT r,n,b FROM shared_object_usage WHERE c=? AND u=?',[$this->definition($collection)['_slot'],$this->bytes(SharedObjectStorage::uuid($user))],$lock);
        return $r===null?['revision'=>0,'count'=>0,'chargedBytes'=>SharedObjectRules::OWNER,'exists'=>false]:['revision'=>SharedObjectStorage::number($r['r']),'count'=>SharedObjectStorage::number($r['n']),'chargedBytes'=>$r['b']===null?SharedObjectRules::OWNER:SharedObjectStorage::number($r['b']),'exists'=>true];
    }
    public function saveUsage(string $collection,string $user,array $before,int $count): array {
        if($count<0)SharedObjectRules::fail('STATE_CONFLICT');$next=$before;$next['revision']=SharedObjectRules::next($before['revision']);$next['count']=$count;$slot=$this->definition($collection)['_slot'];$id=$this->bytes(SharedObjectStorage::uuid($user));
        if(!$before['exists'])$this->statement('INSERT INTO shared_object_usage(c,u,active_user_id,r,n,b) VALUES(?,?,?,?,?,NULL)',[$slot,$id,$user,$next['revision'],$count]);
        else if($this->statement('UPDATE shared_object_usage SET r=?,n=? WHERE c=? AND u=? AND r=?',[$next['revision'],$count,$slot,$id,$before['revision']])->rowCount()!==1)SharedObjectRules::fail('STATE_CONFLICT');$next['exists']=true;return $next;
    }
    public function quota(bool $lock=false): array {$r=$this->one('SELECT r,b FROM shared_object_quota WHERE id=1',[],$lock);return $r===null?['revision'=>0,'chargedBytes'=>0,'exists'=>false]:['revision'=>SharedObjectStorage::number($r['r']),'chargedBytes'=>SharedObjectStorage::number($r['b']),'exists'=>true];}
    public function saveQuota(array $before,int $bytes): void {
        $revision=SharedObjectRules::next($before['revision']);if(!$before['exists'])$this->statement('INSERT INTO shared_object_quota(id,r,b) VALUES(1,?,?)',[$revision,$bytes]);
        else if($this->statement('UPDATE shared_object_quota SET r=?,b=? WHERE id=1 AND r=?',[$revision,$bytes,$before['revision']])->rowCount()!==1)SharedObjectRules::fail('STATE_CONFLICT');
    }
    private function rows(array $rows): array {
        $missing=[];foreach($rows as$r){$slot=SharedObjectStorage::number($r['c'],1,SharedObjectStorage::INT_MAX);if(!isset($this->slots[$slot]))$missing[$slot]=true;}
        $this->rememberSlots(array_keys($missing));
        $out=[];foreach($rows as$r){$d=$this->slots[(int)$r['c']]??null;if($d===null)SharedObjectStorage::corrupt();$out[]=SharedObjectStorage::row($r,$d);}return $out;
    }
    private function rememberSlots(array $ids): void {
        $ids=array_values(array_filter(array_unique($ids),function($id){return !isset($this->slots[(int)$id]);}));if(!$ids)return;
        foreach($this->statement('SELECT c,k,r,d FROM shared_object_collections WHERE c IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_map('intval',$ids))->fetchAll(PDO::FETCH_ASSOC)as$d)$this->remember($d);
    }
    public function object(string $collection,string $id,bool $lock=false): ?array {
        $r=$this->one('SELECT '.self::COLUMNS.' FROM shared_objects WHERE i=? AND c=?',[$this->bytes(SharedObjectStorage::uuid($id)),$this->definition($collection)['_slot']],$lock);return $r===null?null:$this->rows([$r])[0];
    }
    private function metadata(array $r): array {
        $row=SharedObjectStorage::metadata($r,$this->definition($r['collection_key']));foreach(['i','q','o','g','s0','s1','s2']as$key)if($row[$key]!==null)$row[$key]=$this->bytes($row[$key]);return array_values($row);
    }
    public function insert(array $r): void {
        $this->statement('INSERT INTO shared_objects('.self::COLUMNS.') VALUES('.implode(',',array_fill(0,17,'?')).')',$this->metadata($r));
        $this->statement('INSERT INTO shared_object_payloads(r,b,i) VALUES(?,?,?)',[$r['revision'],$this->bytes($r['payload']),$this->bytes(SharedObjectStorage::uuid($r['id']))]);
    }
    public function save(array $before,array $r): void {
        $assign=array_map(static function($c){return $c.'=?';},explode(',',self::COLUMNS));$id=$this->bytes(SharedObjectStorage::uuid($before['id']));
        if($this->statement('UPDATE shared_objects SET '.implode(',',$assign).' WHERE i=? AND c=? AND r=?',array_merge($this->metadata($r),[$id,$this->definition($before['collection_key'])['_slot'],$before['revision']]))->rowCount()!==1||
            $this->statement('UPDATE shared_object_payloads SET r=?,b=? WHERE i=? AND r=? AND '.$this->payloadPredicate(),[$r['revision'],$this->bytes($r['payload']),$id,$before['revision'],$before['payload_bytes']])->rowCount()!==1)SharedObjectRules::fail('REVISION_CONFLICT');
    }
    public function remove(array $r): void {
        $id=$this->bytes(SharedObjectStorage::uuid($r['id']));if($this->statement('DELETE FROM shared_object_payloads WHERE i=? AND r=? AND '.$this->payloadPredicate(),[$id,$r['revision'],$r['payload_bytes']])->rowCount()!==1||
            $this->statement('DELETE FROM shared_objects WHERE i=? AND c=? AND r=?',[$id,$this->definition($r['collection_key'])['_slot'],$r['revision']])->rowCount()!==1)SharedObjectRules::fail('REVISION_CONFLICT');
    }
    private function payloadPredicate(): string {
        return $this->mysql?'OCTET_LENGTH(b)=?':"length(b)=? AND typeof(r)='integer' AND (typeof(b)='blob' OR (typeof(b)='text' AND length(b)=0))";
    }
    public function payloads(string $collection,array $rows): array {
        if(count($rows)>50)throw new LogicException('Payload selection bound');if(!$rows)return [];$ids=array_map(function($r){return $this->bytes(SharedObjectStorage::uuid($r['id']));},$rows);
        $result=[];foreach($this->statement('SELECT i,r,b FROM shared_object_payloads WHERE i IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll(PDO::FETCH_ASSOC)as$p){$id=SharedObjectStorage::id($p['i']);$result[$id]=['revision'=>SharedObjectStorage::number($p['r']),'payload'=>$p['b']];}
        foreach($rows as$r)if(!isset($result[$r['id']])||$result[$r['id']]['revision']!==$r['revision']||!is_string($result[$r['id']]['payload'])||strlen($result[$r['id']]['payload'])!==$r['payload_bytes'])SharedObjectRules::fail('STATE_CONFLICT');return $result;
    }
    private function sortArg($text,string $field,array $definition) {
        if($text===null)return null;if($field==='id')return $this->bytes(SharedObjectStorage::uuid($text));
        if($field==='position_x')return (int)$text-SharedObjectRules::MAX;
        $slot=(int)substr($field,4);$type=$definition['searchFields'][$slot]['type']??null;if($type===null)SharedObjectStorage::corrupt();return $this->bytes(SharedObjectStorage::keyBytes($text,$type));
    }
    public function seekSql(string $collection,array $seek,int $limit): array {
        if($limit<1||$limit>256)throw new LogicException('Metadata candidate bound');$fields=['id'=>'i','slot0'=>'s0','slot1'=>'s1','slot2'=>'s2','position_x'=>'x'];$logical=$seek['field'];$field=$fields[$logical]??null;if($field===null)throw new LogicException('Invalid sort column');$d=$this->definition($collection);
        $partition=$seek['partition'];$q=$partition==='all'?'':SharedObjectStorage::uuid(substr($partition,6));if(strpos($partition,'group:')===0)$q=SharedObjectStorage::uuid(substr($partition,6));
        $where=['c=?','q=?'];$args=[$d['_slot'],$this->bytes($q)];$index=$seek['owner']!==null?'idx_shared_v1_owner_page':'idx_shared_v1_'.$logical.'_page';
        if($seek['owner']!==null){if($field!=='i')throw new LogicException('Owner seek order');if($d['readPolicy']==='owner'||$d['readPolicy']==='friends'||$d['readPolicy']==='dashboard'){$index='idx_shared_v1_id_page';if($q!==SharedObjectStorage::uuid($seek['owner']))SharedObjectRules::invalid();}else{$where[]='o=?';$args[]=$this->bytes(SharedObjectStorage::uuid($seek['owner']));}}
        if($seek['lower']!==null&&!(!$this->mysql&&$seek['afterId']!==null&&$field!=='i')){$where[]=$field.(!empty($seek['lowerExclusive'])?'>':'>=').'?';$args[]=$this->sortArg($seek['lower'],$logical,$d);}
        if($seek['upper']!==null){$where[]=$field.($seek['exclusive']?'<':'<=').'?';$args[]=$this->sortArg($seek['upper'],$logical,$d);}
        if($seek['afterId']!==null){$id=$this->bytes(SharedObjectStorage::uuid($seek['afterId']));$sort=$this->sortArg($seek['afterSort'],$logical,$d);if($field==='i'){$where[]='i>?';$args[]=$id;}
            elseif($this->mysql&&!empty($seek['equalResume'])){$where[]=$field.'=? AND i>?';array_push($args,$sort,$id);}
            elseif($this->mysql){$where[]='('.$field.'>? OR ('.$field.'=? AND i>?))';array_push($args,$sort,$sort,$id);}
            else{$where[]='('.$field.',i)>(?,?)';array_push($args,$sort,$id);}}
        $hint=$this->mysql?' FORCE INDEX ('.$index.')':' INDEXED BY '.$index;return ['SELECT '.self::COLUMNS.' FROM shared_objects'.$hint.' WHERE '.implode(' AND ',$where).' ORDER BY '.($field==='i'?'i':$field.',i').' LIMIT '.$limit,$args];
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
    private function seekRows(string $collection,array $seek,int $limit): array {[$sql,$args]=$this->seekSql($collection,$seek,$limit);return $this->rows($this->statement($sql,$args)->fetchAll(PDO::FETCH_ASSOC));}
    private function batch(string $where,array $args,string $order,string $index,int $limit=20): array {$hint=$this->mysql?' FORCE INDEX ('.$index.')':' INDEXED BY '.$index;return $this->rows($this->statement('SELECT '.self::COLUMNS.' FROM shared_objects'.$hint.' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$limit,$args)->fetchAll(PDO::FETCH_ASSOC));}
    public function expired(int $now): array {return $this->batch('e IS NOT NULL AND e<=?',[$now],'e,i','idx_shared_v1_expiry');}
    public function owned(string $user): array {
        $id=$this->bytes(SharedObjectStorage::uuid($user));$rows=array_merge($this->batch('f=0 AND q=?',[$id],'i','idx_shared_v1_partition_owner'),$this->batch('o=?',[$id],'i','idx_shared_v1_deleted_owner'));
        usort($rows,static function($a,$b){return strcmp($a['id'],$b['id']);});return array_slice($rows,0,20);
    }
    public function ownerUsages(string $user): array {
        $rows=$this->statement('SELECT c FROM shared_object_usage WHERE u=? ORDER BY c LIMIT 20',[$this->bytes(SharedObjectStorage::uuid($user))])->fetchAll(PDO::FETCH_COLUMN);$keys=[];
        $this->rememberSlots($rows);foreach($rows as$slot){if(!isset($this->slots[(int)$slot]))SharedObjectStorage::corrupt();$keys[]=$this->slots[(int)$slot]['key'];}return $keys;
    }
    public function removeUsage(string $collection,string $user,array $usage): void {if($this->statement('DELETE FROM shared_object_usage WHERE c=? AND u=? AND r=? AND n=0',[$this->definition($collection)['_slot'],$this->bytes(SharedObjectStorage::uuid($user)),$usage['revision']])->rowCount()!==1)SharedObjectRules::fail('STATE_CONFLICT');}
    public function deletedOwners(?string $after): array {
        $rows=$this->statement('SELECT DISTINCT u FROM shared_object_usage WHERE active_user_id IS NULL'.($after===null?'':' AND u>?').' ORDER BY u LIMIT 11',$after===null?[]:[$this->bytes(SharedObjectStorage::uuid($after))])->fetchAll(PDO::FETCH_COLUMN);return array_map([SharedObjectStorage::class,'id'],$rows);
    }
    public function rebind(string $user): void {
        $id=$this->bytes(SharedObjectStorage::uuid($user));$slots=$this->statement('SELECT c FROM shared_object_usage WHERE active_user_id IS NULL AND u=? ORDER BY c LIMIT 20',[$id])->fetchAll(PDO::FETCH_COLUMN);
        if($slots)$this->statement('UPDATE shared_object_usage SET active_user_id=? WHERE u=? AND active_user_id IS NULL AND c IN ('.implode(',',array_fill(0,count($slots),'?')).')',array_merge([$user,$id],array_map('intval',$slots)));
    }
}
