<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Friends/FriendsService.php';
try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['player', 'limits', 'cleanup-deleted'], true)) {
        fwrite(STDERR, "Usage: php bin/friends.php player UUID | limits | cleanup-deleted\n"); exit(2);
    }
    chdir(dirname(__DIR__)); Config::load('.env'); Database::migrate(); $service = new FriendsService(Database::connect());
    $result = $command === 'player' ? $service->get(FriendsRules::uuid($argv[2] ?? null)) : ($command === 'limits' ? $service->limits() : $service->cleanupDeleted());
    echo InboxRules::json($result) . "\n";
} catch (Throwable $failure) { fwrite(STDERR, 'Friends operation rejected: ' . $failure->getMessage() . "\n"); exit(1); }
