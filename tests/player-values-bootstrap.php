<?php

declare(strict_types=1);

// A held value mutation must not block unrelated reads during HTTP bootstrap.
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
Config::load(__DIR__ . '/../.env');
$pdo = Database::connect();
$pdo->exec('BEGIN IMMEDIATE');
try {
    $pdo->exec('UPDATE player_values_config SET revision = revision WHERE id = 1');
    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $response = file_get_contents('http://localhost:' . $argv[1] . '/api/v1/app/health', false, $context);
    if ($response === false || !preg_match('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '')) {
        throw new RuntimeException('HTTP bootstrap blocked by an existing player-value write');
    }
    echo "PASS: HTTP health remains readable during a value transaction\n";
} finally {
    $pdo->exec('ROLLBACK');
}
