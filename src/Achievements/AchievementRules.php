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
    public static function definitions($rows,array $previous,array $statistics,array $values,int $now,int $activeLimit=self::ACTIVE,int $lifetimeLimit=self::LIFETIME):array
    {
        if(!is_array($rows)||strlen(InboxRules::json($rows))>1024*1024||($rows!==[]&&array_keys($rows)!==range(0,count($rows)-1)))self::invalid('Provide a bounded definition list');
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
            $amounts=PlayerValuesLedger::list(array_key_exists('values',$rewards)?$rewards['values']:[],8);$unique=[];$normalized=[];
            foreach($amounts as $value){
                if(!is_array($value))self::invalid('Invalid value reward');
                $valueKey=PlayerValuesLedger::key($value['key']??null);$amount=PlayerValuesLedger::integer($value['amount']??null,1);
                if(isset($unique[$valueKey]))self::invalid('Duplicate value reward');$unique[$valueKey]=true;
                $normalized[]=['key'=>$valueKey,'amount'=>$amount];
            }
            usort($normalized,static function($a,$b){return strcmp($a['key'],$b['key']);});$rewards=['values'=>$normalized];
            foreach($rewards['values']as$value)if(!isset($values[$value['key']])||$value['amount']>$values[$value['key']]['maxBalance'])self::fail('UNKNOWN_VALUE','Reward must fit a configured maximum balance',422);
            $definition=['key'=>$key,'name'=>$name,'description'=>$description,'imageUrl'=>$image,'hidden'=>$hidden,'active'=>$enabled,'mode'=>$mode,'condition'=>$condition,'rewards'=>$rewards];
            $next[]=$definition;if(!in_array($key,$known,true))$known[]=$key;
        }
        if($active>$activeLimit && $active>count(array_filter($previous['definitions'],static function($row){return $row['active'];})))self::fail('ACHIEVEMENT_LIMIT_REACHED','Active definition limit reached',403);
        if(count($known)>$lifetimeLimit && count($known)>count($previous['lifetimeKeys']))self::fail('ACHIEVEMENT_LIFETIME_LIMIT_REACHED','Lifetime key limit reached');
        $catalog=['projectId'=>$previous['projectId'],'revision'=>$previous['revision'],'definitions'=>$next,'lifetimeKeys'=>$known];
        if(strlen(InboxRules::json($catalog))>1024*1024)self::invalid('Catalogue exceeds1MiB');
        return $catalog;
    }
}
