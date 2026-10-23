<?php

declare(strict_types=1);
require_once __DIR__ . '/PlayerValuesService.php';

class PlayerValuesController
{
    private static function userId($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
            PlayerValuesLedger::fail('INVALID_REQUEST', 'userId must be a UUID', 400);
        }
        return strtolower($value);
    }

    private static function authorized(Request $request, bool $body, callable $action): void
    {
        try {
            $data = $body ? $request->body() : ['userId' => $request->query('userId')];
            if (!is_array($data)) PlayerValuesLedger::fail('INVALID_REQUEST', 'A JSON object is required', 400);
            $userId = self::userId($data['userId'] ?? null);
            Auth::requirePlayerSession($request, $userId);
            Response::json($action(new PlayerValuesService(Database::connect()), $userId, $data));
        } catch (PlayerValuesFailure $failure) {
            $response = ['error' => true, 'code' => $failure->errorCode, 'message' => $failure->getMessage()];
            if ($failure->state !== null) $response['state'] = $failure->state;
            Response::json($response, $failure->status);
        }
    }

    public static function get(Request $request): void
    {
        self::authorized($request, false, static function ($service, $userId) { return $service->get($userId); });
    }

    public static function offers(Request $request): void
    {
        self::authorized($request, false, static function ($service, $userId) { return $service->offers($userId); });
    }

    public static function purchase(Request $request): void
    {
        self::authorized($request, true, static function ($service, $userId, $body) {
            $operation = PlayerValuesLedger::text($body['operationId'] ?? null, 128, 'operationId');
            return $service->purchase($userId, PlayerValuesLedger::key($body['offerKey'] ?? null), $operation);
        });
    }

    public static function adjust(Request $request): void
    {
        self::authorized($request, true, static function ($service, $userId, $body) {
            $operation = PlayerValuesLedger::text($body['operationId'] ?? null, 128, 'operationId');
            return $service->adjust($userId, PlayerValuesLedger::key($body['key'] ?? null),
                PlayerValuesLedger::integer($body['amount'] ?? null, -PlayerValuesLedger::MAX),
                PlayerValuesLedger::text($body['reason'] ?? null, 200, 'reason'), $operation);
        });
    }
}
