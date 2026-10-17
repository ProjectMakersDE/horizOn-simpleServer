<?php
declare(strict_types=1);
require_once __DIR__.'/SharedObjectStore.php';
require_once __DIR__.'/../Core/SqlTransaction.php';
/** Offline, feature-local cold conversion. Never called by bootstrap, a read or an HTTP route. */
class SharedObjectMigration
{
    private PDO $pdo;private bool $mysql;private $checkpoint;
    private const KEYS=['shared_object_catalog'=>'id','shared_object_collections'=>'collection_key','shared_object_quota'=>'id','shared_object_usage'=>'collection_key,user_id','shared_objects'=>'id','shared_object_payloads'=>'id'];
    private const LEGACY=[
        'shared_object_catalog'=>['id','revision','total'],'shared_object_collections'=>['collection_key','revision','data'],'shared_object_quota'=>['id','revision','charged_bytes'],
        'shared_object_usage'=>['collection_key','user_id','active_user_id','revision','count','charged_bytes'],
        'shared_objects'=>['id','collection_key','partition_key','owner_id','group_id','revision','payload_bytes','charged_bytes','search_json','position_json','slot0','slot1','slot2','position_x','created_millis','updated_millis','expires_millis'],
        'shared_object_payloads'=>['id','collection_key','revision','payload']];
    private const COMPACT=[
        'shared_object_catalog'=>['id','r','n','s'],'shared_object_collections'=>['c','k','r','d'],'shared_object_quota'=>['id','r','b'],
        'shared_object_usage'=>['c','u','active_user_id','r','n','b'],'shared_objects'=>['i','c','f','q','o','g','r','l','b','s0','s1','s2','x','y','a','u','e'],
        'shared_object_payloads'=>['i','r','b']];
    private const INDEXES=[
        'shared_object_catalog'=>['PRIMARY'=>['id']], 'shared_object_collections'=>['PRIMARY'=>['collection_key']], 'shared_object_quota'=>['PRIMARY'=>['id']],
        'shared_object_usage'=>['PRIMARY'=>['collection_key','user_id'],'idx_shared_usage_user'=>['user_id','collection_key'],'idx_shared_usage_deleted'=>['active_user_id','user_id','collection_key']],
        'shared_objects'=>['PRIMARY'=>['id'],'idx_shared_id_page'=>['collection_key','partition_key','id'],'idx_shared_owner_page'=>['collection_key','partition_key','owner_id','id'],
            'idx_shared_slot0_page'=>['collection_key','partition_key','slot0','id'],'idx_shared_slot1_page'=>['collection_key','partition_key','slot1','id'],'idx_shared_slot2_page'=>['collection_key','partition_key','slot2','id'],
            'idx_shared_position_x_page'=>['collection_key','partition_key','position_x','id'],'idx_shared_expiry'=>['expires_millis','id'],'idx_shared_deleted_owner'=>['owner_id','id']],
        'shared_object_payloads'=>['PRIMARY'=>['id']]];
    public function __construct(PDO $pdo,?callable $checkpoint=null){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->checkpoint=$checkpoint;}
    private function point(string $phase):void {if($this->checkpoint!==null)($this->checkpoint)($phase);}
    private static function validName(string $name):void {if(!preg_match('/^shared_(?:object_(?:catalog|collections|quota|usage|payloads)|objects)(?:_(?:next|legacy)1012)?$/D',$name))throw new InvalidArgumentException('Shared migration table');}
    private function columns(string $table):array {
        self::validName($table);return $this->mysql?$this->pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN):array_column($this->pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
    }
    /** A keyset page holds at most20 opaque payloads (each <=1MiB); no complete installation in memory. */
    private function each(string $base,string $suffix,callable $work):void {
        $table=$base.$suffix;self::validName($table);$keys=explode(',',self::KEYS[$base]);$after=null;
        do{$where='';$args=[];if($after!==null){if(count($keys)===1){$where=' WHERE '.$keys[0].'>?';$args=[$after[0]];}else{$where=' WHERE ('.$keys[0].'>? OR ('.$keys[0].'=? AND '.$keys[1].'>?))';$args=[$after[0],$after[0],$after[1]];}}
            $q=$this->pdo->prepare('SELECT * FROM '.$table.$where.' ORDER BY '.implode(',',$keys).' LIMIT 20');$q->execute($args);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as$row)$work($row);if($rows){$last=$rows[count($rows)-1];$after=array_map(static function($k)use($last){return $last[$k];},$keys);}
        }while(count($rows)===20);
    }
    private function fingerprint(string $suffix=''):array {
        $tables=[];foreach(self::LEGACY as$table=>$columns){if($this->columns($table.$suffix)!==$columns)SharedObjectStorage::required();$this->attestLegacy($table,$suffix);$this->boundedOriginals($table,$suffix);$hash=hash_init('sha256');$count=0;
            $this->each($table,$suffix,static function($row)use($hash,&$count){foreach($row as$key=>$value){hash_update($hash,pack('N',strlen($key)).$key.($value===null?'n':('s'.pack('N',strlen((string)$value)).(string)$value)));}$count++;});$tables[$table]=['rows'=>$count,'sha256'=>hash_final($hash)];}
        $identity=$this->mysql?$this->pdo->query('SELECT DATABASE()')->fetchColumn():$this->pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC)[0]['file'];
        $result=['storageVersion'=>1,'driver'=>$this->mysql?'mysql':'sqlite','database'=>$identity,'tables'=>$tables];$result['sha256']=hash('sha256',SharedObjectRules::json($result));return $result;
    }
    private function boundedOriginals(string $base,string $suffix):void {
        $bounds=['id'=>36,'collection_key'=>64,'partition_key'=>42,'owner_id'=>36,'group_id'=>36,'user_id'=>36,'active_user_id'=>36,
            'data'=>4096,'payload'=>SharedObjectRules::PAGE_BYTES,'search_json'=>2048,'position_json'=>256,'slot0'=>192,'slot1'=>192,'slot2'=>192,'position_x'=>17];
        $where=[];$args=[];foreach(self::LEGACY[$base]as$c){$length=$this->mysql?'OCTET_LENGTH('.$c.')':'length(CAST('.$c.' AS BLOB))';$where[]=$length.'>?';$args[]=$bounds[$c]??17;}
        $q=$this->pdo->prepare('SELECT 1 FROM '.$base.$suffix.' WHERE '.implode(' OR ',$where).' LIMIT 1');$q->execute($args);if($q->fetchColumn()!==false)SharedObjectStorage::required();
    }
    /** Unknown indexes, constraints and triggers are not silently lost at cold replacement. */
    private function expectedIndexes(string $base,bool $compact):array {
        $expected=self::INDEXES[$base];if(!$compact)return $expected;
        if($base==='shared_object_collections')return ['PRIMARY'=>['c'],'k'=>['k']];
        $mapped=[];$names=['collection_key'=>'c','partition_key'=>'q','owner_id'=>'o','user_id'=>'u','slot0'=>'s0','slot1'=>'s1','slot2'=>'s2','position_x'=>'x','expires_millis'=>'e'];
        foreach($expected as$name=>$columns){$next=$name==='PRIMARY'?'PRIMARY':str_replace('idx_shared_','idx_shared_v1_',$name);$mapped[$next]=array_map(static function($c)use($base,$names){if($c==='id'&&in_array($base,['shared_objects','shared_object_payloads'],true))return 'i';return $names[$c]??$c;},$columns);}
        if($base==='shared_objects')$mapped['idx_shared_v1_partition_owner']=['f','q','i'];return $mapped;
    }
    private function attestLegacy(string $base,string $suffix,bool $compact=false):void {
        $table=$base.$suffix;$indexes=[];$expected=$this->expectedIndexes($base,$compact);
        if($this->mysql){
            $q=$this->pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');$q->execute([$table]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r){if($r['SUB_PART']!==null||(int)$r['NON_UNIQUE']!==(in_array($r['INDEX_NAME'],['PRIMARY','k'],true)?0:1))SharedObjectStorage::required();$indexes[$r['INDEX_NAME']][]=$r['COLUMN_NAME'];}
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');$q->execute([$table]);if((int)$q->fetchColumn())SharedObjectStorage::required();
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK'");$q->execute([$table]);if((int)$q->fetchColumn())SharedObjectStorage::required();
            $q=$this->pdo->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY ORDINAL_POSITION');$q->execute([$table]);$foreign=$q->fetchAll(PDO::FETCH_NUM);
            $q=$this->pdo->prepare('SELECT UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)if(!in_array($r['UPDATE_RULE'],['RESTRICT','NO ACTION'],true)||$r['DELETE_RULE']!==($base==='shared_object_usage'?'SET NULL':'CASCADE'))SharedObjectStorage::required();
        }else{
            $info=$this->pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC);$primary=[];foreach($info as$r)if($r['pk'])$primary[(int)$r['pk']]=$r['name'];ksort($primary);$indexes['PRIMARY']=array_values($primary);
            foreach($this->pdo->query('PRAGMA index_list('.$table.')')->fetchAll(PDO::FETCH_ASSOC)as$r){if($r['origin']==='pk')continue;$name=$compact&&$base==='shared_object_collections'&&$r['origin']==='u'?'k':$r['name'];if((int)$r['unique']!==($name==='k'?1:0)||(int)$r['partial']!==0||!isset($expected[$name]))SharedObjectStorage::required();$indexes[$name]=array_column($this->pdo->query('PRAGMA index_info('.$r['name'].')')->fetchAll(PDO::FETCH_ASSOC),'name');}
            $q=$this->pdo->prepare("SELECT type,sql FROM sqlite_master WHERE tbl_name=? AND type IN ('table','trigger')");$q->execute([$table]);foreach($q->fetchAll(PDO::FETCH_ASSOC)as$r)if($r['type']==='trigger'||preg_match('/\bCHECK\s*\(/i',$r['sql']))SharedObjectStorage::required();
            $foreign=[];foreach($this->pdo->query('PRAGMA foreign_key_list('.$table.')')->fetchAll(PDO::FETCH_ASSOC)as$r){if($r['on_update']!=='NO ACTION'||$r['on_delete']!==($base==='shared_object_usage'?'SET NULL':'CASCADE'))SharedObjectStorage::required();$foreign[]=[$r['from'],$r['table'],$r['to']];}
        }
        ksort($expected);ksort($indexes);if($indexes!==$expected)SharedObjectStorage::required();
        $id=$compact?'i':'id';$expectedForeign=$base==='shared_object_usage'?[['active_user_id','users','id']]:($base==='shared_object_payloads'?[[$id,'shared_objects'.$suffix,$id]]:[]);
        if($foreign!==$expectedForeign)SharedObjectStorage::required();
    }
    public function plan():array {
        if($this->pdo->inTransaction())throw new RuntimeException('Cold plan requires its own connection');if($this->mysql)$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->beginTransaction();try{$plan=$this->fingerprint();$this->pdo->commit();return $plan;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function supported():void {
        if(!$this->mysql)return;$version=$this->pdo->query('SELECT VERSION()')->fetchColumn();
        // MariaDB and older MySQL may run fresh storage, but this converter has not proved their lock+rename behavior.
        if(!is_string($version)||strpos($version,'MariaDB')!==false||!preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)/',$version,$m)||version_compare($m[1],'8.0.13','<'))throw new RuntimeException('Cold cutover requires proven MySQL8.0.13+ atomic InnoDB RENAME under WRITE locks; runtime support is unchanged');
        foreach(SharedObjectSchema::TABLES as$table){$q=$this->pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);if($q->fetchColumn()!=='InnoDB')throw new RuntimeException('Cold cutover requires all six InnoDB tables');}
    }
    private function schema(string $suffix):string {
        $sql=file_get_contents(__DIR__.'/../../migrations/shared-objects-'.($this->mysql?'mysql':'sqlite').'.sql');
        foreach(array_reverse(SharedObjectSchema::TABLES)as$table)$sql=preg_replace('/\b'.preg_quote($table,'/').'\b/',$table.$suffix,$sql);return $sql;
    }
    private function guardExists():bool {
        return (int)$this->pdo->query($this->mysql?"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shared_object_migration1012'":"SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='shared_object_migration1012'")->fetchColumn()!==0;
    }
    private function guard(string $digest):void {
        if(!$this->guardExists())throw new RuntimeException('Missing migration ownership marker; left unchanged');
        $columns=$this->mysql?$this->pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='shared_object_migration1012' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN):array_column($this->pdo->query('PRAGMA table_info(shared_object_migration1012)')->fetchAll(PDO::FETCH_ASSOC),'name');
        if($columns!==['id','v','sha256'])throw new RuntimeException('Unknown migration ownership marker');$rows=$this->pdo->query('SELECT * FROM shared_object_migration1012')->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1||(int)$rows[0]['id']!==1||(int)$rows[0]['v']!==1||!is_string($rows[0]['sha256'])||!hash_equals($digest,$rows[0]['sha256']))throw new RuntimeException('Migration ownership marker mismatch');
    }
    private function createGuard(string $digest):void {
        $this->pdo->exec($this->mysql?'CREATE TABLE shared_object_migration1012(id INT PRIMARY KEY,v INT NOT NULL,sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL) ENGINE=InnoDB':'CREATE TABLE shared_object_migration1012(id INTEGER PRIMARY KEY,v INTEGER NOT NULL,sha256 TEXT NOT NULL)');
        $q=$this->pdo->prepare('INSERT INTO shared_object_migration1012(id,v,sha256) VALUES(1,1,?)');$q->execute([$digest]);
    }
    private function dropDerived(array $tables):void {
        // One atomic InnoDB DDL statement. SQLite caller owns one writer transaction.
        if($this->mysql)$this->pdo->exec('DROP TABLE '.implode(',',$tables));else foreach($tables as$t)$this->pdo->exec('DROP TABLE '.$t);
    }
    private function maintenance(array $suffixes,callable $work):void {
        if($this->pdo->inTransaction())throw new RuntimeException('Cold maintenance requires its own connection');
        if(!$this->mysql){(new SqlTransaction($this->pdo))->run($work);return;}
        $this->supported();$locks=['users WRITE','shared_object_migration1012 WRITE'];foreach(SharedObjectSchema::TABLES as$t){$locks[]=$t.' WRITE';foreach($suffixes as$suffix)if($this->columns($t.$suffix))$locks[]=$t.$suffix.' WRITE';}
        $this->pdo->exec('LOCK TABLES '.implode(',',$locks));try{$work();}finally{$this->pdo->exec('UNLOCK TABLES');}
    }
    private function put(string $table,array $row):void {
        $q=$this->pdo->prepare('INSERT INTO '.$table.'_next1012('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')');
        foreach(array_values($row)as$i=>$value){$column=array_keys($row)[$i];$binary=($table==='shared_objects'&&in_array($column,['i','q','o','g','s0','s1','s2'],true))||($table==='shared_object_payloads'&&in_array($column,['i','b'],true))||($table==='shared_object_usage'&&$column==='u');$q->bindValue($i+1,$value,$value===null?PDO::PARAM_NULL:($binary&&$value!==''?PDO::PARAM_LOB:(is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR)));}$q->execute();
    }
    private function definition(array $row,int $slot):array {
        try{$value=get_object_vars(ItemJson::decode($row['data'],4096));$fields=['key','name','active','readPolicy','writePolicy','maxObjectBytes','maxObjectsPerOwner','ttlSeconds','searchFields','positionEnabled','used','revision'];SharedObjectStorage::fields($value,$fields);
            if($value['key']!==$row['collection_key']||SharedObjectStorage::number($row['revision'],1)!==$value['revision']||!is_bool($value['used']))SharedObjectStorage::required();
            $input=$value;unset($input['key'],$input['used'],$input['revision']);$d=SharedObjectRules::definition($input,['objectBytes'=>SharedObjectRules::PAGE_BYTES]);
            $d=['key'=>SharedObjectRules::key($value['key'])]+$d+['used'=>$value['used'],'revision'=>$value['revision'],'_slot'=>$slot];return $d;
        }catch(Throwable $error){SharedObjectStorage::required();}
    }
    private function convert():void {
        $slot=1;$this->each('shared_object_collections','',function($row)use(&$slot){$d=$this->definition($row,$slot++);$this->put('shared_object_collections',SharedObjectStorage::definitionRow($d));});
        $this->each('shared_object_catalog','',function($row)use($slot){if(SharedObjectStorage::number($row['id'])!==1||SharedObjectStorage::number($row['total'])!==$slot-1)SharedObjectStorage::required();$this->put('shared_object_catalog',['id'=>1,'r'=>SharedObjectStorage::number($row['revision']),'n'=>$slot-1,'s'=>$slot]);});
        if($slot>1&&$this->pdo->query('SELECT COUNT(*) FROM shared_object_catalog')->fetchColumn()!=1)SharedObjectStorage::required();
        $objects=0;$charge=0;
        $this->each('shared_objects','',function($row)use(&$objects,&$charge){
            try{$q=$this->pdo->prepare('SELECT c,k,r,d FROM shared_object_collections_next1012 WHERE k=?');$q->execute([$row['collection_key']]);$definitionRow=$q->fetch(PDO::FETCH_ASSOC);$d=$definitionRow===false?null:SharedObjectStorage::definition($definitionRow);if($d===null||!$d['used'])SharedObjectStorage::required();$r=$row;
                foreach(['revision','payload_bytes','charged_bytes','created_millis','updated_millis']as$k)$r[$k]=SharedObjectStorage::number($r[$k]);$r['expires_millis']=$r['expires_millis']===null?null:SharedObjectStorage::number($r['expires_millis']);
                $search=ItemJson::decode($row['search_json'],2048);$position=$row['position_json']===null?null:ItemJson::decode($row['position_json'],256);$r['search']=$search;$r['position']=$position===null?null:get_object_vars($position);
                $m=SharedObjectRules::metadata(['search'=>$search,'position'=>$position],$d);foreach([0,1,2]as$i)if($row['slot'.$i]!==$m['slots'][$i])SharedObjectStorage::required();if($row['position_x']!==$m['positionX'])SharedObjectStorage::required();
                $raw=SharedObjectStorage::metadata($r,$d);$decoded=SharedObjectStorage::row($raw,$d);foreach(['id','collection_key','partition_key','owner_id','group_id','revision','payload_bytes','charged_bytes','position','created_millis','updated_millis','expires_millis']as$k)if($decoded[$k]!==$r[$k])SharedObjectStorage::required();
                $q=$this->pdo->prepare('SELECT * FROM shared_object_payloads WHERE id=?');$q->execute([$r['id']]);$p=$q->fetch(PDO::FETCH_ASSOC);if($p===false||$p['collection_key']!==$r['collection_key']||SharedObjectStorage::number($p['revision'])!==$r['revision']||!is_string($p['payload'])||strlen($p['payload'])!==$r['payload_bytes'])SharedObjectStorage::required();
                $this->put('shared_objects',$raw);$this->put('shared_object_payloads',['i'=>$raw['i'],'r'=>$r['revision'],'b'=>$p['payload']]);$objects++;$charge+=$r['charged_bytes'];
            }catch(Throwable $error){SharedObjectStorage::required();}
        });
        if((int)$this->pdo->query('SELECT COUNT(*) FROM shared_object_payloads')->fetchColumn()!==$objects)SharedObjectStorage::required();
        $this->each('shared_object_usage','',function($row)use(&$charge){$q=$this->pdo->prepare('SELECT c,k,r,d FROM shared_object_collections_next1012 WHERE k=?');$q->execute([$row['collection_key']]);$definitionRow=$q->fetch(PDO::FETCH_ASSOC);$d=$definitionRow===false?null:SharedObjectStorage::definition($definitionRow);if($d===null)SharedObjectStorage::required();$user=SharedObjectRules::uuid($row['user_id']);if($user!==$row['user_id']||($row['active_user_id']!==null&&$row['active_user_id']!==$user))SharedObjectStorage::required();$count=SharedObjectStorage::number($row['count']);$q=$this->pdo->prepare('SELECT COUNT(*) FROM shared_objects WHERE owner_id=? AND collection_key=?');$q->execute([$user,$row['collection_key']]);if((int)$q->fetchColumn()!==$count)SharedObjectStorage::required();$bytes=SharedObjectStorage::number($row['charged_bytes']);$charge+=$bytes;
            $this->put('shared_object_usage',['c'=>$d['_slot'],'u'=>SharedObjectStorage::uuid($user),'active_user_id'=>$row['active_user_id'],'r'=>SharedObjectStorage::number($row['revision']),'n'=>$count,'b'=>$bytes===SharedObjectRules::OWNER?null:$bytes]);});
        if($charge>SharedObjectRules::MAX||(int)$this->pdo->query('SELECT COUNT(*) FROM shared_objects WHERE NOT EXISTS (SELECT 1 FROM shared_object_usage WHERE shared_object_usage.collection_key=shared_objects.collection_key AND shared_object_usage.user_id=shared_objects.owner_id)')->fetchColumn()!==0)SharedObjectStorage::required();$quota=false;$this->each('shared_object_quota','',function($row)use($charge,&$quota){if(SharedObjectStorage::number($row['id'])!==1||SharedObjectStorage::number($row['charged_bytes'])!==$charge)SharedObjectStorage::required();$quota=true;$this->put('shared_object_quota',['id'=>1,'r'=>SharedObjectStorage::number($row['revision']),'b'=>$charge]);});if($charge&&!$quota)SharedObjectStorage::required();
    }
    public function apply(string $digest,bool $writersStopped,bool $backupRestored,bool $upgraded):array {
        if(!$writersStopped||!$backupRestored||!$upgraded||!preg_match('/^[a-f0-9]{64}$/D',$digest)||$this->pdo->inTransaction())throw new RuntimeException('Cold cutover requires exact plan SHA256, stopped writers, tested backup/restore and all readers/writers upgraded');
        if((int)$this->pdo->query($this->mysql?'SELECT @@FOREIGN_KEY_CHECKS':'PRAGMA foreign_keys')->fetchColumn()!==1)throw new RuntimeException('Foreign-key protection must remain enabled');
        $this->supported();foreach(SharedObjectSchema::TABLES as$table)foreach(['_next1012','_legacy1012']as$suffix)if($this->columns($table.$suffix))throw new RuntimeException('Reserved migration tables exist: inspect and explicitly reconcile; no automatic deletion');
        if($this->guardExists())throw new RuntimeException('Reserved migration ownership marker exists: reconcile explicitly');
        if($this->mysql&&!hash_equals($this->plan()['sha256'],$digest))throw new RuntimeException('Original Shared Objects changed since the sealed plan');
        $locked=false;$sqliteStarted=false;
        try{
            if($this->mysql){$this->createGuard($digest);$this->pdo->exec($this->schema('_next1012'));$locks=['users WRITE','shared_object_migration1012 WRITE'];foreach(SharedObjectSchema::TABLES as$t){$locks[]=$t.' WRITE';$locks[]=$t.'_next1012 WRITE';}$this->pdo->exec('LOCK TABLES '.implode(',',$locks));$locked=true;}
            else{$this->pdo->exec('BEGIN IMMEDIATE');$sqliteStarted=true;}
            $plan=$this->fingerprint();if(!hash_equals($plan['sha256'],$digest))throw new RuntimeException('Original Shared Objects changed since the sealed plan');if(!$this->mysql){$this->createGuard($digest);$this->pdo->exec($this->schema('_next1012'));}
            $this->convert();$this->point('copied');$rename=[];foreach(SharedObjectSchema::TABLES as$t){$rename[]=$t.' TO '.$t.'_legacy1012';$rename[]=$t.'_next1012 TO '.$t;}
            if($this->mysql)$this->pdo->exec('RENAME TABLE '.implode(',',$rename));
            else foreach(SharedObjectSchema::TABLES as$t){$this->pdo->exec('ALTER TABLE '.$t.' RENAME TO '.$t.'_legacy1012');$this->pdo->exec('ALTER TABLE '.$t.'_next1012 RENAME TO '.$t);}
            $this->point('switched');if($sqliteStarted){$this->pdo->exec('COMMIT');$sqliteStarted=false;}return ['switched'=>true,'originalPlanSha256'=>$digest,'legacyRetained'=>true];
        }finally{if($locked)$this->pdo->exec('UNLOCK TABLES');if($sqliteStarted)$this->pdo->exec('ROLLBACK');}
    }
    public function discardStaging(string $digest,bool $writersStopped,bool $backupRestored):void {
        if(!$writersStopped||!$backupRestored)throw new RuntimeException('Staging reconciliation requires stopped writers and verified backup/restore');$this->guard($digest);
        $this->maintenance(['_next1012'],function()use($digest){
            $this->guard($digest);if(!hash_equals($this->fingerprint()['sha256'],$digest))throw new RuntimeException('Staging reconciliation requires unchanged sealed originals');
            foreach(SharedObjectSchema::TABLES as$t)if($this->columns($t.'_legacy1012'))throw new RuntimeException('Already switched: staging must not be discarded');
            foreach(self::COMPACT as$t=>$columns){$actual=$this->columns($t.'_next1012');if($actual&&$actual!==$columns)throw new RuntimeException('Unknown staging schema: left unchanged');if($actual)$this->attestLegacy($t,'_next1012',true);}
            // Only these reserved derived tables; originals remain the restoration source.
            $tables=[];foreach(array_reverse(SharedObjectSchema::TABLES)as$t)if($this->columns($t.'_next1012'))$tables[]=$t.'_next1012';$tables[]='shared_object_migration1012';$this->dropDerived($tables);
        });
    }
    public function retire(string $digest,bool $writersStopped,bool $backupRestored):void {
        if(!$writersStopped||!$backupRestored)throw new RuntimeException('Retirement requires stopped writers and tested backup/restore');$this->guard($digest);
        $this->maintenance(['_legacy1012'],function()use($digest){
            $this->guard($digest);if($this->columns('shared_object_catalog')!==['id','r','n','s']||!hash_equals($this->fingerprint('_legacy1012')['sha256'],$digest))throw new RuntimeException('Retirement requires exact legacy plan and compact cutover');
            $tables=array_map(static function($t){return $t.'_legacy1012';},array_reverse(SharedObjectSchema::TABLES));$tables[]='shared_object_migration1012';$this->dropDerived($tables);
        });
    }
}
