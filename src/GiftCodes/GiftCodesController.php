<?php

declare(strict_types=1);
require_once __DIR__ . '/GiftCodesService.php';

class GiftCodesController
{
    private static function owner(Request $request): array
    {
        $code=$request->body('code','');$user=$request->body('userId','');
        if(!is_string($code)||!is_string($user)||$code===''||strlen($code)>50||$user==='')GiftCodeProtocol::fail('INVALID_REQUEST','code and userId are required',400);
        return [$code,$user];
    }
    private static function error(Throwable $failure): void
    {
        if($failure instanceof GiftCodesFailure)Response::json(['error'=>true,'message'=>$failure->getMessage(),'code'=>$failure->errorCode],$failure->httpStatus);
        if($failure instanceof PlayerValuesFailure){$body=['error'=>true,'message'=>$failure->getMessage(),'code'=>$failure->errorCode];if($failure->state!==null)$body['state']=$failure->state;Response::json($body,$failure->status);}
        throw $failure;
    }
    public static function validate(Request $request): void
    {
        try{
            [$code,$user]=self::owner($request);$include=$request->body('includeIntent');
            if($include!==null&&!is_bool($include))GiftCodeProtocol::fail('INVALID_REQUEST','includeIntent must be boolean',400);
            $service=new GiftCodesService(Database::connect());
            if($include===true){Auth::requirePlayerSession($request,$user);Response::json($service->prepare($code,$user));}
            Response::json(['valid'=>$service->validate($code,$user)]);
        }catch(Throwable $failure){self::error($failure);}
    }
    public static function redeem(Request $request): void
    {
        try{
            [$code,$user]=self::owner($request);Auth::requirePlayerSession($request,$user);
            Response::json((new GiftCodesService(Database::connect()))->redeemPrepared($code,$user,$request->body()??[]));
        }catch(Throwable $failure){self::error($failure);}
    }
}
