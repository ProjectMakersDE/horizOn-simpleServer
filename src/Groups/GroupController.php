<?php

declare(strict_types=1);
require_once __DIR__ . '/GroupService.php';

class GroupController
{
    private static function action(callable $work): void
    {
        try { Response::json($work(new GroupService(Database::connect()))); }
        catch (GroupFailure $failure) { Response::json(['code' => $failure->errorCode,'message' => $failure->getMessage()],$failure->status); }
        catch (InboxFailure $failure) { Response::json(['code' => $failure->errorCode,'message' => $failure->getMessage()],$failure->status); }
    }
    private static function user(Request $request, bool $query = false): string
    {
        $user = GroupRules::uuid($query ? $request->query('userId') : $request->body('userId'),'INVALID_GROUP');
        Auth::requirePlayerSession($request,$user); return $user;
    }
    private static function limit(Request $request): int
    {
        $value = $request->query('limit','50'); if (!preg_match('/^[0-9]+$/D',$value)) GroupRules::fail('INVALID_PAGE',400);
        return GroupRules::page((int)$value);
    }
    public static function get(Request $request): void { self::action(static function ($service) use ($request) { return $service->get(self::user($request,true)); }); }
    public static function detail(Request $request): void { self::action(static function ($service) use ($request) { return $service->detail(self::user($request,true),GroupRules::uuid($request->param('groupId'))); }); }
    public static function members(Request $request): void { self::action(static function ($service) use ($request) { return $service->members(self::user($request,true),GroupRules::uuid($request->param('groupId')),$request->query('afterUserId'),self::limit($request)); }); }
    public static function pending(Request $request): void { self::action(static function ($service) use ($request) { return $service->pending(self::user($request,true),GroupRules::uuid($request->param('groupId'))); }); }
    public static function create(Request $request): void
    {
        self::action(static function ($service) use ($request) {
            if (array_key_exists('groupId',$request->body() ?? []) || array_key_exists('id',$request->body() ?? [])) GroupRules::fail('INVALID_GROUP',400);
            return $service->create(self::user($request),$request->body('expectedPlayerRevision'),$request->body('name'),$request->body('description',''),$request->body('joinMode'),$request->objectBody('data'));
        });
    }
    public static function update(Request $request): void
    {
        self::action(static function ($service) use ($request) { return $service->update(self::user($request),GroupRules::uuid($request->param('groupId')),$request->body('expectedRevision'),$request->body('name'),$request->body('description'),$request->body('joinMode'),$request->objectBody('data')); });
    }
    public static function change(Request $request, string $action): void
    {
        self::action(static function ($service) use ($request,$action) { return $service->change($action,self::user($request),GroupRules::uuid($request->param('groupId')),$request->body('expectedRevision'),$request->body('targetUserId'),$request->body('requestId'),$request->body('role')); });
    }
}
