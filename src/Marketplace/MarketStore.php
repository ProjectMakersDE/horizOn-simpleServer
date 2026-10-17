<?php

declare(strict_types=1);
require_once __DIR__.'/MarketRules.php';
require_once __DIR__.'/../Items/ItemStore.php';

/** Indexed bounded projections. Current MySQL reads are locking reads, never stale RR snapshots. */
class MarketStore
{
    private PDO $pdo; private bool $mysql;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    private function one(string $sql,array $args=[],bool $lock=false): ?array
    { $q=$this->pdo->prepare($sql.($lock&&$this->mysql?' FOR UPDATE':'')); $q->execute($args); $r=$q->fetch(PDO::FETCH_ASSOC); return $r===false?null:$r; }
    private function rows(string $sql,array $args=[]): array { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC); }
    private function hint(string $index): string { return $this->mysql?' FORCE INDEX ('.$index.')':' INDEXED BY '.$index; }
    public function users(array $ids,bool $lock=false): array
    { $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);if (!$ids) return [];if(count($ids)>2)throw new LogicException('Trade actor bound');$q=$this->pdo->prepare('SELECT id FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute($ids);return $q->fetchAll(PDO::FETCH_COLUMN); }
    public function header(bool $lock=false): array
    { $r=$this->one('SELECT version,revision,data FROM market_header WHERE id=1',[],$lock);return $r===null?MarketRules::emptyHeader():['version'=>(int)$r['version'],'revision'=>(int)$r['revision'],'exists'=>true]+json_decode($r['data'],true,32,JSON_THROW_ON_ERROR); }
    public function saveHeader(array $before,array $next): array
    {
        $next['version']=MarketRules::next($before['version']);if($next['projectId']===null)$next['projectId']=Database::uuid();$data=$next;unset($data['version'],$data['revision'],$data['exists']);
        if (!$before['exists']) $this->pdo->prepare('INSERT INTO market_header(id,version,revision,data) VALUES(1,?,?,?)')->execute([$next['version'],$next['revision'],MarketRules::json($data)]);
        else { $q=$this->pdo->prepare('UPDATE market_header SET version=?,revision=?,data=? WHERE id=1 AND version=?');$q->execute([$next['version'],$next['revision'],MarketRules::json($data),$before['version']]);if($q->rowCount()!==1)MarketRules::fail('STATE_CONFLICT'); }
        $next['exists']=true;return $next;
    }
    public function rule(string $key,bool $lock=false): ?array { $r=$this->one('SELECT data FROM market_rules WHERE item_key=?',[$key],$lock);return $r===null?null:json_decode($r['data'],true,16,JSON_THROW_ON_ERROR); }
    public function rules(?string $after,int $limit): array
    { return array_map(static function($r){return json_decode($r['data'],true,16,JSON_THROW_ON_ERROR);},$this->rows('SELECT data FROM market_rules'.($after===null?'':' WHERE item_key>?').' ORDER BY item_key LIMIT '.($limit+1),$after===null?[]:[$after])); }
    public function saveRule(array $r): void
    { $sql=$this->mysql?'INSERT INTO market_rules(item_key,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)':'INSERT INTO market_rules(item_key,data) VALUES(?,?) ON CONFLICT(item_key) DO UPDATE SET data=excluded.data';$this->pdo->prepare($sql)->execute([$r['itemKey'],MarketRules::json($r)]); }
    public function player(string $user,bool $lock=false): array
    { $r=$this->one('SELECT revision,data FROM player_market WHERE user_id=?',[$user],$lock);return $r===null?MarketRules::emptyPlayer():['revision'=>(int)$r['revision'],'exists'=>true]+json_decode($r['data'],true,32,JSON_THROW_ON_ERROR); }
    public function savePlayer(string $user,array $before,array $next): array
    {
        $next=MarketRules::compactPlayer($next);
        $next['revision']=MarketRules::next($before['revision']);$data=$next;unset($data['revision'],$data['exists']);
        if (!$before['exists']) $this->pdo->prepare('INSERT INTO player_market(user_id,active_user_id,revision,data) VALUES(?,?,?,?)')->execute([$user,$user,$next['revision'],MarketRules::json($data)]);
        else { $q=$this->pdo->prepare('UPDATE player_market SET revision=?,data=? WHERE user_id=? AND revision=?');$q->execute([$next['revision'],MarketRules::json($data),$user,$before['revision']]);if($q->rowCount()!==1)MarketRules::fail('STATE_CONFLICT'); }
        $next['exists']=true;return $next;
    }
    public function get(string $id,bool $lock=false): ?array
    { $r=$this->one('SELECT data FROM market_listings WHERE id=?',[$id],$lock);return $r===null?null:$this->decodeListing($r['data'],$lock); }
    private function decodeListing(string $data,bool $current=false): array
    { return $this->decodeListings([$data],$current)[0]; }
    /** One indexed basis batch per <=128 distinct versions, shared by all selected listings. */
    private function decodeListings(array $data,bool $current=false): array
    {
        $listings=[];$items=[];
        foreach($data as $json){$v=json_decode($json,true,32,JSON_THROW_ON_ERROR);$v['ops']=$v['ops']??[];
            if($v['item']!==null){if(!isset($v['item']['s'])){$original=json_decode($json,false,32,JSON_THROW_ON_ERROR);if(!($original->item->properties??null) instanceof stdClass)ItemStorage::required();}$items[count($listings)]=$this->item($v['item']);}$listings[]=$v;}
        $hydrated=(new ItemStore($this->pdo))->hydrate(array_values($items),$current);
        foreach(array_keys($items) as $position=>$index)$listings[$index]['item']=$hydrated[$position];return $listings;
    }
    private function item(array $item):array
    {
        if(!isset($item['s'])){$item['properties']=(object)$item['properties'];return $item;}
        if($item['s']!==1||array_keys($item)!==['s','b'])ItemStorage::corrupt();
        $items=ItemStorage::unpack(ItemStorage::binary($item['b']));if(count($items)!==1)ItemStorage::corrupt();
        return $items[0];
    }
    private function listingData(array $value):string
    {
        if($value['item']!==null&&isset($value['item']['_basis']))$value['item']=['s'=>1,'b'=>base64_encode(ItemStorage::pack([$value['item']]))];
        return MarketRules::json($value);
    }
    private function itemReferences(?array $before,?array $after):void
    { (new ItemStore($this->pdo))->references(ItemStorage::versions($before===null?[]:[$before]),ItemStorage::versions($after===null?[]:[$after])); }
    /** Two indexed point probes cover every action without a JSON scan or personal receipt copy. */
    public function operation(string $user,string $operation,bool $lock=false): ?array
    {
        foreach(['create','close']as$slot){$r=$this->one('SELECT data FROM market_listings'.$this->hint('idx_market_'.$slot.'_operation').' WHERE '.$slot.'_actor=? AND '.$slot.'_operation=? LIMIT 1',[$user,$operation],$lock);if($r!==null)return$this->decodeListing($r['data'],$lock);}return null;
    }
    public function removePlayer(string $user,int $revision): void
    { $q=$this->pdo->prepare('DELETE FROM player_market WHERE user_id=? AND revision=? AND active_user_id IS NULL');$q->execute([$user,$revision]);if($q->rowCount()!==1)MarketRules::fail('STATE_CONFLICT'); }
    private function columns(array $v): array
    { $slots=['create'=>null,'close'=>null];foreach($v['ops']??[]as$op)$slots[$op['k']==='create'?'create':'close']=$op;return [$v['revision'],$v['status'],$v['itemReference']['itemKey'],$v['price'],$v['createdAt'],in_array($v['status'],['sold','returned'],true)?1:0,$v['dueAt'],$v['returnOwnerId'],$v['returnEntryId'],$slots['create']['u']??null,$slots['create']['o']??null,$slots['close']['u']??null,$slots['close']['o']??null,$this->listingData($v)]; }
    public function insert(array $v): void
    { $this->pdo->prepare('INSERT INTO market_listings(id,revision,status,item_key,price,created_millis,due_kind,due_millis,return_owner,return_entry,create_actor,create_operation,close_actor,close_operation,data) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge([$v['id']],$this->columns($v)));$this->itemReferences(null,$v['item']); }
    public function save(array $before,array $next): void
    { $q=$this->pdo->prepare('UPDATE market_listings SET revision=?,status=?,item_key=?,price=?,created_millis=?,due_kind=?,due_millis=?,return_owner=?,return_entry=?,create_actor=?,create_operation=?,close_actor=?,close_operation=?,data=? WHERE id=? AND revision=?');$q->execute(array_merge($this->columns($next),[$before['id'],$before['revision']]));if($q->rowCount()!==1)MarketRules::fail('STATE_CONFLICT');$this->itemReferences($before['item'],$next['item']); }
    public function remove(array $v): void { $q=$this->pdo->prepare('DELETE FROM market_listings WHERE id=? AND revision=?');$q->execute([$v['id'],$v['revision']]);if($q->rowCount()!==1)MarketRules::fail('STATE_CONFLICT');$this->itemReferences($v['item'],null); }
    public function many(array $ids): array
    {
        $byId=[];
        foreach(array_chunk(array_values(array_unique($ids)),128) as $chunk){
            $rows=$this->rows('SELECT data FROM market_listings WHERE id IN ('.implode(',',array_fill(0,count($chunk),'?')).')',$chunk);
            foreach($this->decodeListings(array_column($rows,'data')) as $v)$byId[$v['id']]=$v;
        }
        $out=[];foreach($ids as $id)if(isset($byId[$id]))$out[]=$byId[$id];return $out;
    }
    /** Two sargable seeks share a candidate budget; no OR residual scan or OFFSET. */
    public function page(?string $item,int $min,int $max,?array $after,int $budget=256): array
    {
        $base=['status=?'];$args=['active'];if($item!==null){$base[]='item_key=?';$args[]=$item;}$index=$item===null?'idx_market_price':'idx_market_item_price';$out=[];
        $modes=$after!==null&&$this->mysql?[true,false]:[false];
        foreach($modes as$equal){$where=$base;$bind=$args;
            if($after===null){$where[]='price>=?';$bind[]=$min;}
            elseif($this->mysql){$where[]=$equal?'price=? AND id>?':'price>?';$bind[]=$after['price'];if($equal)$bind[]=$after['id'];}
            else{$where[]='(price,id)>(?,?)';array_push($bind,$after['price'],$after['id']);}
            $where[]='price<=?';$bind[]=$max;
            $rows=$this->rows('SELECT data FROM market_listings'.$this->hint($index).' WHERE '.implode(' AND ',$where).' ORDER BY price,id LIMIT '.($budget-count($out)),$bind);
            $out=array_merge($out,$this->decodeListings(array_column($rows,'data')));if(count($out)>=$budget)break;
        }return$out;
    }
    public function adminPage(?string $status,?array $after,int $limit): array
    {
        $out=[];$modes=$after!==null&&$this->mysql?[true,false]:[false];$index=$status===null?'idx_market_admin':'idx_market_admin_status';
        foreach($modes as$equal){$where=[];$args=[];if($status!==null){$where[]='status=?';$args[]=$status;}
            if($after!==null){if($this->mysql){$where[]=$equal?'created_millis=? AND id>?':'created_millis>?';$args[]=$after['created'];if($equal)$args[]=$after['id'];}else{$where[]='(created_millis,id)>(?,?)';array_push($args,$after['created'],$after['id']);}}
            $rows=$this->rows('SELECT id,created_millis FROM market_listings'.$this->hint($index).($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY created_millis,id LIMIT '.($limit+1-count($out)),$args);$out=array_merge($out,$rows);if(count($out)>$limit)break;
        }return$out;
    }
    public function maintenance(string $key,int $now,bool $lock=false): array
    { $r=$this->one('SELECT revision,data FROM market_maintenance WHERE kind=?',[$key],$lock);return$r===null?['revision'=>0,'exists'=>false,'cutoff'=>$now,'after'=>null,'first'=>0]:['revision'=>(int)$r['revision'],'exists'=>true]+json_decode($r['data'],true,16,JSON_THROW_ON_ERROR); }
    public function saveMaintenance(string $key,array $before,array $next): void
    { $data=$next;unset($data['revision'],$data['exists']);$args=[MarketRules::next($before['revision']),MarketRules::json($data)];if(!$before['exists'])$this->pdo->prepare('INSERT INTO market_maintenance(revision,data,kind) VALUES(?,?,?)')->execute(array_merge($args,[$key]));else{$q=$this->pdo->prepare('UPDATE market_maintenance SET revision=?,data=? WHERE kind=? AND revision=?');$q->execute(array_merge($args,[$key,$before['revision']]));if($q->rowCount()!==1)MarketRules::fail('STATE_CONFLICT');} }
    public function due(int $kind,array $cursor,int $limit): array
    {
        $out=[];$after=$cursor['after'];$modes=$after!==null&&$this->mysql?[true,false]:[false];
        foreach($modes as$equal){$where=['due_kind=?','due_millis<=?'];$args=[$kind,$cursor['cutoff']];
            if($after!==null){if($this->mysql){$where[]=$equal?'due_millis=? AND id>?':'due_millis>?';$args[]=$after['due'];if($equal)$args[]=$after['id'];}else{$where[]='(due_millis,id)>(?,?)';array_push($args,$after['due'],$after['id']);}}
            $out=array_merge($out,$this->rows('SELECT id,due_millis FROM market_listings'.$this->hint('idx_market_due').' WHERE '.implode(' AND ',$where).' ORDER BY due_millis,id LIMIT '.($limit-count($out)),$args));if(count($out)>=$limit)break;
        }return$out;
    }
    public function owned(string $user,?string $after,int $limit=20): array
    { return $this->rows('SELECT id FROM market_listings'.$this->hint('idx_market_owner').' WHERE return_owner=?'.($after===null?'':' AND id>?').' ORDER BY id LIMIT '.$limit,$after===null?[$user]:[$user,$after]); }
    public function deleted(?string $after): array
    { return $this->rows('SELECT user_id FROM player_market WHERE active_user_id IS NULL'.($after===null?'':' AND user_id>?').' ORDER BY user_id LIMIT 11',$after===null?[]:[$after]); }
}
