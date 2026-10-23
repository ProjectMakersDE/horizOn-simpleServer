<?php

declare(strict_types=1);
require_once __DIR__.'/../Core/Config.php';

class InboxLimits
{
    public static function current(): array {
        return ['targeted'=>Config::operatorLimit('INBOX_TARGETED',50),
            'broadcasts'=>Config::operatorLimit('INBOX_BROADCASTS',500),
            'freeTexts'=>Config::operatorLimit('INBOX_FREE_TEXTS',2),
            'freeTextBytes'=>Config::operatorLimit('INBOX_FREE_TEXT_BYTES',256),
            'freeTextRetentionDays'=>Config::operatorLimit('INBOX_FREE_TEXT_RETENTION_DAYS',7)];
    }
}
