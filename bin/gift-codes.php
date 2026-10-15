<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/GiftCodes/GiftCodesService.php';
try {
    $command = $argv[1] ?? ''; $code = $argv[2] ?? '';
    if (!in_array($command, ['values', 'set-values'], true) || $code === '') {
        fwrite(STDERR, "Usage: php bin/gift-codes.php values CODE\n       php bin/gift-codes.php set-values CODE < reward-values.json\n"); exit(2);
    }
    $request = [];
    if ($command === 'set-values') {
        $raw = stream_get_contents(STDIN, 16385);
        if (strlen($raw) > 16384 || substr(ltrim($raw), 0, 1) !== '{') throw new InvalidArgumentException('A bounded JSON object is required');
        $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
    chdir(dirname(__DIR__)); Config::load('.env'); Database::migrate();
    echo json_encode((new GiftCodesService(Database::connect()))->configureValues($code, $request), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $failure) { fwrite(STDERR, 'Gift-code operation rejected: ' . $failure->getMessage() . "\n"); exit(1); }
