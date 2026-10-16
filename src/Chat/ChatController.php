<?php

declare(strict_types=1);

/** Hosted-only capability boundary. No chat payload, receipt, cache or polling storage. */
class ChatController
{
    public const MESSAGE = 'Chat is available in horizOn Cloud only; no polling fallback is supported';

    public static function appUnsupported(Request $request): void
    {
        // Preserve the normal player identity boundary before returning a capability error.
        $player = Auth::requirePlayerSessionUser($request);
        $user = $request->method() === 'GET' ? $request->query('userId') : $request->body('userId');
        if (!is_string($user) || $user === '') {
            Response::error('userId is required', 'INVALID_REQUEST', 400);
        }
        if (!hash_equals($player, $user)) {
            Response::error('Session is not authorized for this player', 'SESSION_FORBIDDEN', 403);
        }
        self::operatorUnsupported($request);
    }

    /** Constant public refusal only; app credentials never confer moderation permissions. */
    public static function operatorUnsupported(Request $request): void
    {
        Response::error(self::MESSAGE, 'NOT_SUPPORTED', 501);
    }
}
