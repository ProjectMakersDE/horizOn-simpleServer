<?php

declare(strict_types=1);
require_once __DIR__.'/MatchStore.php';
require_once __DIR__.'/../Core/SqlTransaction.php';

/** Explicit bounded encoding conversion. Never advances gameplay, prunes history or sends notices. */
class MatchMigration
{
    private PDO $pdo;private MatchStore $store;private SqlTransaction $scope;private bool $mysql;
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->store=new MatchStore($pdo);$this->scope=new SqlTransaction($pdo);$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';}
    public function page(string $kind,?string $after=null,int $limit=10,bool $apply=false):array{
        $targets=['details'=>['matches_state','id'],'players'=>['player_matches','user_id']];if(!isset($targets[$kind])||$limit<1||$limit>20)throw new InvalidArgumentException('Matches cold phase/page');if($after!==null)MatchStorage::uuid($after);[$table,$id]=$targets[$kind];
        $q=$this->pdo->prepare('SELECT '.$id.' FROM '.$table.($after===null?'':' WHERE '.$id.'>?').' ORDER BY '.$id.' LIMIT '.$limit);$q->execute($after===null?[]:[$after]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$q->closeCursor();$converted=0;
        foreach($ids as $value){if($apply){if($this->one($kind,$value))$converted++;}else$this->check($kind,$value);}
        return ['examined'=>count($ids),'converted'=>$converted,'nextCursor'=>$ids?end($ids):$after,'complete'=>count($ids)<$limit];
    }
    private function check(string $kind,string $id):void{
        if($kind==='details'){$v=$this->store->get($id);if($v!==null)$this->store->payload($v);}else$this->store->player($id);
    }
    public function one(string $kind,string $id):bool{
        if(!in_array($kind,['details','players'],true))throw new InvalidArgumentException('Matches cold phase');MatchStorage::uuid($id);
        return $this->scope->run(function()use($kind,$id){
            if($kind==='details'){$v=$this->store->get($id,true);if($v===null)return false;$this->store->payload($v);if($v['_compact'])return false;$this->store->save($v,$v);return true;}
            // Own the unchanged original before decoding dependent metadata in the same snapshot.
            $q=$this->pdo->prepare('SELECT data FROM player_matches WHERE user_id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$id]);$raw=$q->fetchColumn();$q->closeCursor();if($raw===false)return false;
            $v=$this->store->player($id,true);$parsed=MatchStorage::json($raw);if(array_key_exists('v',$parsed))return false;
            $encoded=MatchStorage::playerWrite($v);$q=$this->pdo->prepare('UPDATE player_matches SET data=? WHERE user_id=? AND revision=? AND '.($this->mysql?'BINARY data=BINARY ?':'data=? COLLATE BINARY'));$q->execute([$encoded,$id,$v['revision'],$raw]);if($q->rowCount()!==1)MatchRules::fail('STATE_CONFLICT');return true;
        });
    }
}
