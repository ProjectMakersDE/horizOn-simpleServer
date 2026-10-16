<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Groups/GroupService.php';
try {
    $command = $argv[1] ?? '';
    if (!in_array($command,['player','group','members','pending','limits','cleanup-deleted'],true)) { fwrite(STDERR,"Usage: php bin/groups.php player UUID | group UUID | members UUID [AFTER_UUID] | pending UUID | limits | cleanup-deleted [AFTER_USER_UUID]\n"); exit(2); }
    chdir(dirname(__DIR__)); Config::load('.env'); Database::migrate(); $service = new GroupService(Database::connect());
    switch ($command) {
        case 'player': $result = $service->get(GroupRules::uuid($argv[2] ?? null)); break;
        case 'group': $result = $service->inspect(GroupRules::uuid($argv[2] ?? null)); break;
        case 'members': $result = $service->members(null,GroupRules::uuid($argv[2] ?? null),$argv[3] ?? null,50); break;
        case 'pending': $result = $service->pending(null,GroupRules::uuid($argv[2] ?? null)); break;
        case 'cleanup-deleted': $result = $service->cleanupDeleted($argv[2] ?? null); break;
        default: $result = $service->limits();
    }
    echo GroupRules::json($result) . "\n";
} catch (Throwable $error) { fwrite(STDERR,'Groups operation rejected: ' . $error->getMessage() . "\n"); exit(1); }
