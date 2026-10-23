<?php

declare(strict_types=1);

class FriendsFailure extends RuntimeException
{
    public string $errorCode;
    public int $status;
    public function __construct(string $code, string $message, int $status = 409)
    { parent::__construct($message); $this->errorCode = $code; $this->status = $status; }
}

class FriendsRules
{
    public const MAX = 9007199254740991;
    public const FRIENDS = 200;
    public const PENDING = 20;
    public const BLOCKED = 100;
    public const ACTIONS = ['request', 'accept', 'decline', 'cancel', 'remove', 'block', 'unblock'];
    public static function fail(string $code, string $message, int $status = 409): void { throw new FriendsFailure($code, $message, $status); }
    public static function invalid(string $message): void { self::fail('INVALID_REQUEST', $message, 400); }
    public static function conflict(): void { self::fail('REVISION_CONFLICT', 'Read the current snapshot and reconcile the original intent'); }
    public static function missing(): void { self::fail('PLAYER_NOT_FOUND', 'Player not found', 404); }
    public static function uuid($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) self::invalid('Exact player UUID required');
        return strtolower($value);
    }
    public static function revision($value): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX) self::invalid('A nonnegative safe integer revision is required');
        return $value;
    }
    public static function code(string $user): string { return 'H-' . rtrim(strtr(base64_encode(hex2bin(str_replace('-', '', self::uuid($user)))), '+/', '-_'), '='); }
    public static function decode($query): string
    {
        if (!is_string($query)) self::invalid('Exact UUID or canonical friend code required');
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $query)) return strtolower($query);
        if (!preg_match('/^H-[A-Za-z0-9_-]{22}$/D', $query)) self::invalid('Exact UUID or canonical friend code required');
        $bytes = base64_decode(strtr(substr($query, 2), '-_', '+/') . '==', true);
        if ($bytes === false || strlen($bytes) !== 16) self::invalid('Invalid friend code');
        $hex = bin2hex($bytes); $id = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        if (self::code($id) !== $query) self::invalid('Noncanonical friend code');
        return $id;
    }
    public static function emptyState(): array { return ['revision' => 0, 'friends' => [], 'incoming' => [], 'outgoing' => [], 'blocked' => [], 'exists' => false, '_legacy' => false]; }
    public static function has(array $entries, string $user): bool { foreach ($entries as $entry) if ($entry['userId'] === $user) return true; return false; }
    public static function without(array $state, string $user, bool $blocks = false): array
    {
        foreach ($blocks ? ['friends', 'incoming', 'outgoing', 'blocked'] : ['friends', 'incoming', 'outgoing'] as $field)
            $state[$field] = array_values(array_filter($state[$field], static function ($entry) use ($user) { return $entry['userId'] !== $user; }));
        return $state;
    }
    private static function request(array $entries, string $peer, string $request): array
    {
        foreach ($entries as $entry) if ($entry['userId'] === $peer && $entry['requestId'] === $request) return $entry;
        self::fail('FRIEND_REQUEST_NOT_FOUND', 'This exact request is no longer available', 404);
    }
    public static function transition(string $action, string $actor, string $peer, array $a, array $b, ?string $request, string $time, int $maxFriends, ?array $limits = null): array
    {
        $limits = $limits ?? ['maxIncomingRequests'=>self::PENDING,'maxOutgoingRequests'=>self::PENDING,'maxBlocked'=>self::BLOCKED];
        $notice = null;
        if (in_array($action, ['request', 'accept'], true) && (self::has($a['blocked'], $peer) || self::has($b['blocked'], $actor)))
            self::fail('RELATIONSHIP_NOT_ALLOWED', 'Relationship is unavailable', 403);
        switch ($action) {
            case 'request':
                if (self::has($a['friends'], $peer) || self::has($a['outgoing'], $peer)) break;
                if (count($a['outgoing']) >= $limits['maxOutgoingRequests'] || count($b['incoming']) >= $limits['maxIncomingRequests']) self::fail('FRIEND_REQUEST_LIMIT_REACHED', 'Pending request capacity reached');
                $id = Database::uuid();
                $a['outgoing'][] = ['userId' => $peer, 'requestId' => $id, 'createdAt' => null];
                $b['incoming'][] = ['userId' => $actor, 'requestId' => $id, 'createdAt' => null];
                $notice = $id; break;
            case 'accept':
                $entry = self::request($a['incoming'], $peer, $request);
                self::request($b['outgoing'], $actor, $request);
                if ((!self::has($a['friends'], $peer) && count($a['friends']) >= $maxFriends) || (!self::has($b['friends'], $actor) && count($b['friends']) >= $maxFriends))
                    self::fail('FRIEND_LIMIT_REACHED', 'Friend capacity reached');
                $a = self::without($a, $peer); $b = self::without($b, $actor);
                $a['friends'][] = ['userId' => $peer, 'since' => null]; $b['friends'][] = ['userId' => $actor, 'since' => null];
                $notice = $entry['requestId']; break;
            case 'decline': case 'cancel':
                $own = $action === 'decline' ? 'incoming' : 'outgoing'; $other = $action === 'decline' ? 'outgoing' : 'incoming';
                self::request($a[$own], $peer, $request);
                $a[$own] = array_values(array_filter($a[$own], static function ($row) use ($peer, $request) { return $row['userId'] !== $peer || $row['requestId'] !== $request; }));
                $b[$other] = array_values(array_filter($b[$other], static function ($row) use ($actor, $request) { return $row['userId'] !== $actor || $row['requestId'] !== $request; }));
                break;
            case 'remove':
                $a['friends'] = array_values(array_filter($a['friends'], static function ($row) use ($peer) { return $row['userId'] !== $peer; }));
                $b['friends'] = array_values(array_filter($b['friends'], static function ($row) use ($actor) { return $row['userId'] !== $actor; })); break;
            case 'block':
                $already = self::has($a['blocked'], $peer);
                if (!$already && count($a['blocked']) >= $limits['maxBlocked']) self::fail('BLOCK_LIMIT_REACHED', 'Block capacity reached');
                $a = self::without($a, $peer); $b = self::without($b, $actor);
                if (!$already) $a['blocked'][] = ['userId' => $peer, 'blockedAt' => null]; break;
            case 'unblock':
                $a['blocked'] = array_values(array_filter($a['blocked'], static function ($row) use ($peer) { return $row['userId'] !== $peer; })); break;
            default: self::invalid('Unknown social action');
        }
        return [$a, $b, $notice];
    }
}
