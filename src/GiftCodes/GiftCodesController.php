<?php

declare(strict_types=1);

class GiftCodesController
{
    /**
     * End of the transition window in which redeem requests WITHOUT a player
     * session (older SDK versions) are still accepted. Override with
     * GIFT_CODE_LEGACY_REDEEM_SUNSET, switch off with GIFT_CODE_LEGACY_REDEEM_ENABLED=false.
     */
    private const DEFAULT_LEGACY_REDEEM_SUNSET = '2027-03-01T00:00:00Z';

    public static function validate(Request $request): void
    {
        $code = $request->body('code', '');
        $userId = $request->body('userId', '');

        if ($code === '' || $userId === '') {
            Response::badRequest('code and userId are required');
            return;
        }

        $valid = self::isCodeValid($code, $userId);

        Response::json(['valid' => $valid]);
    }

    public static function redeem(Request $request): void
    {
        $code = $request->body('code', '');
        $userId = $request->body('userId', '');

        if ($code === '' || $userId === '') {
            Response::badRequest('code and userId are required');
            return;
        }

        // Bind the redemption to the player's session (exits with 401/403 otherwise)
        self::requireRedeemSession($request, (string)$userId);

        $pdo = Database::connect();

        // Find the gift code
        $stmt = $pdo->prepare('SELECT * FROM gift_codes WHERE code = ?');
        $stmt->execute([$code]);
        $giftCode = $stmt->fetch();

        if ($giftCode === false) {
            Response::json([
                'success' => false,
                'message' => 'Gift code not found',
                'giftData' => null,
            ]);
            return;
        }

        // Check expiry
        if ($giftCode['expires_at'] !== null && $giftCode['expires_at'] < Database::now()) {
            Response::json([
                'success' => false,
                'message' => 'Gift code has expired',
                'giftData' => null,
            ]);
            return;
        }

        // Check max redemptions
        if ((int)$giftCode['current_redemptions'] >= (int)$giftCode['max_redemptions']) {
            Response::json([
                'success' => false,
                'message' => 'Gift code has reached maximum redemptions',
                'giftData' => null,
            ]);
            return;
        }

        // Check if user already redeemed
        $stmt = $pdo->prepare('SELECT id FROM gift_code_redemptions WHERE gift_code_id = ? AND user_id = ?');
        $stmt->execute([$giftCode['id'], $userId]);
        if ($stmt->fetch() !== false) {
            Response::json([
                'success' => false,
                'message' => 'You have already redeemed this gift code',
                'giftData' => null,
            ]);
            return;
        }

        // Redeem
        $now = Database::now();
        $stmt = $pdo->prepare('INSERT INTO gift_code_redemptions (id, gift_code_id, user_id, redeemed_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([Database::uuid(), $giftCode['id'], $userId, $now]);

        $stmt = $pdo->prepare('UPDATE gift_codes SET current_redemptions = current_redemptions + 1 WHERE id = ?');
        $stmt->execute([$giftCode['id']]);

        Response::json([
            'success' => true,
            'message' => 'Gift code redeemed successfully',
            'giftData' => $giftCode['reward_data'],
        ]);
    }

    /**
     * A redeem request with an Authorization header must carry a valid, unexpired
     * Bearer session of the body userId (401 for a missing or expired session,
     * 403 for a session of another user). A request without any Authorization
     * header comes from an older SDK and is only accepted during the transition window.
     */
    private static function requireRedeemSession(Request $request, string $userId): void
    {
        $authorization = trim((string)$request->header('authorization', ''));
        if ($authorization === '') {
            self::requireLegacyRedeemWindow($userId);
            return;
        }

        if (stripos($authorization, 'Bearer ') !== 0) {
            header('WWW-Authenticate: Bearer');
            Response::unauthorized('Bearer session required');
        }

        $token = trim(substr($authorization, 7));
        if ($token === '') {
            header('WWW-Authenticate: Bearer');
            Response::unauthorized('Invalid or expired session');
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, session_expires_at FROM users WHERE session_token = ?');
        $stmt->execute([$token]);
        $sessionUser = $stmt->fetch();

        if ($sessionUser === false
            || ($sessionUser['session_expires_at'] !== null && $sessionUser['session_expires_at'] < Database::now())
        ) {
            header('WWW-Authenticate: Bearer');
            Response::unauthorized('Invalid or expired session');
        }

        if (!hash_equals((string)$sessionUser['id'], $userId)) {
            Response::error('Session is not authorized for this redemption', 'FORBIDDEN', 403);
        }
    }

    private static function requireLegacyRedeemWindow(string $userId): void
    {
        $sunset = Config::get('GIFT_CODE_LEGACY_REDEEM_SUNSET', self::DEFAULT_LEGACY_REDEEM_SUNSET);
        $sunsetTimestamp = strtotime($sunset);

        header('Deprecation: true');
        if ($sunsetTimestamp !== false) {
            header('Sunset: ' . gmdate('D, d M Y H:i:s', $sunsetTimestamp) . ' GMT');
        }

        $enabled = Config::getBool('GIFT_CODE_LEGACY_REDEEM_ENABLED', true);
        if (!$enabled || $sunsetTimestamp === false || time() >= $sunsetTimestamp) {
            header('WWW-Authenticate: Bearer');
            Response::unauthorized('Bearer session required');
        }

        error_log('[horizOn] Gift code redeem without player session for user ' . $userId
            . ' (legacy SDK), accepted until ' . $sunset);
    }

    private static function isCodeValid(string $code, string $userId): bool
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare('SELECT * FROM gift_codes WHERE code = ?');
        $stmt->execute([$code]);
        $giftCode = $stmt->fetch();

        if ($giftCode === false) {
            return false;
        }

        // Check expiry
        if ($giftCode['expires_at'] !== null && $giftCode['expires_at'] < Database::now()) {
            return false;
        }

        // Check max redemptions
        if ((int)$giftCode['current_redemptions'] >= (int)$giftCode['max_redemptions']) {
            return false;
        }

        // Check if user already redeemed
        $stmt = $pdo->prepare('SELECT id FROM gift_code_redemptions WHERE gift_code_id = ? AND user_id = ?');
        $stmt->execute([$giftCode['id'], $userId]);
        if ($stmt->fetch() !== false) {
            return false;
        }

        return true;
    }
}
