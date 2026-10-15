<?php

declare(strict_types=1);
// Operator-only: reject direct file requests before loading settings or touching the database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Statistics/StatisticsService.php';

try {
    Config::load(__DIR__ . '/../.env'); Database::migrate(); $service = new StatisticsService(Database::connect());
    $command = $argv[1] ?? 'show';
    if ($command === 'show') $result = $service->definitions();
    elseif ($command === 'configure' && isset($argv[2]) && preg_match('/^[0-9]{1,16}$/D', $argv[2]) && (int)$argv[2] < StatisticsRules::MAX) {
        $body = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body) || !isset($body['definitions'])) StatisticsRules::fail('INVALID_REQUEST', 'Expected a definitions array');
        $result = $service->configure(StatisticsRules::list($body['definitions']), (int)$argv[2]);
    } else StatisticsRules::fail('INVALID_REQUEST', 'Usage: php bin/statistics.php show | configure <expectedVersion> < catalog.json');
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (StatisticsFailure $failure) { fwrite(STDERR, $failure->errorCode . ': ' . $failure->getMessage() . PHP_EOL); exit(1); }
catch (Throwable $failure) { fwrite(STDERR, "Configuration failed; verify the local database/settings and JSON input\n"); exit(1); }
