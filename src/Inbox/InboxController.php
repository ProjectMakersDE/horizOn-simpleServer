<?php

declare(strict_types=1);
require_once __DIR__ . '/InboxService.php';

class InboxController
{
    private static function authorized(Request $request, bool $body, callable $action): void
    {
        try {
            $data = $body ? $request->body() : ['userId' => $request->query('userId'), 'since' => $request->query('since')];
            if (!is_array($data)) InboxRules::invalid('A JSON object is required');
            $user = InboxRules::uuid($data['userId'] ?? null); Auth::requirePlayerSession($request, $user);
            Response::json($action(new InboxService(Database::connect()), $user, $data));
        } catch (InboxFailure $failure) { Response::json(['error' => true, 'code' => $failure->errorCode, 'message' => $failure->getMessage()], $failure->status); }
        catch (PlayerValuesFailure $failure) { Response::json(['error' => true, 'code' => $failure->errorCode, 'message' => $failure->getMessage()], $failure->status); }
    }
    public static function get(Request $request): void
    {
        self::authorized($request, false, static function ($service, $user, $body) { return $service->get($user, $body['since']); });
    }
    public static function read(Request $request): void
    {
        self::authorized($request, true, static function ($service, $user, $body) { return $service->markRead($user, InboxRules::entry($body['entryId'] ?? null)); });
    }
    public static function claim(Request $request): void
    {
        self::authorized($request, true, static function ($service, $user, $body) { return $service->claim($user, InboxRules::entry($body['entryId'] ?? null)); });
    }
    public static function remove(Request $request): void
    {
        self::authorized($request, true, static function ($service, $user, $body) { return $service->remove($user, InboxRules::entry($body['entryId'] ?? null)); });
    }
}
