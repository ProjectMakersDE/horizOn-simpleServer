<?php

declare(strict_types=1);

class UserManagementController
{
    public static function signup(Request $request): void
    {
        $type = $request->body('type');
        $username = $request->body('username', '');

        if ($type !== 'ANONYMOUS') {
            Response::badRequest('Only ANONYMOUS signup is supported by this server');
            return;
        }

        if ($username === '' || strlen($username) > 30) {
            Response::badRequest('username is required and must be max 30 characters');
            return;
        }

        $pdo = Database::connect();
        $userId = Database::uuid();
        $anonymousToken = bin2hex(random_bytes(16)); // 32 char hex token
        $now = Database::now();

        $stmt = $pdo->prepare('INSERT INTO users (id, display_name, anonymous_token, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $username, $anonymousToken, $now]);

        Response::created([
            'userId' => $userId,
            'username' => $username,
            'email' => null,
            'isAnonymous' => true,
            'isVerified' => false,
            'anonymousToken' => $anonymousToken,
            'googleId' => null,
            'message' => null,
            'createdAt' => $now,
        ]);
    }

    public static function signin(Request $request): void
    {
        $type = $request->body('type');
        $anonymousToken = $request->body('anonymousToken', '');

        if ($type !== 'ANONYMOUS') {
            Response::badRequest('Only ANONYMOUS signin is supported by this server');
            return;
        }

        if ($anonymousToken === '') {
            Response::badRequest('anonymousToken is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, display_name FROM users WHERE anonymous_token = ?');
        $stmt->execute([$anonymousToken]);
        $user = $stmt->fetch();

        if ($user === false) {
            Response::json([
                'userId' => null,
                'username' => null,
                'email' => null,
                'accessToken' => null,
                'authStatus' => 'USER_NOT_FOUND',
                'message' => 'No user found with this anonymous token',
            ], 404);
            return;
        }

        // Generate session token
        $sessionToken = bin2hex(random_bytes(32)); // 64 char hex token
        $expiresAt = gmdate('Y-m-d\TH:i:s', time() + 86400 * 30); // 30 days

        $stmt = $pdo->prepare('UPDATE users SET session_token = ?, session_expires_at = ? WHERE id = ?');
        $stmt->execute([$sessionToken, $expiresAt, $user['id']]);

        Response::json([
            'userId' => $user['id'],
            'username' => $user['display_name'],
            'email' => null,
            'accessToken' => $sessionToken,
            'authStatus' => 'AUTHENTICATED',
            'message' => null,
        ]);
    }

    public static function checkAuth(Request $request): void
    {
        $userId = $request->body('userId', '');
        $sessionToken = $request->body('sessionToken', '');

        if ($userId === '' || $sessionToken === '') {
            Response::badRequest('userId and sessionToken are required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT session_token, session_expires_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            Response::json([
                'userId' => null,
                'isAuthenticated' => false,
                'authStatus' => 'USER_NOT_FOUND',
                'message' => 'User not found',
            ]);
            return;
        }

        if ($user['session_token'] === null || !hash_equals($user['session_token'], $sessionToken)) {
            Response::json([
                'userId' => $userId,
                'isAuthenticated' => false,
                'authStatus' => 'INVALID_TOKEN',
                'message' => 'Invalid session token',
            ]);
            return;
        }

        if ($user['session_expires_at'] !== null && $user['session_expires_at'] < Database::now()) {
            Response::json([
                'userId' => $userId,
                'isAuthenticated' => false,
                'authStatus' => 'TOKEN_EXPIRED',
                'message' => 'Session token has expired',
            ]);
            return;
        }

        Response::json([
            'userId' => $userId,
            'isAuthenticated' => true,
            'authStatus' => 'AUTHENTICATED',
            'message' => null,
        ]);
    }
}
