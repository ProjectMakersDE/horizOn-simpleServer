<?php

declare(strict_types=1);
require_once __DIR__ . '/StatisticsService.php';
require_once __DIR__ . '/../Achievements/AchievementService.php';

class StatisticsController
{
    private static function version(Request $request): ?int
    {
        $raw = $request->query('definitionVersion');
        if ($raw === null) return null;
        if (!preg_match('/^[0-9]{1,16}$/D', $raw) || (int)$raw > StatisticsRules::MAX) StatisticsRules::fail('INVALID_REQUEST', 'Invalid definitionVersion');
        return (int)$raw;
    }
    private static function action(callable $action): void
    {
        try { Response::json($action(new StatisticsService(Database::connect()))); }
        catch (StatisticsFailure $failure) { Response::json(['error' => true, 'code' => $failure->errorCode, 'message' => $failure->getMessage()], $failure->status); }
        catch (AchievementFailure $failure) { Response::json(['error' => true, 'code' => $failure->errorCode, 'message' => $failure->getMessage()], $failure->status); }
    }
    private static function user($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) StatisticsRules::fail('INVALID_REQUEST', 'userId must be a UUID');
        return strtolower($value);
    }
    public static function get(Request $request): void
    {
        self::action(static function ($service) use ($request) {
            $user = self::user($request->query('userId')); Auth::requirePlayerSession($request, $user);
            return $service->get($user, self::version($request));
        });
    }
    public static function definitions(Request $request): void
    {
        self::action(static function ($service) use ($request) {
            Auth::requirePlayerSessionUser($request);
            return $service->definitions(self::version($request));
        });
    }
    public static function update(Request $request): void
    {
        self::action(static function ($service) use ($request) {
            $body = $request->body(); if (!is_array($body)) StatisticsRules::fail('INVALID_REQUEST', 'A JSON object is required');
            $user = self::user($body['userId'] ?? null); Auth::requirePlayerSession($request, $user);
            $operation = $body['operationId'] ?? null; StatisticsRules::operation($operation);
            return (new AchievementService(Database::connect()))->report($user, $operation, StatisticsRules::updates($body['updates'] ?? null));
        });
    }
}
