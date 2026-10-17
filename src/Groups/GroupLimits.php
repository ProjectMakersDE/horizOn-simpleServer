<?php

declare(strict_types=1);
require_once __DIR__ . '/../Core/Config.php';

/** Operator policy applies to new effects, never to retention or decoding. */
class GroupLimits
{
    public static function values(?int $members = null, ?int $memberships = null): array
    {
        $settings = [
            'maxMembers' => ['GROUP_MEMBERS', 500],
            'maxGroupsPerPlayer' => ['GROUP_MEMBERSHIPS', 5],
            'maxInvitations' => ['GROUP_INVITATIONS', 20],
            'maxJoinRequests' => ['GROUP_JOIN_REQUESTS', 20],
            'maxDataBytes' => ['GROUP_DATA_BYTES', 2048],
            'memberPageSize' => ['GROUP_MEMBER_PAGE_SIZE', 50],
            'maxPlayerInvitations' => ['GROUP_PLAYER_INVITATIONS', 2],
            'maxPlayerRequests' => ['GROUP_PLAYER_REQUESTS', 2],
            'maxNameBytes' => ['GROUP_NAME_BYTES', 120],
            'maxDescriptionBytes' => ['GROUP_DESCRIPTION_BYTES', 1024],
            'maxDataDepth' => ['GROUP_DATA_DEPTH', 16],
        ];
        $result = [];
        foreach ($settings as $field => [$key, $default]) $result[$field] = Config::operatorLimit($key, $default);
        foreach (['maxMembers' => $members, 'maxGroupsPerPlayer' => $memberships] as $field => $value) {
            if ($value !== null) {
                if ($value < 1 || $value > 2147483647) throw new InvalidArgumentException('Invalid group bound');
                $result[$field] = $value;
            }
        }
        return $result;
    }
}
