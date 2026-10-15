<?php

declare(strict_types=1);
require_once __DIR__ . '/../Inbox/InboxRules.php';

class AchievementFailure extends RuntimeException
{
    public string $errorCode;
    public int $status;
    public function __construct(string $code, string $message, int $status)
    { parent::__construct($message); $this->errorCode=$code; $this->status=$status; }
}

class AchievementRules
{
    public const ACTIVE=250;
    public const LIFETIME=500;
    public static function fail(string $code,string $message,int $status=409):void { throw new AchievementFailure($code,$message,$status); }
    public static function invalid(string $message):void { self::fail('INVALID_REQUEST',$message,400); }
    public static function key($value):string
    {
        if(!is_string($value)||!preg_match('/^[a-z0-9][a-z0-9._-]{0,23}$/D',$value))self::invalid('Invalid achievement key');
        return $value;
    }
    private static function text($value,int $bytes,bool $blank=true):string
    {
        if(!is_string($value)||!preg_match('//u',$value)||strlen($value)>$bytes||(!$blank&&preg_match('/^[\s\p{Z}]*$/u',$value)))self::invalid('Invalid bounded UTF-8 text');
        return $value;
    }
    public static function content(array $definition,int $issued):array
    {
        return InboxRules::content(['type'=>'achievement','reference'=>$definition['key'],'textKey'=>'horizon.achievement.unlocked',
            'parameters'=>['key'=>$definition['key']],'expiresAt'=>InboxRules::instant($issued+InboxRules::RETENTION),'attachment'=>$definition['rewards']]);
    }
    public static function definitions($rows,array $previous,array $statistics,array $values,int $now):array
    {
        if(!is_array($rows)||count($rows)>self::LIFETIME||($rows!==[]&&array_keys($rows)!==range(0,count($rows)-1)))self::invalid('Provide at most500 definitions');
        $known=$previous['lifetimeKeys'];$seen=[];$active=0;$next=[];
        foreach($rows as $row){
            if(!is_array($row))self::invalid('Definition must be an object');
            $key=self::key($row['key']??null);if(isset($seen[$key]))self::invalid('Duplicate achievement key');$seen[$key]=true;
            $name=self::text($row['name']??null,120,false);$description=self::text(array_key_exists('description',$row)?$row['description']:'',512);
            $hidden=$row['hidden']??false;$enabled=$row['active']??true;
            if(!is_bool($hidden)||!is_bool($enabled)||array_key_exists('hidden',$row)&&$row['hidden']===null||array_key_exists('active',$row)&&$row['active']===null)self::invalid('Flags must be booleans');
            if($enabled)$active++;
            $image=$row['imageUrl']??null;
            if($image!==null){
                InboxRules::text($image,2048);$url=parse_url($image);
                if($url===false||!in_array($url['scheme']??null,['http','https'],true)||empty($url['host'])||isset($url['user'])||isset($url['pass']))self::invalid('Image URL must be HTTP(S) without credentials');
            }
            $mode=$row['mode']??null;$condition=$row['condition']??null;
            if(!in_array($mode,['manual','statistic'],true))self::invalid('Invalid unlock mode');
            if($mode==='manual'&&$condition!==null)self::invalid('Manual achievements have no statistic condition');
            if($mode==='statistic'){
                if(!is_array($condition))self::invalid('Statistic condition required');
                $stat=self::key($condition['statisticKey']??null);$threshold=$condition['threshold']??null;
                if(!is_int($threshold)||$threshold<0||$threshold>PlayerValuesLedger::MAX)self::invalid('Threshold must be a nonnegative safe integer');
                if(!isset($statistics[$stat]))self::fail('UNKNOWN_STATISTIC','Statistic must be configured',422);
                $condition=['statisticKey'=>$stat,'threshold'=>$threshold];
            }
            $rewards=array_key_exists('rewards',$row)?$row['rewards']:['values'=>[]];
            if(!is_array($rewards)||array_diff(array_keys($rewards),['values']))self::invalid('Only value rewards are supported');
            $content=InboxRules::content(['type'=>'achievement','textKey'=>'horizon.achievement.unlocked','expiresAt'=>InboxRules::instant($now+InboxRules::RETENTION),'attachment'=>$rewards]);
            $rewards=$content['attachment'];
            foreach($rewards['values']as$value)if(!isset($values[$value['key']])||$value['amount']>$values[$value['key']]['maxBalance'])self::fail('UNKNOWN_VALUE','Reward must fit a configured maximum balance',422);
            $definition=['key'=>$key,'name'=>$name,'description'=>$description,'imageUrl'=>$image,'hidden'=>$hidden,'active'=>$enabled,'mode'=>$mode,'condition'=>$condition,'rewards'=>$rewards];
            if($rewards['values'])InboxRules::validate(self::content($definition,$now),$now);
            $next[]=$definition;if(!in_array($key,$known,true))$known[]=$key;
        }
        if($active>self::ACTIVE)self::fail('ACHIEVEMENT_LIMIT_REACHED','Active definition limit reached',403);
        if(count($known)>self::LIFETIME)self::fail('ACHIEVEMENT_LIFETIME_LIMIT_REACHED','Lifetime key limit reached');
        $catalog=['projectId'=>$previous['projectId'],'revision'=>$previous['revision'],'definitions'=>$next,'lifetimeKeys'=>$known];
        if(strlen(InboxRules::json($catalog))>1024*1024)self::invalid('Catalogue exceeds1MiB');
        return $catalog;
    }
}
