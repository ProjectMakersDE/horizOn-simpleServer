<?php

declare(strict_types=1);
require_once __DIR__ . '/GiftCodesService.php';

class GiftCodesController
{
    public static function validate(Request $request): void
    {
        $code = $request->body('code', ''); $user = $request->body('userId', '');
        if (!is_string($code) || !is_string($user) || $code === '' || $user === '') Response::badRequest('code and userId are required');
        Response::json(['valid' => (new GiftCodesService(Database::connect()))->validate($code, $user)]);
    }
    public static function redeem(Request $request): void
    {
        $code = $request->body('code', ''); $user = $request->body('userId', '');
        if (!is_string($code) || !is_string($user) || $code === '' || $user === '') Response::badRequest('code and userId are required');
        Auth::requirePlayerSession($request, $user);
        try {
            Response::json((new GiftCodesService(Database::connect()))->redeem($code, $user, $request->body('idempotencyId')));
        } catch (GiftCodesFailure $failure) {
            if ($failure->httpStatus === 200) Response::json(['success' => false, 'message' => $failure->getMessage(), 'giftData' => null,
                'grantedUnlocks' => [], 'grantedValues' => [], 'playerValues' => null, 'replayed' => false]);
            Response::json(['error' => true, 'message' => $failure->getMessage(), 'code' => $failure->errorCode,
                'grantedUnlocks' => [], 'grantedValues' => [], 'playerValues' => null], $failure->httpStatus);
        } catch (PlayerValuesFailure $failure) {
            $response = ['error' => true, 'message' => $failure->getMessage(), 'code' => $failure->errorCode];
            if ($failure->state !== null) $response['state'] = $failure->state;
            Response::json($response, $failure->status);
        }
    }
}
