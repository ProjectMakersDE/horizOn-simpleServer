<?php

declare(strict_types=1);
require_once __DIR__.'/MarketService.php';

class MarketController
{
    private static function query(Request $r,string $key): ?string
    { $v=$r->query($key);if($v===null&&$r->hasQuery($key))MarketRules::invalid();return$v; }
    private static function number(Request $r,string $key,int $default,int $min=0,int $max=MarketRules::MAX): int
    { $v=self::query($r,$key);return$v===null?$default:MarketRules::integer(MarketRules::revision($v),$min,$max); }
    private static function call(Request $r,bool $write,callable $work,bool $created=false): void
    {
        try {
            $raw=$write?MarketRules::decode($r->rawBody()):null;$user=MarketRules::uuid($write?($raw->userId??null):self::query($r,'userId'));
            Auth::requirePlayerSession($r,$user);$result=$work(new MarketService(Database::connect()),$user,$raw);
            Response::json($result,$created&&!($result['replayed']??false)?201:200);
        } catch(MarketFailure $e) {
            if($e->retryAfter!==null)header('Retry-After: '.$e->retryAfter);
            Response::json(['code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status);
        } catch(PlayerValuesFailure $e) { Response::json(['code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status); }
        catch(InboxFailure $e) { Response::json(['code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status); }
    }
    public static function limits(Request $r): void { self::call($r,false,static function($s,$u){return$s->limits($u);}); }
    public static function rules(Request $r): void
    { self::call($r,false,static function($s,$u)use($r){$revision=self::query($r,'catalogRevision');return$s->rules($u,self::query($r,'after'),self::number($r,'limit',20,1,50),$revision===null?null:MarketRules::revision($revision));}); }
    public static function state(Request $r): void { self::call($r,false,static function($s,$u){return$s->state($u);}); }
    public static function search(Request $r): void
    { self::call($r,false,static function($s,$u)use($r){return$s->search($u,self::query($r,'itemKey'),self::number($r,'minPrice',0,0),self::number($r,'maxPrice',MarketRules::MAX,0),self::number($r,'limit',20,1,50),self::query($r,'cursor'));}); }
    public static function get(Request $r): void { self::call($r,false,static function($s,$u)use($r){return$s->get($u,MarketRules::uuid($r->param('id')));}); }
    public static function operation(Request $r): void { self::call($r,false,static function($s,$u)use($r){return$s->operation($u,MarketRules::uuid($r->param('operationId')));}); }
    public static function create(Request $r): void { self::call($r,true,static function($s,$u,$raw){return$s->create($u,$raw);},true); }
    public static function buy(Request $r): void { self::call($r,true,static function($s,$u,$raw)use($r){return$s->buy($u,MarketRules::uuid($r->param('id')),$raw);}); }
    public static function cancel(Request $r): void { self::call($r,true,static function($s,$u,$raw)use($r){return$s->cancel($u,MarketRules::uuid($r->param('id')),$raw);}); }
    public static function retryReturn(Request $r): void
    { self::call($r,true,static function($s,$u,$raw)use($r){MarketRules::fields($raw,['userId']);return$s->retryReturn($u,MarketRules::uuid($r->param('id')));}); }
}
