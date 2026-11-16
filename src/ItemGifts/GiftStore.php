<?php

declare(strict_types=1);
require_once __DIR__.'/GiftRules.php';
require_once __DIR__.'/../Items/ItemStore.php';

/** Indexed bounded projections of item_gifts. Current MySQL reads are locking reads, never stale RR snapshots. */
class GiftStore
{
    private PDO $pdo; private bool $mysql;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    private function one(string $sql,array $args=[],bool $lock=false): ?array
    { $q=$this->pdo->prepare($sql.($lock&&$this->mysql?' FOR UPDATE':'')); $q->execute($args); $r=$q->fetch(PDO::FETCH_ASSOC); $q->closeCursor(); return $r===false?null:$r; }
    private function rows(string $sql,array $args=[]): array { $q=$this->pdo->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC); }
    private function hint(string $index): string { return $this->mysql?' FORCE INDEX ('.$index.')':' INDEXED BY '.$index; }
    /** Active players are existing user rows; deletion removes the row. Sorted locks avoid deadlocks. */
    public function users(array $ids,bool $lock=false): array
    {
        $ids=array_values(array_unique($ids));sort($ids,SORT_STRING);if(!$ids)return[];if(count($ids)>2)throw new LogicException('Gift actor bound');
        $q=$this->pdo->prepare('SELECT id FROM users WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id'.($lock&&$this->mysql?' FOR UPDATE':''));$q->execute($ids);return $q->fetchAll(PDO::FETCH_COLUMN);
    }
    public function header(bool $lock=false): array
    { $r=$this->one('SELECT version,revision,data FROM gift_header WHERE id=1',[],$lock);return $r===null?GiftRules::emptyHeader():['version'=>(int)$r['version'],'revision'=>(int)$r['revision'],'exists'=>true]+json_decode($r['data'],true,16,JSON_THROW_ON_ERROR); }
    public function saveHeader(array $before,array $next): array
    {
        if($next['escrowCount']<0||$next['retainedCount']<$next['escrowCount'])GiftRules::fail('STATE_CONFLICT');
        $next['version']=GiftRules::next($before['version']);$data=$next;unset($data['version'],$data['revision'],$data['exists']);
        if(!$before['exists'])$this->pdo->prepare('INSERT INTO gift_header(id,version,revision,data) VALUES(1,?,?,?)')->execute([$next['version'],$next['revision'],GiftRules::json($data)]);
        else{$q=$this->pdo->prepare('UPDATE gift_header SET version=?,revision=?,data=? WHERE id=1 AND version=?');$q->execute([$next['version'],$next['revision'],GiftRules::json($data),$before['version']]);if($q->rowCount()!==1)GiftRules::fail('STATE_CONFLICT');}
        $next['exists']=true;return $next;
    }
    public function player(string $user,bool $lock=false): array
    { $r=$this->one('SELECT revision,data FROM gift_players WHERE user_id=?',[$user],$lock);return $r===null?GiftRules::emptyPlayer():['revision'=>(int)$r['revision'],'exists'=>true]+json_decode($r['data'],true,16,JSON_THROW_ON_ERROR); }
    public function savePlayer(string $user,array $before,array $next): array
    {
        $next['revision']=GiftRules::next($before['revision']);$data=$next;unset($data['revision'],$data['exists']);
        if(!$before['exists'])$this->pdo->prepare('INSERT INTO gift_players(user_id,active_user_id,revision,data) VALUES(?,?,?,?)')->execute([$user,$user,$next['revision'],GiftRules::json($data)]);
        else{$q=$this->pdo->prepare('UPDATE gift_players SET revision=?,data=? WHERE user_id=? AND revision=?');$q->execute([$next['revision'],GiftRules::json($data),$user,$before['revision']]);if($q->rowCount()!==1)GiftRules::fail('STATE_CONFLICT');}
        $next['exists']=true;return $next;
    }
    /** Only a ledger whose identity ended (FK set NULL) is removed. */
    public function removePlayer(string $user,int $revision): void
    { $q=$this->pdo->prepare('DELETE FROM gift_players WHERE user_id=? AND revision=? AND active_user_id IS NULL');$q->execute([$user,$revision]);if($q->rowCount()!==1)GiftRules::fail('STATE_CONFLICT'); }
    public function deletedPlayers(int $limit): array
    { return $this->rows('SELECT user_id,revision FROM gift_players'.$this->hint('idx_gift_deleted').' WHERE active_user_id IS NULL ORDER BY user_id LIMIT '.$limit); }
    public function get(string $id,bool $lock=false): ?array
    { $r=$this->one('SELECT data FROM item_gifts WHERE id=?',[$id],$lock);return $r===null?null:$this->decode([$r['data']],$lock)[0]; }
    /** One indexed basis batch per <=128 distinct versions, shared by all selected gifts. */
    private function decode(array $data,bool $current=false): array
    {
        $gifts=[];$items=[];
        foreach($data as $json){$v=json_decode($json,true,32,JSON_THROW_ON_ERROR);
            if($v['item']!==null){if(!isset($v['item']['s'])){$original=json_decode($json,false,32,JSON_THROW_ON_ERROR);if(!($original->item->properties??null) instanceof stdClass)ItemStorage::required();}$items[count($gifts)]=$this->item($v['item']);}$gifts[]=$v;}
        $hydrated=(new ItemStore($this->pdo))->hydrate(array_values($items),$current);
        foreach(array_keys($items) as $position=>$index)$gifts[$index]['item']=$hydrated[$position];return $gifts;
    }
    private function item(array $item): array
    {
        if(!isset($item['s'])){$item['properties']=(object)$item['properties'];return $item;}
        if($item['s']!==1||array_keys($item)!==['s','b'])ItemStorage::corrupt();
        $items=ItemStorage::unpack(ItemStorage::binary($item['b']));if(count($items)!==1)ItemStorage::corrupt();
        return $items[0];
    }
    private function data(array $value): string
    {
        if($value['item']!==null&&isset($value['item']['_basis']))$value['item']=['s'=>1,'b'=>base64_encode(ItemStorage::pack([$value['item']]))];
        return GiftRules::json($value);
    }
    private function itemReferences(?array $before,?array $after): void
    { (new ItemStore($this->pdo))->references(ItemStorage::versions($before===null?[]:[$before]),ItemStorage::versions($after===null?[]:[$after])); }
    private function columns(array $g): array
    {
        $op=$g['ops'][0]??null;
        return [$g['revision'],$g['status'],$g['senderId'],$g['recipientId'],$g['returnOwnerId'],$g['createdAt'],$g['dueKind'],$g['dueAt'],$g['ownerUnavailable']?1:0,
            $g['offerEntryId'],$g['returnEntryId'],$op['u']??null,$op['o']??null,$this->data($g)];
    }
    public function insert(array $g): void
    {
        $this->pdo->prepare('INSERT INTO item_gifts(id,revision,status,sender_id,recipient_id,return_owner,created_millis,due_kind,due_millis,owner_unavailable,offer_entry,return_entry,op_actor,op_operation,data) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge([$g['id']],$this->columns($g)));$this->itemReferences(null,$g['item']);
    }
    public function save(array $before,array $next): array
    {
        $next['revision']=GiftRules::next($before['revision']);
        $q=$this->pdo->prepare('UPDATE item_gifts SET revision=?,status=?,sender_id=?,recipient_id=?,return_owner=?,created_millis=?,due_kind=?,due_millis=?,owner_unavailable=?,offer_entry=?,return_entry=?,op_actor=?,op_operation=?,data=? WHERE id=? AND revision=?');
        $q->execute(array_merge($this->columns($next),[$before['id'],$before['revision']]));if($q->rowCount()!==1)GiftRules::fail('STATE_CONFLICT');$this->itemReferences($before['item'],$next['item']);return $next;
    }
    public function remove(array $g): void
    { $q=$this->pdo->prepare('DELETE FROM item_gifts WHERE id=? AND revision=?');$q->execute([$g['id'],$g['revision']]);if($q->rowCount()!==1)GiftRules::fail('STATE_CONFLICT');$this->itemReferences($g['item'],null); }
    public function many(array $ids): array
    {
        $byId=[];
        foreach(array_chunk(array_values(array_unique($ids)),128) as $chunk){
            $rows=$this->rows('SELECT data FROM item_gifts WHERE id IN ('.implode(',',array_fill(0,count($chunk),'?')).')',$chunk);
            foreach($this->decode(array_column($rows,'data')) as $g)$byId[$g['id']]=$g;
        }
        $out=[];foreach($ids as $id)if(isset($byId[$id]))$out[]=$byId[$id];return $out;
    }
    /** One indexed point probe on the sender's original operation, no personal receipt copy. */
    public function operation(string $user,string $operation,bool $lock=false): ?array
    { $r=$this->one('SELECT data FROM item_gifts'.$this->hint('idx_gift_operation').' WHERE op_actor=? AND op_operation=? LIMIT 1',[$user,$operation],$lock);return $r===null?null:$this->decode([$r['data']],$lock)[0]; }
    /** Open offers to one recipient, oldest first, bounded by the received page size. */
    public function received(string $user,int $now,int $limit): array
    {
        $rows=$this->rows('SELECT data FROM item_gifts'.$this->hint('idx_gift_recipient').' WHERE recipient_id=? AND status=? AND created_millis>? ORDER BY created_millis,id LIMIT '.$limit,[$user,'offered',$now-GiftRules::EXPIRY]);
        return array_values(array_filter($this->decode(array_column($rows,'data')),static function($g)use($now){return GiftRules::claimable($g,$now);}));
    }
    public function due(int $kind,int $now,int $limit): array
    { return $this->rows('SELECT id FROM item_gifts'.$this->hint('idx_gift_due').' WHERE due_kind=? AND due_millis<=? ORDER BY due_millis,id LIMIT '.$limit,[$kind,$now]); }
    public function adminPage(?string $status,?array $after,int $limit): array
    {
        $out=[];$modes=$after!==null&&$this->mysql?[true,false]:[false];$index=$status===null?'idx_gift_admin':'idx_gift_admin_status';
        foreach($modes as $equal){$where=[];$args=[];if($status!==null){$where[]='status=?';$args[]=$status;}
            if($after!==null){if($this->mysql){$where[]=$equal?'created_millis=? AND id>?':'created_millis>?';$args[]=$after['created'];if($equal)$args[]=$after['id'];}else{$where[]='(created_millis,id)>(?,?)';array_push($args,$after['created'],$after['id']);}}
            $rows=$this->rows('SELECT id,created_millis FROM item_gifts'.$this->hint($index).($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY created_millis,id LIMIT '.($limit+1-count($out)),$args);$out=array_merge($out,$rows);if(count($out)>$limit)break;
        }return $out;
    }
    /** Offers whose recipient identity ended. Bounded by the status index, no user table scan. */
    public function orphanedOffers(int $limit): array
    { return $this->rows('SELECT g.id FROM item_gifts g'.$this->hint('idx_gift_admin_status').' LEFT JOIN users u ON u.id=g.recipient_id WHERE g.status=? AND u.id IS NULL ORDER BY g.created_millis,g.id LIMIT '.$limit,['offered']); }
    /** Prepared or delivered returns whose owner identity ended and which are not yet held for recovery. */
    public function orphanedReturns(int $limit): array
    {
        $out=[];
        foreach(['return_pending','return_delivered'] as $status)$out=array_merge($out,$this->rows('SELECT g.id FROM item_gifts g'.$this->hint('idx_gift_admin_status').' LEFT JOIN users u ON u.id=g.return_owner WHERE g.status=? AND g.owner_unavailable=0 AND u.id IS NULL ORDER BY g.created_millis,g.id LIMIT '.($limit-count($out)),[$status]));
        return $out;
    }
}
