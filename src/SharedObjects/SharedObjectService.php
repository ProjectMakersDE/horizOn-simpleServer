<?php

declare(strict_types=1);
require_once __DIR__.'/SharedObjectStore.php';
require_once __DIR__.'/SharedObjectQuery.php';
require_once __DIR__.'/../Core/SqlTransaction.php';
require_once __DIR__.'/../Groups/GroupStore.php';
class SharedObjectService
{
    private PDO $pdo;private bool $mysql;private SqlTransaction $transactions;private SharedObjectStore $store;private SharedObjectQuery $planner;private GroupStore $groups;private $clock;private array $cap;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null,?array $limits=null,?SharedObjectQuery $planner=null)
    {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->transactions=$transactions??new SqlTransaction($pdo);if(!$this->transactions->owns($pdo))throw new InvalidArgumentException('Foreign transaction connection');
        $this->clock=$clock??static function(){return Database::nowMillis();};$this->store=new SharedObjectStore($pdo);$this->groups=new GroupStore($pdo);$this->planner=$planner??new SharedObjectQuery();
        $this->cap=$limits??['collections'=>100,'storageBytes'=>2147483648,'objectBytes'=>65536];SharedObjectRules::integer($this->cap['collections'],1,100);SharedObjectRules::integer($this->cap['storageBytes'],1,2147483648);SharedObjectRules::integer($this->cap['objectBytes'],1,65536);
        if(!$this->mysql)$pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function translate(callable $work)
    {
        try{return $work();}catch(SqlTransactionUncertain $e){SharedObjectRules::fail('STATE_CONFLICT');}catch(SqlTransactionRollbackOnly $e){SharedObjectRules::fail('STATE_CONFLICT');}
        catch(GroupFailure $e){SharedObjectRules::fail('PERMISSIONS_CHANGED');}
        catch(PDOException $e){if(in_array((int)($e->errorInfo[1]??0),[5,6,19,1062,1205,1213,2006,2013],true))SharedObjectRules::fail('STATE_CONFLICT');throw $e;}
    }
    private function atomic(callable $work)
    {
        // A caller may already own a native PDO transaction (including an older RR snapshot).
        // Keep failed feature work atomic even if that caller catches the refusal and commits.
        $savepoint=$this->pdo->inTransaction()?'shared_'.bin2hex(random_bytes(8)):null;
        return $this->translate(function()use($work,$savepoint){
            if($savepoint!==null)$this->pdo->exec('SAVEPOINT '.$savepoint);
            try{$result=$this->transactions->run($work);if($savepoint!==null)$this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);return $result;}
            catch(Throwable $error){if($savepoint!==null&&$this->pdo->inTransaction()){try{$this->pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$this->pdo->exec('RELEASE SAVEPOINT '.$savepoint);}catch(Throwable $ignored){}}throw $error;}
        });
    }
    /** Point/permission/payload reads share one snapshot without taking a SQLite writer lock. */
    private function snapshot(callable $work)
    {
        return $this->translate(function()use($work){
            if($this->transactions->isActive()){
                // Session defaults cannot prove a per-transaction isolation override. Public reads
                // require our own RR snapshot; reject an opaque external MySQL read transaction.
                if($this->mysql)SharedObjectRules::fail('STATE_CONFLICT');
                return $work();
            }
            if($this->mysql)$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->beginTransaction();try{$result=$work();$this->pdo->commit();return $result;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        });
    }
    private function now(): int{return ($this->clock)();}
    private function actor(?string $user,bool $lock=false): void{if($user!==null&&!$this->store->players([$user],$lock))SharedObjectRules::fail('PLAYER_NOT_FOUND',404);}
    private function definition(string $collection,bool $lock=false): array{$definition=$this->store->collection(SharedObjectRules::key($collection),$lock);if($definition===null)SharedObjectRules::fail('COLLECTION_NOT_FOUND',404);return $definition;}
    private function usageView(string $collection,string $user,array $usage): array{return ['userId'=>$user,'collection'=>$collection,'revision'=>$usage['revision'],'count'=>$usage['count'],'limits'=>$this->cap,'pollAfterSeconds'=>120,'serverTime'=>SharedObjectRules::instant($this->now())];}
    public function collections(?string $user,?string $after=null,int $limit=50,?int $expected=null): array
    {
        if($user!==null)$user=SharedObjectRules::uuid($user);if($after!==null)SharedObjectRules::key($after);SharedObjectRules::integer($limit,1,50);if($expected!==null)SharedObjectRules::integer($expected);
        return $this->snapshot(function()use($user,$after,$limit,$expected){$this->actor($user);$header=$this->store->catalog();if($expected!==null&&$expected!==$header['revision'])SharedObjectRules::fail('COLLECTION_REVISION_CONFLICT');$rows=$this->store->collections($after,$limit);$page=array_slice($rows,0,$limit);return ['revision'=>$header['revision'],'definitions'=>$page,'nextAfterKey'=>count($rows)>$limit?$page[count($page)-1]['key']:null,'limits'=>$this->cap,'serverTime'=>SharedObjectRules::instant($this->now())];});
    }
    public function put(string $key,$expected,$raw): array
    {
        SharedObjectRules::key($key);SharedObjectRules::integer($expected);
        return $this->atomic(function()use($key,$expected,$raw){$header=$this->store->catalog(true);if($header['revision']!==$expected)SharedObjectRules::fail('COLLECTION_REVISION_CONFLICT');$before=$this->store->collection($key,true);$effective=$this->cap;$effective['objectBytes']=max($effective['objectBytes'],$before['maxObjectBytes']??0);$input=SharedObjectRules::definition($raw,$effective);
            if($input['maxObjectBytes']>$this->cap['objectBytes']&&$input['maxObjectBytes']!==($before['maxObjectBytes']??null))SharedObjectRules::invalid();
            if($before===null&&$header['total']>=$this->cap['collections'])SharedObjectRules::fail('COLLECTION_LIMIT_REACHED',422);
            if($before!==null&&$before['used']&&SharedObjectRules::schema($input)!==SharedObjectRules::schema($before))SharedObjectRules::fail('COLLECTION_SCHEMA_LOCKED',422);
            $next=['key'=>$key]+$input+['used'=>$before['used']??false,'revision'=>SharedObjectRules::next($before['revision']??0)];$changed=$header;$changed['revision']=SharedObjectRules::next($header['revision']);$changed['total']+=($before===null?1:0);$this->store->saveCatalog($header,$changed);$this->store->saveCollection($before,$next);return ['revision'=>$changed['revision'],'definition'=>$next,'limits'=>$this->cap,'serverTime'=>SharedObjectRules::instant($this->now())];});
    }
    public function limits(): array{return $this->snapshot(function(){$q=$this->store->quota();return ['limits'=>$this->cap,'chargedBytes'=>$q['chargedBytes'],'revision'=>$q['revision']];});}
    public function usage(?string $actor,string $collection,string $owner): array
    {
        if($actor!==null)$actor=SharedObjectRules::uuid($actor);$owner=SharedObjectRules::uuid($owner);
        return $this->snapshot(function()use($actor,$collection,$owner){$this->actor($actor);$this->definition($collection);if($actor!==null&&$actor!==$owner)SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);return $this->usageView($collection,$owner,$this->store->usage($collection,$owner));});
    }
    private function member(string $group,string $user): bool{return $this->groups->group($group)!==null&&$this->groups->member($group,$user)!==null;}
    private function groupFence(string $group,?string $user,bool $requireMember): void
    {
        // Locking the current group row serializes against every GroupService permission transition.
        // GroupStore rejects a newer locking row than a pre-existing outer RR snapshot (ABA fence).
        if($this->groups->group($group,true)===null)SharedObjectRules::fail('PERMISSIONS_CHANGED');
        if($requireMember&&($user===null||$this->groups->member($group,$user)===null))SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);
    }
    private function friends(string $first,string $second): bool
    {
        $q=$this->pdo->prepare('SELECT user_id,friends,blocked FROM friends_state WHERE user_id IN (?,?)');$q->execute([$first,$second]);$rows=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$rows[$r['user_id']]=$r;
        foreach([[$first,$second],[$second,$first]] as [$owner,$peer]){if(!isset($rows[$owner]))return false;$friends=json_decode($rows[$owner]['friends'],true,512,JSON_THROW_ON_ERROR);$blocked=json_decode($rows[$owner]['blocked'],true,512,JSON_THROW_ON_ERROR);if(!in_array($peer,array_column($friends,'userId'),true)||in_array($peer,array_column($blocked,'userId'),true))return false;}return true;
    }
    private function canRead(?string $actor,array $definition,array $row): bool
    {if($actor===null)return true;if($definition['readPolicy']==='dashboard')return false;if($actor===$row['owner_id'])return true;switch($definition['readPolicy']){case 'all':return true;case 'friends':return $this->friends($actor,$row['owner_id']);case 'group':return $row['group_id']!==null&&$this->member($row['group_id'],$actor);default:return false;}}
    private function view(array $row,string $payload): array
    {return ['id'=>$row['id'],'collection'=>$row['collection_key'],'ownerId'=>$row['owner_id'],'groupId'=>$row['group_id'],'revision'=>$row['revision'],'dataBase64'=>base64_encode($payload),'search'=>$row['search'],'position'=>$row['position'],'createdAt'=>SharedObjectRules::instant($row['created_millis']),'updatedAt'=>SharedObjectRules::instant($row['updated_millis']),'expiresAt'=>$row['expires_millis']===null?null:SharedObjectRules::instant($row['expires_millis'])];}
    public function get(?string $actor,string $collection,string $id): array
    {
        if($actor!==null)$actor=SharedObjectRules::uuid($actor);$id=SharedObjectRules::uuid($id);
        return $this->snapshot(function()use($actor,$collection,$id){$this->actor($actor);$definition=$this->definition($collection);$row=$this->store->object($collection,$id);
            if($row===null||($row['expires_millis']!==null&&$row['expires_millis']<=$this->now())||!$this->canRead($actor,$definition,$row)||($actor!==null&&!$this->store->players([$row['owner_id']])))SharedObjectRules::fail('OBJECT_NOT_FOUND',404);
            $payloads=$this->store->payloads($collection,[$row]);return ['object'=>$this->view($row,$payloads[$id]['payload']),'pollAfterSeconds'=>120,'serverTime'=>SharedObjectRules::instant($this->now())];});
    }
    private function queryPartition(?string $actor,array $definition,array $input): string
    {
        if(($input['userId']??null)!==$actor)SharedObjectRules::invalid();if($actor!==null&&$definition['readPolicy']==='dashboard')SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);$owner=$input['ownerId']??$actor;
        if(in_array($definition['readPolicy'],['owner','friends','dashboard'],true)){
            if(isset($input['groupId'])||$owner===null||($definition['readPolicy']==='friends'&&!isset($input['ownerId'])))SharedObjectRules::invalid();
            if($actor!==null&&$actor!==$owner&&($definition['readPolicy']!=='friends'||!$this->friends($actor,$owner)))SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);return 'owner:'.$owner;
        }
        if(isset($input['ownerId'])&&($input['mode']??null)!=='owner')SharedObjectRules::invalid();
        if($definition['readPolicy']==='all'){if(isset($input['groupId']))SharedObjectRules::invalid();return 'all';}
        $group=$input['groupId']??null;if($group===null)SharedObjectRules::invalid();if($actor!==null&&!(($input['mode']??null)==='owner'&&($input['ownerId']??null)===$actor)&&!$this->member($group,$actor))SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);return 'group:'.$group;
    }
    public function query(?string $actor,string $collection,$raw): array
    {
        if($actor!==null)$actor=SharedObjectRules::uuid($actor);$input=SharedObjectRules::fields($raw,['userId','ownerId','groupId','mode','limit','cursor','field','equal','min','max','minX','maxX','minY','maxY']);foreach(['userId','ownerId','groupId'] as $f)if(array_key_exists($f,$input))$input[$f]=SharedObjectRules::uuid($input[$f]);
        return $this->snapshot(function()use($actor,$collection,$input){$this->actor($actor);$definition=$this->definition($collection);$partition=$this->queryPartition($actor,$definition,$input);$plan=$this->planner->plan($definition,$input,$partition,$actor);$candidates=$this->store->candidates($collection,$plan['seek'],SharedObjectRules::CANDIDATES);if($plan['wrap']!==null&&count($candidates)<SharedObjectRules::CANDIDATES)$candidates=array_merge($candidates,$this->store->candidates($collection,$plan['wrap'],SharedObjectRules::CANDIDATES-count($candidates)));
            $live=$actor===null?array_column($candidates,'owner_id'):$this->store->players(array_column($candidates,'owner_id'));if($plan['random'])shuffle($candidates);$selected=[];$bytes=0;$consumed=null;$now=$this->now();
            foreach($candidates as $row){$visible=in_array($row['owner_id'],$live,true)&&($row['expires_millis']===null||$row['expires_millis']>$now)&&$this->planner->accepts($plan,$row);if($visible&&(count($selected)===$plan['limit']||$bytes+$row['payload_bytes']>SharedObjectRules::PAGE_BYTES))break;$consumed=$row;if($visible){$selected[]=$row;$bytes+=$row['payload_bytes'];}}
            $payloads=$this->store->payloads($collection,$selected);$objects=[];foreach($selected as $row)$objects[]=$this->view($row,$payloads[$row['id']]['payload']);$last=$candidates?end($candidates):null;$more=$consumed!==null&&($consumed['id']!==($last['id']??null)||count($candidates)===SharedObjectRules::CANDIDATES);
            return ['objects'=>$objects,'nextCursor'=>!$plan['random']&&$more?$this->planner->cursor($plan,$consumed):null,'pollAfterSeconds'=>120,'serverTime'=>SharedObjectRules::instant($now)];});
    }
    private function writeFences(?string $actor,string $owner,?string $group,array $definition,bool $creating): void
    {
        if($actor!==null&&($definition['writePolicy']==='dashboard'||($definition['writePolicy']==='owner'&&$actor!==$owner)))SharedObjectRules::fail('COLLECTION_ACCESS_DENIED',403);
        $ids=array_values(array_unique($actor===null?[$owner]:[$actor,$owner]));if(count($this->store->players($ids,true))!==count($ids))SharedObjectRules::fail('PLAYER_NOT_FOUND',404);
        if(SharedObjectRules::groupRequired($definition)){$this->groupFence($group??SharedObjectRules::invalid(),$actor??$owner,$creating||$actor!==null);}elseif($group!==null)SharedObjectRules::invalid();
    }
    private function quotaChange(int $delta): void
    {$before=$this->store->quota(true);$total=$before['chargedBytes']+$delta;if($total<0||$total>SharedObjectRules::MAX)SharedObjectRules::fail('STATE_CONFLICT');if($delta>0&&$total>$this->cap['storageBytes'])SharedObjectRules::fail('STORAGE_LIMIT_REACHED',422);$this->store->saveQuota($before,$total);}
    private function writable(array $definition): void{if(!$definition['active'])SharedObjectRules::fail('COLLECTION_ARCHIVED',422);}
    private function input($raw): array
    {$input=SharedObjectRules::fields($raw,['userId','ownerId','dataBase64','search','position','groupId']);foreach(['userId','ownerId','groupId'] as $f)if(array_key_exists($f,$input))$input[$f]=SharedObjectRules::uuid($input[$f]);return $input;}
    private function response(array $row,array $usage): array{return ['object'=>$this->view($row,$row['payload']),'usage'=>$this->usageView($row['collection_key'],$row['owner_id'],$usage),'pollAfterSeconds'=>120,'serverTime'=>SharedObjectRules::instant($this->now())];}
    public function create(?string $actor,string $collection,$expected,$raw): array
    {
        if($actor!==null)$actor=SharedObjectRules::uuid($actor);SharedObjectRules::integer($expected);$input=$this->input($raw);if(($actor!==null&&(($input['userId']??null)!==$actor||array_key_exists('ownerId',$input)))||($actor===null&&(!isset($input['ownerId'])||array_key_exists('userId',$input))))SharedObjectRules::invalid();$owner=$actor??$input['ownerId'];
        return $this->atomic(function()use($actor,$owner,$collection,$expected,$input){$this->actor($actor,true);$definition=$this->definition($collection,true);$this->writable($definition);$payload=SharedObjectRules::bytes($input['dataBase64']??null,min($definition['maxObjectBytes'],$this->cap['objectBytes']));$metadata=SharedObjectRules::metadata($input,$definition);$usage=$this->store->usage($collection,$owner,true);if($usage['revision']!==$expected)SharedObjectRules::fail('REVISION_CONFLICT');if($usage['count']>=$definition['maxObjectsPerOwner'])SharedObjectRules::fail('OBJECT_COUNT_LIMIT_REACHED',422);
            $group=$input['groupId']??null;$this->writeFences($actor,$owner,$group,$definition,true);
            if(!$definition['used']){$header=$this->store->catalog(true);$next=$header;$next['revision']=SharedObjectRules::next($header['revision']);$this->store->saveCatalog($header,$next);$changed=$definition;$changed['used']=true;$changed['revision']=SharedObjectRules::next($definition['revision']);$this->store->saveCollection($definition,$changed);$definition=$changed;}
            $charged=strlen($payload)+SharedObjectRules::METADATA;$this->quotaChange($charged+($usage['exists']?0:SharedObjectRules::OWNER));$nextUsage=$this->store->saveUsage($collection,$owner,$usage,$usage['count']+1);$now=$this->now();$row=['id'=>Database::uuid(),'collection_key'=>$collection,'partition_key'=>$definition['readPolicy']==='all'?'all':($definition['readPolicy']==='group'?'group:'.$group:'owner:'.$owner),'owner_id'=>$owner,'group_id'=>$group,'revision'=>1,'payload'=>$payload,'payload_bytes'=>strlen($payload),'charged_bytes'=>$charged,'search'=>$metadata['search'],'position'=>$metadata['position'],'slot0'=>$metadata['slots'][0],'slot1'=>$metadata['slots'][1],'slot2'=>$metadata['slots'][2],'position_x'=>$metadata['positionX'],'created_millis'=>$now,'updated_millis'=>$now,'expires_millis'=>$definition['ttlSeconds']===null?null:$now+$definition['ttlSeconds']*1000];$this->store->insert($row);return $this->response($row,$nextUsage);});
    }
    public function update(?string $actor,string $collection,string $id,$expected,$raw): array
    {
        if($actor!==null)$actor=SharedObjectRules::uuid($actor);$id=SharedObjectRules::uuid($id);SharedObjectRules::integer($expected);$input=$this->input($raw);if(array_key_exists('ownerId',$input)||array_key_exists('groupId',$input)||($actor!==null&&($input['userId']??null)!==$actor)||($actor===null&&array_key_exists('userId',$input)))SharedObjectRules::invalid();
        return $this->atomic(function()use($actor,$collection,$id,$expected,$input){$this->actor($actor,true);$definition=$this->definition($collection,true);$this->writable($definition);$before=$this->store->object($collection,$id,true);if($before===null)SharedObjectRules::fail('OBJECT_NOT_FOUND',404);
            if($actor!==null&&($definition['writePolicy']==='dashboard'||($definition['writePolicy']==='owner'&&$actor!==$before['owner_id'])||($definition['writePolicy']==='group'&&($before['group_id']===null||!$this->member($before['group_id'],$actor)))))SharedObjectRules::fail('OBJECT_NOT_FOUND',404);
            if($before['revision']!==$expected)SharedObjectRules::fail('REVISION_CONFLICT');if($before['expires_millis']!==null&&$before['expires_millis']<=$this->now())SharedObjectRules::fail('OBJECT_NOT_FOUND',404);$this->writeFences($actor,$before['owner_id'],$before['group_id'],$definition,false);
            $payload=SharedObjectRules::bytes($input['dataBase64']??null,min($definition['maxObjectBytes'],$this->cap['objectBytes']));$metadata=SharedObjectRules::metadata($input,$definition);$next=$before;$next['revision']=SharedObjectRules::next($before['revision']);$next['payload']=$payload;$next['payload_bytes']=strlen($payload);$next['charged_bytes']=strlen($payload)+SharedObjectRules::METADATA;$next['search']=$metadata['search'];$next['position']=$metadata['position'];$next['position_x']=$metadata['positionX'];foreach([0,1,2] as $slot)$next['slot'.$slot]=$metadata['slots'][$slot];$next['updated_millis']=$this->now();
            $this->quotaChange($next['charged_bytes']-$before['charged_bytes']);$usage=$this->store->usage($collection,$before['owner_id'],true);if(!$usage['exists'])SharedObjectRules::fail('STATE_CONFLICT');$usage=$this->store->saveUsage($collection,$before['owner_id'],$usage,$usage['count']);$this->store->save($before,$next);return $this->response($next,$usage);});
    }
    private function remove(array $row): array
    {$this->store->remove($row);$usage=$this->store->usage($row['collection_key'],$row['owner_id'],true);if(!$usage['exists'])SharedObjectRules::fail('STATE_CONFLICT');$next=$this->store->saveUsage($row['collection_key'],$row['owner_id'],$usage,$usage['count']-1);$this->quotaChange(-$row['charged_bytes']);return $next;}
    public function delete(?string $actor,string $collection,string $id,$expected): array
    {
        if($actor!==null)$actor=SharedObjectRules::uuid($actor);$id=SharedObjectRules::uuid($id);SharedObjectRules::integer($expected);
        return $this->atomic(function()use($actor,$collection,$id,$expected){$this->actor($actor,true);$definition=$this->definition($collection,true);$row=$this->store->object($collection,$id,true);if($row===null)SharedObjectRules::fail('OBJECT_NOT_FOUND',404);
            if($actor!==null){if($definition['writePolicy']==='dashboard')SharedObjectRules::fail('OBJECT_NOT_FOUND',404);if($actor!==$row['owner_id']){if($definition['writePolicy']!=='group'||$row['group_id']===null||!$this->member($row['group_id'],$actor))SharedObjectRules::fail('OBJECT_NOT_FOUND',404);$this->groupFence($row['group_id'],$actor,true);}$this->actor($actor,true);}
            if($row['revision']!==$expected)SharedObjectRules::fail('REVISION_CONFLICT');$usage=$this->remove($row);return ['objectId'=>$id,'deleted'=>true,'usage'=>$this->usageView($collection,$row['owner_id'],$usage),'pollAfterSeconds'=>120,'serverTime'=>SharedObjectRules::instant($this->now())];});
    }
    public function cleanupExpired(): array
    {
        $removed=0;$refunded=0;foreach($this->store->expired($this->now()) as $candidate)$this->atomic(function()use($candidate,&$removed,&$refunded){$row=$this->store->object($candidate['collection_key'],$candidate['id'],true);if($row!==null&&$row['expires_millis']!==null&&$row['expires_millis']<=$this->now()){$this->remove($row);$removed++;$refunded+=$row['charged_bytes'];}});return ['removed'=>$removed,'refundedBytes'=>$refunded];
    }
    public function cleanupDeletedPlayer(string $user): array
    {
        $user=SharedObjectRules::uuid($user);if($this->store->players([$user]))SharedObjectRules::fail('STATE_CONFLICT');$rows=$this->store->owned($user);$removed=0;$refunded=0;
        foreach($rows as $candidate)$this->atomic(function()use($user,$candidate,&$removed,&$refunded){if($this->store->players([$user],true))SharedObjectRules::fail('STATE_CONFLICT');$row=$this->store->object($candidate['collection_key'],$candidate['id'],true);if($row!==null){$this->remove($row);$removed++;$refunded+=$row['charged_bytes'];}});
        if($rows)return ['userId'=>$user,'removed'=>$removed,'refundedBytes'=>$refunded,'complete'=>false];$owners=$this->store->ownerUsages($user);
        foreach($owners as $collection)$this->atomic(function()use($user,$collection,&$refunded){if($this->store->players([$user],true))SharedObjectRules::fail('STATE_CONFLICT');$usage=$this->store->usage($collection,$user,true);if(!$usage['exists']||$usage['count']!==0)SharedObjectRules::fail('STATE_CONFLICT');$this->store->removeUsage($collection,$user,$usage);$this->quotaChange(-$usage['chargedBytes']);$refunded+=$usage['chargedBytes'];});
        return ['userId'=>$user,'removed'=>0,'refundedBytes'=>$refunded,'complete'=>!$owners];
    }
    public function cleanupDeleted(?string $after=null): array
    {
        if($after!==null)$after=SharedObjectRules::uuid($after);$ids=$this->store->deletedOwners($after);$results=[];
        foreach(array_slice($ids,0,10) as $user){$restored=$this->atomic(function()use($user){if(!$this->store->players([$user],true))return false;$this->store->rebind($user);return true;});$results[]=$restored?['userId'=>$user,'restored'=>true,'complete'=>false]:$this->cleanupDeletedPlayer($user);}
        return ['results'=>$results,'nextAfterUserId'=>count($ids)>10?$ids[9]:null];
    }
}
