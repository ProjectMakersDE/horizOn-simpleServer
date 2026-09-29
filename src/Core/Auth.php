<?php

declare(strict_types=1);

class Auth
{
    public static function validateApiKey(Request $request): void
    {
        $expected = Config::get('API_KEY');
        if ($expected === '' || $expected === 'change-me-to-a-secure-key') {
            Response::serverError('API_KEY not configured. Please set a secure API key in .env');
        }

        $provided = $request->header('x-api-key');
        if ($provided === null || $provided === '') {
            Response::unauthorized('Missing X-API-Key header');
        }

        if (!hash_equals($expected, $provided)) {
            Response::unauthorized('Invalid API key');
        }
    }

    /**
     * Requires a valid, unexpired Bearer session (users.session_token) that
     * belongs to $userId. Exits with 401 SESSION_REQUIRED (and
     * WWW-Authenticate: Bearer) for a missing, malformed, unknown or expired
     * session, and with 403 SESSION_FORBIDDEN for a session of another player.
     */
    public static function requirePlayerSession(Request $request, string $userId): void
    {
        $authorization = trim((string)$request->header('authorization', ''));
        if ($authorization === '' || stripos($authorization, 'Bearer ') !== 0) {
            self::sessionRequired('Bearer session required');
        }

        $token = trim(substr($authorization, 7));
        if ($token === '') {
            self::sessionRequired('Invalid or expired session');
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, session_expires_at FROM users WHERE session_token = ?');
        $stmt->execute([$token]);
        $sessionUser = $stmt->fetch();

        if ($sessionUser === false
            || ($sessionUser['session_expires_at'] !== null && $sessionUser['session_expires_at'] < Database::now())
        ) {
            self::sessionRequired('Invalid or expired session');
        }

        if (!hash_equals((string)$sessionUser['id'], $userId)) {
            Response::error('Session is not authorized for this player', 'SESSION_FORBIDDEN', 403);
        }
    }

    /**
     * 401 SESSION_REQUIRED with WWW-Authenticate: Bearer.
     */
    public static function sessionRequired(string $message): void
    {
        header('WWW-Authenticate: Bearer');
        Response::error($message, 'SESSION_REQUIRED', 401);
    }
}
