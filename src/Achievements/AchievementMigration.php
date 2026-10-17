<?php

declare(strict_types=1);
require_once __DIR__ . '/AchievementService.php';
require_once __DIR__ . '/../Inbox/InboxLegacyRewards.php';

/** Operator-invoked bounded cold conversion. Reads/startup never settle historical rewards. */
class AchievementMigration
{
    private PDO $pdo;
    private SqlTransaction $transactions;
    private $clock;
    private bool $mysql;
    public function __construct(PDO $pdo,?callable $clock=null) {
        $this->pdo=$pdo;$this->transactions=new SqlTransaction($pdo);
        $this->clock=$clock??static function(){return (int)floor(microtime(true)*1000);};
        $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    }
    public function page(?string $after=null,int $limit=10,bool $apply=false):array {
        if($limit<1||$limit>50)throw new InvalidArgumentException('Page limit must be1..50');
        if($after!==null)InboxRules::uuid($after);
        $q=$this->pdo->prepare('SELECT user_id FROM achievement_state'.($after===null?'':' WHERE user_id>?').' ORDER BY user_id LIMIT '.$limit);
        $q->execute($after===null?[]:[$after]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$converted=0;
        if($apply)foreach($ids as $user)if($this->player($user))$converted++;
        return ['examined'=>count($ids),'converted'=>$converted,'nextCursor'=>$ids?end($ids):$after,'complete'=>count($ids)<$limit];
    }
    public function player(string $user):bool {
        InboxRules::uuid($user);
        return $this->transactions->run(function()use($user){
            $lock=$this->mysql?' FOR UPDATE':'';
            $q=$this->pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$q->execute([$user]);if($q->fetchColumn()===false)AchievementStorage::required();
            $catalog=json_decode($this->pdo->query('SELECT data FROM achievement_catalog WHERE id=1'.$lock)->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            $q=$this->pdo->prepare('SELECT revision,data FROM achievement_state WHERE user_id=?'.$lock);$q->execute([$user]);$row=$q->fetch();if($row===false)return false;
            $state=AchievementStorage::decode($row['data'],$catalog['lifetimeKeys']);if($state['compact'])return false;
            if($state['revision']!==(int)$row['revision']||$state['revision']>=PlayerValuesLedger::MAX||$state['catalogRevision']>$catalog['revision'])AchievementStorage::required();
            if(array_diff(array_keys($state),['revision','catalogRevision','unlocks','compact']) || !is_array($state['unlocks']))AchievementStorage::required();
            $seen=[];$sources=[];$pending=[];$next=AchievementStorage::empty();
            $next['revision']=$state['revision']+1;$next['catalogRevision']=$catalog['revision'];
            $inbox=new InboxLegacyRewards($this->pdo,$this->clock,$this->transactions);
            foreach($state['unlocks'] as $record){
                if(!is_array($record)||array_diff(array_keys($record),['key','issuedAt','sourceId','rewardState','pending','inboxEntryId'])||count($record)!==6||!is_int($record['issuedAt'])||$record['issuedAt']<0)AchievementStorage::required();
                $key=$record['key'];if(isset($seen[$key])||!in_array($key,$catalog['lifetimeKeys'],true))AchievementStorage::required();$seen[$key]=true;
                if($record['rewardState']!=='none'){
                    $source='achievement:'.InboxRules::uuid($record['sourceId']);if(isset($sources[$source]))AchievementStorage::required();$sources[$source]=true;
                }
                if($record['rewardState']==='delivered'){
                    if($record['pending']!==null||$record['inboxEntryId']===null)AchievementStorage::required();
                    $inbox->settle($user,$record['inboxEntryId'],$source,$record['issuedAt'],$key);
                }elseif($record['rewardState']==='pending'){
                    $original=$record['pending'];
                    if(!is_array($original)||$record['inboxEntryId']!==null||$original['type']!=='achievement'||$original['reference']!==$key||empty($original['attachment']['values'])||isset($original['attachment']['itemReturn']))AchievementStorage::required();
                    $original=InboxLegacyRewards::content($original);
                    $inbox->requireUndelivered($user,$source);$keys=[];
                    foreach($original['attachment']['values'] as $amount){
                        $k=PlayerValuesLedger::key($amount['key']);$n=PlayerValuesLedger::integer($amount['amount'],1);
                        if(isset($keys[$k])||($pending[$k]??0)>PlayerValuesLedger::MAX-$n)AchievementStorage::required();
                        $keys[$k]=true;$pending[$k]=($pending[$k]??0)+$n;
                    }
                }elseif($record['rewardState']!=='none'||$record['pending']!==null||$record['inboxEntryId']!==null)AchievementStorage::required();
                $next['unlocks'][]=['key'=>$key,'issuedAt'=>null,'rewardState'=>'complete','pending'=>null,'inboxEntryId'=>null];
            }
            $q=$this->pdo->prepare('UPDATE achievement_state SET revision=?,data=? WHERE user_id=? AND revision=? AND data=?');
            $q->execute([$next['revision'],AchievementStorage::encode($next,$catalog['lifetimeKeys']),$user,$row['revision'],$row['data']]);if($q->rowCount()!==1)AchievementRules::fail('STATE_CONFLICT','Original achievement changed');
            if($pending){$grants=[];foreach($pending as $key=>$amount)$grants[]=['key'=>$key,'amount'=>$amount];
                (new PlayerValuesService($this->pdo,$this->clock,$this->transactions))->grantFromSource($user,$grants,'achievement-migration:'.$user.':'.$next['revision'],($this->clock)(),'achievements');}
            return true;
        });
    }
}
