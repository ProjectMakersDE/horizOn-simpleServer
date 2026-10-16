<?php

declare(strict_types=1);
require_once __DIR__.'/SharedObjectService.php';
class SharedObjectController
{
    private static function call(Request $request,bool $body,callable $work): void
    {
        try{$input=$body?SharedObjectRules::decode($request->rawBody()):null;$user=SharedObjectRules::uuid($body?($input->userId??null):$request->query('userId'));Auth::requirePlayerSession($request,$user);
            $collection=$request->param('collection');if($collection!==null)SharedObjectRules::key($collection);Response::json($work(new SharedObjectService(Database::connect()),$user,$collection,$input));
        }catch(SharedObjectFailure $failure){Response::json(['code'=>$failure->errorCode,'message'=>$failure->getMessage()],$failure->status);}
    }
    private static function query(Request $request,string $name): ?string{$v=$request->query($name);if($v===null&&$request->hasQuery($name))SharedObjectRules::invalid();return $v;}
    public static function collections(Request $request): void
    {self::call($request,false,static function($service,$user)use($request){$limit=self::query($request,'limit');$revision=self::query($request,'catalogRevision');return $service->collections($user,self::query($request,'afterKey'),$limit===null?50:SharedObjectRules::revision($limit),$revision===null?null:SharedObjectRules::revision($revision));});}
    public static function usage(Request $request): void{self::call($request,false,static function($service,$user,$collection){return $service->usage($user,$collection,$user);});}
    public static function get(Request $request): void{self::call($request,false,static function($service,$user,$collection)use($request){return $service->get($user,$collection,SharedObjectRules::uuid($request->param('objectId')));});}
    public static function queryObjects(Request $request): void{self::call($request,true,static function($service,$user,$collection,$input){return $service->query($user,$collection,$input);});}
    public static function create(Request $request): void{self::call($request,true,static function($service,$user,$collection,$input)use($request){return $service->create($user,$collection,SharedObjectRules::revision($request->header('If-Match')),$input);});}
    public static function update(Request $request): void{self::call($request,true,static function($service,$user,$collection,$input)use($request){return $service->update($user,$collection,SharedObjectRules::uuid($request->param('objectId')),SharedObjectRules::revision($request->header('If-Match')),$input);});}
    public static function delete(Request $request): void{self::call($request,false,static function($service,$user,$collection)use($request){return $service->delete($user,$collection,SharedObjectRules::uuid($request->param('objectId')),SharedObjectRules::revision($request->header('If-Match')));});}
}
