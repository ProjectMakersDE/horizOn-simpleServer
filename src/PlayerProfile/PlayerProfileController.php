<?php

declare(strict_types=1);

/**
 * Player profile (avatar, frame, badges) and cosmetic unlocks.
 *
 * Same app endpoints, JSON shapes, caps and error codes as horizOn. The
 * catalog is the table `cosmetics` (filled by SQL, one catalog per
 * installation, no size limit). Players keep avatar_id, frame_id, badges
 * (JSON array) and unlocks (JSON array) on the `users` row. Unlocks are only
 * written by the server: gift code grants, or SQL by the operator.
 */
class PlayerProfileController
{
    /** Maximum number of badges a player shows at the same time. */
    public const MAX_BADGES = 3;

    /** Maximum number of unlocks stored on one player. */
    public const MAX_UNLOCKS = 25;

    /** Maximum number of grants read from one gift code. */
    public const MAX_GRANTS_PER_GIFT_CODE = 10;

    /** Lowercase letters, digits, '.', '_' and '-'; starts with a letter or digit; 1 to 32 characters. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,31}$/';

    public const TYPE_AVATAR = 'avatar';
    public const TYPE_FRAME = 'frame';
    public const TYPE_BADGE = 'badge';

    /**
     * GET /api/v1/app/player-profile?userId=
     * Profile, unlocks and the catalog with an `available` flag per entry.
     */
    public static function get(Request $request): void
    {
        $userId = trim((string)$request->query('userId', ''));
        if ($userId === '') {
            Response::badRequest('userId query parameter is required');
        }

        Auth::requirePlayerSession($request, $userId);
        $user = self::requirePlayer($userId);

        Response::json(self::buildResponse(
            $userId,
            self::profileFromRow($user),
            self::decodeList($user['unlocks'] ?? null),
            self::loadCatalog()
        ));
    }

    /**
     * PUT /api/v1/app/player-profile
     * Replaces the whole visible profile. Missing, null or "" clears a slot,
     * a missing or empty badge list clears the badges.
     */
    public static function set(Request $request): void
    {
        $body = $request->body();
        if (!is_array($body)) {
            Response::badRequest('A JSON body is required');
        }

        $userId = $body['userId'] ?? null;
        if (!is_string($userId) || trim($userId) === '') {
            Response::badRequest('userId is required');
        }
        $userId = trim($userId);

        Auth::requirePlayerSession($request, $userId);
        $user = self::requirePlayer($userId);
        $unlocks = self::decodeList($user['unlocks'] ?? null);

        $avatarId = self::slotValue($body, 'avatarId');
        $frameId = self::slotValue($body, 'frameId');
        $badges = self::badgesValue($body);

        // Badge count and duplicates first, like horizOn
        if (count($badges) > self::MAX_BADGES) {
            self::fail('INVALID_BADGES', 'At most ' . self::MAX_BADGES . ' badges can be shown', 400);
        }
        if (count(array_unique($badges)) !== count($badges)) {
            self::fail('INVALID_BADGES', 'A badge must not be listed twice', 400);
        }

        // Pattern of every requested ID
        $requested = array_merge(array_values(array_filter([$avatarId, $frameId], function ($id) {
            return $id !== null;
        })), $badges);
        foreach ($requested as $id) {
            self::requireValidId($id);
        }

        // Catalog, type and lock per slot
        $catalog = self::loadCatalog();
        $byId = [];
        foreach ($catalog as $entry) {
            $byId[$entry['cosmetic_id']] = $entry;
        }
        if ($avatarId !== null) {
            self::requireUsable($byId, $avatarId, self::TYPE_AVATAR, $unlocks);
        }
        if ($frameId !== null) {
            self::requireUsable($byId, $frameId, self::TYPE_FRAME, $unlocks);
        }
        foreach ($badges as $badge) {
            self::requireUsable($byId, $badge, self::TYPE_BADGE, $unlocks);
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE users SET avatar_id = ?, frame_id = ?, badges = ? WHERE id = ?');
        $stmt->execute([
            $avatarId,
            $frameId,
            count($badges) > 0 ? json_encode(array_values($badges)) : null,
            $userId,
        ]);

        Response::json(self::buildResponse(
            $userId,
            ['avatarId' => $avatarId, 'frameId' => $frameId, 'badges' => array_values($badges)],
            $unlocks,
            $catalog
        ));
    }

    // ==================== Shared with leaderboard and gift codes ====================

    /**
     * The profile object of a row that has avatar_id, frame_id and badges.
     * Empty values become null and [], so the object is always complete.
     */
    public static function profileFromRow(array $row): array
    {
        return [
            'avatarId' => self::nullIfEmpty($row['avatar_id'] ?? null),
            'frameId' => self::nullIfEmpty($row['frame_id'] ?? null),
            'badges' => self::decodeList($row['badges'] ?? null),
        ];
    }

    /**
     * The empty profile object.
     */
    public static function emptyProfile(): array
    {
        return ['avatarId' => null, 'frameId' => null, 'badges' => []];
    }

    /**
     * Lenient read of the grants of a gift code's reward_data: the distinct,
     * valid IDs of a top-level `grants` array (at most 10). Empty when the
     * data is no JSON object or has no `grants` array.
     */
    public static function parseGrants(?string $rewardData): array
    {
        if ($rewardData === null || trim($rewardData) === '') {
            return [];
        }
        $decoded = json_decode($rewardData, true);
        if (!is_array($decoded) || !array_key_exists('grants', $decoded) || self::isList($decoded)) {
            return [];
        }
        $grants = $decoded['grants'];
        if (!is_array($grants) || !self::isList($grants)) {
            return [];
        }

        $ids = [];
        foreach ($grants as $grant) {
            if (is_string($grant) && self::isValidId($grant) && !in_array($grant, $ids, true)) {
                $ids[] = $grant;
            }
        }
        return array_slice($ids, 0, self::MAX_GRANTS_PER_GIFT_CODE);
    }

    /**
     * The subset of $ids that exists in the catalog, in the order of $ids.
     */
    public static function existingCosmeticIds(PDO $pdo, array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT cosmetic_id FROM cosmetics WHERE cosmetic_id IN ({$placeholders})");
        $stmt->execute(array_values($ids));
        $found = array_map(function ($row) {
            return (string)$row['cosmetic_id'];
        }, $stmt->fetchAll());

        return array_values(array_filter($ids, function ($id) use ($found) {
            return in_array($id, $found, true);
        }));
    }

    /**
     * Decodes a JSON array column into a list of strings. NULL, empty or
     * malformed values give [].
     */
    public static function decodeList($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $list = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = $item;
            }
        }
        return $list;
    }

    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    // ==================== Helpers ====================

    private static function buildResponse(string $userId, array $profile, array $unlocks, array $catalog): array
    {
        $cosmetics = [];
        foreach ($catalog as $entry) {
            $locked = (int)$entry['locked'] !== 0;
            $cosmetics[] = [
                'id' => (string)$entry['cosmetic_id'],
                'type' => (string)$entry['type'],
                'locked' => $locked,
                'available' => !$locked || in_array((string)$entry['cosmetic_id'], $unlocks, true),
            ];
        }

        return [
            'userId' => $userId,
            'profile' => $profile,
            'unlocks' => array_values($unlocks),
            'cosmetics' => $cosmetics,
            'limits' => [
                'maxBadges' => self::MAX_BADGES,
                'maxUnlocks' => self::MAX_UNLOCKS,
            ],
        ];
    }

    /**
     * The catalog sorted by ID.
     */
    private static function loadCatalog(): array
    {
        $pdo = Database::connect();
        return $pdo->query('SELECT cosmetic_id, type, locked FROM cosmetics ORDER BY cosmetic_id')->fetchAll();
    }

    private static function requirePlayer(string $userId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, avatar_id, frame_id, badges, unlocks FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user === false) {
            self::fail('PLAYER_NOT_FOUND', 'Player not found', 404);
        }
        return $user;
    }

    private static function requireValidId(string $id): void
    {
        if (!self::isValidId($id)) {
            self::fail(
                'INVALID_COSMETIC_ID',
                "Invalid cosmetic ID '" . substr($id, 0, 64) . "': use 1 to 32 characters a-z, 0-9, '.', '_', '-', starting with a letter or digit",
                400
            );
        }
    }

    private static function requireUsable(array $byId, string $cosmeticId, string $expectedType, array $unlocks): void
    {
        if (!isset($byId[$cosmeticId])) {
            self::fail('COSMETIC_NOT_FOUND', "Cosmetic '{$cosmeticId}' is not in the catalog", 400);
        }
        $entry = $byId[$cosmeticId];
        if ((string)$entry['type'] !== $expectedType) {
            self::fail(
                'COSMETIC_TYPE_MISMATCH',
                "Cosmetic '{$cosmeticId}' is a {$entry['type']}, not a {$expectedType}",
                400
            );
        }
        if ((int)$entry['locked'] !== 0 && !in_array($cosmeticId, $unlocks, true)) {
            self::fail('COSMETIC_LOCKED', "Cosmetic '{$cosmeticId}' is locked for this player", 403);
        }
    }

    /**
     * A slot value from the body: null for missing, null or blank; the trimmed
     * string otherwise. Other JSON types are a bad request.
     */
    private static function slotValue(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        if (!is_string($body[$key])) {
            Response::badRequest("{$key} must be a string or null");
        }
        $value = trim($body[$key]);
        return $value === '' ? null : $value;
    }

    /**
     * The badges from the body: [] for missing or null, trimmed strings otherwise.
     */
    private static function badgesValue(array $body): array
    {
        if (!array_key_exists('badges', $body) || $body['badges'] === null) {
            return [];
        }
        $badges = $body['badges'];
        if (!is_array($badges) || !self::isList($badges)) {
            Response::badRequest('badges must be an array of cosmetic IDs');
        }
        $list = [];
        foreach ($badges as $badge) {
            if (!is_string($badge)) {
                Response::badRequest('badges must be an array of cosmetic IDs');
            }
            $list[] = trim($badge);
        }
        return $list;
    }

    private static function nullIfEmpty($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string)$value;
        return $value === '' ? null : $value;
    }

    /**
     * True for a sequential array (a JSON array), false for a JSON object.
     * PHP 7.4 has no array_is_list().
     */
    private static function isList(array $value): bool
    {
        return count($value) === 0 || array_keys($value) === range(0, count($value) - 1);
    }

    private static function fail(string $code, string $message, int $status): void
    {
        Response::error($message, $code, $status);
    }
}
