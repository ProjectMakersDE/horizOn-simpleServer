<?php

declare(strict_types=1);
require_once __DIR__.'/GiftService.php';

/** The six hosted app routes under /api/v1/app/item-gifts. Claiming stays the existing Inbox claim. */
class GiftController
{
    private static function query(Request $r,string $key): ?string
    { $v=$r->query($key);if($v===null&&$r->hasQuery($key))GiftRules::invalid();return $v; }
    private static function call(Request $r,bool $write,callable $work,bool $created=false): void
    {
        try {
            $raw=$write?GiftRules::decode($r->rawBody()):null;$user=GiftRules::uuid($write?($raw->userId??null):self::query($r,'userId'));
            Auth::requirePlayerSession($r,$user);$result=$work(new GiftService(Database::connect()),$user,$raw);
            Response::json($result,$created&&!($result['replayed']??false)?201:200);
        } catch(GiftFailure $e) {
            if($e->retryAfter!==null)header('Retry-After: '.$e->retryAfter);
            Response::json(['code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status);
        } catch(PlayerValuesFailure $e) { Response::json(['code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status); }
        catch(InboxFailure $e) { Response::json(['code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status); }
    }
    public static function config(Request $r): void { self::call($r,false,static function($s,$u){return $s->config($u);}); }
    public static function state(Request $r): void { self::call($r,false,static function($s,$u){return $s->state($u);}); }
    public static function send(Request $r): void { self::call($r,true,static function($s,$u,$raw){return $s->send($u,$raw);},true); }
    public static function operation(Request $r): void { self::call($r,false,static function($s,$u)use($r){return $s->operation($u,GiftRules::uuid($r->param('operationId')));}); }
    public static function get(Request $r): void { self::call($r,false,static function($s,$u)use($r){return $s->get($u,GiftRules::uuid($r->param('giftId')));}); }
    public static function decline(Request $r): void { self::call($r,true,static function($s,$u,$raw)use($r){return $s->decline($u,GiftRules::uuid($r->param('giftId')),$raw);}); }
}
