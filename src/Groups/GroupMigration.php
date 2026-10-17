<?php

declare(strict_types=1);
require_once __DIR__.'/GroupStore.php';
require_once __DIR__.'/../Core/SqlTransaction.php';

/** Cold, resumable operator conversion. The CLI requires stopped writers and a verified backup. */
class GroupMigration
{
    private PDO $pdo;
    private GroupStore $store;
    private SqlTransaction $scope;
    private bool $mysql;
    public function __construct(PDO $pdo) { $this->pdo=$pdo;$this->store=new GroupStore($pdo);$this->scope=new SqlTransaction($pdo);$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    public function indexPage(int $limit,bool $apply): array
    {
        GroupRules::page($limit,100);
        return $this->scope->run(function()use($limit,$apply){
            $header=$this->pdo->query('SELECT revision,cursor_group,ready FROM group_storage WHERE id=1'.($apply&&$this->mysql?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
            if(!$header)GroupStorage::required();
            if((int)$header['ready']===1)return ['ready'=>true,'groups'=>[],'nextAfterGroupId'=>null];
            $cursor=$header['cursor_group'];
            $q=$this->pdo->prepare('SELECT id,revision,data FROM groups_state'.($cursor===null?'':' WHERE id>?').' ORDER BY id LIMIT '.($limit+1).($apply&&$this->mysql?' FOR UPDATE':''));$q->execute($cursor===null?[]:[$cursor]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);$page=array_slice($rows,0,$limit);
            foreach($page as $row) {
                $g=GroupStorage::group($row['data'],$row['id'],(int)$row['revision']);
                if($g['id']!==$row['id']||$g['revision']!==(int)$row['revision'])GroupStorage::required();
                $q=$this->pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id=?');$q->execute([$row['id']]);
                $leader=$this->store->member($g['id'],$g['leaderId']);
                if((int)$q->fetchColumn()!==$g['memberCount']||$leader===null||$leader['role']!=='leader')GroupStorage::required();
                $q=$this->pdo->prepare("SELECT user_id FROM group_members WHERE group_id=? AND (role NOT IN ('leader','officer','member') OR (role='leader' AND user_id<>?)) LIMIT 1");$q->execute([$g['id'],$g['leaderId']]);if($q->fetchColumn()!==false)GroupStorage::required();
                if($apply)$this->store->indexGroup($g);
            }
            $ready=count($rows)<=$limit;$next=$ready?null:$page[count($page)-1]['id'];
            if($apply) {
                if((int)$header['revision']>=GroupRules::MAX)GroupStorage::required();
                $q=$this->pdo->prepare('UPDATE group_storage SET revision=revision+1,cursor_group=?,ready=? WHERE id=1 AND revision=?');$q->execute([$next,$ready?1:0,$header['revision']]);if($q->rowCount()!==1)GroupRules::conflict();
            }
            return ['ready'=>$apply&&$ready,'groups'=>array_column($page,'id'),'nextAfterGroupId'=>$next];
        });
    }
    public function players(?string $after,int $limit,bool $apply): array
    {
        if($after!==null)$after=GroupRules::uuid($after,'INVALID_PAGE');GroupRules::page($limit,100);$this->store->writable();
        $q=$this->pdo->prepare('SELECT user_id FROM player_groups'.($after===null?'':' WHERE user_id>?').' ORDER BY user_id LIMIT '.($limit+1));$q->execute($after===null?[]:[$after]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$converted=0;
        foreach(array_slice($ids,0,$limit)as $user)$converted+=$this->scope->run(function()use($user,$apply){
            $q=$this->pdo->prepare('SELECT revision,data FROM player_groups WHERE user_id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$user]);$raw=$q->fetch(PDO::FETCH_ASSOC);if(!$raw)return 0;
            $decoded=GroupStorage::player($raw['data']);$state=$this->store->player($user);
            if($decoded['_compact'])return 0;
            if($apply){$q=$this->pdo->prepare('UPDATE player_groups SET data=? WHERE user_id=? AND revision=? AND data=?');$q->execute([GroupStorage::encodePlayer($state),$user,$raw['revision'],$raw['data']]);if($q->rowCount()!==1)GroupRules::conflict();}
            return 1;
        });
        return ['checked'=>min(count($ids),$limit),'legacyPlayers'=>$converted,'applied'=>$apply,'nextAfterUserId'=>count($ids)>$limit?$ids[$limit-1]:null];
    }
    public function groups(?string $after,int $limit,bool $apply): array
    {
        if($after!==null)$after=GroupRules::uuid($after,'INVALID_PAGE');GroupRules::page($limit,100);$this->store->writable();
        $q=$this->pdo->prepare('SELECT id FROM groups_state'.($after===null?'':' WHERE id>?').' ORDER BY id LIMIT '.($limit+1));$q->execute($after===null?[]:[$after]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$converted=0;
        foreach(array_slice($ids,0,$limit)as $id)$converted+=$this->scope->run(function()use($id,$apply){
            $q=$this->pdo->prepare('SELECT revision,data FROM groups_state WHERE id=?'.($this->mysql?' FOR UPDATE':''));$q->execute([$id]);$raw=$q->fetch(PDO::FETCH_ASSOC);if(!$raw)return 0;
            $group=$this->store->group($id);$next=GroupStorage::encodeGroup($group);
            if($next===$raw['data'])return 0;
            if($apply){$q=$this->pdo->prepare('UPDATE groups_state SET data=? WHERE id=? AND revision=? AND data=?');$q->execute([$next,$id,$raw['revision'],$raw['data']]);if($q->rowCount()!==1)GroupRules::conflict();}
            return 1;
        });
        return ['checked'=>min(count($ids),$limit),'legacyGroups'=>$converted,'applied'=>$apply,'nextAfterGroupId'=>count($ids)>$limit?$ids[$limit-1]:null];
    }
}
