<?php

declare(strict_types=1);
require_once __DIR__ . '/../Inbox/InboxRules.php';

class GroupFailure extends RuntimeException
{
    public string $errorCode;
    public int $status;
    public function __construct(string $code, int $status = 409) { parent::__construct(strtolower(str_replace('_', ' ', $code))); $this->errorCode = $code; $this->status = $status; }
}

class GroupRules
{
    public const MAX = 9007199254740991;
    public const ACTIONS = ['join','request','invite','accept-invite','decline-invite','cancel-invite','accept-request','decline-request','cancel-request','leave','remove','role','transfer','delete'];
    public static function fail(string $code, int $status = 409): void { throw new GroupFailure($code, $status); }
    public static function conflict(): void { self::fail('REVISION_CONFLICT'); }
    public static function denied(): void { self::fail('GROUP_PERMISSION_DENIED', 403); }
    public static function missing(): void { self::fail('GROUP_NOT_FOUND', 404); }
    public static function uuid($value, string $code = 'INVALID_GROUP_ACTION'): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/Di', $value)) self::fail($code, 400);
        return strtolower($value);
    }
    public static function revision($value): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX) self::fail('INVALID_REVISION', 400);
        return $value;
    }
    public static function page($limit): int
    {
        if (!is_int($limit) || $limit < 1 || $limit > 50) self::fail('INVALID_PAGE', 400);
        return $limit;
    }
    public static function metadata($name, $description, $mode, $data): array
    {
        if (!is_string($name) || !is_string($description) || !is_string($mode) || !($data instanceof stdClass) ||
            !preg_match('/[^\s\p{Z}\x{001c}-\x{001f}]/u', $name) || strlen($name) > 120 || strlen($description) > 1024 || !in_array($mode, ['open','request','invite'], true)) self::fail('INVALID_GROUP', 400);
        self::visit($data, 0);
        $json = self::json($data);
        if (strlen($json) > 2048) self::fail('INVALID_GROUP', 400);
        return ['name' => $name, 'description' => $description, 'joinMode' => $mode, 'dataJson' => $json];
    }
    private static function visit($value, int $depth): void
    {
        if ($depth > 16 || (is_float($value) && !is_finite($value))) self::fail('INVALID_GROUP', 400);
        if (is_array($value) || $value instanceof stdClass) foreach ($value as $child) self::visit($child, $depth + 1);
        elseif (!is_null($value) && !is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) self::fail('INVALID_GROUP', 400);
    }
    public static function json($value): string
    {
        try { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION); }
        catch (JsonException $error) { self::fail('INVALID_GROUP', 400); }
    }
    public static function action(string $action, string $user, $expected, $target, $request, $role): array
    {
        $revision = self::revision($expected);
        $targeted = in_array($action, ['invite','cancel-invite','accept-request','decline-request','remove','role','transfer'], true);
        $pending = in_array($action, ['accept-invite','decline-invite','cancel-invite','accept-request','decline-request','cancel-request'], true);
        if (!in_array($action, self::ACTIONS, true) || $targeted !== ($target !== null) || $pending !== ($request !== null) ||
            ($action === 'role') !== ($role !== null) || ($role !== null && !in_array($role, ['officer','member'], true))) self::fail('INVALID_GROUP_ACTION', 400);
        $target = $targeted ? self::uuid($target) : $user;
        if ($targeted && $target === $user) self::fail('INVALID_GROUP_ACTION', 400);
        return ['expectedRevision' => $revision, 'targetUserId' => $target, 'requestId' => $pending ? self::uuid($request) : null, 'role' => $role];
    }
    public static function officer(?array $member): void { if ($member === null || !in_array($member['role'], ['leader','officer'], true)) self::denied(); }
    public static function leader(?array $member): void { if ($member === null || $member['role'] !== 'leader') self::denied(); }
    public static function emptyPlayer(): array { return ['revision' => 0, 'groupIds' => [], 'incomingInvites' => [], 'outgoingRequests' => [], 'exists' => false]; }
}
