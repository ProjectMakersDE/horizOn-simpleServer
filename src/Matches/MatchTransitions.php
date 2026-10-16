<?php

declare(strict_types=1);
require_once __DIR__.'/MatchRules.php';
/** Pure bounded state rules. Opaque bytes remain in the separate payload table. */
class MatchTransitions
{
    public static function scheduled(array $v): array
    {$due=$v['status']==='lobby'?$v['lobbyDeadline']:($v['status']==='active'?min(array_filter([$v['turnDeadline'],$v['absoluteDeadline'],$v['notice']['issuedAt']??null],static function($x){return $x!==null;})):($v['status']==='reporting'?$v['reportDeadline']:$v['purgeAt']));$v['dueAt']=$due;$v['openForJoin']=$v['status']==='lobby'&&$v['visibility']==='open'&&count($v['participantIds'])<$v['capacity'];return $v;}
    public static function changed(array $v): array{$v['revision']=MatchRules::next($v['revision']);return self::scheduled($v);}
    public static function close(array $v,string $status,int $now): array
    {if(!in_array($status,MatchRules::TERMINAL,true))throw new LogicException('Invalid terminal state');$v=array_replace($v,['status'=>$status,'currentPlayerId'=>null,'turnDeadline'=>null,'notice'=>null,'invitedIds'=>[],'finishedAt'=>$now,'purgeAt'=>$now+604800000]);return self::changed($v);}
    private static function notice(array $v,int $now): array
    {$v['notice']=['revision'=>$v['revision'],'userId'=>$v['currentPlayerId'],'operationId'=>'match:'.$v['id'].':turn:'.$v['revision'],'issuedAt'=>$now,'expiresAt'=>$v['turnDeadline']];return self::scheduled($v);}
    public static function start(array $v,array $order,int $now): array
    {if($v['status']!=='lobby'||$v['lobbyDeadline']<=$now)MatchRules::fail('MATCH_STATE_CONFLICT');$order=MatchRules::ids($order);$sorted=$order;$players=$v['participantIds'];sort($sorted,SORT_STRING);sort($players,SORT_STRING);if(count($order)<2||$sorted!==$players)MatchRules::invalid();$v=array_replace($v,['status'=>'active','turnOrder'=>$order,'invitedIds'=>[],'startedAt'=>$now,'absoluteDeadline'=>$now+2592000000,'currentPlayerId'=>$order[0],'turnDeadline'=>$now+$v['turnSeconds']*1000]);return self::notice(self::changed($v),$now);}
    private static function reporting(array $v,int $now): array
    {$v=array_replace($v,['status'=>'reporting','currentPlayerId'=>null,'turnDeadline'=>null,'notice'=>null,'reportDeadline'=>$now+86400000]);$v=self::changed($v);$v['finalStateRevision']=$v['revision'];return $v;}
    private static function nextTurn(array $v,int $now): array
    {if($v['absoluteDeadline']<=$now)return self::close($v,'unconfirmed',$now);$index=array_search($v['currentPlayerId'],$v['turnOrder'],true);if($index===false)throw new LogicException('Missing turn owner');for($i=1;$i<=count($v['turnOrder']);$i++){$next=$v['turnOrder'][($index+$i)%count($v['turnOrder'])];if(!in_array($next,$v['forfeitedIds'],true)){$v=array_replace($v,['currentPlayerId'=>$next,'turnDeadline'=>$now+$v['turnSeconds']*1000,'notice'=>null]);return self::notice(self::changed($v),$now);}}return self::close($v,'unconfirmed',$now);}
    public static function turn(array $v,string $user,int $bytes,bool $finish,int $now): array
    {if($v['status']!=='active')MatchRules::fail('MATCH_STATE_CONFLICT');if($v['turnDeadline']<=$now||$v['absoluteDeadline']<=$now)MatchRules::fail('TURN_EXPIRED');if($v['currentPlayerId']!==$user||in_array($user,$v['forfeitedIds'],true))MatchRules::fail('NOT_YOUR_TURN');$v['payloadRevision']=MatchRules::next($v['payloadRevision']);$v['payloadBytes']=$bytes;return $finish?self::reporting($v,$now):self::nextTurn($v,$now);}
    public static function forfeit(array $v,string $user,int $now): array
    {if($v['status']!=='active'||!in_array($user,$v['participantIds'],true)||in_array($user,$v['forfeitedIds'],true))MatchRules::fail('MATCH_STATE_CONFLICT');if($v['absoluteDeadline']<=$now)return self::close($v,'unconfirmed',$now);$v['forfeitedIds'][]=$user;$remaining=array_diff($v['participantIds'],$v['forfeitedIds']);if(!$remaining)return self::close($v,'unconfirmed',$now);if(count($remaining)===1)return self::reporting($v,$now);return $v['currentPlayerId']===$user?self::nextTurn($v,$now):self::changed($v);}
    public static function timeout(array $v,int $now): array
    {if($v['status']!=='active')MatchRules::fail('MATCH_STATE_CONFLICT');if($v['absoluteDeadline']<=$now)return self::close($v,'unconfirmed',$now);if($v['turnDeadline']>$now)MatchRules::fail('MATCH_STATE_CONFLICT');return $v['timeoutPolicy']==='forfeit'?self::forfeit($v,$v['currentPlayerId'],$now):self::nextTurn($v,$now);}
    public static function report(array $v,array $report,int $now): array
    {if($v['status']!=='reporting'||$v['reportDeadline']<=$now)MatchRules::fail('MATCH_STATE_CONFLICT');$v['reports'][]=$report;$first=$v['reports'][0];$v['hasDisagreement']=false;foreach($v['reports']as$r)if($r['resultBase64']!==$first['resultBase64']||$r['winnerIds']!==$first['winnerIds'])$v['hasDisagreement']=true;return count($v['reports'])===count($v['participantIds'])?self::close($v,$v['hasDisagreement']?'disputed':'confirmed',$now):self::changed($v);}
}
