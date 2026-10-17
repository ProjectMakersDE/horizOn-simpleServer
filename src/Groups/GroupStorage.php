<?php

declare(strict_types=1);
require_once __DIR__ . '/GroupRules.php';

/** SQL identities stay native. Persistent player JSON holds only quota counters. */
class GroupStorage
{
    public static function required(): void { GroupRules::fail('GROUP_STORAGE_CONFLICT'); }
    public static function uuid($value): string
    {
        if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value)) self::required();
        return $value;
    }
    private static function number($value, int $maximum = 2147483647): int
    {
        if (!is_int($value) || $value < 0 || $value > $maximum) self::required();
        return $value;
    }
    private static function object(string $raw): array
    {
        try { $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { self::required(); }
        if (!$value instanceof stdClass) self::required();
        // Unknown object values retain their JSON type when a group is updated.
        return get_object_vars($value);
    }
    private static function timestamp($value): void
    {
        try { if (!is_string($value) || InboxRules::instant(InboxRules::millis($value)) !== $value) self::required(); }
        catch (InboxFailure $error) { self::required(); }
    }
    private static function pending($values, bool $invitation, bool $player): array
    {
        if (!is_array($values)) self::required();
        $result = []; $users = []; $requests = [];
        foreach ($values as $value) {
            if (!$value instanceof stdClass) self::required();
            $entry = get_object_vars($value);
            if ($player && (array_diff(array_keys($entry), ['groupId','requestId','issuerId','createdAt']) || !isset($entry['groupId']))) self::required();
            $subject = self::uuid($entry[$player ? 'groupId' : 'userId'] ?? null);
            $request = self::uuid($entry['requestId'] ?? null);
            if (isset($users[$subject]) || isset($requests[$request])) self::required();
            $users[$subject] = true; $requests[$request] = true;
            if (isset($entry['issuerId'])) self::uuid($entry['issuerId']);
            elseif ($invitation || $player) self::required();
            else $entry['issuerId'] = $subject;
            if (!$player && !$invitation && $entry['issuerId'] !== $subject) self::required();
            if (isset($entry['createdAt'])) self::timestamp($entry['createdAt']);
            else unset($entry['createdAt']);
            $result[] = $entry;
        }
        return $result;
    }
    public static function player(string $raw): array
    {
        $value = self::object($raw);
        if (array_key_exists('s', $value)) {
            if ($value['s'] !== 1 || array_diff(array_keys($value), ['s','m','i','r'])) self::required();
            return ['_compact' => true, '_counts' => [
                'memberships' => self::number($value['m'] ?? 0),
                'invitations' => self::number($value['i'] ?? 0),
                'requests' => self::number($value['r'] ?? 0),
            ]];
        }
        if (count($value) !== 3 || array_diff(array_keys($value), ['groupIds','incomingInvites','outgoingRequests']) || !is_array($value['groupIds'])) self::required();
        $ids = []; foreach ($value['groupIds'] as $id) $ids[] = self::uuid($id);
        if (count(array_unique($ids)) !== count($ids)) self::required();
        return ['_compact' => false, 'groupIds' => $ids,
            'incomingInvites' => self::pending($value['incomingInvites'], true, true),
            'outgoingRequests' => self::pending($value['outgoingRequests'], false, true)];
    }
    public static function encodePlayer(array $state): string
    {
        $value = ['s' => 1];
        foreach (['m' => 'groupIds', 'i' => 'incomingInvites', 'r' => 'outgoingRequests'] as $field => $source) {
            $count = self::number(count($state[$source]));
            if ($count > 0) $value[$field] = $count;
        }
        return GroupRules::json($value);
    }
    public static function group(string $raw,string $id,int $revision): array
    {
        $value = self::object($raw);
        if(array_key_exists('s',$value)) {
            if($value['s']!==1||array_key_exists('id',$value)||array_key_exists('revision',$value))self::required();
            unset($value['s']);$value=['id'=>$id,'revision'=>$revision]+$value;
        }
        foreach (['id','leaderId'] as $field) self::uuid($value[$field] ?? null);
        self::number($value['revision'] ?? null, GroupRules::MAX);
        self::number($value['memberCount'] ?? null);
        foreach (['name','description','dataJson'] as $field) if (!isset($value[$field]) || !is_string($value[$field])) self::required();
        if (!isset($value['joinMode']) || !in_array($value['joinMode'], ['open','request','invite'], true)) self::required();
        foreach (['createdAt','updatedAt'] as $field) self::timestamp($value[$field] ?? null);
        $value['invitations'] = self::pending($value['invitations'] ?? null, true, false);
        $value['joinRequests'] = self::pending($value['joinRequests'] ?? null, false, false);
        $subjects = array_merge(array_column($value['invitations'], 'userId'), array_column($value['joinRequests'], 'userId'));
        $requests = array_merge(array_column($value['invitations'], 'requestId'), array_column($value['joinRequests'], 'requestId'));
        if (count(array_unique($subjects)) !== count($subjects) || count(array_unique($requests)) !== count($requests)) self::required();
        self::object($value['dataJson']);
        return $value;
    }
    public static function encodeGroup(array $group): string
    {
        // PK and CAS revision already have typed SQL columns.
        unset($group['id'],$group['revision']);$group=['s'=>1]+$group;
        // Request issuers are the requester. Retain every unresolved original date.
        foreach ($group['joinRequests'] as &$entry) {
            if ($entry['issuerId'] !== $entry['userId']) self::required();
            unset($entry['issuerId']);
        }
        unset($entry);
        return GroupRules::json($group);
    }
    public static function identity(string $group, array $entry): array
    {
        return [$group, $entry['requestId'], $entry['issuerId'], $entry['createdAt'] ?? null];
    }
}
