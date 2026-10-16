<?php

declare(strict_types=1);
require_once __DIR__ . '/ItemRules.php';

class ItemStore
{
    private PDO $pdo; private bool $mysql;
    public function __construct(PDO $pdo) { $this->pdo=$pdo; $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'; }
    private function lock(bool $current): string { return $current && $this->mysql ? ' FOR UPDATE' : ''; }
    public function player(string $user,bool $current=false): void
    {
        $query=$this->pdo->prepare('SELECT id FROM users WHERE id=?'.$this->lock($current)); $query->execute([$user]);
        if ($query->fetch()===false) ItemRules::fail('PLAYER_NOT_FOUND',404);
    }
    public function header(bool $current=false): array
    {
        $row=$this->pdo->query('SELECT revision,total FROM item_catalog WHERE id=1'.$this->lock($current))->fetch(PDO::FETCH_ASSOC);
        return $row===false ? ['revision'=>0,'total'=>0,'exists'=>false] : ['revision'=>(int)$row['revision'],'total'=>(int)$row['total'],'exists'=>true];
    }
    public function saveHeader(array $before,array $next): void
    {
        if (!$before['exists']) $this->pdo->prepare('INSERT INTO item_catalog(id,revision,total) VALUES(1,?,?)')->execute([$next['revision'],$next['total']]);
        else {
            $query=$this->pdo->prepare('UPDATE item_catalog SET revision=?,total=? WHERE id=1 AND revision=?'); $query->execute([$next['revision'],$next['total'],$before['revision']]);
            if ($query->rowCount()!==1) ItemRules::fail('REVISION_CONFLICT');
        }
    }
    private function templateData(string $data): array
    { $definition=json_decode($data,true,32,JSON_THROW_ON_ERROR); $definition['baseProperties']=(object)$definition['baseProperties']; return $definition; }
    public function template(string $key,bool $current=false): ?array
    {
        $query=$this->pdo->prepare('SELECT data FROM item_templates WHERE item_key=?'.$this->lock($current)); $query->execute([$key]); $row=$query->fetch(PDO::FETCH_ASSOC);
        return $row===false ? null : $this->templateData($row['data']);
    }
    public function page(?string $after,int $limit,bool $current=false): array
    {
        $query=$this->pdo->prepare('SELECT data FROM item_templates'.($after===null ? '' : ' WHERE item_key>?').' ORDER BY item_key LIMIT '.($limit+1).$this->lock($current));
        $query->execute($after===null ? [] : [$after]); return array_map(function ($row) { return $this->templateData($row['data']); },$query->fetchAll(PDO::FETCH_ASSOC));
    }
    public function saveTemplate(array $definition): void
    {
        $sql=$this->mysql ? 'INSERT INTO item_templates(item_key,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data)' : 'INSERT INTO item_templates(item_key,data) VALUES(?,?) ON CONFLICT(item_key) DO UPDATE SET data=excluded.data';
        $this->pdo->prepare($sql)->execute([$definition['key'],ItemRules::canonical((object)$definition)]);
    }
    public function inventory(string $user,bool $current=false): array
    {
        $query=$this->pdo->prepare('SELECT revision,data FROM player_items WHERE user_id=?'.$this->lock($current)); $query->execute([$user]); $row=$query->fetch(PDO::FETCH_ASSOC);
        return $row===false ? ['revision'=>0,'instances'=>[],'receipts'=>[],'evictedThroughMillis'=>0,'exists'=>false] : ['revision'=>(int)$row['revision'],'exists'=>true]+ItemRules::decodeState($row['data']);
    }
    public function saveInventory(string $user,array $before,array $next,bool $allowCreate): void
    {
        $data=json_encode(['instances'=>array_values($next['instances']),'receipts'=>array_values($next['receipts']),'evictedThroughMillis'=>$next['evictedThroughMillis']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!$before['exists'] && $allowCreate) $this->pdo->prepare('INSERT INTO player_items(user_id,revision,data) VALUES(?,?,?)')->execute([$user,$next['revision'],$data]);
        else {
            $query=$this->pdo->prepare('UPDATE player_items SET revision=?,data=? WHERE user_id=? AND revision=?'); $query->execute([$next['revision'],$data,$user,$before['revision']]);
            if ($query->rowCount()!==1) ItemRules::fail('REVISION_CONFLICT');
        }
    }
}
