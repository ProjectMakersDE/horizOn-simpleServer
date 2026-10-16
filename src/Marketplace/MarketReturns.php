<?php

declare(strict_types=1);

/** Durable original-item handoff; source preparation and Inbox delivery commit separately. */
trait MarketReturns
{
    private function prepareReturn(array $v,?string $actor=null,?string $note=null,bool $recordCancel=true): array
    {
        $next=$v;$next['revision']=MarketRules::next($v['revision']);$next['status']='return_pending';
        $next['returnEntryId']=null;$next['returnOperation']='market:return:'.Database::uuid();$next['returnIssued']=$this->now();$next['dueAt']=$this->now();
        if($recordCancel){$next['audit']['cancelActor']=$actor;$next['audit']['cancelNote']=$note;}return$next;
    }
    public function cancel(string $user,string $id,$raw): array
    {
        $user=MarketRules::uuid($user);$id=MarketRules::uuid($id);$r=$this->input($user,'cancel:'.$id,$raw,['expectedListingRevision']);MarketRules::integer($r['expectedListingRevision']);
        $result=$this->atomic(function()use($user,$id,$r){$this->actors([$user],true);$p=$this->store->player($user,true);$old=MarketRules::receipt($p,$r['operationId'],$r['_hash']);if($old!==null)return$this->envelope($old,true);MarketRules::window($p,$r['_issued'],$this->now());$v=$this->listing($id,true);if($v['sellerId']!==$user)MarketRules::fail('LISTING_NOT_FOUND',404);MarketRules::expected($v['revision'],$r['expectedListingRevision']);if($v['status']!=='active')MarketRules::fail('LISTING_UNAVAILABLE');$next=$this->prepareReturn($v);$receipt=$this->receipt('cancel',$r,$next);$this->store->save($v,$next);$this->store->savePlayer($user,$p,MarketRules::record($p,$receipt,$r['_hash'],$r['_issued'],$this->now(),$this->cap['receiptsPerPlayer']));return$this->envelope($receipt,false);});
        return$result;
    }
    public function adminCancel(string $id,int $expected,string $note): array
    {
        $id=MarketRules::uuid($id);MarketRules::integer($expected);MarketRules::note($note);
        $this->atomic(function()use($id,$expected,$note){$v=$this->listing($id,true);MarketRules::expected($v['revision'],$expected);if($v['status']!=='active')MarketRules::fail('LISTING_UNAVAILABLE');$this->store->save($v,$this->prepareReturn($v,'local-operator',$note));});
        // Admin cancel records a source only; retry-return/maintenance performs the handoff.
        $view=$this->get(null,$id);unset($view['audit']);return$view;
    }
    private function returnContent(array $v): array
    { return ['type'=>'marketplace','reference'=>$v['id'],'textKey'=>'marketplace.item_return','parameters'=>(object)[],
        'expiresAt'=>MarketRules::instant($v['returnIssued']+MarketRules::RETENTION),'attachment'=>['values'=>[],'itemReturn'=>['listingId'=>$v['id']]+$v['itemReference']]]; }
    public function retryReturn(?string $user,string $id): array
    {
        $id=MarketRules::uuid($id);if($user!==null)MarketRules::uuid($user);
        $prepared=$this->atomic(function()use($user,$id){$this->store->header(true);if($user!==null)$this->actors([$user],true);$v=$this->listing($id,true);if($user!==null&&$v['returnOwnerId']!==$user)MarketRules::fail('LISTING_NOT_FOUND',404);
            if($v['status']==='active'){if($v['expiresAt']>$this->now())MarketRules::fail('LISTING_UNAVAILABLE');$next=$this->prepareReturn($v);$this->store->save($v,$next);return$next;}if($v['status']==='sold')MarketRules::fail('LISTING_UNAVAILABLE');return$v;});
        $delivery=$prepared['status']==='return_pending'&&!$prepared['ownerUnavailable']?$this->deliverReturn($id,$user):($prepared['status']==='returned'?'returned':($prepared['status']==='return_delivered'?'delivered':'pending'));
        $v=$this->listing($id);if($user!==null&&$v['returnOwnerId']!==$user)MarketRules::fail('LISTING_NOT_FOUND',404);return['listing'=>$this->view($v),'delivery'=>$delivery,'serverTime'=>MarketRules::instant($this->now())];
    }
    private function deliverReturn(string $id,?string $expectedUser=null): string
    {
        try{return$this->atomic(function()use($id,$expectedUser){$this->store->header(true);$v=$this->listing($id,true);if($expectedUser!==null&&$v['returnOwnerId']!==$expectedUser)MarketRules::fail('LISTING_NOT_FOUND',404);
            if($v['status']==='returned')return'returned';if($v['status']==='return_delivered')return'delivered';if($v['status']!=='return_pending')MarketRules::fail('LISTING_UNAVAILABLE');
            if($v['ownerUnavailable'])return'pending';$this->actors([$v['returnOwnerId']],true);
            $this->inbox->deliverItemReturnPending($v['returnOwnerId'],$this->returnContent($v),$v['returnOperation'],$v['returnIssued'],'marketplace:'.$id,
                function(PDO $connection,string $entry)use($v){if($connection!==$this->pdo)throw new LogicException('Foreign return PDO');$current=$this->listing($v['id'],true);
                    if($current['revision']!==$v['revision']||$current['status']!=='return_pending'||$current['returnOwnerId']!==$v['returnOwnerId']||$current['returnOperation']!==$v['returnOperation'])return false;
                    $owner=$this->store->player($current['returnOwnerId'],true);if(!in_array($current['id'],$owner['openIds'],true))MarketRules::fail('STATE_CONFLICT');$this->store->savePlayer($current['returnOwnerId'],$owner,$owner);$next=$current;$next['revision']=MarketRules::next($current['revision']);$next['status']='return_delivered';$next['returnEntryId']=$entry;$next['dueAt']=MarketRules::MAX;$this->store->save($current,$next);return true;});return'delivered';});
        }catch(InboxFailure $e){if($e->errorCode!=='INBOX_FULL')throw$e;
            $this->atomic(function()use($id){$v=$this->listing($id,true);if($v['status']==='return_pending'){$next=$v;$next['revision']=MarketRules::next($v['revision']);$next['dueAt']=max($v['dueAt'],$this->now()+1800000);$this->store->save($v,$next);}});return'pending';}
    }
    /** Only Inbox claim, inside its exact shared SQL scope; original reception has no new grant ID. */
    public function claimReturn(string $user,string $entry,array $attachment): array
    {
        if(!$this->transactions->isRunning())throw new LogicException('Return claim requires Inbox SQL scope');
        return$this->atomic(function()use($user,$entry,$attachment){$h=$this->store->header(true);$this->actors([$user],true);$v=$this->listing(MarketRules::uuid($attachment['listingId']),true);
            if($v['returnOwnerId']!==$user||$v['returnEntryId']!==$entry||$v['status']!=='return_delivered'||$v['ownerUnavailable']||$v['itemReference']['instanceId']!==$attachment['instanceId']||$v['itemReference']['itemKey']!==$attachment['itemKey'])MarketRules::fail('STATE_CONFLICT');
            $p=$this->store->player($user,true);if(!in_array($v['id'],$p['openIds'],true))MarketRules::fail('STATE_CONFLICT');$reference=$this->items->escrowReceive($user,$v['item']);$next=$v;$next['revision']=MarketRules::next($v['revision']);$next['status']='returned';$next['item']=null;$next['terminalAt']=$this->now();$next['dueAt']=$this->now()+MarketRules::TERMINAL;$this->store->save($v,$next);$this->store->savePlayer($user,$p,$this->history($p,$v['id']));$nextH=$h;$nextH['escrowCount']--;if($nextH['escrowCount']<0)MarketRules::fail('STATE_CONFLICT');$this->store->saveHeader($h,$nextH);return[$reference];});
    }
    public function recover(string $id,int $expected,string $recipient,string $note): array
    {
        $id=MarketRules::uuid($id);$recipient=MarketRules::uuid($recipient);MarketRules::integer($expected);MarketRules::note($note);
        $this->atomic(function()use($id,$expected,$recipient,$note){$this->store->header(true);$v=$this->listing($id,true);MarketRules::expected($v['revision'],$expected);
            if(in_array($v['status'],['sold','returned'],true)||$this->store->users([$v['returnOwnerId']],true))MarketRules::fail('RECOVERY_NOT_ALLOWED',422);if(count($v['audit']['recoveries'])>=8)MarketRules::fail('RECOVERY_LIMIT_REACHED');
            $this->actors([$recipient],true);$p=$this->store->player($recipient,true);if(count($p['openIds'])>=$this->cap['openListingsPerPlayer'])MarketRules::fail('OPEN_LISTING_LIMIT_REACHED',422);$old=$this->store->player($v['returnOwnerId'],true);
            $next=$this->prepareReturn($v,null,null,false);$next['returnOwnerId']=$recipient;$next['ownerUnavailable']=false;$next['audit']['recoveries'][]=['previousOwnerId'=>$v['returnOwnerId'],'recipientId'=>$recipient,'actor'=>'local-operator','at'=>MarketRules::instant($this->now()),'note'=>$note];
            $oldNext=$old;$oldNext['openIds']=array_values(array_diff($old['openIds'],[$id]));if($old['exists'])$this->store->savePlayer($v['returnOwnerId'],$old,$oldNext);$newP=$p;$newP['openIds'][]=$id;$this->store->savePlayer($recipient,$p,$newP);$this->store->save($v,$next);});$view=$this->get(null,$id);unset($view['audit']);return$view;
    }
    /** At most20 indexed owned rows. The durable nullable-user anchor survives ordinary deletion. */
    public function cleanupDeletedPlayer(string $user,?string $after=null): array
    {
        $user=MarketRules::uuid($user);if($after!==null)MarketRules::uuid($after);$anchor=$this->store->player($user);if($anchor['deletionComplete'])return['userId'=>$user,'updated'=>0,'nextCursor'=>null,'complete'=>true];$rows=$this->store->owned($user,$after??$anchor['deleteAfter']);$updated=0;
        foreach($rows as$row)$updated+=$this->atomic(function()use($user,$row){if($this->store->users([$user],true))return 0;$v=$this->store->get($row['id'],true);if($v===null||$v['returnOwnerId']!==$user||in_array($v['status'],['sold','returned'],true)||$v['ownerUnavailable'])return 0;$next=$this->prepareReturn($v,null,null,false);$next['ownerUnavailable']=true;$next['dueAt']=MarketRules::MAX;$this->store->save($v,$next);return 1;});
        $nextAfter=count($rows)===20?$rows[count($rows)-1]['id']:null;$complete=count($rows)<20;
        $this->atomic(function()use($user,$anchor,$nextAfter,$complete){if($this->store->users([$user],true))return;$p=$this->store->player($user,true);MarketRules::expected($p['revision'],$anchor['revision']);if(!$p['exists'])return;
            // Identity ended: after every source was marked, discard only its receipt/PII-free ledger.
            // Valuable listings retain original owner UUID/item/audit independently for recovery.
            if($complete){$this->store->removePlayer($user,$p['revision']);return;}
            $next=$p;$next['deleteAfter']=$nextAfter;$next['deletionComplete']=false;$this->store->savePlayer($user,$p,$next);});
        return['userId'=>$user,'updated'=>$updated,'nextCursor'=>$nextAfter,'complete'=>$complete];
    }
    public function cleanupDeleted(?string $after=null): array
    { if($after!==null)MarketRules::uuid($after);$rows=$this->store->deleted($after);$out=[];foreach(array_slice($rows,0,10)as$row)$out[]=$this->cleanupDeletedPlayer($row['user_id']);return['players'=>$out,'nextCursor'=>count($rows)>10?$rows[9]['user_id']:null]; }
    public function cleanup(string $namespace='admin'): array
    {
        if(!in_array($namespace,['admin','scheduled'],true))MarketRules::invalid();$rotation=$this->store->maintenance($namespace.':rotation',$this->now());$first=$rotation['first'];$summary=['examined'=>0,'returnsPrepared'=>0,'returnsDelivered'=>0,'terminalRemoved'=>0,'deferred'=>0];
        $unused=0;$firstFull=false;
        for($pass=0;$pass<2||($pass===2&&$firstFull&&$unused>0);$pass++){$kind=$pass===1?1-$first:$first;$budget=$pass===0?10:($pass===1?10+$unused:$unused);$key=$namespace.':'.$kind;$cursor=$this->store->maintenance($key,$this->now());$rows=$this->store->due($kind,$cursor,$budget);if($pass===0)$firstFull=count($rows)===$budget;$unused=0;
            foreach($rows as$row){$summary['examined']++;$id=$row['id'];try{
                if($kind===1){$removed=$this->atomic(function()use($id){$h=$this->store->header(true);$v=$this->store->get($id,true);if($v===null||!in_array($v['status'],['sold','returned'],true)||$v['terminalAt']+MarketRules::TERMINAL>$this->now())return false;$this->store->remove($v);$next=$h;$next['retainedCount']--;if($next['retainedCount']<0)MarketRules::fail('STATE_CONFLICT');$this->store->saveHeader($h,$next);return true;});if($removed)$summary['terminalRemoved']++;}
                else{$prepared=$this->atomic(function()use($id){$v=$this->store->get($id,true);if($v===null)return false;if($v['status']==='active'&&$v['expiresAt']<=$this->now()){$next=$this->prepareReturn($v);$this->store->save($v,$next);return true;}return false;});if($prepared)$summary['returnsPrepared']++;$v=$this->store->get($id);if($v!==null&&$v['status']==='return_pending'){$state=$this->deliverReturn($id);if($state==='delivered')$summary['returnsDelivered']++;else$summary['deferred']++;}}
            }catch(Throwable $e){$summary['deferred']++;}
                // Always advance after a full inbox, race or per-row failure. Persisted cutoff stays fixed.
                $next=$cursor;$next['after']=['due'=>(int)$row['due_millis'],'id'=>$id];$this->atomic(function()use($key,&$cursor,$next){$current=$this->store->maintenance($key,$this->now(),true);MarketRules::expected($current['revision'],$cursor['revision']);$this->store->saveMaintenance($key,$current,$next);$cursor=$this->store->maintenance($key,$this->now(),true);});
            }
            if(count($rows)<$budget){$this->atomic(function()use($key,$cursor){$current=$this->store->maintenance($key,$this->now(),true);MarketRules::expected($current['revision'],$cursor['revision']);$next=$current;$next['cutoff']=$this->now();$next['after']=null;$this->store->saveMaintenance($key,$current,$next);});$unused=max(0,$budget-count($rows));}
        }
        $this->atomic(function()use($namespace,$rotation,$first){$key=$namespace.':rotation';$current=$this->store->maintenance($key,$this->now(),true);MarketRules::expected($current['revision'],$rotation['revision']);$next=$current;$next['first']=1-$first;$this->store->saveMaintenance($key,$current,$next);});$summary['serverTime']=MarketRules::instant($this->now());return$summary;
    }
}
