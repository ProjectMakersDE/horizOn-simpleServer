<?php

declare(strict_types=1);
// This is an operator tool, never an HTTP administration endpoint protected by the public app key.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/PlayerValues/PlayerValuesService.php';
require_once __DIR__ . '/../src/PlayerValues/PlayerValuesMigration.php';
require_once __DIR__ . '/../src/Items/ItemJson.php';

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['config', 'configure', 'player', 'correct', 'compact-inspect', 'compact-apply'], true)) {
        fwrite(STDERR, "Usage:\n  php bin/player-values.php config\n  php bin/player-values.php configure EXPECTED_REVISION < config.json\n  php bin/player-values.php player USER_UUID\n  php bin/player-values.php correct USER_UUID < correction.json\n");
        fwrite(STDERR, "  php bin/player-values.php compact-inspect AFTER_UUID_OR_DASH PAGE_SIZE\n  php bin/player-values.php compact-apply AFTER_UUID_OR_DASH PAGE_SIZE --writes-stopped --backup-verified\n");
        exit(2);
    }
    chdir(dirname(__DIR__));
    Config::load('.env'); Database::migrate();
    if ($command === 'compact-inspect' || $command === 'compact-apply') {
        $size = $argv[3] ?? '100';
        if (!preg_match('/^[1-9][0-9]{0,2}$/D', $size)) throw new InvalidArgumentException('Expected page size');
        if ($command === 'compact-apply' && (($argv[4] ?? '') !== '--writes-stopped' || ($argv[5] ?? '') !== '--backup-verified')) {
            throw new InvalidArgumentException('Stop all writers and verify a restorable backup before applying');
        }
        $after = ($argv[2] ?? '-') === '-' ? '' : $argv[2];
        echo json_encode((new PlayerValuesMigration(Database::connect()))->page($after, (int)$size, $command === 'compact-apply'), JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    $service = new PlayerValuesService(Database::connect());
    $input = null;
    if (in_array($command, ['configure', 'correct'], true)) {
        $raw = stream_get_contents(STDIN, 1048577);
        if (strlen($raw) > 1048576) PlayerValuesLedger::fail('INVALID_REQUEST', 'Input exceeds 1 MiB', 400);
        if ($command === 'configure') {
            // Preserve JSON array/object identity before legacy associative normalization.
            $configObject = ItemJson::decode($raw, 1048576);
            foreach (['definitions', 'offers'] as $field) if (property_exists($configObject, $field) && !is_array($configObject->{$field})) ItemRules::invalid();
            foreach ($configObject->offers ?? [] as $offer) {
                if (!$offer instanceof stdClass) ItemRules::invalid();
                foreach (['costs', 'rewards', 'itemRewards'] as $field)
                    if (property_exists($offer, $field) && !is_array($offer->{$field})) ItemRules::invalid();
            }
        }
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
            PlayerValuesLedger::list($input['values'] ?? null, 256), PlayerValuesLedger::text($input['note'] ?? null, 200, 'note'),
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
