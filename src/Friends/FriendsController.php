<?php

declare(strict_types=1);
require_once __DIR__ . '/FriendsService.php';

class FriendsController
{
    private static function action(callable $work): void
    {
        try { Response::json($work(new FriendsService(Database::connect()))); }
        catch (FriendsFailure $failure) { Response::json(['code' => $failure->errorCode, 'message' => $failure->getMessage()], $failure->status); }
        catch (InboxFailure $failure) { Response::json(['code' => $failure->errorCode, 'message' => $failure->getMessage()], $failure->status); }
    }
    private static function user(Request $request, bool $query = false): string
    {
        $user = FriendsRules::uuid($query ? $request->query('userId') : $request->body('userId'));
        Auth::requirePlayerSession($request, $user); return $user;
    }
    public static function get(Request $request): void { self::action(static function ($service) use ($request) { return $service->get(self::user($request, true)); }); }
    public static function find(Request $request): void { self::action(static function ($service) use ($request) { return $service->find(self::user($request, true), $request->query('query')); }); }
    public static function change(Request $request, string $action): void
    {
        self::action(static function ($service) use ($request, $action) {
            return $service->change($action, self::user($request), FriendsRules::uuid($request->body('targetUserId')), $request->body('expectedRevision'), $request->body('requestId'));
        });
    }
}
