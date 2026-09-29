<?php

declare(strict_types=1);

class GiftCodesController
{
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

        // Bind the redemption to the player's session: a missing, invalid or expired
        // session exits with 401 SESSION_REQUIRED, a session of another player with
        // 403 SESSION_FORBIDDEN. There is no redemption without a session.
        Auth::requirePlayerSession($request, (string)$userId);

        $pdo = Database::connect();

        // Find the gift code
        $stmt = $pdo->prepare('SELECT * FROM gift_codes WHERE code = ?');
        $stmt->execute([$code]);
        $giftCode = $stmt->fetch();

        if ($giftCode === false) {
            self::failed('Gift code not found');
        }

        // Check expiry
        if ($giftCode['expires_at'] !== null && $giftCode['expires_at'] < Database::now()) {
            self::failed('Gift code has expired');
        }

        // Check max redemptions
        if ((int)$giftCode['current_redemptions'] >= (int)$giftCode['max_redemptions']) {
            self::failed('Gift code has reached maximum redemptions');
        }

        // Check if user already redeemed
        $stmt = $pdo->prepare('SELECT id FROM gift_code_redemptions WHERE gift_code_id = ? AND user_id = ?');
        $stmt->execute([$giftCode['id'], $userId]);
        if ($stmt->fetch() !== false) {
            self::failed('You have already redeemed this gift code');
        }

        // Cosmetic grants go to the session player verified above.
        $grants = PlayerProfileController::parseGrants(
            $giftCode['reward_data'] !== null ? (string)$giftCode['reward_data'] : null
        );

        // Redeem: redemption row, counter and unlocks in one transaction
        $now = Database::now();
        $grantedUnlocks = [];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE gift_codes SET current_redemptions = current_redemptions + 1
                 WHERE id = ? AND current_redemptions < max_redemptions'
            );
            $stmt->execute([$giftCode['id']]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                self::failed('Gift code has reached maximum redemptions');
            }

            $stmt = $pdo->prepare('INSERT INTO gift_code_redemptions (id, gift_code_id, user_id, redeemed_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([Database::uuid(), $giftCode['id'], $userId, $now]);

            if (count($grants) > 0) {
                $grantedUnlocks = self::applyGrants($pdo, (string)$userId, $grants);
                if ($grantedUnlocks === null) {
                    $pdo->rollBack();
                    Response::json([
                        'error' => true,
                        'message' => 'A player can hold at most ' . PlayerProfileController::MAX_UNLOCKS . ' unlocks',
                        'code' => 'UNLOCK_LIMIT_REACHED',
                        'grantedUnlocks' => [],
                    ], 409);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Response::json([
            'success' => true,
            'message' => 'Gift code redeemed successfully',
            'giftData' => $giftCode['reward_data'],
            'grantedUnlocks' => $grantedUnlocks,
        ]);
    }

    /**
     * Merges the grants that exist in the catalog into users.unlocks (no
     * duplicates). Grant IDs no longer in the catalog are skipped. Runs inside
     * the redemption transaction.
     *
     * @return array|null the granted IDs the player owns afterwards, or null
     *   when the result would exceed PlayerProfileController::MAX_UNLOCKS.
     */
    private static function applyGrants(PDO $pdo, string $userId, array $grants): ?array
    {
        $grantable = PlayerProfileController::existingCosmeticIds($pdo, $grants);
        if (count($grantable) < count($grants)) {
            error_log('[horizOn] Gift code grants not in the cosmetics catalog, skipped: '
                . implode(', ', array_diff($grants, $grantable)));
        }
        if (count($grantable) === 0) {
            return [];
        }

        $lock = Config::get('DB_DRIVER', 'sqlite') === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT unlocks FROM users WHERE id = ?' . $lock);
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $unlocks = PlayerProfileController::decodeList($row === false ? null : $row['unlocks']);

        $merged = $unlocks;
        foreach ($grantable as $id) {
            if (!in_array($id, $merged, true)) {
                $merged[] = $id;
            }
        }
        if (count($merged) > PlayerProfileController::MAX_UNLOCKS) {
            return null;
        }

        if (count($merged) !== count($unlocks)) {
            $stmt = $pdo->prepare('UPDATE users SET unlocks = ? WHERE id = ?');
            $stmt->execute([json_encode(array_values($merged)), $userId]);
        }
        return $grantable;
    }

    /**
     * A failed redemption keeps the 200 response of simpleServer with success false.
     */
    private static function failed(string $message): void
    {
        Response::json([
            'success' => false,
            'message' => $message,
            'giftData' => null,
            'grantedUnlocks' => [],
        ]);
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
