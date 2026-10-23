<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Friends/FriendsService.php';
try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['player', 'limits', 'cleanup-deleted', 'compact-check', 'compact-apply'], true)) {
        fwrite(STDERR, "Usage: php bin/friends.php player UUID | limits | cleanup-deleted | compact-check [AFTER|- [1..50]] | compact-apply AFTER|- 1..50 writers-stopped backup-restored\n"); exit(2);
    }
    chdir(dirname(__DIR__)); Config::load('.env'); Database::migrate(); $service = new FriendsService(Database::connect());
    if ($command==='compact-apply'&&array_slice($argv,4)!==['writers-stopped','backup-restored']) throw new InvalidArgumentException('Cold apply requires stopped upgraded readers/writers and independently restored backup acknowledgements');
    if ($command==='compact-check'||$command==='compact-apply') $result=$service->compactPage(($argv[2]??'-')==='-'?null:FriendsRules::uuid($argv[2]),isset($argv[3])?(ctype_digit($argv[3])?(int)$argv[3]:0):10,$command==='compact-apply');
    else $result = $command === 'player' ? $service->get(FriendsRules::uuid($argv[2] ?? null)) : ($command === 'limits' ? $service->limits() : $service->cleanupDeleted());
    echo InboxRules::json($result) . "\n";
} catch (Throwable $failure) { fwrite(STDERR, 'Friends operation rejected: ' . $failure->getMessage() . "\n"); exit(1); }
