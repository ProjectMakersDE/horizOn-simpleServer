<?php

declare(strict_types=1);
require_once __DIR__.'/MarketStore.php';
require_once __DIR__.'/MarketReturns.php';
require_once __DIR__.'/../Core/Database.php';
require_once __DIR__.'/../Core/SqlTransaction.php';
require_once __DIR__.'/../Items/ItemService.php';
require_once __DIR__.'/../PlayerValues/PlayerValuesService.php';
require_once __DIR__.'/../Inbox/InboxService.php';

class MarketService
{
    use MarketReturns;
    private PDO $pdo; private bool $mysql; private SqlTransaction $transactions; private MarketStore $store;
    private ItemService $items; private PlayerValuesService $values; private InboxService $inbox; private $clock; private array $cap;
    public function __construct(PDO $pdo,?callable $clock=null,?SqlTransaction $transactions=null,?array $limits=null,?ItemService $items=null)
    {
        $this->pdo=$pdo;$this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$this->clock=$clock??static function(){return Database::nowMillis();};
        $this->transactions=$transactions??new SqlTransaction($pdo);if(!$this->transactions->owns($pdo))throw new InvalidArgumentException('Foreign marketplace SQL connection');
        $this->store=new MarketStore($pdo);$this->items=$items??new ItemService($pdo,$this->clock,$this->transactions);
        $this->values=new PlayerValuesService($pdo,$this->clock,$this->transactions);$this->inbox=new InboxService($pdo,$this->clock,$this->transactions);
        $this->cap=$limits??MarketRules::limits('ENTERPRISE');foreach(MarketRules::limits('ENTERPRISE')as$key=>$max)MarketRules::integer($this->cap[$key]??null,1,$max);
        if(!$this->mysql)$pdo->exec('PRAGMA busy_timeout=5000');
    }
    private function now(): int { return ($this->clock)(); }
    private function atomic(callable $work)
    {
        if($this->pdo->inTransaction()&&!$this->transactions->isRunning())MarketRules::fail('STATE_CONFLICT');
        try{return $this->transactions->run($work);}
        catch(SqlTransactionUncertain $e){MarketRules::fail('STATE_CONFLICT');}
        catch(SqlTransactionRollbackOnly $e){MarketRules::fail('STATE_CONFLICT');}
        catch(PDOException $e){if(in_array((int)($e->errorInfo[1]??0),[5,6,19,1062,1205,1213,1452,2006,2013],true))MarketRules::fail('STATE_CONFLICT');throw $e;}
    }
    private function snapshot(callable $work)
    {
        if($this->transactions->isActive()){if($this->mysql&&!$this->transactions->isRunning())MarketRules::fail('STATE_CONFLICT');return $work();}
        if($this->mysql)$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->beginTransaction();
        try{$r=$work();$this->pdo->commit();return$r;}catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }
    private function actors(array $ids,bool $lock=false): void
    { if(count($this->store->users($ids,$lock))!==count(array_unique($ids)))MarketRules::fail('PLAYER_NOT_FOUND',404); }
    private function listing(string $id,bool $lock=false): array
    { $v=$this->store->get($id,$lock);if($v===null||($v['terminalAt']!==null&&$v['terminalAt']+$this->cap['terminalRetentionSeconds']*1000<=$this->now()))MarketRules::fail('LISTING_NOT_FOUND',404);return$v; }
    private function visible(array $v,?string $user): bool
    { return $user===null||($v['status']==='active'&&$v['expiresAt']>$this->now())||in_array($user,[$v['sellerId'],$v['buyerId'],$v['returnOwnerId']],true); }
    private function view(array $v): array
    { $r=array_intersect_key($v,array_flip(['id','revision','sellerId','buyerId','returnOwnerId','item','itemReference','currencyKey','price','feeBps','feeAmount','sellerNet','status','createdAt','expiresAt','terminalAt','returnEntryId','ownerUnavailable']));foreach(['createdAt','expiresAt','terminalAt']as$k)$r[$k]=MarketRules::instant($r[$k]);return$r; }
    private function envelope(array $receipt,bool $replayed): array
    { $v=$this->store->get($receipt['listingId']);if($v!==null&&$v['terminalAt']!==null&&$v['terminalAt']+MarketRules::TERMINAL<=$this->now())$v=null;return['receipt'=>$receipt,'listing'=>$v===null?null:$this->view($v),'replayed'=>$replayed,'serverTime'=>MarketRules::instant($this->now())]; }
    private function configView(array $h,bool $public=false): array
    { return array_intersect_key($h,array_flip($public?['revision','enabled','currencyKey','feeBps']:['revision','enabled','currencyKey','feeBps','ruleCount','escrowCount','retainedCount'])); }
    public function limits(?string $user=null): array
    { if($user!==null)$this->actors([MarketRules::uuid($user)]);$h=$this->store->header();return['limits'=>$this->cap,'capabilities'=>['marketplace'=>true,'realtime'=>false],'config'=>$this->configView($h,true),'serverTime'=>MarketRules::instant($this->now())]; }
    public function config(): array { return ['config'=>$this->configView($this->store->header()),'serverTime'=>MarketRules::instant($this->now())]; }
    public function configure(int $expected,$raw): array
    {
        MarketRules::integer($expected);$r=MarketRules::fields($raw,['enabled','currencyKey','feeBps']);MarketRules::boolean($r['enabled']);if($r['currencyKey']!==null)MarketRules::currency($r['currencyKey']);if($r['feeBps']!==null)MarketRules::integer($r['feeBps'],0,10000);
        return $this->atomic(function()use($expected,$r){$h=$this->store->header(true);MarketRules::expected($h['revision'],$expected);
            if($h['escrowCount']>0&&($r['currencyKey']!==$h['currencyKey']||$r['feeBps']===null))MarketRules::fail('CURRENCY_IN_USE');
            if($r['enabled']){if($r['currencyKey']===null||$r['feeBps']===null)MarketRules::invalid();$this->currency($r['currencyKey']);if($h['activeRuleCount']===0)MarketRules::invalid();}
            $next=array_replace($h,$r);$next['revision']=MarketRules::next($h['revision']);$next=$this->store->saveHeader($h,$next);return['config'=>$this->configView($next),'serverTime'=>MarketRules::instant($this->now())];});
    }
    public function rules(?string $user=null,?string $after=null,int $limit=20,?int $revision=null): array
    {
        if($user!==null)$this->actors([MarketRules::uuid($user)]);if($after!==null)MarketRules::itemKey($after);MarketRules::integer($limit,1,50);if($revision!==null)MarketRules::integer($revision);
        $before=$this->store->header();if($revision!==null)MarketRules::expected($before['revision'],$revision);$rows=$this->store->rules($after,$limit);$afterHeader=$this->store->header();MarketRules::expected($afterHeader['revision'],$before['revision']);$page=array_slice($rows,0,$limit);
        return['revision'=>$before['revision'],'rules'=>$page,'nextCursor'=>count($rows)>$limit?$page[count($page)-1]['itemKey']:null,'serverTime'=>MarketRules::instant($this->now())];
    }
    public function putRule(string $key,int $expected,$raw): array
    {
        MarketRules::itemKey($key);MarketRules::integer($expected);$r=MarketRules::fields($raw,['active','minPrice','maxPrice']);MarketRules::boolean($r['active']);MarketRules::integer($r['minPrice'],1);MarketRules::integer($r['maxPrice'],$r['minPrice']);$r=['itemKey'=>$key]+$r;
        return$this->atomic(function()use($key,$expected,$r){$h=$this->store->header(true);MarketRules::expected($h['revision'],$expected);$templates=new ItemStore($this->pdo);if($templates->template($key,true)===null)MarketRules::fail('ITEM_TEMPLATE_NOT_FOUND',404);$old=$this->store->rule($key,true);if($old===null&&$h['ruleCount']>=5000)MarketRules::fail('LISTING_LIMIT_REACHED',422);$next=$h;$next['ruleCount']+=($old===null?1:0);$next['activeRuleCount']+=(int)$r['active']-(int)($old['active']??false);$next['revision']=MarketRules::next($h['revision']);$this->store->saveRule($r);$this->store->saveHeader($h,$next);return['revision'=>$next['revision'],'rule'=>$r,'serverTime'=>MarketRules::instant($this->now())];});
    }
    private function currency(string $key): void
    { $c=$this->values->config(false,true);if(!in_array($key,array_column($c['definitions'],'key'),true))MarketRules::invalid(); }
    private function policy(array $h,string $item,int $price,bool $checkCurrency=true): void
    { if(!$h['enabled'])MarketRules::fail('MARKETPLACE_DISABLED',403);$r=$this->store->rule($item,true);if($r===null||!$r['active'])MarketRules::fail('LISTING_UNAVAILABLE');if($price<$r['minPrice']||$price>$r['maxPrice'])MarketRules::fail('PRICE_OUT_OF_RANGE',422);$this->items->escrowTemplate($item);if($checkCurrency)$this->currency($h['currencyKey']); }
    private function notBanned(array $p): void { if($p['banUntil']!==null&&$p['banUntil']>$this->now())MarketRules::fail('MARKETPLACE_BANNED',403); }
    private function rate(array &$h,array &$p): void
    {
        $now=$this->now();$bucket=intdiv($now,60000);if($bucket<$h['bucket'])MarketRules::fail('MARKETPLACE_RATE_LIMITED',429,max(1,(int)ceil((($h['bucket']+1)*60000-$now)/1000)));
        if($bucket>$h['bucket']){$h['bucket']=$bucket;$h['writes']=0;}
        if($h['writes']>=$this->cap['projectWritesPerMinute'])MarketRules::fail('MARKETPLACE_RATE_LIMITED',429,max(1,(int)ceil((($bucket+1)*60000-$now)/1000)));
        if($p['nextAllowedAt']>$now)MarketRules::fail('MARKETPLACE_RATE_LIMITED',429,max(1,(int)ceil(($p['nextAllowedAt']-$now)/1000)));
        $h['writes']++;$p['nextAllowedAt']=max($p['nextAllowedAt'],$now+1000);
    }
    private function input(string $user,string $kind,$raw,array $extra): array
    { $r=MarketRules::fields($raw,array_merge(['userId','operationId','issuedAt'],$extra));if(MarketRules::uuid($r['userId'])!==$user)MarketRules::invalid();MarketRules::uuid($r['operationId']);$r['_issued']=MarketRules::millis($r['issuedAt']);$parts=['marketplace',$user,$kind];foreach(array_merge(['userId','operationId','issuedAt'],$extra)as$key){$v=$r[$key];$parts[]=$key;$parts[]=is_int($v)?(string)$v:(is_string($v)?$v:MarketRules::json($v));}$r['_hash']=PlayerValuesLedger::hash(...$parts);return$r; }
    private function receipt(string $kind,array $r,array $v): array
    { return['operationId'=>$r['operationId'],'issuedAt'=>$r['issuedAt'],'kind'=>$kind,'listingId'=>$v['id'],'listingRevision'=>$v['revision'],'recordedAt'=>MarketRules::instant($this->now()),'itemReference'=>$v['itemReference'],'currencyKey'=>$v['currencyKey'],'price'=>$v['price'],'feeAmount'=>$v['feeAmount'],'sellerNet'=>$v['sellerNet']]; }
    private function history(array $p,string $id): array
    { $p['openIds']=array_values(array_diff($p['openIds'],[$id]));$p['historyIds']=array_slice(array_values(array_unique(array_merge([$id],$p['historyIds']))),0,$this->cap['historyPerPlayer']);return$p; }
    public function create(string $user,$raw): array
    {
        $user=MarketRules::uuid($user);$r=$this->input($user,'create',$raw,['instanceId','expectedInventoryRevision','expectedConfigRevision','price','durationSeconds']);MarketRules::uuid($r['instanceId']);MarketRules::integer($r['expectedInventoryRevision']);MarketRules::integer($r['expectedConfigRevision']);MarketRules::integer($r['price'],1);MarketRules::integer($r['durationSeconds'],300,604800);
        return$this->atomic(function()use($user,$r){$h=$this->store->header(true);$this->actors([$user],true);$p=$this->store->player($user,true);$old=MarketRules::receipt($p,$r['operationId'],$r['_hash']);if($old!==null)return$this->envelope($old,true);$now=$this->now();MarketRules::window($p,$r['_issued'],$now);$this->notBanned($p);MarketRules::expected($h['revision'],$r['expectedConfigRevision']);if(!$h['enabled'])MarketRules::fail('MARKETPLACE_DISABLED',403);
            if(count($p['openIds'])>=$this->cap['openListingsPerPlayer'])MarketRules::fail('OPEN_LISTING_LIMIT_REACHED',422);if($h['retainedCount']>=$this->cap['retainedListingsPerProject'])MarketRules::fail('LISTING_LIMIT_REACHED',422);
            $item=$this->items->escrowWithdraw($user,$r['instanceId'],$r['expectedInventoryRevision']);$this->policy($h,$item['itemKey'],$r['price']);$nextP=$p;$nextH=$h;$this->rate($nextH,$nextP);$id=Database::uuid();$fee=MarketRules::fee($r['price'],$h['feeBps']);
            $v=['id'=>$id,'revision'=>1,'sellerId'=>$user,'buyerId'=>null,'returnOwnerId'=>$user,'item'=>$item,'itemReference'=>['instanceId'=>$item['instanceId'],'itemKey'=>$item['itemKey']],'currencyKey'=>$h['currencyKey'],'price'=>$r['price'],'feeBps'=>$h['feeBps'],'feeAmount'=>$fee,'sellerNet'=>$r['price']-$fee,'status'=>'active','createdAt'=>$now,'expiresAt'=>$now+$r['durationSeconds']*1000,'terminalAt'=>null,'returnEntryId'=>null,'ownerUnavailable'=>false,'dueAt'=>$now+$r['durationSeconds']*1000,'returnOperation'=>null,'returnIssued'=>null,'audit'=>['cancelActor'=>null,'cancelNote'=>null,'recoveries'=>[]]];
            $receipt=$this->receipt('create',$r,$v);$nextP['openIds'][]=$id;$nextP=MarketRules::record($nextP,$receipt,$r['_hash'],$r['_issued'],$now,$this->cap['receiptsPerPlayer']);$this->store->insert($v);$this->store->savePlayer($user,$p,$nextP);$nextH['escrowCount']++;$nextH['retainedCount']++;$this->store->saveHeader($h,$nextH);return$this->envelope($receipt,false);});
    }
    public function buy(string $user,string $id,$raw): array
    {
        $user=MarketRules::uuid($user);$id=MarketRules::uuid($id);$r=$this->input($user,'buy:'.$id,$raw,['expectedListingRevision','expectedPrice','expectedCurrencyKey']);MarketRules::integer($r['expectedListingRevision']);MarketRules::integer($r['expectedPrice'],1);MarketRules::currency($r['expectedCurrencyKey']);
        return$this->atomic(function()use($user,$id,$r){$h=$this->store->header(true);$this->actors([$user],true);$buyer=$this->store->player($user,true);$old=MarketRules::receipt($buyer,$r['operationId'],$r['_hash']);if($old!==null)return$this->envelope($old,true);$now=$this->now();MarketRules::window($buyer,$r['_issued'],$now);$v=$this->listing($id,true);if($v['status']!=='active'||$v['expiresAt']<=$now||$v['sellerId']===$user||$v['ownerUnavailable'])MarketRules::fail('LISTING_UNAVAILABLE');
            $this->actors([$user,$v['sellerId']],true);$seller=$this->store->player($v['sellerId'],true);$this->notBanned($buyer);$this->notBanned($seller);MarketRules::expected($v['revision'],$r['expectedListingRevision']);if($v['price']!==$r['expectedPrice']||$v['currencyKey']!==$r['expectedCurrencyKey'])MarketRules::fail('PRICE_CHANGED');if($h['currencyKey']!==$v['currencyKey'])MarketRules::fail('LISTING_UNAVAILABLE');$this->policy($h,$v['itemReference']['itemKey'],$v['price'],false);
            $nextH=$h;$nextB=$buyer;$this->rate($nextH,$nextB);$this->values->transferEscrow($user,$v['sellerId'],$v['currencyKey'],$v['price'],$v['sellerNet'],$id,$r['_issued']);$this->items->escrowReceive($user,$v['item']);
            $next=$v;$next['revision']=MarketRules::next($v['revision']);$next['status']='sold';$next['buyerId']=$user;$next['item']=null;$next['terminalAt']=$now;$next['dueAt']=$now+MarketRules::TERMINAL;$receipt=$this->receipt('buy',$r,$next);
            $nextB=MarketRules::record($this->history($nextB,$id),$receipt,$r['_hash'],$r['_issued'],$now,$this->cap['receiptsPerPlayer']);$this->store->savePlayer($user,$buyer,$nextB);$this->store->savePlayer($v['sellerId'],$seller,$this->history($seller,$id));$this->store->save($v,$next);$nextH['escrowCount']--;$this->store->saveHeader($h,$nextH);return$this->envelope($receipt,false);});
    }
    public function get(?string $user,string $id): array
    { if($user!==null)$this->actors([MarketRules::uuid($user)]);$v=$this->listing(MarketRules::uuid($id));if(!$this->visible($v,$user))MarketRules::fail('LISTING_NOT_FOUND',404);$r=['listing'=>$this->view($v),'serverTime'=>MarketRules::instant($this->now())];if($user===null)$r['audit']=$v['audit'];return$r; }
    public function operation(string $user,string $operation): array
    { $this->actors([MarketRules::uuid($user)]);MarketRules::uuid($operation);$p=$this->store->player($user);foreach($p['receipts']as$r)if($r['receipt']['operationId']===$operation)return['receipt'=>$r['receipt'],'serverTime'=>MarketRules::instant($this->now())];MarketRules::fail('OPERATION_NOT_FOUND',404); }
    public function state(string $user): array
    { $user=MarketRules::uuid($user);return$this->snapshot(function()use($user){$this->actors([$user]);$p=$this->store->player($user);$open=[];$history=[];foreach($this->store->many($p['openIds'])as$v)$open[]=$this->view($v);foreach($this->store->many($p['historyIds'])as$v)if($v['terminalAt']===null||$v['terminalAt']+MarketRules::TERMINAL>$this->now())$history[]=$this->view($v);return['state'=>['userId'=>$user,'revision'=>$p['revision'],'openListings'=>$open,'history'=>$history,'operationWindow'=>['serverTime'=>MarketRules::instant($this->now()),'acceptAfter'=>MarketRules::instant(max($p['floor'],$this->now()-MarketRules::RETENTION))],'moderation'=>['banUntil'=>MarketRules::instant($p['banUntil']),'reason'=>$p['reason']],'limits'=>$this->cap],'serverTime'=>MarketRules::instant($this->now())];}); }
    public function search(string $user,?string $item=null,int $min=0,int $max=MarketRules::MAX,int $limit=20,?string $cursor=null): array
    {
        $this->actors([MarketRules::uuid($user)]);if($item!==null)MarketRules::itemKey($item);MarketRules::integer($min);MarketRules::integer($max,$min);MarketRules::integer($limit,1,50);$scope=['project'=>$this->store->header()['projectId'],'kind'=>'public','item'=>$item,'min'=>$min,'max'=>$max];$c=MarketRules::unCursor($cursor);$after=null;if($c!==null){$c=MarketRules::fields($c,['scope','price','id']);if($c['scope']!==$scope)MarketRules::invalid();MarketRules::integer($c['price'],$min,$max);MarketRules::uuid($c['id']);$after=$c;}
        $rows=$this->store->page($item,$min,$max,$after);$out=[];$last=null;$used=0;foreach($rows as$v){$last=$v;$used++;if($v['expiresAt']>$this->now()&&!$v['ownerUnavailable'])$out[]=$this->view($v);if(count($out)>=$limit)break;}$next=$last!==null&&($used<count($rows)||count($rows)===256)?MarketRules::cursor(['scope'=>$scope,'price'=>$last['price'],'id'=>$last['id']]):null;return['listings'=>$out,'nextCursor'=>$next,'serverTime'=>MarketRules::instant($this->now())];
    }
    public function adminListings(?string $status=null,int $limit=20,?string $cursor=null): array
    { if($status!==null&&!in_array($status,MarketRules::STATUSES,true))MarketRules::invalid();MarketRules::integer($limit,1,50);$scope=['project'=>$this->store->header()['projectId'],'kind'=>'admin','status'=>$status];$c=MarketRules::unCursor($cursor);if($c!==null){$c=MarketRules::fields($c,['scope','created','id']);if($c['scope']!==$scope)MarketRules::invalid();MarketRules::integer($c['created']);MarketRules::uuid($c['id']);}$rows=$this->store->adminPage($status,$c,$limit);$page=array_slice($rows,0,$limit);$out=[];foreach($page as$r){$v=$this->store->get($r['id']);if($v!==null)$out[]=$this->view($v);}$last=$page?end($page):null;return['listings'=>$out,'nextCursor'=>count($rows)>$limit?MarketRules::cursor(['scope'=>$scope,'created'=>(int)$last['created_millis'],'id'=>$last['id']]):null,'serverTime'=>MarketRules::instant($this->now())]; }
    public function moderation(string $user): array
    { $this->actors([MarketRules::uuid($user)]);$p=$this->store->player($user);return['userId'=>$user,'revision'=>$p['revision'],'banUntil'=>MarketRules::instant($p['banUntil']),'reason'=>$p['reason'],'serverTime'=>MarketRules::instant($this->now())]; }
    public function moderate(string $user,int $expected,$raw): array
    { $user=MarketRules::uuid($user);MarketRules::integer($expected);$r=MarketRules::fields($raw,['banUntil','reason']);$until=$r['banUntil']===null?null:MarketRules::millis($r['banUntil']);$note=MarketRules::note($r['reason'],$until!==null);if($until!==null&&($until<=$this->now()||$until>$this->now()+MarketRules::RETENTION))MarketRules::invalid();return$this->atomic(function()use($user,$expected,$until,$note){$this->actors([$user],true);$p=$this->store->player($user,true);MarketRules::expected($p['revision'],$expected);$next=$p;$next['banUntil']=$until;$next['reason']=$note;$this->store->savePlayer($user,$p,$next);return$this->moderation($user);}); }
}
