<?php

declare(strict_types=1);
require_once __DIR__.'/ItemService.php';

/** Explicit cold conversion: fixed-size keyset pages, exact originals and same SQL transaction. */
class ItemMigration
{
    private PDO $pdo;private ItemStore $store;private SqlTransaction $scope;private bool $mysql;
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->store=new ItemStore($pdo);$this->scope=new SqlTransaction($pdo);$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    private function lock():string{return $this->mysql?' FOR UPDATE':'';}
    public function page(string $kind,?string $after=null,int $limit=20,bool $apply=false):array{
        $tables=['templates'=>['item_templates','item_key'],'inventory'=>['player_items','user_id'],'escrow'=>['market_listings','id']];
        if(!isset($tables[$kind])||$limit<1||$limit>50)throw new InvalidArgumentException('Items migration kind/page');
        if($after!==null){if($kind==='templates')ItemRules::key($after);else ItemRules::uuid($after);}
        [$table,$column]=$tables[$kind];$q=$this->pdo->prepare('SELECT '.$column.' FROM '.$table.($after===null?'':' WHERE '.$column.'>?').' ORDER BY '.$column.' LIMIT '.$limit);$q->execute($after===null?[]:[$after]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$converted=0;
        if($apply)foreach($ids as $id)if($this->one($kind,$id))$converted++;
        return ['examined'=>count($ids),'converted'=>$converted,'nextCursor'=>$ids?end($ids):$after,'complete'=>count($ids)<$limit];
    }
    private function legacyItem(array $item):array{
        if(array_diff(array_keys($item),['instanceId','itemKey','properties','equipped','createdAt'])||count($item)!==5)ItemStorage::required();
        if(!$item['properties'] instanceof stdClass)ItemStorage::required();
        ItemRules::uuid($item['instanceId']);ItemRules::key($item['itemKey']);ItemRules::boolean($item['equipped']);ItemStorage::millis($item['createdAt']);$item['properties']=ItemRules::properties($item['properties']);return $item;
    }
    public function one(string $kind,string $id):bool{
        return $this->scope->run(function()use($kind,$id){
            if($kind==='templates'){
                ItemRules::key($id);$this->store->header(true);$template=$this->store->template($id,true);if($template===null||isset($template['_basis']))return false;
                $body=$template;unset($body['key']);ItemRules::definition($id,$body);$this->store->saveTemplate($template);return true;
            }
            ItemRules::uuid($id);
            if($kind==='inventory'){
                $this->store->player($id,true);$q=$this->pdo->prepare('SELECT revision,data FROM player_items WHERE user_id=?'.$this->lock());$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row===false)return false;
                $raw=json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);if(isset($raw['s'])){$this->store->inventory($id,true);return false;}
                if(array_diff(array_keys($raw),['instances','receipts','evictedThroughMillis'])||count($raw)!==3||!is_array($raw['instances'])||!is_array($raw['receipts']))ItemStorage::required();
                ItemRules::integer($raw['evictedThroughMillis']);$before=$this->store->inventory($id,true);$next=$before;$items=[];
                $original=json_decode($row['data'],false,64,JSON_THROW_ON_ERROR);
                foreach($original->instances as $item){if(!$item instanceof stdClass)ItemStorage::required();$items[]=$this->legacyItem(get_object_vars($item));}
                $next['instances']=$this->store->prepareLegacy($items);$next['revision']=ItemRules::next($before['revision']);
                // Retained source receipts are preserved byte-for-field, never inferred from current offers.
                $this->store->saveInventory($id,$before,$next,false,true);return true;
            }
            if($kind==='escrow'){
                $q=$this->pdo->prepare('SELECT revision,data FROM market_listings WHERE id=?'.$this->lock());$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row===false)return false;
                $value=json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);if($value['item']===null)return false;
                if(isset($value['item']['s'])){if($value['item']['s']!==1||array_keys($value['item'])!==['s','b'])ItemStorage::corrupt();$packed=ItemStorage::unpack(ItemStorage::binary($value['item']['b']));if(count($packed)!==1)ItemStorage::corrupt();$this->store->hydrate($packed,true);return false;}
                $original=json_decode($row['data'],false,64,JSON_THROW_ON_ERROR);if(!$original->item instanceof stdClass)ItemStorage::required();
                $item=$this->legacyItem(get_object_vars($original->item));$prepared=$this->store->prepareLegacy([$item]);$value['item']=['s'=>1,'b'=>base64_encode(ItemStorage::pack($prepared))];
                $q=$this->pdo->prepare('UPDATE market_listings SET data=? WHERE id=? AND revision=? AND data=?');$q->execute([json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id,$row['revision'],$row['data']]);if($q->rowCount()!==1)ItemStorage::corrupt();
                $this->store->references([],ItemStorage::versions($prepared));return true;
            }
            throw new InvalidArgumentException('Unknown Items migration kind');
        });
    }
    /** Cold operator maintenance only. Bounded memory, all owners proven, no count-only deletion. */
    public function collect(int $version):bool{
        ItemRules::integer($version,1);
        return $this->scope->run(function()use($version){
            $q=$this->pdo->prepare('SELECT version FROM item_template_storage WHERE version=?'.$this->lock());$q->execute([$version]);if($q->fetchColumn()!==false)return false;
            $q=$this->pdo->prepare('SELECT ref_count FROM item_bases WHERE version=?'.$this->lock());$q->execute([$version]);if($q->fetchColumn()===false)return false;$references=0;
            foreach([['player_items','user_id'],['market_listings','id']]as $target){[$table,$key]=$target;$after=null;
                do{$q=$this->pdo->prepare('SELECT '.$key.',data FROM '.$table.($after===null?'':' WHERE '.$key.'>?').' ORDER BY '.$key.' LIMIT 50'.$this->lock());$q->execute($after===null?[]:[$after]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
                    foreach($rows as $row){$value=json_decode($row['data'],true,64,JSON_THROW_ON_ERROR);
                        if($table==='player_items'){if(($value['s']??null)!==1||array_diff(array_keys($value),['s','i','g','e']))ItemStorage::required();$packed=$value['i'];}
                        else{if($value['item']===null)continue;if(($value['item']['s']??null)!==1||array_diff(array_keys($value['item']),['s','b']))ItemStorage::required();$packed=$value['item']['b'];}
                        foreach(ItemStorage::unpack(ItemStorage::binary($packed))as $item)if($item['_basis']['version']===$version)$references++;
                    }if($rows)$after=$rows[count($rows)-1][$key];
                }while(count($rows)===50);
            }
            // This proof also repairs a conservative count after an operator's direct SQL user deletion.
            if($references){$this->pdo->prepare('UPDATE item_bases SET ref_count=? WHERE version=?')->execute([$references,$version]);return false;}
            $q=$this->pdo->prepare('DELETE FROM item_bases WHERE version=?');$q->execute([$version]);return $q->rowCount()===1;
        });
    }
}
