<?php

declare(strict_types=1);
require_once __DIR__ . '/ItemService.php';
require_once __DIR__ . '/ItemJson.php';

class ItemController
{
    private static function action(Request $request,bool $write,callable $work): void
    {
        try {
            $body=$write ? ItemJson::decode($request->rawBody()) : null;
            $user=ItemRules::uuid($write ? ($body->userId ?? null) : $request->query('userId')); Auth::requirePlayerSession($request,$user);
            Response::json($work(new ItemService(Database::connect()),$user,$body));
        } catch (ItemFailure $failure) { Response::json(['code'=>$failure->errorCode,'message'=>$failure->getMessage()],$failure->status); }
    }
    private static function query(Request $request,string $key): ?string
    {
        $value=$request->query($key); if ($request->hasQuery($key) && $value===null) ItemRules::invalid(); return $value;
    }
    private static function queryInteger(?string $value,int $default,int $min=0,int $max=ItemRules::MAX): int
    {
        if ($value===null) return $default;
        if (!preg_match('/^(0|[1-9][0-9]{0,15})$/D',$value) || strlen($value)>strlen((string)$max) || (strlen($value)===strlen((string)$max) && strcmp($value,(string)$max)>0)) ItemRules::invalid();
        return ItemRules::integer((int)$value,$min,$max);
    }
    public static function get(Request $request): void
    { self::action($request,false,static function ($service,$user) { return $service->get($user); }); }
    public static function catalog(Request $request): void
    {
        self::action($request,false,static function ($service,$user) use ($request) {
            $revision=self::query($request,'catalogRevision'); return $service->catalog($user,self::query($request,'afterKey'),self::queryInteger(self::query($request,'limit'),50,1,50),$revision===null ? null : self::queryInteger($revision,0));
        });
    }
    public static function adjust(Request $request): void
    {
        self::action($request,true,static function ($service,$user,$body) {
            $body=ItemRules::fields($body,['userId','instanceId','expectedRevision','changes']);
            return $service->adjust($user,ItemRules::uuid($body['instanceId'] ?? null),$body['expectedRevision'] ?? null,$body['changes'] ?? null);
        });
    }
    public static function equip(Request $request): void
    {
        self::action($request,true,static function ($service,$user,$body) {
            $body=ItemRules::fields($body,['userId','instanceId','expectedRevision','equipped']);
            return $service->equip($user,ItemRules::uuid($body['instanceId'] ?? null),$body['expectedRevision'] ?? null,$body['equipped'] ?? null);
        });
    }
    public static function discard(Request $request): void
    {
        self::action($request,true,static function ($service,$user,$body) {
            $body=ItemRules::fields($body,['userId','instanceId','expectedRevision']);
            return $service->discard($user,ItemRules::uuid($body['instanceId'] ?? null),$body['expectedRevision'] ?? null);
        });
    }
}
