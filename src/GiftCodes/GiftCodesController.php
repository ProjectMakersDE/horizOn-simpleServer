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
