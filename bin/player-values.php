<?php

declare(strict_types=1);
// This is an operator tool, never an HTTP administration endpoint protected by the public app key.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/PlayerValues/PlayerValuesService.php';

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['config', 'configure', 'player', 'correct'], true)) {
        fwrite(STDERR, "Usage:\n  php bin/player-values.php config\n  php bin/player-values.php configure EXPECTED_REVISION < config.json\n  php bin/player-values.php player USER_UUID\n  php bin/player-values.php correct USER_UUID < correction.json\n");
        exit(2);
    }
    chdir(dirname(__DIR__));
    Config::load('.env'); Database::migrate();
    $service = new PlayerValuesService(Database::connect());
    $input = null;
    if (in_array($command, ['configure', 'correct'], true)) {
        $raw = stream_get_contents(STDIN, 1048577);
        if (strlen($raw) > 1048576) PlayerValuesLedger::fail('INVALID_REQUEST', 'Input exceeds 1 MiB', 400);
        $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input)) PlayerValuesLedger::fail('INVALID_REQUEST', 'A JSON object is required', 400);
    }
    if ($command === 'config') $result = $service->config();
    elseif ($command === 'configure') {
        $revision = $argv[2] ?? '';
        if (!preg_match('/^[0-9]{1,16}$/D', $revision)) PlayerValuesLedger::fail('INVALID_REQUEST', 'Expected revision is required', 400);
        $result = $service->configure($input, PlayerValuesLedger::integer((int)$revision));
    } else {
        $userId = strtolower($argv[2] ?? '');
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $userId)) PlayerValuesLedger::fail('INVALID_REQUEST', 'A player UUID is required', 400);
        $result = $command === 'player' ? $service->history($userId) : $service->correct($userId,
            PlayerValuesLedger::list($input['values'] ?? null, 64), PlayerValuesLedger::text($input['note'] ?? null, 200, 'note'),
            PlayerValuesLedger::text($input['operationId'] ?? null, 128, 'operationId'));
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (PlayerValuesFailure $failure) {
    fwrite(STDERR, json_encode(['code' => $failure->errorCode, 'message' => $failure->getMessage(), 'state' => $failure->state]) . "\n");
    exit(1);
} catch (Throwable $failure) {
    fwrite(STDERR, "Player-values command failed: " . $failure->getMessage() . "\n");
    exit(1);
}
