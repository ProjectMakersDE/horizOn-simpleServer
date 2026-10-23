<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Inbox/InboxMigration.php';
require_once __DIR__ . '/../src/SharedObjects/TrustedNoticeService.php';

try {
    $command = $argv[1] ?? 'broadcasts';
    if(in_array($command,['compact-apply','sources-apply'],true)&&(($argv[4]??'')!=='--writes-stopped'||($argv[5]??'')!=='--backup-verified'))throw new InvalidArgumentException('Stop all writers and verify a restorable backup first');
    Config::load(__DIR__ . '/../.env'); Database::migrate(); $service = new InboxService(Database::connect());
    if(in_array($command,['compact-inspect','compact-apply','sources-inspect','sources-apply'],true)){
        $size=$argv[3]??'10';if(!preg_match('/^[1-9][0-9]{0,2}$/D',$size)||(int)$size>100)InboxRules::invalid('Page size1..100');
        $migration=new InboxMigration(Database::connect());$after=($argv[2]??'-')==='-'?null:$argv[2];
        $result=strpos($command,'sources-')===0?$migration->sourcePage($after,(int)$size,$command==='sources-apply'):$migration->page($after,(int)$size,$command==='compact-apply');
    } elseif($command==='cleanup')$result=['processed'=>$service->cleanup()];
    elseif($command==='free-text'){
        $raw=stream_get_contents(STDIN,16385);if(strlen($raw)>16384)InboxRules::invalid('Input exceeds16KiB');
        $result=(new TrustedNoticeService(Database::connect()))->sendCommitted(SharedObjectRules::decode($raw));
    } elseif ($command === 'broadcasts') $result = $service->broadcasts();
    elseif ($command === 'player' && isset($argv[2])) $result = $service->get(InboxRules::uuid($argv[2]));
    elseif ($command === 'publish' && isset($argv[2]) && preg_match('/^[0-9]{1,16}$/D', $argv[2]) && (int)$argv[2] <= PlayerValuesLedger::MAX) {
        $raw = stream_get_contents(STDIN, 16385); if (strlen($raw) > 16384) InboxRules::invalid('Input exceeds16KiB');
        $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) InboxRules::invalid('A JSON object is required');
        $operation = $body['operationId'] ?? null; InboxRules::issued($operation);
        $result = $service->publish($body, $operation, (int)$argv[2]);
    } elseif ($command === 'remove-broadcast' && isset($argv[2], $argv[3]) && preg_match('/^[0-9]{1,16}$/D', $argv[2]) && (int)$argv[2] <= PlayerValuesLedger::MAX) {
        $result = $service->removeBroadcast(InboxRules::entry($argv[3]), (int)$argv[2]);
    } else InboxRules::invalid('Usage: broadcasts | player UUID | publish EXPECTED_REVISION | remove-broadcast EXPECTED_REVISION ENTRY_ID | free-text < intent.json | cleanup | compact-inspect/compact-apply/sources-inspect/sources-apply AFTER_OR_DASH PAGE_SIZE [--writes-stopped --backup-verified]');
    echo InboxRules::json($result), PHP_EOL;
} catch (InboxFailure $failure) { fwrite(STDERR, $failure->errorCode . ': ' . $failure->getMessage() . PHP_EOL); exit(1); }
catch (PlayerValuesFailure $failure) { fwrite(STDERR, $failure->errorCode . ': ' . $failure->getMessage() . PHP_EOL); exit(1); }
catch (Throwable $failure) { fwrite(STDERR, "Inbox operator failed; check local settings and JSON input\n"); exit(1); }
