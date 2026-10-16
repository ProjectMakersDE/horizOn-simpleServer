<?php

declare(strict_types=1);
require_once __DIR__.'/SharedObjectRules.php';
class SharedObjectQuery
{
    private $random;
    public function __construct(?callable $random=null){$this->random=$random??static function(int $min,int $max):int{return random_int($min,$max);};}
    public function plan(array $definition,$raw,string $partition,?string $actor): array
    {
        $input=SharedObjectRules::fields($raw,['userId','ownerId','groupId','mode','limit','cursor','field','equal','min','max','minX','maxX','minY','maxY']);
        $mode=$input['mode']??null;if(!in_array($mode,['list','owner','field','rectangle','random'],true))SharedObjectRules::invalid();
        $limit=SharedObjectRules::integer($input['limit']??20,1,50);
        $extra=['list'=>[],'owner'=>[],'field'=>['field','equal','min','max'],'random'=>['field','min','max'],'rectangle'=>['minX','maxX','minY','maxY']];
        if(array_diff(array_keys($input),array_merge(['userId','ownerId','groupId','mode','limit','cursor'],$extra[$mode])))SharedObjectRules::invalid();
        foreach(['userId','ownerId','groupId'] as $f)if(array_key_exists($f,$input))$input[$f]=SharedObjectRules::uuid($input[$f]);
        if(($input['userId']??null)!==$actor||($mode==='owner'&&!isset($input['ownerId']))||($mode==='random'&&array_key_exists('cursor',$input)))SharedObjectRules::invalid();
        foreach(['equal','min','max'] as $f)if(array_key_exists($f,$input)&&!is_int($input[$f])&&!is_string($input[$f]))SharedObjectRules::invalid();
        $seek=['partition'=>$partition,'owner'=>$mode==='owner'?$input['ownerId']:null,'field'=>'id','lower'=>null,'upper'=>null,'exclusive'=>false,'afterId'=>null,'afterSort'=>null];$wrap=null;
        if($mode==='field'||$mode==='random'){
            $field=SharedObjectRules::key($input['field']??null);$slot=null;$type=null;foreach($definition['searchFields'] as $i=>$configured)if($configured['key']===$field){$slot=$i;$type=$configured['type'];}
            if($slot===null)SharedObjectRules::invalid();$equality=array_key_exists('equal',$input);
            if(($equality&&(array_key_exists('min',$input)||array_key_exists('max',$input)))||(!$equality&&(!array_key_exists('min',$input)||!array_key_exists('max',$input))))SharedObjectRules::invalid();
            $seek['field']='slot'.$slot;$seek['lower']=SharedObjectRules::sort($equality?$input['equal']:$input['min'],$type);$seek['upper']=SharedObjectRules::sort($equality?$input['equal']:$input['max'],$type);
            if(strcmp($seek['lower'],$seek['upper'])>0)SharedObjectRules::invalid();
            if($mode==='random'){
                if($type!=='integer'||$equality)SharedObjectRules::invalid();$pivot=($this->random)($input['min'],$input['max']);SharedObjectRules::integer($pivot,-SharedObjectRules::MAX);if($pivot<$input['min']||$pivot>$input['max'])throw new LogicException('Random pivot bound');
                $pivot=SharedObjectRules::sort($pivot,'integer');$wrap=$seek;$wrap['upper']=$pivot;$wrap['exclusive']=true;$seek['lower']=$pivot;
            }
        }elseif($mode==='rectangle'){
            if(!$definition['positionEnabled'])SharedObjectRules::invalid();foreach(['minX','maxX','minY','maxY'] as $f)$input[$f]=SharedObjectRules::integer($input[$f]??null,-SharedObjectRules::MAX);
            if($input['minX']>$input['maxX']||$input['minY']>$input['maxY'])SharedObjectRules::invalid();$seek['field']='position_x';$seek['lower']=SharedObjectRules::sort($input['minX'],'integer');$seek['upper']=SharedObjectRules::sort($input['maxX'],'integer');
        }
        $identity=[$definition['key'],$definition['revision'],$partition,$actor,$input['ownerId']??null,$input['groupId']??null,$mode,$limit,$input['field']??null,$input['equal']??null,$input['min']??null,$input['max']??null,$input['minX']??null,$input['maxX']??null,$input['minY']??null,$input['maxY']??null];
        $fingerprint=hash('sha256',SharedObjectRules::json($identity));
        if(array_key_exists('cursor',$input)){
            $cursor=$input['cursor'];if(!is_string($cursor)||strlen($cursor)<1||strlen($cursor)>2048||!preg_match('/^[A-Za-z0-9_-]+$/D',$cursor))SharedObjectRules::invalid();
            $bytes=base64_decode(strtr($cursor,'-_','+/'),true);if($bytes===false||self::base64($bytes)!==$cursor)SharedObjectRules::invalid();
            try{$tree=json_decode($bytes,true,8,JSON_THROW_ON_ERROR);}catch(JsonException $error){SharedObjectRules::invalid();}
            if(!is_array($tree)||array_keys($tree)!==[0,1,2]||!is_string($tree[0])||!is_string($tree[1])||!is_string($tree[2])||$tree[0]!==$fingerprint||SharedObjectRules::uuid($tree[2])!==$tree[2])SharedObjectRules::invalid();
            $sort=$tree[1];if($seek['field']==='id'){if($sort!=='')SharedObjectRules::invalid();}
            elseif(strlen($sort)>192||!preg_match('/^[0-9a-f]*$/D',$sort)||($seek['lower']!==null&&strcmp($sort,$seek['lower'])<0)||($seek['upper']!==null&&strcmp($sort,$seek['upper'])>0))SharedObjectRules::invalid();
            $seek['afterId']=$tree[2];$seek['afterSort']=$seek['field']==='id'?null:$sort;
        }
        return ['seek'=>$seek,'wrap'=>$wrap,'fingerprint'=>$fingerprint,'random'=>$mode==='random','rectangle'=>$mode==='rectangle'?$input:null,'limit'=>$limit];
    }
    private static function base64(string $value): string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
    public function cursor(array $plan,array $row): string{return self::base64(SharedObjectRules::json([$plan['fingerprint'],$plan['seek']['field']==='id'?'':$row[$plan['seek']['field']],$row['id']]));}
    public function accepts(array $plan,array $row): bool
    {if($plan['rectangle']===null)return true;$p=$row['position'];$r=$plan['rectangle'];return $p!==null&&$p['x']>=$r['minX']&&$p['x']<=$r['maxX']&&$p['y']>=$r['minY']&&$p['y']<=$r['maxY'];}
}
