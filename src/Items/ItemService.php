<?php

declare(strict_types=1);
require_once __DIR__ . '/ItemStore.php';
require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';

/** Connection-bound grant provider and explicit inventory CAS; no public grant endpoint. */
class ItemService
{
    private PDO $pdo; private ItemStore $store; private SqlTransaction $transactions; private $clock; private array $limits; private bool $stacksEnabled;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null,?int $catalog=null,?int $inventory=null,?int $receipts=null,?int $stacks=null,?bool $stacksEnabled=null)
    {
        $this->pdo=$pdo; $this->store=new ItemStore($pdo); $this->transactions=$transactions ?? new SqlTransaction($pdo);
        if (!$this->transactions->owns($pdo)) throw new InvalidArgumentException('Transaction scope belongs to another connection');
        $this->clock=$clock ?? static function () { return Database::nowMillis(); };
        $this->limits=['catalog'=>$catalog??Config::operatorLimit('ITEMS_CATALOG',5000,ItemStorage::SLOTS),'inventory'=>$inventory??Config::operatorLimit('ITEMS_INVENTORY',150),'receipts'=>$receipts??Config::operatorLimit('ITEMS_LEGACY_RECEIPTS',50),'stacks'=>$stacks??Config::operatorLimit('ITEMS_STACKS',2500)];
        // Creating stack templates only. Existing stacks keep working when it is off.
        $this->stacksEnabled=$stacksEnabled??self::stackSwitch();
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
    private static function stackSwitch(): bool
    { try { return Config::getBool('ITEMS_STACK_ENABLED',true); } catch (RuntimeException $notLoaded) { return true; } }
    private static function stack(array $template): bool { return ($template['mode'] ?? 'unique')==='stack'; }
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
        if (self::stack($template)) ItemRules::fail('ITEM_NOT_TRADABLE',422);
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
    { return ['userId'=>$user,'revision'=>$state['revision'],'instances'=>array_map([ItemStorage::class,'wire'],array_values($state['instances'])),'stacks'=>array_values($state['stacks'] ?? []),'limits'=>$this->limits,'serverTime'=>PlayerValuesLedger::instant(($this->clock)())]; }
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
        $expected=ItemRules::integer($expected); ItemRules::key($key); $mode=ItemRules::mode($input);
        return $this->atomic(function () use ($key,$expected,$input,$mode) {
            $header=$this->store->header(true); if ($header['revision']!==$expected) ItemRules::fail('REVISION_CONFLICT'); $previous=$this->store->template($key,true);
            // The mode is fixed at creation. Stack templates need the switch only to be created.
            if ($previous!==null && $mode!==null && $mode!==($previous['mode'] ?? 'unique')) ItemRules::fail('ITEM_MODE_LOCKED',422);
            $definition=ItemRules::definition($key,$input,$previous);
            if ($previous===null && self::stack($definition) && !$this->stacksEnabled) ItemRules::fail('ITEM_MODE_LOCKED',422);
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
    public function equipStack(string $user,string $key,$expected,$equipped): array
    { $equipped=ItemRules::boolean($equipped); return $this->editStack($user,$key,$expected,static function ($stack) use ($equipped) { $stack['equipped']=$equipped; return $stack; }); }
    public function discardStack(string $user,string $key,$expected,$count=1): array
    {
        $count=ItemRules::integer($count,1,ItemRules::MAX_STACK);
        return $this->editStack($user,$key,$expected,static function ($stack) use ($count) {
            if ($count>$stack['count']) ItemRules::fail('ITEM_STACK_TOO_SMALL',422); $stack['count']-=$count; return $stack['count']>0 ? $stack : null;
        });
    }
    /** A stack exists only while its count is positive. Values always come from the template. */
    private function editStack(string $user,string $key,$expected,callable $change): array
    {
        $user=ItemRules::uuid($user); $key=ItemRules::key($key); $expected=ItemRules::integer($expected);
        return $this->atomic(function () use ($user,$key,$expected,$change) {
            $this->store->player($user,true); $state=$this->store->inventory($user,true); if ($state['revision']!==$expected) ItemRules::fail('REVISION_CONFLICT');
            $found=false; $next=$state; $next['stacks']=[];
            foreach ($state['stacks'] as $stack) {
                if ($stack['itemKey']===$key) { $found=true; $stack=$change($stack); }
                if ($stack!==null) $next['stacks'][]=$stack;
            }
            if (!$found) ItemRules::fail('ITEM_NOT_FOUND',404); $next['revision']=ItemRules::next($state['revision']);
            $this->store->saveInventory($user,$state,$next,false); return $this->view($user,$next);
        });
    }
    /** Unique rewards become new instances, stack rewards raise the count of one stack per template. */
    private function add(array $state,array $items,int $now): array
    {
        $templates=[];$unique=0;
        foreach ($items as $index=>$item) {
            $template=$this->store->template($item['itemKey'],true); if ($template===null || !$template['active']) ItemRules::fail('ITEM_TEMPLATE_NOT_FOUND',404);
            if (!self::stack($template)) { if (!isset($template['_basis'])) ItemStorage::required(); $unique+=$item['quantity']; } $templates[$index]=$template;
        }
        if (count($state['instances'])+$unique>$this->limits['inventory']) ItemRules::fail('INVENTORY_FULL',422);
        $next=$state; $next['stacks']=$state['stacks'] ?? []; $references=[]; $stacks=[];
        foreach ($items as $index=>$item) {
            $template=$templates[$index];
            if (self::stack($template)) {
                $position=null; foreach ($next['stacks'] as $at=>$stack) if ($stack['itemKey']===$item['itemKey']) $position=$at;
                if ($position===null) {
                    if (count($next['stacks'])>=$this->limits['stacks']) ItemRules::fail('STACK_LIMIT_REACHED',422);
                    $next['stacks'][]=['itemKey'=>$item['itemKey'],'count'=>0,'equipped'=>false]; $position=count($next['stacks'])-1;
                }
                $count=$next['stacks'][$position]['count']+$item['quantity']; if ($count>$template['maxStack']) ItemRules::fail('STACK_LIMIT_REACHED',422);
                $next['stacks'][$position]['count']=$count; $stacks[]=['itemKey'=>$item['itemKey'],'quantity'=>$item['quantity'],'count'=>$count]; continue;
            }
            for ($i=0;$i<$item['quantity'];$i++) {
                $id=Database::uuid(); $references[]=['instanceId'=>$id,'itemKey'=>$item['itemKey']];
                $next['instances'][]=['instanceId'=>$id,'itemKey'=>$item['itemKey'],'properties'=>clone $template['baseProperties'],'equipped'=>false,'createdAt'=>PlayerValuesLedger::instant($now),'_basis'=>$template['_basis']];
            }
        }
        return [$next,$references,$stacks];
    }
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
            [$next,$references,$stacks]=$this->add($state,$items,($this->clock)());
            $next['revision']=ItemRules::next($state['revision']);$this->store->saveInventory($user,$state,$next,true);
            return ['items'=>$references,'stacks'=>$stacks,'replayed'=>false];
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
                if (!hash_equals($receipt['payloadHash'],$hash)) ItemRules::fail('OPERATION_CONFLICT'); return ['items'=>$receipt['items'],'stacks'=>[],'replayed'=>true];
            }
            $now=($this->clock)(); if ($issued>$now+30000) ItemRules::invalid(); if ($issued<$now-ItemRules::RETENTION || $issued<=$state['evictedThroughMillis']) ItemRules::fail('OPERATION_EXPIRED');
            [$next,$references,$stacks]=$this->add($state,$items,$now);
            $all=array_merge($state['receipts'],[['operationId'=>$operation,'payloadHash'=>$hash,'source'=>$source,'issuedAt'=>PlayerValuesLedger::instant($issued),
                'recordedAt'=>PlayerValuesLedger::instant($now),'issuedAtMillis'=>$issued,'recordedAtMillis'=>$now,'items'=>$references]]);
            $next['receipts']=array_slice(array_values(array_filter($all,static function ($row) use ($now) { return $row['recordedAtMillis'] >= $now-ItemRules::RETENTION; })),-$this->limits['receipts']);
            $ids=array_fill_keys(array_column($next['receipts'],'operationId'),true);
            foreach ($all as $receipt) if (!isset($ids[$receipt['operationId']])) $next['evictedThroughMillis']=max($next['evictedThroughMillis'],$receipt['issuedAtMillis']);
            $next['revision']=ItemRules::next($state['revision']); $this->store->saveInventory($user,$state,$next,true); return ['items'=>$references,'stacks'=>$stacks,'replayed'=>false];
        });
    }
}
