<?php

declare(strict_types=1);
require_once __DIR__.'/ItemStorage.php';

/** Same-transaction CAS and immutable bases. Reads hydrate bounded indexed batches, never write. */
class ItemStore
{
    private PDO $pdo; private bool $mysql;
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    private function lock(bool $current):string{return $current&&$this->mysql?' FOR UPDATE':'';}
    public function player(string $user,bool $current=false):void{
        $q=$this->pdo->prepare('SELECT id FROM users WHERE id=?'.$this->lock($current));$q->execute([$user]);if($q->fetch()===false)ItemRules::fail('PLAYER_NOT_FOUND',404);
    }
    public function header(bool $current=false):array{
        $r=$this->pdo->query('SELECT revision,total FROM item_catalog WHERE id=1'.$this->lock($current))->fetch(PDO::FETCH_ASSOC);
        return $r===false?['revision'=>0,'total'=>0,'exists'=>false]:['revision'=>(int)$r['revision'],'total'=>(int)$r['total'],'exists'=>true];
    }
    public function saveHeader(array $before,array $next):void{
        if(!$before['exists'])$this->pdo->prepare('INSERT INTO item_catalog(id,revision,total) VALUES(1,?,?)')->execute([$next['revision'],$next['total']]);
        else{$q=$this->pdo->prepare('UPDATE item_catalog SET revision=?,total=? WHERE id=1 AND revision=?');$q->execute([$next['revision'],$next['total'],$before['revision']]);if($q->rowCount()!==1)ItemRules::fail('REVISION_CONFLICT');}
    }
    private function reserve(bool $slot):int{
        $row=$this->pdo->query('SELECT next_slot,next_version FROM item_storage_catalog WHERE id=1'.$this->lock(true))->fetch(PDO::FETCH_ASSOC);
        if($row===false){$row=['next_slot'=>0,'next_version'=>1];$this->pdo->exec('INSERT INTO item_storage_catalog(id,next_slot,next_version) VALUES(1,0,1)');}
        $field=$slot?'next_slot':'next_version';$value=(int)$row[$field];
        if($value>=($slot?ItemStorage::SLOTS:ItemRules::MAX))ItemRules::fail('ITEM_STORAGE_IDENTITY_EXHAUSTED');
        $q=$this->pdo->prepare('UPDATE item_storage_catalog SET '.$field.'=? WHERE id=1 AND '.$field.'=?');$q->execute([$value+1,$value]);if($q->rowCount()!==1)ItemRules::fail('STATE_CONFLICT');return $value;
    }
    private function bases(array $versions,bool $current=false):array{
        $out=[];
        foreach(array_chunk(array_values(array_unique($versions)),128)as $chunk){
            $q=$this->pdo->prepare('SELECT b.version,b.slot,b.data,t.item_key FROM item_bases b JOIN item_template_storage t ON t.slot=b.slot WHERE b.version IN ('.implode(',',array_fill(0,count($chunk),'?')).')'.$this->lock($current));$q->execute($chunk);
            foreach($q->fetchAll(PDO::FETCH_ASSOC)as $r)$out[(int)$r['version']]=['version'=>(int)$r['version'],'slot'=>(int)$r['slot'],'key'=>$r['item_key'],'properties'=>ItemStorage::properties($r['data'])];
        }return $out;
    }
    private function definitions(array $rows,bool $current):array{
        $parsed=[];$versions=[];foreach($rows as $row){$r=json_decode($row['data'],true,32,JSON_THROW_ON_ERROR);if(!isset($r['s'])){$original=json_decode($row['data'],false,32,JSON_THROW_ON_ERROR);if(!($original->baseProperties??null) instanceof stdClass)ItemStorage::required();$r['baseProperties']=ItemRules::properties($original->baseProperties);}$parsed[]=$r;if(isset($r['s'])){if($r['s']!==1)ItemStorage::corrupt();$versions[]=$r['v'];}}
        $bases=$this->bases($versions,$current);$out=[];
        foreach($parsed as $r){
            if(!isset($r['s'])){$r['baseProperties']=(object)$r['baseProperties'];$out[]=$r;continue;}
            $base=$bases[$r['v']]??null;if($base===null||$base['key']!==$r['k'])ItemStorage::corrupt();
            $definition=['key'=>$r['k'],'name'=>$r['n'],'type'=>$r['t'],'rarity'=>$r['q'],'active'=>$r['a'],'baseProperties'=>$base['properties'],'propertyRules'=>ItemStorage::readRules(ItemStorage::binary($r['x']),$base['properties'])];
            $body=$definition;unset($body['key']);ItemRules::definition($definition['key'],$body);$definition['_basis']=$base;$out[]=$definition;
        }return $out;
    }
    public static function definitionWire(array $definition):array{unset($definition['_basis']);return $definition;}
    public function template(string $key,bool $current=false):?array{
        $q=$this->pdo->prepare('SELECT data FROM item_templates WHERE item_key=?'.$this->lock($current));$q->execute([$key]);$r=$q->fetch(PDO::FETCH_ASSOC);return $r===false?null:$this->definitions([$r],$current)[0];
    }
    public function page(?string $after,int $limit,bool $current=false):array{
        $q=$this->pdo->prepare('SELECT data FROM item_templates'.($after===null?'':' WHERE item_key>?').' ORDER BY item_key LIMIT '.($limit+1).$this->lock($current));$q->execute($after===null?[]:[$after]);return array_map([self::class,'definitionWire'],$this->definitions($q->fetchAll(PDO::FETCH_ASSOC),$current));
    }
    private function createBasis(int $slot,string $key,stdClass $properties):array{
        $version=$this->reserve(false);$q=$this->pdo->prepare('INSERT INTO item_bases(version,slot,data,shape,ref_count) VALUES(?,?,?,?,0)');
        $q->bindValue(1,$version,PDO::PARAM_INT);$q->bindValue(2,$slot,PDO::PARAM_INT);$q->bindValue(3,ItemRules::canonical($properties));$q->bindValue(4,ItemStorage::shape($properties),PDO::PARAM_LOB);$q->execute();
        return ['version'=>$version,'slot'=>$slot,'key'=>$key,'properties'=>$properties];
    }
    public function saveTemplate(array $definition):void{
        $previous=$this->template($definition['key'],true);$base=$previous['_basis']??null;$slot=$base['slot']??$this->reserve(true);
        if($base===null||ItemRules::canonical($base['properties'])!==ItemRules::canonical($definition['baseProperties']))$base=$this->createBasis($slot,$definition['key'],$definition['baseProperties']);
        $sql=$this->mysql?'INSERT INTO item_template_storage(item_key,slot,version) VALUES(?,?,?) ON DUPLICATE KEY UPDATE version=VALUES(version)':'INSERT INTO item_template_storage(item_key,slot,version) VALUES(?,?,?) ON CONFLICT(item_key) DO UPDATE SET version=excluded.version';
        $this->pdo->prepare($sql)->execute([$definition['key'],$slot,$base['version']]);
        $r=['s'=>1,'k'=>$definition['key'],'n'=>$definition['name'],'t'=>$definition['type'],'q'=>$definition['rarity'],'a'=>$definition['active'],'v'=>$base['version'],'x'=>base64_encode(ItemStorage::rules($definition))];
        $sql=$this->mysql?'INSERT INTO item_templates(item_key,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)':'INSERT INTO item_templates(item_key,data) VALUES(?,?) ON CONFLICT(item_key) DO UPDATE SET data=excluded.data';
        $this->pdo->prepare($sql)->execute([$definition['key'],json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
    public function hydrate(array $items,bool $current=false):array{
        $versions=[];foreach($items as $item)if(isset($item['_basis'])&&!isset($item['_basis']['properties']))$versions[]=$item['_basis']['version'];$bases=$this->bases($versions,$current);
        foreach($items as &$item)if(isset($item['_basis'])&&!isset($item['_basis']['properties'])){$base=$bases[$item['_basis']['version']]??null;if($base===null)ItemStorage::corrupt();$item=ItemStorage::hydrate($item,$base);}unset($item);return $items;
    }
    /** Cold conversion only. Numeric variants share a basis, immutable strings/types require another version. */
    public function prepareLegacy(array $items):array{
        $templates=[];
        foreach($items as &$item){
            if(isset($item['_basis']))continue;$key=$item['itemKey'];
            if(!isset($templates[$key])){$t=$this->template($key,true);if($t===null)ItemStorage::required();if(!isset($t['_basis'])){$this->saveTemplate($t);$t=$this->template($key,true);}$templates[$key]=$t;}
            $base=$templates[$key]['_basis'];
            if(!ItemStorage::compatible($base['properties'],$item['properties'])){
                $q=$this->pdo->prepare('SELECT version FROM item_bases WHERE slot=? AND shape=? ORDER BY version LIMIT 1'.$this->lock(true));$q->bindValue(1,$base['slot'],PDO::PARAM_INT);$q->bindValue(2,ItemStorage::shape($item['properties']),PDO::PARAM_LOB);$q->execute();$version=$q->fetchColumn();
                $base=$version===false?$this->createBasis($base['slot'],$key,$item['properties']):$this->bases([(int)$version],true)[(int)$version];
                if(!ItemStorage::compatible($base['properties'],$item['properties']))ItemStorage::corrupt();
            }$item['_basis']=$base;
        }unset($item);return $items;
    }
    public function references(array $before,array $after):void{
        foreach(array_unique(array_merge(array_keys($before),array_keys($after)))as $version){$delta=($after[$version]??0)-($before[$version]??0);if(!$delta)continue;
            $q=$this->pdo->prepare('UPDATE item_bases SET ref_count=ref_count+? WHERE version=? AND ref_count>=? AND ref_count<=?');$q->execute([$delta,$version,max(0,-$delta),PHP_INT_MAX-max(0,$delta)]);if($q->rowCount()!==1)ItemStorage::corrupt();}
    }
    public function inventory(string $user,bool $current=false):array{
        $q=$this->pdo->prepare('SELECT revision,data FROM player_items WHERE user_id=?'.$this->lock($current));$q->execute([$user]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row===false)return ['revision'=>0,'instances'=>[],'receipts'=>[],'evictedThroughMillis'=>0,'exists'=>false,'compact'=>true];
        $r=json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);
        if(!isset($r['s']))return ['revision'=>(int)$row['revision'],'exists'=>true,'compact'=>false]+ItemRules::decodeState($row['data']);
        if($r['s']!==1||array_diff(array_keys($r),['s','i','g','e']))ItemStorage::corrupt();
        return ['revision'=>(int)$row['revision'],'exists'=>true,'compact'=>true,'instances'=>$this->hydrate(ItemStorage::unpack(ItemStorage::binary($r['i'])),$current),'receipts'=>$r['g']??[],'evictedThroughMillis'=>$r['e']??0];
    }
    public function saveInventory(string $user,array $before,array $next,bool $allowCreate,bool $migration=false):void{
        if(!$migration&&!($before['compact']??true))ItemStorage::required();
        $r=['s'=>1,'i'=>base64_encode(ItemStorage::pack($next['instances']))];if($next['receipts'])$r['g']=array_values($next['receipts']);if($next['evictedThroughMillis']>0)$r['e']=$next['evictedThroughMillis'];$data=json_encode($r,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!$before['exists']&&$allowCreate)$this->pdo->prepare('INSERT INTO player_items(user_id,revision,data) VALUES(?,?,?)')->execute([$user,$next['revision'],$data]);
        else{$q=$this->pdo->prepare('UPDATE player_items SET revision=?,data=? WHERE user_id=? AND revision=?');$q->execute([$next['revision'],$data,$user,$before['revision']]);if($q->rowCount()!==1)ItemRules::fail('REVISION_CONFLICT');}
        $this->references(ItemStorage::versions($before['instances']),ItemStorage::versions($next['instances']));
    }
}
