<?php

declare(strict_types=1);
require_once __DIR__.'/../Core/Config.php';
require_once __DIR__.'/FriendsStorage.php';
class FriendsLimits
{
    public static function values(): array
    {
        return ['maxFriends'=>Config::operatorLimit('FRIENDS_MAX_FRIENDS',200,FriendsStorage::capacity('friends')),
            'maxIncomingRequests'=>Config::operatorLimit('FRIENDS_INCOMING_REQUESTS',20,FriendsStorage::capacity('incoming')),
            'maxOutgoingRequests'=>Config::operatorLimit('FRIENDS_OUTGOING_REQUESTS',20,FriendsStorage::capacity('outgoing')),
            'maxBlocked'=>Config::operatorLimit('FRIENDS_BLOCKED',100,FriendsStorage::capacity('blocked'))];
    }
    public static function validate(array $values): array
    {
        $fields=['maxFriends'=>'friends','maxIncomingRequests'=>'incoming','maxOutgoingRequests'=>'outgoing','maxBlocked'=>'blocked'];
        if (array_diff(array_keys($values),array_keys($fields))||count($values)!==4) throw new InvalidArgumentException('Four relationship policies required');
        foreach ($fields as $key=>$field) if (!is_int($values[$key])||$values[$key]<1||$values[$key]>FriendsStorage::capacity($field))
            throw new InvalidArgumentException('Invalid '.$key.': portable SQL TEXT admits at most'.FriendsStorage::capacity($field).' entries');
        return $values;
    }
}
