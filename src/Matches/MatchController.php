<?php

declare(strict_types=1);
require_once __DIR__.'/MatchService.php';

class MatchController
{
    private static function query(Request $request,string $name): ?string
    {
        $value=$request->query($name);
        if($value===null&&$request->hasQuery($name))MatchRules::invalid();
        return$value;
    }
    private static function call(Request $request,bool $body,callable $work): void
    {
        try {
            $input=$body?MatchRules::decode($request->rawBody()):null;
            $user=MatchRules::uuid($body?($input->userId??null):self::query($request,'userId'));
            Auth::requirePlayerSession($request,$user);
            Response::json($work(new MatchService(Database::connect()),$user,$input));
        } catch(MatchFailure $e) {
            Response::json(['code'=>$e->errorCode,'message'=>$e->getMessage()],$e->status);
        }
    }
    public static function mine(Request $r): void{self::call($r,false,static function($s,$u){return$s->mine($u);});}
    public static function limits(Request $r): void{self::call($r,false,static function($s,$u){return$s->limits($u);});}
    public static function open(Request $r): void
    {
        self::call($r,false,static function($s,$u)use($r){
            $limit=self::query($r,'limit');
            return$s->open($u,MatchRules::signed(self::query($r,'minStrength')),MatchRules::signed(self::query($r,'maxStrength')),$limit===null?20:MatchRules::revision($limit),self::query($r,'cursor'));
        });
    }
    public static function get(Request $r): void{self::call($r,false,static function($s,$u)use($r){return$s->get($u,MatchRules::uuid($r->param('id')));});}
    public static function create(Request $r): void{self::call($r,true,static function($s,$u,$input)use($r){return$s->create($u,MatchRules::revision($r->header('If-Match')),$input);});}
    public static function action(Request $r): void{self::call($r,true,static function($s,$u,$input)use($r){return$s->action($u,MatchRules::uuid($r->param('id')),$r->param('action'),MatchRules::revision($r->header('If-Match')),$input);});}
    public static function result(Request $r): void{self::call($r,true,static function($s,$u,$input)use($r){return$s->report($u,MatchRules::uuid($r->param('id')),$input);});}
    public static function rankedRun(Request $r): void{self::call($r,true,static function($s,$u,$input)use($r){return$s->rankedRun($u,MatchRules::uuid($r->param('id')),$input);});}
}
