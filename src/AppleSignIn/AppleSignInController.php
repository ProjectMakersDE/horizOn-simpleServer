<?php

declare(strict_types=1);

/**
 * Apple Sign-In controller for horizOn-simpleServer.
 *
 * Implements the public login endpoint (`POST /api/v1/public/auth/apple`)
 * and the shared logic used by the extended user-management signup/signin
 * routes when an `appleIdentityToken` is supplied.
 *
 * simpleServer is single-tenant: there is no separate Account table, so
 * both the public endpoint and the app endpoints resolve to rows in the
 * single `users` table. The Apple audience whitelist comes from `.env`
 * (APPLE_SERVICE_ID, APPLE_BUNDLE_ID), not from a per-apiKey config.
 */
class AppleSignInController
{
    /**
     * POST /api/v1/public/auth/apple
     *
     * Log in or register a user via an Apple identity token.
     * No API key required — this is the public endpoint.
     */
    public static function publicAuth(Request $request): void
    {
        if (!Config::getBool('APPLE_SIGN_IN_ENABLED', false)) {
            Response::json([
                'accessToken' => null,
                'user' => null,
                'authStatus' => 'APPLE_NOT_CONFIGURED',
                'message' => 'Apple Sign-In is not enabled on this server. Set APPLE_SIGN_IN_ENABLED=true in .env.',
            ]);
            return;
        }

        $identityToken = (string)$request->body('identityToken', '');
        if ($identityToken === '') {
            Response::badRequest('identityToken is required');
            return;
        }

        $firstName = self::stringOrNull($request->body('firstName'));
        $lastName = self::stringOrNull($request->body('lastName'));

        $result = self::authenticate($identityToken, $firstName, $lastName);

        if ($result['authStatus'] !== 'AUTHENTICATED') {
            Response::json([
                'accessToken' => null,
                'user' => null,
                'authStatus' => $result['authStatus'],
                'message' => $result['message'] ?? null,
            ]);
            return;
        }

        $user = $result['user'];
        Response::json([
            'accessToken' => $result['accessToken'],
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['display_name'],
                'appleUserId' => $user['apple_user_id'],
                'isPrivateRelayEmail' => (bool)$user['is_private_relay_email'],
                'isVerified' => true,
            ],
            'authStatus' => 'AUTHENTICATED',
        ]);
    }

    /**
     * Shared apple auth — used by UserManagementController signup/signin
     * when an `appleIdentityToken` is present on the request body.
     *
     * Returns an associative array:
     *   ['authStatus' => 'AUTHENTICATED', 'user' => <row>, 'accessToken' => ..., 'created' => bool]
     *   or ['authStatus' => 'INVALID_APPLE_TOKEN' | 'APPLE_NOT_CONFIGURED' | 'APPLE_EMAIL_CONFLICT' | ..., 'message' => ?]
     */
    public static function authenticate(string $identityToken, ?string $firstName = null, ?string $lastName = null): array
    {
        if (!Config::getBool('APPLE_SIGN_IN_ENABLED', false)) {
            return [
                'authStatus' => 'APPLE_NOT_CONFIGURED',
                'message' => 'Apple Sign-In is not enabled (APPLE_SIGN_IN_ENABLED=false).',
            ];
        }

        $audiences = self::configuredAudiences();
        if (empty($audiences)) {
            return [
                'authStatus' => 'APPLE_NOT_CONFIGURED',
                'message' => 'No Apple audience configured. Set APPLE_SERVICE_ID and/or APPLE_BUNDLE_ID in .env.',
            ];
        }

        $tokenInfo = AppleIdTokenVerifier::verify($identityToken, $audiences);
        if ($tokenInfo === null) {
            return [
                'authStatus' => 'INVALID_APPLE_TOKEN',
                'message' => 'Apple identity token could not be verified.',
            ];
        }

        $pdo = Database::connect();
        $now = Database::now();
        $appleUserId = $tokenInfo['sub'];
        $email = $tokenInfo['email'];
        $isPrivateRelay = $tokenInfo['isPrivateRelay'] ? 1 : 0;

        // Find existing user by apple_user_id
        $stmt = $pdo->prepare('SELECT id, display_name, email, apple_user_id, is_private_relay_email FROM users WHERE apple_user_id = ? LIMIT 1');
        $stmt->execute([$appleUserId]);
        $user = $stmt->fetch();

        // Fallback: link by email if email is present, verified, and not a private relay alias.
        if ($user === false && $email !== null && !$tokenInfo['isPrivateRelay']) {
            $stmt = $pdo->prepare('SELECT id, display_name, email, apple_user_id, is_private_relay_email FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $existing = $stmt->fetch();
            if ($existing !== false) {
                if (!empty($existing['apple_user_id']) && $existing['apple_user_id'] !== $appleUserId) {
                    return [
                        'authStatus' => 'APPLE_EMAIL_CONFLICT',
                        'message' => 'This email is already linked to a different Apple account.',
                    ];
                }
                // Link Apple ID to the existing email user.
                $upd = $pdo->prepare('UPDATE users SET apple_user_id = ?, is_private_relay_email = ? WHERE id = ?');
                $upd->execute([$appleUserId, $isPrivateRelay, $existing['id']]);
                $user = $existing;
                $user['apple_user_id'] = $appleUserId;
                $user['is_private_relay_email'] = $isPrivateRelay;
            }
        }

        $created = false;
        if ($user === false) {
            $userId = Database::uuid();
            $displayName = self::buildDisplayName($firstName, $lastName, $email, $appleUserId);
            $stmt = $pdo->prepare(
                'INSERT INTO users (id, display_name, anonymous_token, email, apple_user_id, is_private_relay_email, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            // `anonymous_token` is NOT NULL UNIQUE on the existing schema — generate a dummy
            // so Apple users satisfy the constraint without being reachable via the anonymous flow.
            $placeholderToken = 'apple:' . bin2hex(random_bytes(16));
            $stmt->execute([$userId, $displayName, $placeholderToken, $email, $appleUserId, $isPrivateRelay, $now]);

            $user = [
                'id' => $userId,
                'display_name' => $displayName,
                'email' => $email,
                'apple_user_id' => $appleUserId,
                'is_private_relay_email' => $isPrivateRelay,
            ];
            $created = true;
        }

        // Issue session token (30 days) — matches the anonymous sign-in pattern.
        $sessionToken = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d\TH:i:s', time() + 86400 * 30);
        $upd = $pdo->prepare('UPDATE users SET session_token = ?, session_expires_at = ? WHERE id = ?');
        $upd->execute([$sessionToken, $expiresAt, $user['id']]);

        return [
            'authStatus' => 'AUTHENTICATED',
            'user' => $user,
            'accessToken' => $sessionToken,
            'created' => $created,
        ];
    }

    private static function configuredAudiences(): array
    {
        $audiences = [];
        $serviceId = trim(Config::get('APPLE_SERVICE_ID', ''));
        $bundleId = trim(Config::get('APPLE_BUNDLE_ID', ''));
        if ($serviceId !== '') {
            $audiences[] = $serviceId;
        }
        if ($bundleId !== '') {
            $audiences[] = $bundleId;
        }
        return array_values(array_unique($audiences));
    }

    private static function buildDisplayName(?string $firstName, ?string $lastName, ?string $email, string $appleUserId): string
    {
        $fn = $firstName !== null ? trim($firstName) : '';
        $ln = $lastName !== null ? trim($lastName) : '';
        $full = trim($fn . ' ' . $ln);
        if ($full !== '') {
            return mb_substr($full, 0, 30);
        }
        if ($email !== null && $email !== '') {
            $localPart = strstr($email, '@', true);
            if ($localPart !== false && $localPart !== '') {
                return mb_substr($localPart, 0, 30);
            }
        }
        // Fall back to a truncated, prefixed Apple sub.
        return 'apple-' . substr($appleUserId, 0, 8);
    }

    private static function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }
}
