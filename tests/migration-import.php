<?php

declare(strict_types=1);
// End-to-end check of bin/migration-import.php (TASK-691): dry run, import, mappings, skips,
// manipulated files, rollback and a second import, against a throwaway SQLite database.
// MySQL: set HORIZON_MIGRATION_TEST_DSN (loopback, empty database task691_<hex>) plus
// HORIZON_MIGRATION_TEST_USER and HORIZON_MIGRATION_TEST_PASSWORD.
require_once __DIR__ . '/../src/Core/Database.php';

$passed = 0;
$dir = sys_get_temp_dir() . '/migration-import-' . Database::uuid();
mkdir($dir);
mkdir($dir . '/bin');
copy(__DIR__ . '/../bin/migration-import.php', $dir . '/bin/migration-import.php');
symlink(realpath(__DIR__ . '/../src'), $dir . '/src');
symlink(realpath(__DIR__ . '/../migrations'), $dir . '/migrations');
$db = $dir . '/data/horizon.db';
$dsn = getenv('HORIZON_MIGRATION_TEST_DSN') ?: '';
if ($dsn !== '' && !preg_match('/^mysql:host=127\.0\.0\.1;port=([0-9]{1,5});dbname=(task691_[a-f0-9]+);charset=utf8mb4$/D', $dsn, $mysql)) {
    throw new RuntimeException('Only an explicit loopback task691_<hex> database is permitted');
}
$env = $dsn === '' ? "DB_DRIVER=sqlite\nDB_PATH=$db\n"
    : "DB_DRIVER=mysql\nDB_HOST=127.0.0.1\nDB_PORT={$mysql[1]}\nDB_NAME={$mysql[2]}\nDB_USER=" . getenv('HORIZON_MIGRATION_TEST_USER')
        . "\nDB_PASS=" . getenv('HORIZON_MIGRATION_TEST_PASSWORD') . "\n";
$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/horizon-migration-v1.json'), true);

function miCheck($expected, $actual, string $label): void
{
    global $passed;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': ' . json_encode([$expected, $actual]));
    }
    $passed++;
}

function miRun(string $dir, array $document, array $options = []): array
{
    $file = $dir . '/upload-' . Database::uuid() . '.json';
    file_put_contents($file, json_encode($document));
    $pipes = [];
    $proc = proc_open(array_merge([PHP_BINARY, $dir . '/bin/migration-import.php', $file], $options), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    unlink($file);
    return [proc_close($proc), json_decode((string)$out, true), $err];
}

function miCounts(PDO $pdo): array
{
    $counts = [];
    foreach (['remote_configs', 'localizations', 'news', 'gift_codes', 'email_templates', 'project_migrations'] as $table) {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
    return $counts;
}

/** Whether the target holds any table yet: a rejected file must not even create the schema. */
function miTargetTouched(string $db, string $dsn): bool
{
    if ($dsn === '') return is_file($db);
    return (int)miConnect($db, $dsn)->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn() > 0;
}

function miConnect(string $db, string $dsn): PDO
{
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    return $dsn === '' ? new PDO('sqlite:' . $db, null, null, $options)
        : new PDO($dsn, (string)getenv('HORIZON_MIGRATION_TEST_USER'), (string)getenv('HORIZON_MIGRATION_TEST_PASSWORD'), $options);
}

function miVariant(array $fixture, callable $change): array
{
    $change($fixture);
    return $fixture;
}

try {
    file_put_contents($dir . '/.env', $env);
    miCheck(false, miTargetTouched($db, $dsn), 'target starts empty');

    // A rejected file never opens or creates the database.
    [$status, , $err] = miRun($dir, miVariant($fixture, function (&$f) { $f['formatVersion'] = 2; }));
    miCheck(2, $status, 'unknown format version is rejected');
    miCheck(true, strpos($err, 'UNSUPPORTED_VERSION') !== false, 'rejection names the stable code');
    miCheck(false, miTargetTouched($db, $dsn), 'rejected file does not create the database');

    $manipulated = [
        'reserved SMTP key' => function (&$f) { $f['data']['remoteConfig'][] = ['key' => 'SMTP_config', 'value' => '{}']; },
        'duplicate localization key' => function (&$f) { $f['data']['localization'][] = $f['data']['localization'][0]; },
        'unknown language' => function (&$f) { $f['data']['localization'][0]['translations']['xx'] = 'x'; },
        'invalid gift code' => function (&$f) { $f['data']['giftCodes'][0]['code'] = 'lower'; },
        'negative limit' => function (&$f) { $f['data']['giftCodes'][0]['maxTotalRedemptions'] = 0; },
        'unknown content type' => function (&$f) { $f['data']['users'] = []; },
        'missing content type' => function (&$f) { unset($f['data']['news']); },
        'malformed migrationId' => function (&$f) { $f['migrationId'] = '../../etc'; },
        'non-string news title' => function (&$f) { $f['data']['news'][0]['titles']['en'] = 5; },
    ];
    foreach ($manipulated as $label => $change) {
        [$status] = miRun($dir, miVariant($fixture, $change));
        miCheck(2, $status, "manipulated file rejected: $label");
    }
    miCheck(false, miTargetTouched($db, $dsn), 'manipulated files never touch the database');

    // Dry run validates and checks the target without writing data.
    [$status, $dry] = miRun($dir, $fixture, ['--dry-run']);
    miCheck(0, $status, 'dry run succeeds');
    miCheck(true, $dry['dryRun'], 'dry run is marked');
    $expected = ['remoteConfig' => 2, 'localization' => 3, 'news' => 3, 'giftCodes' => 2, 'emailTemplates' => 1];
    miCheck($expected, $dry['imported'], 'dry run reports the planned rows');
    $pdo = miConnect($db, $dsn);
    miCheck(['remote_configs' => 0, 'localizations' => 0, 'news' => 0, 'gift_codes' => 0, 'email_templates' => 0, 'project_migrations' => 0],
        miCounts($pdo), 'dry run writes no data');

    // A failure in the middle rolls back every row written before it.
    $pdo->exec($dsn === '' ? "CREATE TRIGGER block_templates BEFORE INSERT ON email_templates BEGIN SELECT RAISE(ABORT, 'blocked'); END"
        : "CREATE TRIGGER block_templates BEFORE INSERT ON email_templates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'");
    [$status] = miRun($dir, $fixture);
    miCheck(3, $status, 'failing write ends the import');
    miCheck(0, array_sum(miCounts($pdo)), 'failed import leaves no partial data');
    $pdo->exec('DROP TRIGGER block_templates');

    // Import.
    [$status, $result] = miRun($dir, $fixture);
    miCheck(0, $status, 'import succeeds');
    miCheck($expected, $result['imported'], 'import reports the rows');
    miCheck(['remote_configs' => 2, 'localizations' => 3, 'news' => 3, 'gift_codes' => 2, 'email_templates' => 1, 'project_migrations' => 1],
        miCounts($pdo), 'rows are written');
    miCheck(['news:2e0d7c4b-3a5f-4b9c-8d2e-6f8a4b3c9d51/fr', 'giftCodes:GOLDPACK', 'giftCodes:AVATAR'],
        array_map(function ($s) { return $s['type'] . ':' . $s['ref']; }, $result['skipped']), 'skipped records are named');
    miCheck(true, (bool)array_filter($result['skipped'], function ($s) { return strpos($s['reason'], 'catalog') !== false; }), 'skips carry a reason');
    miCheck('news', $result['transformed'][0]['type'], 'news split per language is reported');
    miCheck('players', $result['sourceExcluded'][0]['type'], 'export exclusions are passed through');
    miCheck('LAUNCH', $result['sourceWarnings'][0]['ref'], 'export warnings are passed through');

    // Mappings.
    miCheck('hard', $pdo->query("SELECT config_value FROM remote_configs WHERE config_key = 'difficulty'")->fetchColumn(), 'remote config value');
    miCheck('Spielen', $pdo->query("SELECT value FROM localizations WHERE localization_key = 'menu.play' AND lang = 'de'")->fetchColumn(), 'localization per language');
    miCheck(['de', 'en', 'en'], array_column($pdo->query('SELECT language_code FROM news ORDER BY published_at DESC, language_code')->fetchAll(), 'language_code'), 'news rows per language');
    miCheck('2026-10-23 12:00:00', $pdo->query("SELECT published_at FROM news WHERE title = 'Patch 1.2' AND language_code = 'en'")->fetchColumn(), 'news release date in UTC');
    $launch = $pdo->query("SELECT * FROM gift_codes WHERE code = 'LAUNCH'")->fetch();
    miCheck(['2', '100', '1', '0', '0', '{"gold":100}', '2026-11-23 12:00:00'],
        array_map('strval', [$launch['protocol_version'], $launch['max_redemptions'], $launch['max_per_user'], $launch['unlimited_total'], $launch['current_redemptions'], $launch['reward_data'], $launch['expires_at']]),
        'gift code configuration');
    $unlimited = $pdo->query("SELECT unlimited_total, max_per_user FROM gift_codes WHERE code = 'UNLIMITED'")->fetch();
    miCheck(['1', null], [(string)$unlimited['unlimited_total'], $unlimited['max_per_user']], 'unlimited gift code');
    $template = $pdo->query("SELECT * FROM email_templates WHERE slug = 'welcome'")->fetch();
    miCheck(['de' => 'Hallo {{name}}', 'en' => 'Hi {{name}}'], json_decode($template['subject'], true), 'email subject map');
    miCheck(['name'], json_decode($template['variables'], true), 'email variables');

    // A second import of the same file and an import into a filled target change nothing.
    $before = miCounts($pdo);
    [$status, , $err] = miRun($dir, $fixture);
    miCheck(4, $status, 'same file twice is refused');
    miCheck(true, strpos($err, 'ALREADY_IMPORTED') !== false, 'second import names the code');
    [$status, , $err] = miRun($dir, miVariant($fixture, function (&$f) { $f['migrationId'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; }));
    miCheck(4, $status, 'filled target is refused');
    miCheck(true, strpos($err, 'TARGET_NOT_EMPTY') !== false, 'filled target names the code');
    miCheck($before, miCounts($pdo), 'refused imports change nothing');

    // Byte limit is enforced before parsing.
    file_put_contents($dir . '/.env', $env . "MIGRATION_IMPORT_MAX_BYTES=100\n");
    [$status, , $err] = miRun($dir, $fixture, ['--dry-run']);
    miCheck(2, $status, 'oversized file rejected');
    miCheck(true, strpos($err, 'FILE_TOO_LARGE') !== false, 'oversized file names the code');

    echo 'PASS: ' . $passed . ' migration import assertions (' . ($dsn === '' ? 'SQLite' : 'MySQL') . ")\n";
} finally {
    $pdo = null;
    foreach (['src', 'migrations'] as $link) {
        if (is_link($dir . '/' . $link)) unlink($dir . '/' . $link);
    }
    foreach (array_merge(glob($dir . '/bin/*') ?: [], glob($dir . '/data/*') ?: [], glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: []) as $file) {
        if (is_file($file)) unlink($file);
    }
    foreach ([$dir . '/bin', $dir . '/data', $dir] as $folder) {
        if (is_dir($folder)) rmdir($folder);
    }
}
