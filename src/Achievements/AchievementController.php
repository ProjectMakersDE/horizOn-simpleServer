<?php

declare(strict_types=1);
require_once __DIR__ . '/AchievementService.php';

class AchievementController
{
    private static function action(callable $work):void
    {
        try{Response::json($work(new AchievementService(Database::connect())));}
        catch(AchievementFailure $failure){Response::json(['code'=>$failure->errorCode,'message'=>$failure->getMessage()],$failure->status);}
        catch(InboxFailure $failure){Response::json(['code'=>$failure->errorCode,'message'=>$failure->getMessage()],$failure->status);}
        catch(PlayerValuesFailure $failure){Response::json(['code'=>$failure->errorCode,'message'=>$failure->getMessage()],$failure->status);}
    }
    private static function user(Request $request,bool $query=false):string
    {
        $user=InboxRules::uuid($query?$request->query('userId'):$request->body('userId'));
        Auth::requirePlayerSession($request,$user);return $user;
    }
    public static function get(Request $request):void
    { self::action(static function($service)use($request){$known=$request->query('definitionVersion');if($known!==null&&!is_string($known))AchievementRules::invalid('Invalid definitionVersion');return $service->get(self::user($request,true),$known);}); }
    public static function unlock(Request $request):void
    { self::action(static function($service)use($request){return $service->unlock(self::user($request),AchievementRules::key($request->body('key')));}); }
    public static function deliver(Request $request):void
    { self::action(static function($service)use($request){return $service->deliver(self::user($request),$request->body('keys'));}); }
}
