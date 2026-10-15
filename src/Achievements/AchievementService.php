<?php

declare(strict_types=1);
require_once __DIR__ . '/AchievementRules.php';
require_once __DIR__ . '/../Statistics/StatisticsService.php';
require_once __DIR__ . '/../Inbox/InboxService.php';

/** One installation, explicit shared PDO scope, permanent guards and immutable pending Inbox intents. */
class AchievementService
{
    private PDO $pdo;
    private bool $mysql;
    private $clock;
    private SqlTransaction $transactions;
    private StatisticsService $statistics;
    private InboxService $inbox;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null)
    {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->clock=$clock??static function(){return (int)floor(microtime(true)*1000);};
        $this->transactions=$transactions??new SqlTransaction($pdo);
        if(!$this->transactions->owns($pdo))throw new InvalidArgumentException('Foreign transaction connection');
        $this->statistics=new StatisticsService($pdo,$this->clock,$this->transactions);
        $this->inbox=new InboxService($pdo,$this->clock,$this->transactions);
        if(!$this->mysql)$pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function transaction(callable $work)
    {
        try{return $this->transactions->run($work);}
        catch(SqlTransactionUncertain $error){AchievementRules::fail('STATE_CONFLICT','Commit uncertain; retry the same operation');}
        catch(SqlTransactionRollbackOnly $error){AchievementRules::fail('STATE_CONFLICT','Nested transaction failed');}
        catch(PDOException $error){if(in_array((int)($error->errorInfo[1]??0),[5,6,19,1062,1205,1213,2006,2013],true))AchievementRules::fail('STATE_CONFLICT','Concurrent state; retry the same operation');throw $error;}
    }
    private function player(string $user,bool $lock=false):void
    {
        $q=$this->pdo->prepare('SELECT id FROM users WHERE id=?'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute([$user]);
        if($q->fetch()===false)AchievementRules::fail('PLAYER_NOT_FOUND','Player not found',404);
    }
    private function catalog(int $lock=0):array
    {
        $suffix=$this->mysql?($lock===2?' FOR UPDATE':($lock===1?' LOCK IN SHARE MODE':'')):'';
        $data=$this->pdo->query('SELECT data FROM achievement_catalog WHERE id=1'.$suffix)->fetchColumn();
        if($data===false)throw new RuntimeException('Achievement schema not initialized');
        return json_decode($data,true,512,JSON_THROW_ON_ERROR);
    }
    private function state(string $user,bool $lock=false):array
    {
        $q=$this->pdo->prepare('SELECT revision,data FROM achievement_state WHERE user_id=?'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute([$user]);$row=$q->fetch();
        if($row===false)return ['revision'=>0,'catalogRevision'=>0,'unlocks'=>[],'exists'=>false];
        $state=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
        if((int)$row['revision']!==$state['revision'])AchievementRules::fail('STATE_CONFLICT','Inconsistent progress revision');
        $state['exists']=true;return $state;
    }
    private function save(string $user,array $state,int $expected):void
    {
        if($expected>=PlayerValuesLedger::MAX)AchievementRules::fail('STATE_CONFLICT','Progress revision exhausted');
        $exists=$state['exists'];unset($state['exists']);$state['revision']=$expected+1;
        if($exists){$q=$this->pdo->prepare('UPDATE achievement_state SET revision=?,data=? WHERE user_id=? AND revision=?');$q->execute([$state['revision'],InboxRules::json($state),$user,$expected]);if($q->rowCount()!==1)AchievementRules::fail('STATE_CONFLICT','Progress changed');}
        else $this->pdo->prepare('INSERT INTO achievement_state(user_id,revision,data) VALUES(?,?,?)')->execute([$user,$state['revision'],InboxRules::json($state)]);
    }
    public function definitions():array
    {
        $catalog=$this->catalog();$present=array_column($catalog['definitions'],'key');
        return ['apiKeyId'=>$catalog['projectId'],'revision'=>$catalog['revision'],'definitions'=>$catalog['definitions'],
            'archivedKeys'=>array_values(array_diff($catalog['lifetimeKeys'],$present)),'limits'=>['maxDefinitions'=>AchievementRules::ACTIVE,'maxLifetimeKeys'=>AchievementRules::LIFETIME],'serverTime'=>InboxRules::instant(($this->clock)())];
    }
    public function configure($definitions,int $expected):array
    {
        return $this->transaction(function()use($definitions,$expected){
            $previous=$this->catalog(2);if($expected<0||$previous['revision']!==$expected)AchievementRules::fail('REVISION_CONFLICT','Reload definitions');
            $stats=array_column($this->statistics->config(false,true)['definitions'],null,'key');
            $values=array_column((new PlayerValuesService($this->pdo,$this->clock,$this->transactions))->config(false,true)['definitions'],null,'key');
            $next=AchievementRules::definitions($definitions,$previous,$stats,$values,($this->clock)());
            if($next['definitions']!==$previous['definitions']){
                if($expected>=PlayerValuesLedger::MAX)AchievementRules::fail('STATE_CONFLICT','Catalogue revision exhausted');
                $next['revision']=$expected+1;$this->pdo->prepare('UPDATE achievement_catalog SET data=? WHERE id=1')->execute([InboxRules::json($next)]);
            }
            return $this->definitions();
        });
    }
    public function get(string $user,?string $known=null):array
    {
        $this->player($user);
        if($known!==null&&!preg_match('/^[0-9a-f]{64}$/D',$known))AchievementRules::invalid('Invalid definitionVersion');
        $catalog=$this->catalog();$state=$this->state($user);
        if($state['catalogRevision']>$catalog['revision'])$catalog=$this->catalog();
        if($state['catalogRevision']>$catalog['revision'])AchievementRules::fail('STATE_CONFLICT','Catalogue is behind progress');
        $version=hash('sha256',$catalog['projectId'].':'.$user.':'.$catalog['revision'].':'.$state['revision']);$unlocked=array_column($state['unlocks'],'key');
        $definitions=$known===$version?null:array_values(array_filter($catalog['definitions'],static function($row)use($unlocked){return $row['active']&&(!$row['hidden']||in_array($row['key'],$unlocked,true));}));
        $progress=array_map(static function($record){return ['key'=>$record['key'],'unlockedAt'=>InboxRules::instant($record['issuedAt']),
            'rewardState'=>$record['rewardState'],'inboxEntryId'=>$record['inboxEntryId'],'pendingReward'=>$record['pending']===null?null:
                ['issuedAt'=>InboxRules::instant($record['issuedAt']),'values'=>$record['pending']['attachment']['values']]];},$state['unlocks']);
        return ['userId'=>$user,'revision'=>$state['revision'],'definitionVersion'=>$version,'definitions'=>$definitions,'progress'=>$progress,
            'limits'=>['maxDefinitions'=>AchievementRules::ACTIVE,'maxLifetimeKeys'=>AchievementRules::LIFETIME],'serverTime'=>InboxRules::instant(($this->clock)())];
    }
    private function add(string $user,array $current,array $catalog,array $definitions):void
    {
        if($current['catalogRevision']>$catalog['revision'])AchievementRules::fail('STATE_CONFLICT','Stale catalogue');
        $now=($this->clock)();$next=$current;
        foreach($definitions as$definition){
            $pending=$definition['rewards']['values']?AchievementRules::content($definition,$now):null;
            $next['unlocks'][]=['key'=>$definition['key'],'issuedAt'=>$now,'sourceId'=>Database::uuid(),'rewardState'=>$pending===null?'none':'pending','pending'=>$pending,'inboxEntryId'=>null];
        }
        if(count($next['unlocks'])>AchievementRules::LIFETIME)AchievementRules::fail('ACHIEVEMENT_LIFETIME_LIMIT_REACHED','Progress lifetime bound reached');
        $next['catalogRevision']=max($current['catalogRevision'],$catalog['revision']);$this->save($user,$next,$current['revision']);
    }
    public function report(string $user,string $operation,array $updates):array
    {
        StatisticsRules::operation($operation);StatisticsRules::updates($updates);
        $catalog=$this->catalog();$keys=array_column($updates,'key');
        $relevant=array_values(array_filter($catalog['definitions'],static function($row)use($keys){return $row['active']&&$row['mode']==='statistic'&&in_array($row['condition']['statisticKey'],$keys,true);}));
        if(!$relevant)return $this->statistics->update($user,$operation,$updates);
        $external=$this->transactions->isActive();
        $result=$this->transaction(function()use($user,$operation,$updates,$catalog,$relevant){
            $this->player($user,true);$currentCatalog=$this->catalog(1);
            if($catalog['revision']!==$currentCatalog['revision'])AchievementRules::fail('STATE_CONFLICT','Catalogue changed');
            $snapshot=$this->statistics->update($user,$operation,$updates);
            if($snapshot['replayed'])return $snapshot;
            $current=$this->state($user,true);if($current['catalogRevision']>$catalog['revision'])AchievementRules::fail('STATE_CONFLICT','Stale catalogue');
            $unlocked=array_column($current['unlocks'],'key');$values=array_column($snapshot['values'],null,'key');
            $new=array_values(array_filter($relevant,static function($row)use($unlocked,$values){$condition=$row['condition'];$value=$values[$condition['statisticKey']]??null;return !in_array($row['key'],$unlocked,true)&&$value!==null&&$value['hasValue']&&$value['value']>=$condition['threshold'];}));
            if($new)$this->add($user,$current,$catalog,$new);
            $snapshot['newlyUnlocked']=array_column($new,'key');return $snapshot;
        });
        // Never attempt Inbox delivery before an external caller commits its outer transaction.
        if(!$external&&$result['newlyUnlocked'])$this->drain($user,array_slice($result['newlyUnlocked'],0,8));
        return $result;
    }
    public function unlock(string $user,string $key):array
    {
        AchievementRules::key($key);$external=$this->transactions->isActive();
        $replayed=$this->transaction(function()use($user,$key){
            $this->player($user,true);$current=$this->state($user,true);
            if(in_array($key,array_column($current['unlocks'],'key'),true))return true;
            $catalog=$this->catalog(1);$definition=null;
            foreach($catalog['definitions']as$row)if($row['key']===$key&&$row['active'])$definition=$row;
            if($definition===null)AchievementRules::fail('ACHIEVEMENT_NOT_FOUND','Achievement not found',404);
            if($definition['mode']!=='manual')AchievementRules::fail('ACHIEVEMENT_NOT_MANUAL','Statistic achievement cannot be unlocked manually');
            $this->add($user,$current,$catalog,[$definition]);return false;
        });
        $results=$external?[]:$this->drain($user,[$key]);
        return ['achievements'=>$this->get($user),'newlyUnlocked'=>$replayed?[]:[$key],'replayed'=>$replayed,'deliveryResults'=>$results];
    }
    public function deliver(string $user,$keys):array
    {
        if(!is_array($keys)||!$keys||count($keys)>8||array_keys($keys)!==range(0,count($keys)-1))AchievementRules::invalid('Provide one to eight keys');
        foreach($keys as$key)AchievementRules::key($key);if(count(array_unique($keys))!==count($keys))AchievementRules::invalid('Keys must be unique');
        $this->player($user);if($this->transactions->isActive())AchievementRules::fail('STATE_CONFLICT','Commit outer progress before delivering rewards');
        $results=$this->drain($user,$keys);
        return ['achievements'=>$this->get($user),'results'=>$results];
    }
    private function drain(string $user,array $keys):array
    {
        $outcomes=[];$full=false;
        foreach($keys as$key){
            try{
                $state=$this->state($user);$record=null;foreach($state['unlocks']as$row)if($row['key']===$key)$record=$row;
                if($record===null){$outcomes[]=['key'=>$key,'state'=>'none','code'=>'ACHIEVEMENT_NOT_FOUND'];continue;}
                if($record['rewardState']!=='pending'){$outcomes[]=['key'=>$key,'state'=>$record['rewardState'],'inboxEntryId'=>$record['inboxEntryId']];continue;}
                if($full){$outcomes[]=['key'=>$key,'state'=>'pending','code'=>'INBOX_FULL'];continue;}
                $source='achievement:'.$record['sourceId'];
                $result=$this->inbox->deliverPending($user,$record['pending'],$source,$record['issuedAt'],$source,function(PDO $connection,string $entry)use($user,$key,$record){
                    if($connection!==$this->pdo)return false;$current=$this->state($user,true);$changed=false;
                    foreach($current['unlocks']as&$pending)if($pending['key']===$key&&$pending['sourceId']===$record['sourceId']&&$pending['rewardState']==='pending'&&$pending['pending']===$record['pending']){
                        $pending['rewardState']='delivered';$pending['pending']=null;$pending['inboxEntryId']=$entry;$changed=true;
                    }unset($pending);
                    if(!$changed)return false;$this->save($user,$current,$current['revision']);return true;
                });
                $outcomes[]=['key'=>$key,'state'=>'delivered','inboxEntryId'=>$result['entryId']];
            }catch(Throwable $error){
                $code=$error instanceof InboxFailure?$error->errorCode:'DELIVERY_PENDING';if($code==='INBOX_FULL')$full=true;
                $confirmed=null;try{foreach($this->state($user)['unlocks']as$row)if($row['key']===$key&&$row['rewardState']==='delivered')$confirmed=$row;}catch(Throwable $ignored){}
                $outcomes[]=$confirmed?['key'=>$key,'state'=>'delivered','inboxEntryId'=>$confirmed['inboxEntryId']]:['key'=>$key,'state'=>'pending','code'=>$code];
            }
        }
        return $outcomes;
    }
}
