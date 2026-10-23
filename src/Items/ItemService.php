<?php

declare(strict_types=1);
require_once __DIR__ . '/ItemStore.php';
require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';

/** Connection-bound grant provider and explicit inventory CAS; no public grant endpoint. */
class ItemService
{
    private PDO $pdo; private ItemStore $store; private SqlTransaction $transactions; private $clock; private array $limits;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null,?int $catalog=null,?int $inventory=null,?int $receipts=null)
    {
        $this->pdo=$pdo; $this->store=new ItemStore($pdo); $this->transactions=$transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Transaction scope belongs to another connection');
        $this->clock=$clock ?? static function () { return Database::nowMillis(); };
        $this->limits=['catalog'=>$catalog??Config::operatorLimit('ITEMS_CATALOG',5000,ItemStorage::SLOTS),'inventory'=>$inventory??Config::operatorLimit('ITEMS_INVENTORY',150),'receipts'=>$receipts??Config::operatorLimit('ITEMS_LEGACY_RECEIPTS',50)];
        foreach($this->limits as $name=>$value)if($value<1||$value>($name==='catalog'?ItemStorage::SLOTS:2147483647))throw new InvalidArgumentException('Invalid item limit: '.$name);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') $pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function atomic(callable $work)
    {
        try { return $this->transactions->run($work); }
        catch (SqlTransactionUncertain $error) { ItemRules::fail('STATE_CONFLICT'); }
        catch (SqlTransactionRollbackOnly $error) { ItemRules::fail('STATE_CONFLICT'); }
        catch (PDOException $error) {
            if (in_array((int)($error->errorInfo[1] ?? 0),[5,6,19,1062,1205,1213,2006,2013],true)) ItemRules::fail('STATE_CONFLICT'); throw $error;
        }
    }
    public function limits(): array { return $this->limits; }
    /** Server-only original-instance transfer. The caller owns the durable source guard. */
    public function escrowWithdraw(string $user,string $instance,int $expected): array
    {
        if (!$this->transactions->isRunning()) throw new LogicException('Original transfer requires the owning SQL scope');
        // Marketplace has already locked the active user; keep that fence until commit.
        $state=$this->store->inventory($user,true);
        if ($state['revision']!==$expected) ItemRules::fail('REVISION_CONFLICT');
        $original=null; $next=$state; $next['instances']=[];
        foreach ($state['instances'] as $item) {
            if ($item['instanceId']===$instance) $original=$item; else $next['instances'][]=$item;
        }
        if ($original===null) ItemRules::fail('ITEM_NOT_FOUND',404);
        if ($original['equipped']) ItemRules::fail('ITEM_EQUIPPED',422);
        $this->escrowTemplate($original['itemKey']);
        $next['revision']=ItemRules::next($state['revision']); $this->store->saveInventory($user,$state,$next,false);
        return $original;
    }
    public function escrowTemplate(string $key): void
    {
        if (!$this->transactions->isRunning()) throw new LogicException('Template fence requires the owning SQL scope');
        $template=$this->store->template($key,true);
        if ($template===null || !$template['active']) ItemRules::fail('ITEM_TEMPLATE_NOT_FOUND',404);
    }
    public function escrowReceive(string $user,array $original): array
    {
        if (!$this->transactions->isRunning()) throw new LogicException('Original transfer requires the owning SQL scope');
        // The source caller holds the active-user fence in this same scope.
        $state=$this->store->inventory($user,true);
        if (count($state['instances']) >= $this->limits['inventory']) ItemRules::fail('INVENTORY_FULL',422);
        foreach ($state['instances'] as $item) if ($item['instanceId']===$original['instanceId']) ItemRules::fail('STATE_CONFLICT');
        // Returning owned goods does not depend on a still-active catalogue template.
        $original['properties']=ItemRules::properties((object)$original['properties']); $original['equipped']=false;
        $next=$state; $next['instances'][]=$original; $next['revision']=ItemRules::next($state['revision']);
        $this->store->saveInventory($user,$state,$next,true);
        return ['instanceId'=>$original['instanceId'],'itemKey'=>$original['itemKey']];
    }
    private function view(string $user,array $state): array
    { return ['userId'=>$user,'revision'=>$state['revision'],'instances'=>array_map([ItemStorage::class,'wire'],array_values($state['instances'])),'limits'=>$this->limits,'serverTime'=>PlayerValuesLedger::instant(($this->clock)())]; }
    public function get(string $user): array
    { $user=ItemRules::uuid($user); $this->store->player($user); return $this->view($user,$this->store->inventory($user)); }
    public function inspect(string $user): array
    { $user=ItemRules::uuid($user); $this->store->player($user); $state=$this->store->inventory($user); return ['inventory'=>$this->view($user,$state),'receipts'=>$state['receipts']]; }
    public function catalog(?string $user=null,?string $after=null,int $limit=50,?int $revision=null): array
    {
        if ($user!==null) $this->store->player(ItemRules::uuid($user)); if ($after!==null) ItemRules::key($after); ItemRules::integer($limit,1,50); if ($revision!==null) ItemRules::integer($revision);
        $current=$this->transactions->isActive(); $before=$this->store->header($current);
        if ($revision!==null && $revision!==$before['revision']) ItemRules::fail('REVISION_CONFLICT');
        $rows=$this->store->page($after,$limit,$current); $afterHeader=$this->store->header($current);
        if ($before['revision']!==$afterHeader['revision']) ItemRules::fail('REVISION_CONFLICT');
        $definitions=array_slice($rows,0,$limit);
        return ['revision'=>$before['revision'],'definitions'=>$definitions,'nextAfterKey'=>count($rows)>$limit ? $definitions[count($definitions)-1]['key'] : null,
            'total'=>$before['total'],'limits'=>$this->limits,'serverTime'=>PlayerValuesLedger::instant(($this->clock)())];
    }
    public function put(string $key,$expected,$input): array
    {
        $expected=ItemRules::integer($expected); $definition=ItemRules::definition($key,$input);
        return $this->atomic(function () use ($key,$expected,$definition) {
            $header=$this->store->header(true); if ($header['revision']!==$expected) ItemRules::fail('REVISION_CONFLICT'); $previous=$this->store->template($key,true);
            if ($previous===null && $header['total'] >= $this->limits['catalog']) ItemRules::fail('ITEM_CATALOG_LIMIT_REACHED',422);
            if ($previous===null || ItemRules::canonical((object)ItemStore::definitionWire($previous))!==ItemRules::canonical((object)$definition)) {
                $next=['revision'=>ItemRules::next($header['revision']),'total'=>$header['total']+($previous===null ? 1 : 0)];
                $this->store->saveHeader($header,$next); $this->store->saveTemplate($definition); $header=$next;
            }
            return ['revision'=>$header['revision'],'definition'=>$definition,'limits'=>$this->limits,'serverTime'=>PlayerValuesLedger::instant(($this->clock)())];
        });
    }
    public function adjust(string $user,string $instance,$expected,$changes): array
    {
        return $this->edit($user,$instance,$expected,function ($item) use ($changes) {
            $template=$this->store->template($item['itemKey'],true); if ($template===null) ItemRules::fail('ITEM_TEMPLATE_NOT_FOUND',404);
            return ItemRules::adjust($item,$template,$changes);
        });
    }
    public function equip(string $user,string $instance,$expected,$equipped): array
    { $equipped=ItemRules::boolean($equipped); return $this->edit($user,$instance,$expected,static function ($item) use ($equipped) { $item['equipped']=$equipped; return $item; }); }
    public function discard(string $user,string $instance,$expected): array
    { return $this->edit($user,$instance,$expected,static function ($item) { return null; }); }
    private function edit(string $user,string $instance,$expected,callable $change): array
    {
        $user=ItemRules::uuid($user); $instance=ItemRules::uuid($instance); $expected=ItemRules::integer($expected);
        return $this->atomic(function () use ($user,$instance,$expected,$change) {
            $this->store->player($user,true); $state=$this->store->inventory($user,true); if ($state['revision']!==$expected) ItemRules::fail('REVISION_CONFLICT');
            $found=false; $next=$state; $next['instances']=[];
            foreach ($state['instances'] as $item) {
                if ($item['instanceId']===$instance) { $found=true; $item=$change($item); }
                if ($item!==null) $next['instances'][]=$item;
            }
            if (!$found) ItemRules::fail('ITEM_NOT_FOUND',404); $next['revision']=ItemRules::next($state['revision']);
            $this->store->saveInventory($user,$state,$next,false); return $this->view($user,$next);
        });
    }
    /** Only the durable source may guard a new grant, in this same explicit SQL scope. */
    public function grantFromSource(string $user,$items,string $operation,int $issued,string $source):array
    {
        if(!$this->transactions->isRunning())throw new LogicException('Source-owned item grant requires the owning SQL scope');
        $user=ItemRules::uuid($user);$items=ItemRules::rewards($items);ItemRules::integer($issued);
        PlayerValuesLedger::text($operation,128,'operationId');PlayerValuesLedger::text($source,200,'source');
        return $this->atomic(function()use($user,$items){
            $this->store->player($user,true);$state=$this->store->inventory($user,true);
            if(!($state['compact']??false))ItemStorage::required();
            if(count($state['instances'])+array_sum(array_column($items,'quantity'))>$this->limits['inventory'])ItemRules::fail('INVENTORY_FULL',422);
            $next=$state;$references=[];$now=($this->clock)();
            foreach($items as $item){
                $template=$this->store->template($item['itemKey'],true);if($template===null||!$template['active'])ItemRules::fail('ITEM_TEMPLATE_NOT_FOUND',404);
                if(!isset($template['_basis']))ItemStorage::required();
                for($i=0;$i<$item['quantity'];$i++){$id=Database::uuid();$references[]=['instanceId'=>$id,'itemKey'=>$item['itemKey']];
                    $next['instances'][]=['instanceId'=>$id,'itemKey'=>$item['itemKey'],'properties'=>clone $template['baseProperties'],'equipped'=>false,'createdAt'=>PlayerValuesLedger::instant($now),'_basis'=>$template['_basis']];}
            }
            $next['revision']=ItemRules::next($state['revision']);$this->store->saveInventory($user,$state,$next,true);
            return ['items'=>$references,'replayed'=>false];
        });
    }
    /** Original timestamp/provenance are persisted by the caller before this server-only grant. */
    public function grant(string $user,$items,string $operation,int $issued,string $source): array
    {
        $user=ItemRules::uuid($user); $items=ItemRules::rewards($items); ItemRules::integer($issued);
        PlayerValuesLedger::text($operation,128,'operationId'); PlayerValuesLedger::text($source,200,'source');
        $sorted=$items; usort($sorted,static function ($a,$b) { return strcmp($a['itemKey'],$b['itemKey']); }); $parts=['items',$source,(string)$issued];
        foreach ($sorted as $item) { $parts[]=$item['itemKey']; $parts[]=(string)$item['quantity']; } $hash=PlayerValuesLedger::hash(...$parts);
        return $this->atomic(function () use ($user,$items,$operation,$issued,$source,$hash) {
            $this->store->player($user,true); $state=$this->store->inventory($user,true);
            foreach ($state['receipts'] as $receipt) if ($receipt['operationId']===$operation) {
                if (!hash_equals($receipt['payloadHash'],$hash)) ItemRules::fail('OPERATION_CONFLICT'); return ['items'=>$receipt['items'],'replayed'=>true];
            }
            $now=($this->clock)(); if ($issued>$now+30000) ItemRules::invalid(); if ($issued<$now-ItemRules::RETENTION || $issued<=$state['evictedThroughMillis']) ItemRules::fail('OPERATION_EXPIRED');
            if (count($state['instances'])+array_sum(array_column($items,'quantity'))>$this->limits['inventory']) ItemRules::fail('INVENTORY_FULL',422);
            $next=$state; $references=[];
            foreach ($items as $item) {
                $template=$this->store->template($item['itemKey'],true); if ($template===null || !$template['active']) ItemRules::fail('ITEM_TEMPLATE_NOT_FOUND',404);
                if(!isset($template['_basis']))ItemStorage::required();
                for ($i=0;$i<$item['quantity'];$i++) {
                    $id=Database::uuid(); $references[]=['instanceId'=>$id,'itemKey'=>$item['itemKey']];
                    $next['instances'][]=['instanceId'=>$id,'itemKey'=>$item['itemKey'],'properties'=>clone $template['baseProperties'],'equipped'=>false,'createdAt'=>PlayerValuesLedger::instant($now),'_basis'=>$template['_basis']??null];
                }
            }
            $all=array_merge($state['receipts'],[['operationId'=>$operation,'payloadHash'=>$hash,'source'=>$source,'issuedAt'=>PlayerValuesLedger::instant($issued),
                'recordedAt'=>PlayerValuesLedger::instant($now),'issuedAtMillis'=>$issued,'recordedAtMillis'=>$now,'items'=>$references]]);
            $next['receipts']=array_slice(array_values(array_filter($all,static function ($row) use ($now) { return $row['recordedAtMillis'] >= $now-ItemRules::RETENTION; })),-$this->limits['receipts']);
            $ids=array_fill_keys(array_column($next['receipts'],'operationId'),true);
            foreach ($all as $receipt) if (!isset($ids[$receipt['operationId']])) $next['evictedThroughMillis']=max($next['evictedThroughMillis'],$receipt['issuedAtMillis']);
            $next['revision']=ItemRules::next($state['revision']); $this->store->saveInventory($user,$state,$next,true); return ['items'=>$references,'replayed'=>false];
        });
    }
}
