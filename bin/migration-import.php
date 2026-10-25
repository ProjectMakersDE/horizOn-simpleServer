<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Core/Config.php';
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Migration/ProjectMigrationImporter.php';

// Imports horizon-migration.json from horizOn BaaS. Usage:
//   php bin/migration-import.php /path/horizon-migration.json --dry-run
//   php bin/migration-import.php /path/horizon-migration.json
// Exit codes: 0 done, 2 file rejected, 4 target not empty or file already imported, 3 other failure.
// Output is a JSON summary with counts and reasons only, never file content.
try {
    $path = $argv[1] ?? '';
    $options = array_slice($argv, 2);
    if ($path === '' || array_diff($options, ['--dry-run'])) {
        throw new InvalidArgumentException('Usage: php bin/migration-import.php <file> [--dry-run]');
    }
    chdir(dirname(__DIR__));
    Config::load('.env');
    $document = ProjectMigrationImporter::readFile($path, Config::operatorLimit('MIGRATION_IMPORT_MAX_BYTES', 33554432));
    // The whole file is checked before the database is opened, so a rejected file changes nothing.
    $plan = ProjectMigrationImporter::validate($document);
    Database::migrate();
    $importer = new ProjectMigrationImporter(Database::connect());
    $result = in_array('--dry-run', $options, true) ? $importer->dryRun($plan) : $importer->import($plan);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $failure) {
    $code = $failure instanceof ProjectMigrationFailure ? $failure->errorCode
        : ($failure instanceof GiftCodesFailure ? 'GIFT_CODE_' . $failure->errorCode : 'INVALID_OR_FAILED_OPERATION');
    $detail = $failure instanceof ProjectMigrationFailure || $failure instanceof InvalidArgumentException ? ': ' . $failure->getMessage() : '';
    fwrite(STDERR, 'Migration import rejected: ' . $code . $detail . "\n");
    if ($failure instanceof ProjectMigrationFailure) {
        exit(in_array($code, ['TARGET_NOT_EMPTY', 'ALREADY_IMPORTED'], true) ? 4 : 2);
    }
    exit($failure instanceof InvalidArgumentException || $failure instanceof GiftCodesFailure ? 2 : 3);
}
