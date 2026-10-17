<?php

declare(strict_types=1);
require_once __DIR__.'/MatchRules.php';

/** Pure SQL codec. Existing indexed columns are authoritative; no second header/report copy. */
class MatchStorage
{
    public const ACTIVE=1,DETAIL=2,INVITED=4,SETTLEMENT=8,CLOSED=32;
    public const SQL_FIELDS='id,revision,store_revision,payload_revision,payload_bytes,creator_id,status,visibility,strength,open_for_join,lobby_deadline,due_millis';
    private const HEADER=['v','n','c','f','t','a','u','i','o','fm','ci','td','st','fin','pur','fv','rd','ad','q','no','ng'];
    private const SUMMARY=['id','name','revision','creatorId','capacity','visibility','strength','status','participantIds','invitedIds','forfeitedIds','currentPlayerId','turnDeadline','createdAt','startedAt','finishedAt','purgeAt','finalStateRevision','hasDisagreement','ranked','absoluteDeadline','lobbyDeadline'];
    private const RECORD=['id','creatorId','name','capacity','visibility','strength','turnSeconds','timeoutPolicy','ranked','rankedLeaderboardKey','createdAt','lobbyDeadline','revision','payloadRevision','payloadBytes','status','participantIds','invitedIds','forfeitedIds','turnOrder','currentPlayerId','turnDeadline','startedAt','finishedAt','purgeAt','finalStateRevision','hasDisagreement','reportDeadline','absoluteDeadline','reports','notice','dueAt','openForJoin'];
    public static function required():void{MatchRules::fail('MIGRATION_REQUIRED');}
    private static function valid(bool $value):void{if(!$value)self::required();}
    private static function fields(array $value,array $allowed,array $required=[]):void{self::valid(!array_diff(array_keys($value),$allowed)&&!array_diff($required,array_keys($value)));}
    public static function json(string $raw):array{
        try{ItemJson::decode($raw,strlen($raw));$v=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){self::required();}
        self::valid(is_array($v)&&($v===[]||array_keys($v)!==range(0,count($v)-1)));return $v;
    }
    public static function number($v,int $min=0,int $max=MatchRules::MAX):int{self::valid(is_int($v)&&$v>=$min&&$v<=$max);return $v;}
    public static function sqlNumber($v,int $min=0,int $max=MatchRules::MAX):int{
        if(is_string($v)){self::valid(preg_match('/^-?(0|[1-9][0-9]*)$/D',$v)===1&&(string)(int)$v===$v);$v=(int)$v;}return self::number($v,$min,$max);
    }
    public static function uuid($id):string{self::valid(is_string($id)&&preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id)===1);return $id;}
    private static function list($v):array{self::valid(is_array($v)&&(!$v||array_keys($v)===range(0,count($v)-1)));return $v;}
    private static function ids($v,int $max=8):array{$v=self::list($v);self::valid(count($v)<=$max&&count(array_unique($v,SORT_REGULAR))===count($v));foreach($v as $id)self::uuid($id);return $v;}
    private static function text($v,int $max):string{self::valid(is_string($v)&&strlen($v)<=$max&&preg_match('//u',$v)===1);return $v;}
    private static function binary($v):string{self::valid(is_string($v));$b=base64_decode($v,true);self::valid($b!==false&&base64_encode($b)===$v);return $b;}
    private static function uuidBytes(string $id):string{return hex2bin(str_replace('-','',self::uuid($id)));}
    private static function uuidRead(string $bytes):string{self::valid(strlen($bytes)===16);$h=bin2hex($bytes);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);}
    private static function packedIds(array $ids):string{$b='';foreach(self::ids($ids) as $id)$b.=self::uuidBytes($id);return base64_encode($b);}
    private static function readIds($base64):array{$b=self::binary($base64);self::valid(strlen($b)%16===0);$v=[];for($i=0;$i<strlen($b);$i+=16)$v[]=self::uuidRead(substr($b,$i,16));return self::ids($v);}
    private static function index(array $users,string $id):int{$i=array_search($id,$users,true);self::valid($i!==false);return $i;}
    private static function indexes(array $users,array $ids):string{$b='';foreach(self::ids($ids) as $id)$b.=chr(self::index($users,$id));return base64_encode($b);}
    private static function readIndexes(array $users,$base64):array{$b=self::binary($base64);$v=[];for($i=0;$i<strlen($b);$i++){$n=ord($b[$i]);self::valid(isset($users[$n]));$v[]=$users[$n];}return self::ids($v);}
    private static function longRead(string $bytes,int &$offset):int{self::valid(strlen($bytes)-$offset>=8);$v=unpack('P',substr($bytes,$offset,8))[1];$offset+=8;return self::number($v);}
    private static function optionalNumber(array $v,string $key):void{if($v[$key]!==null)self::number($v[$key]);}
    private static function validate(array $v,bool $reports=true):void{
        foreach(['id','creatorId'] as $key)self::uuid($v[$key]);self::text($v['name'],120);
        self::number($v['capacity'],2,8);self::number($v['strength'],-MatchRules::MAX);self::number($v['turnSeconds'],60,604800);
        self::valid(in_array($v['visibility'],['open','private'],true)&&in_array($v['timeoutPolicy'],['skip','forfeit'],true)&&in_array($v['status'],MatchRules::STATUSES,true));
        self::valid($v['ranked']===false&&$v['rankedLeaderboardKey']===null&&is_bool($v['hasDisagreement'])&&is_bool($v['openForJoin']));
        foreach(['revision','payloadRevision','payloadBytes','createdAt','lobbyDeadline','dueAt'] as $key)self::number($v[$key]);
        foreach(['turnDeadline','startedAt','finishedAt','purgeAt','finalStateRevision','reportDeadline','absoluteDeadline'] as $key)self::optionalNumber($v,$key);
        $users=self::ids($v['participantIds']);self::valid(count($users)>=1&&count($users)<=$v['capacity']);self::ids($v['invitedIds'],7);self::valid(!array_intersect($users,$v['invitedIds']));
        foreach(['turnOrder','forfeitedIds'] as $key)self::valid(!array_diff(self::ids($v[$key]),$users));
        if($v['currentPlayerId']!==null)self::index($users,self::uuid($v['currentPlayerId']));
        if(array_key_exists('noticeGeneration',$v))self::number($v['noticeGeneration']);
        if($v['notice']!==null){$n=$v['notice'];self::valid(is_array($n));self::fields($n,['revision','userId','operationId','issuedAt','expiresAt'],['revision','userId','operationId','issuedAt','expiresAt']);self::number($n['revision']);self::index($users,self::uuid($n['userId']));self::text($n['operationId'],512);self::number($n['issuedAt']);self::number($n['expiresAt']);}
        if(!$reports)return;$seen=[];foreach(self::list($v['reports']) as $r){self::valid(is_array($r));self::fields($r,['userId','finalStateRevision','resultBase64','winnerIds','reportedAt'],['userId','finalStateRevision','resultBase64','winnerIds','reportedAt']);$u=self::uuid($r['userId']);self::index($users,$u);self::valid(!isset($seen[$u]));$seen[$u]=true;self::number($r['finalStateRevision']);self::number($r['reportedAt']);$ids=self::ids($r['winnerIds']);$sort=$ids;sort($sort,SORT_STRING);self::valid($sort===$ids&&!array_diff($ids,$users));self::valid(strlen(self::binary($r['resultBase64']))<=256);}
    }
    public static function write(array $v):array{
        self::validate($v);$u=$v['participantIds'];$h=['v'=>1,'n'=>$v['name'],'c'=>$v['capacity'],'f'=>($v['timeoutPolicy']==='forfeit'?1:0)|($v['hasDisagreement']?2:0),'t'=>$v['turnSeconds'],'a'=>$v['createdAt'],'u'=>self::packedIds($u)];
        foreach(['i'=>'invitedIds','o'=>'turnOrder','fm'=>'forfeitedIds'] as $key=>$name)if($v[$name])$h[$key]=$key==='i'?self::packedIds($v[$name]):self::indexes($u,$v[$name]);
        if($v['currentPlayerId']!==null)$h['ci']=self::index($u,$v['currentPlayerId']);
        foreach(['td'=>'turnDeadline','st'=>'startedAt','fin'=>'finishedAt','pur'=>'purgeAt','fv'=>'finalStateRevision','rd'=>'reportDeadline','ad'=>'absoluteDeadline','ng'=>'noticeGeneration'] as $key=>$name)if(isset($v[$name]))$h[$key]=$v[$name];
        if($v['notice']!==null){$n=$v['notice'];$h['q']=base64_encode(pack('P',$n['revision']).chr(self::index($u,$n['userId'])).pack('P2',$n['issuedAt'],$n['expiresAt']));if($n['operationId']!=='match:'.$v['id'].':turn:'.$n['revision'])$h['no']=$n['operationId'];}
        $reports='';foreach($v['reports'] as $r){$mask=0;foreach($r['winnerIds'] as $id)$mask|=1<<self::index($u,$id);$bytes=self::binary($r['resultBase64']);$reports.=chr(self::index($u,$r['userId'])).pack('P2',$r['finalStateRevision'],$r['reportedAt']).chr($mask).pack('v',strlen($bytes)).$bytes;}
        return [MatchRules::json($h),MatchRules::json($reports===''?['v'=>1]:['v'=>1,'b'=>base64_encode($reports)])];
    }
    private static function columns(array $row):array{
        return ['id'=>self::uuid($row['id']),'revision'=>self::sqlNumber($row['revision']),'payloadRevision'=>self::sqlNumber($row['payload_revision']),'payloadBytes'=>self::sqlNumber($row['payload_bytes']),'creatorId'=>self::uuid($row['creator_id']),'status'=>self::text($row['status'],16),'visibility'=>self::text($row['visibility'],8),'strength'=>self::sqlNumber($row['strength'],-MatchRules::MAX),'openForJoin'=>self::sqlNumber($row['open_for_join'],0,1)===1,'lobbyDeadline'=>self::sqlNumber($row['lobby_deadline']),'dueAt'=>self::sqlNumber($row['due_millis'])];
    }
    public static function summary(array $row):array{
        $h=self::json($row['summary']);
        if(!array_key_exists('v',$h)){
            self::fields($h,self::SUMMARY,self::SUMMARY);foreach(self::columns($row) as $key=>$value)if(array_key_exists($key,$h))self::valid($h[$key]===$value);
            self::text($h['name'],120);self::number($h['capacity'],2,8);$users=self::ids($h['participantIds']);self::valid(count($users)>=1&&count($users)<=$h['capacity']);self::ids($h['invitedIds'],7);self::valid(!array_diff(self::ids($h['forfeitedIds']),$users)&&$h['ranked']===false&&is_bool($h['hasDisagreement']));
            foreach(['createdAt','lobbyDeadline'] as $key)self::number($h[$key]);foreach(['turnDeadline','startedAt','finishedAt','purgeAt','finalStateRevision','absoluteDeadline'] as $key)self::optionalNumber($h,$key);if($h['currentPlayerId']!==null)self::index($users,self::uuid($h['currentPlayerId']));return $h;
        }
        self::fields($h,self::HEADER,['v','n','c','f','t','a','u']);self::valid($h['v']===1&&!in_array(null,$h,true));$f=self::number($h['f'],0,3);$u=self::readIds($h['u']);self::valid(count($u)>=1);
        $v=self::columns($row)+['name'=>self::text($h['n'],120),'capacity'=>self::number($h['c'],2,8),'turnSeconds'=>self::number($h['t'],60,604800),'timeoutPolicy'=>$f&1?'forfeit':'skip','hasDisagreement'=>(bool)($f&2),'createdAt'=>self::number($h['a']),'participantIds'=>$u,'invitedIds'=>isset($h['i'])?self::readIds($h['i']):[],'turnOrder'=>isset($h['o'])?self::readIndexes($u,$h['o']):[],'forfeitedIds'=>isset($h['fm'])?self::readIndexes($u,$h['fm']):[],'ranked'=>false,'rankedLeaderboardKey'=>null,'notice'=>null,'reports'=>[],'currentPlayerId'=>null,'_compact'=>true];
        foreach(['td'=>'turnDeadline','st'=>'startedAt','fin'=>'finishedAt','pur'=>'purgeAt','fv'=>'finalStateRevision','rd'=>'reportDeadline','ad'=>'absoluteDeadline'] as $key=>$name)$v[$name]=isset($h[$key])?self::number($h[$key]):null;
        if(isset($h['ci'])){$i=self::number($h['ci'],0,7);self::valid(isset($u[$i]));$v['currentPlayerId']=$u[$i];}
        if(isset($h['ng']))$v['noticeGeneration']=self::number($h['ng']);
        if(isset($h['q'])){$b=self::binary($h['q']);self::valid(strlen($b)===25);$p=0;$r=self::longRead($b,$p);$i=ord($b[$p++]);self::valid(isset($u[$i]));$v['notice']=['revision'=>$r,'userId'=>$u[$i],'operationId'=>isset($h['no'])?self::text($h['no'],512):'match:'.$v['id'].':turn:'.$r,'issuedAt'=>self::longRead($b,$p),'expiresAt'=>self::longRead($b,$p)];}else self::valid(!isset($h['no']));
        self::validate($v,false);return $v;
    }
    public static function read(array $row):array{
        $h=self::json($row['summary']);
        if(!array_key_exists('v',$h)){$v=self::json($row['data']);self::fields($v,array_merge(self::RECORD,['noticeGeneration']),self::RECORD);self::validate($v);foreach(self::columns($row) as $key=>$value)self::valid($v[$key]===$value);self::valid(self::summary($row)===array_intersect_key($v,array_flip(self::SUMMARY)));$v['_compact']=false;}
        else{$v=self::summary($row);$d=self::json($row['data']);self::fields($d,['v','b'],['v']);self::valid($d['v']===1&&!in_array(null,$d,true));$b=isset($d['b'])?self::binary($d['b']):'';$p=0;$users=$v['participantIds'];while($p<strlen($b)){self::valid(strlen($b)-$p>=20);$i=ord($b[$p++]);self::valid(isset($users[$i]));$revision=self::longRead($b,$p);$at=self::longRead($b,$p);$mask=ord($b[$p++]);self::valid(($mask&~((1<<count($users))-1))===0);$length=unpack('v',substr($b,$p,2))[1];$p+=2;self::valid($length<=256&&strlen($b)-$p>=$length);$w=[];foreach($users as $j=>$id)if($mask&(1<<$j))$w[]=$id;sort($w,SORT_STRING);$v['reports'][]=['userId'=>$users[$i],'finalStateRevision'=>$revision,'resultBase64'=>base64_encode(substr($b,$p,$length)),'winnerIds'=>$w,'reportedAt'=>$at];$p+=$length;}self::validate($v);}
        $v['_version']=self::sqlNumber($row['store_revision']);return $v;
    }
    public static function playerWrite(array $v):string{
        $edges=[];foreach(['activeIds'=>self::ACTIVE,'historyIds'=>self::DETAIL,'invitedIds'=>self::INVITED,'settlementIds'=>self::SETTLEMENT,'closedIds'=>self::CLOSED] as $name=>$flag)foreach($v[$name] as $id)$edges[self::uuid($id)]=($edges[$id]??0)|$flag;ksort($edges,SORT_STRING);$b='';foreach($edges as $id=>$flag){self::valid(in_array($flag,[4,10,11,34],true));$b.=self::uuidBytes($id).chr($flag);if($flag&self::CLOSED)$b.=pack('P',self::number($v['closedAt'][$id]??null));}return MatchRules::json($b===''?['v'=>1]:['v'=>1,'b'=>base64_encode($b)]);
    }
    public static function playerRead(array $v):array{
        self::fields($v,['v','b'],['v']);self::valid($v['v']===1&&!in_array(null,$v,true));$out=MatchRules::emptyPlayer();$b=isset($v['b'])?self::binary($v['b']):'';$p=0;$seen=[];while($p<strlen($b)){self::valid(strlen($b)-$p>=17);$id=self::uuidRead(substr($b,$p,16));$p+=16;$f=ord($b[$p++]);self::valid(!isset($seen[$id])&&in_array($f,[4,10,11,34],true));$seen[$id]=true;foreach(['activeIds'=>self::ACTIVE,'historyIds'=>self::DETAIL,'invitedIds'=>self::INVITED,'settlementIds'=>self::SETTLEMENT,'closedIds'=>self::CLOSED] as $name=>$flag)if($f&$flag)$out[$name][]=$id;if($f&self::CLOSED)$out['closedAt'][$id]=self::longRead($b,$p);}return $out;
    }
    /** Header-only source check shared with Inbox; never load game bytes or reports. */
    public static function turnNoticeMatches(array $header,string $user,int $expires,int $generation):bool{
        $v=self::summary($header);$original=$v['noticeGeneration']??$header['legacy_notice_generation']??null;if($original!==null)$original=self::sqlNumber($original);return $v['status']==='active'&&$v['currentPlayerId']===$user&&$v['turnDeadline']===$expires&&$original===$generation;
    }
}
