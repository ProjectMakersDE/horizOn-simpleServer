<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Request.php';
require_once __DIR__ . '/../src/RemoteConfig/RemoteConfigController.php';
require_once __DIR__ . '/../src/EmailSending/EmailSendingController.php';

// SQLite emulates the case/accent/trailing-space equivalence of common MySQL
// utf8mb4 collations. The production schema does not pin a binary collation.
// This fixture is in memory and cannot contact any deployed database or SMTP.
class Database
{
    public static PDO $pdo;

    public static function connect(): PDO
    {
        return self::$pdo;
    }
}

class CapturedResponse extends RuntimeException
{
    public array $data;

    public function __construct(array $data)
    {
        parent::__construct('Captured JSON response');
        $this->data = $data;
    }
}

class Response
{
    public static function json(array $data): void
    {
        throw new CapturedResponse($data);
    }
}

Database::$pdo = class_exists('Pdo\\Sqlite')
    ? new \Pdo\Sqlite('sqlite::memory:')
    : new PDO('sqlite::memory:');
Database::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$collation = function (string $left, string $right): int {
    $normalize = function (string $value): string {
        return str_replace(['ó', 'ú'], ['o', 'u'], strtolower(rtrim($value)));
    };
    return strcmp($normalize($left), $normalize($right));
};
if (method_exists(Database::$pdo, 'createCollation')) {
    Database::$pdo->createCollation('MYSQL_LIKE', $collation);
} else {
    Database::$pdo->sqliteCreateCollation('MYSQL_LIKE', $collation);
}
Database::$pdo->exec('CREATE TABLE remote_configs (config_key TEXT COLLATE MYSQL_LIKE, config_value TEXT)');
$insert = Database::$pdo->prepare('INSERT INTO remote_configs VALUES (?, ?)');
$insert->execute(['smtp_config', '{"host":"test.invalid","from_email":"test@example.invalid"}']);
$insert->execute(['game.rule', 'public-value']);

foreach (['smtp_cónfig' => false, 'game.rúle' => true] as $lookup => $expectedFound) {
    $request = new Request();
    $request->setParams(['key' => $lookup]);
    try {
        RemoteConfigController::get($request);
        throw new RuntimeException('Missing config response');
    } catch (CapturedResponse $response) {
        if ($response->data['found'] !== $expectedFound
            || (!$expectedFound && $response->data['configValue'] !== null)
        ) {
            fwrite(STDERR, "FAIL: collated config lookup $lookup\n");
            exit(1);
        }
    }
}

$smtpMethod = new ReflectionMethod(EmailSendingController::class, 'getSmtpConfig');
if (PHP_VERSION_ID < 80100) {
    $smtpMethod->setAccessible(true);
}
foreach (['SMTP_CONFIG', 'smtp_config ', 'smtp_config'] as $storedKey) {
    Database::$pdo->exec('DELETE FROM remote_configs');
    $insert->execute([$storedKey, '{"host":"test.invalid","from_email":"test@example.invalid"}']);
    $config = $smtpMethod->invoke(null, Database::$pdo);
    if (($config !== null) !== ($storedKey === 'smtp_config')) {
        fwrite(STDERR, "FAIL: SMTP config key must match byte for byte\n");
        exit(1);
    }
}

echo "PASS: collated lookups hide SMTP secrets and only the exact SMTP key activates sending\n";
