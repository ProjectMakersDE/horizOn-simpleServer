<?php

declare(strict_types=1);
require_once __DIR__ . '/AchievementRules.php';
require_once __DIR__ . '/../Statistics/StatisticsService.php';
require_once __DIR__ . '/AchievementStorage.php';
require_once __DIR__ . '/../PlayerValues/PlayerValuesService.php';

/** One installation, explicit shared PDO scope, compact permanent bits and atomic source-owned credits. */
class AchievementService
{
    private PDO $pdo;
    private bool $mysql;
    private $clock;
    private SqlTransaction $transactions;
    private StatisticsService $statistics;
    private PlayerValuesService $values;
    private array $limits;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null,array $limits=[])
    {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->clock=$clock??static function(){return (int)floor(microtime(true)*1000);};
        $this->transactions=$transactions??new SqlTransaction($pdo);
        if(!$this->transactions->owns($pdo))throw new InvalidArgumentException('Foreign transaction connection');
        $this->statistics=new StatisticsService($pdo,$this->clock,$this->transactions);
        $this->values=new PlayerValuesService($pdo,$this->clock,$this->transactions);
        $this->limits=array_replace(['maxDefinitions'=>Config::operatorLimit('ACHIEVEMENTS_DEFINITIONS',250),
            'maxLifetimeKeys'=>Config::operatorLimit('ACHIEVEMENTS_LIFETIME_KEYS',500)],$limits);
        foreach($this->limits as $key=>$limit) if(!in_array($key,['maxDefinitions','maxLifetimeKeys'],true)||!is_int($limit)||$limit<1||$limit>2147483647)throw new InvalidArgumentException('Invalid achievement operator limit');
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
    private function state(string $user,array &$catalog,bool $lock=false):array
    {
        $q=$this->pdo->prepare('SELECT revision,data FROM achievement_state WHERE user_id=?'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute([$user]);$row=$q->fetch();
        if($row===false)return AchievementStorage::empty()+['exists'=>false];
        $header=json_decode($row['data'],true,512,JSON_THROW_ON_ERROR);
        if(($header['c']??$header['catalogRevision']??0)>$catalog['revision'])$catalog=$this->catalog($lock?1:0);
        $state=AchievementStorage::decode($row['data'],$catalog['lifetimeKeys']);
        if((int)$row['revision']!==$state['revision'])AchievementRules::fail('STATE_CONFLICT','Inconsistent progress revision');
        $state['exists']=true;return $state;
    }
    private function save(string $user,array $state,int $expected,array $catalog):void
    {
        if($expected>=PlayerValuesLedger::MAX)AchievementRules::fail('STATE_CONFLICT','Progress revision exhausted');
        $exists=$state['exists'];unset($state['exists']);$state['revision']=$expected+1;
        if($exists){$q=$this->pdo->prepare('UPDATE achievement_state SET revision=?,data=? WHERE user_id=? AND revision=?');$q->execute([$state['revision'],AchievementStorage::encode($state,$catalog['lifetimeKeys']),$user,$expected]);if($q->rowCount()!==1)AchievementRules::fail('STATE_CONFLICT','Progress changed');}
        else $this->pdo->prepare('INSERT INTO achievement_state(user_id,revision,data) VALUES(?,?,?)')->execute([$user,$state['revision'],AchievementStorage::encode($state,$catalog['lifetimeKeys'])]);
    }
    public function definitions():array
    {
        $catalog=$this->catalog();$present=array_column($catalog['definitions'],'key');
        return ['apiKeyId'=>$catalog['projectId'],'revision'=>$catalog['revision'],'definitions'=>$catalog['definitions'],
            'archivedKeys'=>array_values(array_diff($catalog['lifetimeKeys'],$present)),'limits'=>$this->limits,'serverTime'=>InboxRules::instant(($this->clock)())];
    }
    public function configure($definitions,int $expected):array
    {
        return $this->transaction(function()use($definitions,$expected){
            $previous=$this->catalog(2);if($expected<0||$previous['revision']!==$expected)AchievementRules::fail('REVISION_CONFLICT','Reload definitions');
            $stats=array_column($this->statistics->config(false,true)['definitions'],null,'key');
            $values=array_column((new PlayerValuesService($this->pdo,$this->clock,$this->transactions))->config(false,true)['definitions'],null,'key');
            $next=AchievementRules::definitions($definitions,$previous,$stats,$values,($this->clock)(),$this->limits['maxDefinitions'],$this->limits['maxLifetimeKeys']);
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
        $catalog=$this->catalog();$state=$this->state($user,$catalog);
        if($state['catalogRevision']>$catalog['revision'])$catalog=$this->catalog();
        if($state['catalogRevision']>$catalog['revision'])AchievementRules::fail('STATE_CONFLICT','Catalogue is behind progress');
        $version=hash('sha256',$catalog['projectId'].':'.$user.':'.$catalog['revision'].':'.$state['revision']);$unlocked=array_column($state['unlocks'],'key');
        $definitions=$known===$version?null:array_values(array_filter($catalog['definitions'],static function($row)use($unlocked){return $row['active']&&(!$row['hidden']||in_array($row['key'],$unlocked,true));}));
        $progress=array_map(static function($record){return ['key'=>$record['key'],'unlockedAt'=>$record['issuedAt']===null?null:InboxRules::instant($record['issuedAt']),
            'rewardState'=>$record['rewardState'],'inboxEntryId'=>$record['inboxEntryId'],'pendingReward'=>$record['pending']===null?null:
                ['issuedAt'=>InboxRules::instant($record['issuedAt']),'values'=>$record['pending']['attachment']['values']]];},$state['unlocks']);
        return ['userId'=>$user,'revision'=>$state['revision'],'definitionVersion'=>$version,'definitions'=>$definitions,'progress'=>$progress,
            'limits'=>$this->limits,'serverTime'=>InboxRules::instant(($this->clock)())];
    }
    private function add(string $user,array $current,array $catalog,array $definitions):void
    {
        if($current['catalogRevision']>$catalog['revision'])AchievementRules::fail('STATE_CONFLICT','Stale catalogue');
        if(!$current['compact'])AchievementStorage::required();
        $next=$current;$amounts=[];
        foreach($definitions as $definition){
            $next['unlocks'][]=['key'=>$definition['key'],'issuedAt'=>null,'rewardState'=>'complete','pending'=>null,'inboxEntryId'=>null];
            foreach($definition['rewards']['values'] as $reward){
                $previous=$amounts[$reward['key']]??0;
                if($previous>PlayerValuesLedger::MAX-$reward['amount'])AchievementRules::fail('INVALID_REWARD','Aggregate reward exceeds safe integer');
                $amounts[$reward['key']]=$previous+$reward['amount'];
            }
        }
        $next['catalogRevision']=max($current['catalogRevision'],$catalog['revision']);$this->save($user,$next,$current['revision'],$catalog);
        if($amounts){$grants=[];foreach($amounts as $key=>$amount)$grants[]=['key'=>$key,'amount'=>$amount];
            $this->values->grantFromSource($user,$grants,'achievement:'.$user.':'.($current['revision']+1),($this->clock)(),'achievements');}
    }
    public function report(string $user,string $operation,array $updates):array
    {
        StatisticsRules::operation($operation);StatisticsRules::updates($updates);
        $catalog=$this->catalog();$keys=array_column($updates,'key');
        $relevant=array_values(array_filter($catalog['definitions'],static function($row)use($keys){return $row['active']&&$row['mode']==='statistic'&&in_array($row['condition']['statisticKey'],$keys,true);}));
        if(!$relevant)return $this->statistics->update($user,$operation,$updates);
        $result=$this->transaction(function()use($user,$operation,$updates,$catalog,$relevant){
            $this->player($user,true);$currentCatalog=$this->catalog(1);
            if($catalog['revision']!==$currentCatalog['revision'])AchievementRules::fail('STATE_CONFLICT','Catalogue changed');
            $snapshot=$this->statistics->update($user,$operation,$updates);
            if($snapshot['replayed'])return $snapshot;
            $current=$this->state($user,$catalog,true);if($current['catalogRevision']>$catalog['revision'])AchievementRules::fail('STATE_CONFLICT','Stale catalogue');
            if(!$current['compact'])AchievementStorage::required();
            $unlocked=array_column($current['unlocks'],'key');$values=array_column($snapshot['values'],null,'key');
            $new=array_values(array_filter($relevant,static function($row)use($unlocked,$values){$condition=$row['condition'];$value=$values[$condition['statisticKey']]??null;return !in_array($row['key'],$unlocked,true)&&$value!==null&&$value['hasValue']&&$value['value']>=$condition['threshold'];}));
            if($new)$this->add($user,$current,$catalog,$new);
            $snapshot['newlyUnlocked']=array_column($new,'key');return $snapshot;
        });
        return $result;
    }
    public function unlock(string $user,string $key):array
    {
        AchievementRules::key($key);
        $replayed=$this->transaction(function()use($user,$key){
            $this->player($user,true);$catalog=$this->catalog(1);$current=$this->state($user,$catalog,true);
            if(in_array($key,array_column($current['unlocks'],'key'),true))return true;
            if(!$current['compact'])AchievementStorage::required();
            $definition=null;
            foreach($catalog['definitions']as$row)if($row['key']===$key&&$row['active'])$definition=$row;
            if($definition===null)AchievementRules::fail('ACHIEVEMENT_NOT_FOUND','Achievement not found',404);
            if($definition['mode']!=='manual')AchievementRules::fail('ACHIEVEMENT_NOT_MANUAL','Statistic achievement cannot be unlocked manually');
            $this->add($user,$current,$catalog,[$definition]);return false;
        });
        $results=$this->statuses($user,[$key]);
        return ['achievements'=>$this->get($user),'newlyUnlocked'=>$replayed?[]:[$key],'replayed'=>$replayed,'deliveryResults'=>$results];
    }
    public function deliver(string $user,$keys):array
    {
        if(!is_array($keys)||!$keys||count($keys)>8||array_keys($keys)!==range(0,count($keys)-1))AchievementRules::invalid('Provide one to eight keys');
        foreach($keys as$key)AchievementRules::key($key);if(count(array_unique($keys))!==count($keys))AchievementRules::invalid('Keys must be unique');
        $this->player($user);$results=$this->statuses($user,$keys);
        return ['achievements'=>$this->get($user),'results'=>$results];
    }
    /** Compatibility read: pending legacy rewards require explicit maintenance, never an implicit handoff. */
    private function statuses(string $user,array $keys):array
    {
        $catalog=$this->catalog();$records=array_column($this->state($user,$catalog)['unlocks'],null,'key');$results=[];
        foreach($keys as $key){
            $record=$records[$key]??null;
            if($record===null)$results[]=['key'=>$key,'state'=>'none','code'=>'ACHIEVEMENT_NOT_FOUND'];
            elseif($record['rewardState']==='pending')$results[]=['key'=>$key,'state'=>'pending','code'=>'MIGRATION_REQUIRED'];
            else $results[]=['key'=>$key,'state'=>$record['rewardState'],'inboxEntryId'=>$record['inboxEntryId']];
        }
        return $results;
    }
}
