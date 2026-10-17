<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Groups/GroupService.php';
require_once __DIR__ . '/../src/Groups/GroupMigration.php';
try {
    $command = $argv[1] ?? '';
    if (!in_array($command,['player','group','members','pending','limits','cleanup-deleted','compact-inspect','compact-apply'],true)) { fwrite(STDERR,"Usage: php bin/groups.php player UUID | group UUID | members UUID [AFTER_UUID] | pending UUID | limits | cleanup-deleted [AFTER_USER_UUID] | compact-inspect/compact-apply index [LIMIT] | compact-inspect/compact-apply groups/players [AFTER_UUID|-] [LIMIT]\n"); exit(2); }
    if($command==='compact-apply'&&(!in_array('--writes-stopped',$argv,true)||!in_array('--backup-verified',$argv,true)))throw new RuntimeException('Stop all writers and verify a backup before Group conversion');
    chdir(dirname(__DIR__)); Config::load('.env'); Database::migrate(); $service = new GroupService(Database::connect());
    switch ($command) {
        case 'compact-inspect': case 'compact-apply':
            $kind=$argv[2]??'';$value=$argv[$kind==='index'?3:4]??'20';
            if(!preg_match('/^[1-9][0-9]{0,2}$/D',$value))GroupRules::fail('INVALID_PAGE',400);
            $migration=new GroupMigration(Database::connect());
            if($kind==='index')$result=$migration->indexPage((int)$value,$command==='compact-apply');
            elseif(in_array($kind,['players','groups'],true))$result=$migration->$kind(isset($argv[3])&&$argv[3]!=='-'?$argv[3]:null,(int)$value,$command==='compact-apply');
            else throw new InvalidArgumentException('Unknown Group migration kind');
            break;
        case 'player': $result = $service->get(GroupRules::uuid($argv[2] ?? null)); break;
        case 'group': $result = $service->inspect(GroupRules::uuid($argv[2] ?? null)); break;
        case 'members': $result = $service->members(null,GroupRules::uuid($argv[2] ?? null),$argv[3] ?? null,$service->limits()['memberPageSize']); break;
        case 'pending': $result = $service->pending(null,GroupRules::uuid($argv[2] ?? null)); break;
        case 'cleanup-deleted': $result = $service->cleanupDeleted($argv[2] ?? null); break;
        default: $result = $service->limits();
    }
    echo GroupRules::json($result) . "\n";
} catch (Throwable $error) { fwrite(STDERR,'Groups operation rejected: ' . $error->getMessage() . "\n"); exit(1); }
