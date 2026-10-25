<?php

declare(strict_types=1);

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/SqlTransaction.php';
require_once __DIR__ . '/../PlayerProfile/PlayerProfileController.php';
require_once __DIR__ . '/../GiftCodes/GiftCodesService.php';

/** A rejected migration file or target. `errorCode` is stable, the message never contains file content. */
class ProjectMigrationFailure extends RuntimeException
{
    public string $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}

/**
 * Imports `horizon-migration.json` from horizOn BaaS (TASK-691, format version 1).
 *
 * The whole file is validated before anything is written. A broken structure, an unknown version
 * or a manipulated record rejects the file. A record the Simple Server cannot hold is skipped and
 * reported with its reason. The import runs in one transaction, only into an empty target, and a
 * migrationId is imported at most once.
 */
class ProjectMigrationImporter
{
    public const FORMAT = 'horizon-migration';
    public const FORMAT_VERSION = 1;

    private const TYPES = ['remoteConfig', 'localization', 'news', 'giftCodes', 'emailTemplates'];
    private const RECORD_LIMITS = [
        'remoteConfig' => 10000, 'localization' => 100000, 'news' => 10000, 'giftCodes' => 10000, 'emailTemplates' => 1000,
    ];
    private const LANGUAGES = ['en', 'de', 'es', 'fr', 'it', 'pt', 'nl', 'pl', 'ru', 'zh', 'ja', 'ar', 'ko', 'tr', 'id'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
    private const INSTANT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z$/D';
    private const TEXT_BYTES = 65535;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Reads and decodes the file within the byte limit. */
    public static function readFile(string $path, int $maxBytes): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ProjectMigrationFailure('FILE_NOT_READABLE', 'Migration file is not readable');
        }
        if (filesize($path) > $maxBytes) {
            throw new ProjectMigrationFailure('FILE_TOO_LARGE', 'Migration file exceeds MIGRATION_IMPORT_MAX_BYTES');
        }
        $raw = (string)file_get_contents($path);
        if (substr(ltrim($raw), 0, 1) !== '{') {
            throw new ProjectMigrationFailure('INVALID_FILE', 'Migration file must be a JSON object');
        }
        try {
            $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            throw new ProjectMigrationFailure('INVALID_FILE', 'Migration file is not valid JSON');
        }
        if (!is_array($document)) {
            throw new ProjectMigrationFailure('INVALID_FILE', 'Migration file must be a JSON object');
        }
        return $document;
    }

    /** Checks the target and reports what an import of the validated plan would do, without writing. */
    public function dryRun(array $plan): array
    {
        $this->assertTarget($plan['migrationId']);
        return self::report($plan, true);
    }

    /** Writes all records of the validated plan and the migration fence in one transaction. */
    public function import(array $plan): array
    {
        $transactions = new SqlTransaction($this->pdo);
        $transactions->run(function () use ($plan, $transactions) {
            $this->assertTarget($plan['migrationId']);
            $this->write($plan, $transactions);
        });
        return self::report($plan, false);
    }

    // ==================== Validation ====================

    /** Validates the whole file without a database and returns the import plan. */
    public static function validate(array $document): array
    {
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new ProjectMigrationFailure('INVALID_FORMAT', 'Not a horizOn migration file');
        }
        if (($document['formatVersion'] ?? null) !== self::FORMAT_VERSION) {
            throw new ProjectMigrationFailure('UNSUPPORTED_VERSION', 'This Simple Server imports format version ' . self::FORMAT_VERSION . ' only');
        }
        $migrationId = $document['migrationId'] ?? null;
        if (!is_string($migrationId) || !preg_match(self::UUID, $migrationId)) {
            throw new ProjectMigrationFailure('INVALID_FILE', 'migrationId must be a lowercase UUID');
        }
        $data = $document['data'] ?? null;
        if (!is_array($data) || self::isList($data) || array_diff(array_keys($data), self::TYPES) || array_diff(self::TYPES, array_keys($data))) {
            throw new ProjectMigrationFailure('INVALID_FILE', 'data must hold exactly the five content types');
        }
        foreach (self::TYPES as $type) {
            if (!is_array($data[$type]) || !self::isList($data[$type])) {
                throw new ProjectMigrationFailure('INVALID_FILE', "data.$type must be a list");
            }
            if (count($data[$type]) > self::RECORD_LIMITS[$type]) {
                throw new ProjectMigrationFailure('TOO_MANY_RECORDS', "data.$type exceeds " . self::RECORD_LIMITS[$type] . ' records');
            }
        }

        $plan = ['migrationId' => $migrationId, 'rows' => [], 'skipped' => [], 'transformed' => [],
            'sourceExcluded' => self::notes($document['excluded'] ?? []), 'sourceWarnings' => self::notes($document['warnings'] ?? [])];
        $plan['rows']['remoteConfig'] = self::remoteConfig($data['remoteConfig'], $plan);
        $plan['rows']['localization'] = self::localization($data['localization'], $plan);
        $plan['rows']['news'] = self::news($data['news'], $plan);
        $plan['rows']['giftCodes'] = self::giftCodes($data['giftCodes'], $plan);
        $plan['rows']['emailTemplates'] = self::emailTemplates($data['emailTemplates'], $plan);
        return $plan;
    }

    private static function remoteConfig(array $records, array &$plan): array
    {
        $rows = [];
        foreach ($records as $index => $record) {
            $key = self::string($record, 'key', "remoteConfig[$index]");
            $value = self::string($record, 'value', "remoteConfig[$index]", true);
            if (strtolower($key) === 'smtp_config') {
                throw new ProjectMigrationFailure('INVALID_FILE', 'The reserved key smtp_config is never imported');
            }
            if (isset($rows[$key])) {
                throw new ProjectMigrationFailure('DUPLICATE_RECORD', "remoteConfig[$index] repeats a key");
            }
            if (mb_strlen($key) > 256 || strlen($value) > self::TEXT_BYTES) {
                self::skip($plan, 'remoteConfig', $key, 'Key over 256 characters or value over 64 KiB');
                continue;
            }
            $rows[$key] = [$key, $value];
        }
        return array_values($rows);
    }

    private static function localization(array $records, array &$plan): array
    {
        $rows = [];
        $keys = [];
        foreach ($records as $index => $record) {
            $key = self::string($record, 'key', "localization[$index]");
            $translations = self::languageMap($record, 'translations', "localization[$index]");
            if (isset($keys[$key])) {
                throw new ProjectMigrationFailure('DUPLICATE_RECORD', "localization[$index] repeats a key");
            }
            $keys[$key] = true;
            if (mb_strlen($key) > 100) {
                self::skip($plan, 'localization', $key, 'Key over 100 characters, the app endpoint could not read it');
                continue;
            }
            foreach ($translations as $lang => $value) {
                $rows[] = [$key, $lang, $value];
            }
        }
        return $rows;
    }

    private static function news(array $records, array &$plan): array
    {
        $rows = [];
        $ids = [];
        foreach ($records as $index => $record) {
            $path = "news[$index]";
            $id = self::string($record, 'id', $path);
            $releaseDate = self::string($record, 'releaseDate', $path);
            if (!preg_match(self::UUID, $id) || !preg_match(self::INSTANT, $releaseDate)) {
                throw new ProjectMigrationFailure('INVALID_FILE', "$path needs a UUID id and a UTC releaseDate");
            }
            if (isset($ids[$id])) {
                throw new ProjectMigrationFailure('DUPLICATE_RECORD', "$path repeats an id");
            }
            $ids[$id] = true;
            $titles = self::languageMap($record, 'titles', $path);
            $messages = self::languageMap($record, 'messages', $path);
            $published = (new DateTimeImmutable($releaseDate))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $languages = array_values(array_intersect(array_keys($titles), array_keys($messages)));
            foreach (array_unique(array_merge(array_keys($titles), array_keys($messages))) as $lang) {
                if (!in_array($lang, $languages, true)) {
                    self::skip($plan, 'news', "$id/$lang", 'Title or message missing in this language');
                } elseif (trim($titles[$lang]) === '' || mb_strlen($titles[$lang]) > 200) {
                    self::skip($plan, 'news', "$id/$lang", 'Title empty or over 200 characters');
                } else {
                    $rows[] = [$titles[$lang], $messages[$lang], $lang, $published];
                }
            }
            if (count($languages) > 1) {
                self::transform($plan, 'news', $id, 'One news item becomes one row per language');
            }
        }
        return $rows;
    }

    private static function giftCodes(array $records, array &$plan): array
    {
        $rows = [];
        foreach ($records as $index => $record) {
            $path = "giftCodes[$index]";
            $code = self::string($record, 'code', $path);
            if (!preg_match('/^[A-Z0-9]{3,50}$/D', $code)) {
                throw new ProjectMigrationFailure('INVALID_FILE', "$path has an invalid code");
            }
            if (isset($rows[$code])) {
                throw new ProjectMigrationFailure('DUPLICATE_RECORD', "$path repeats a code");
            }
            $giftData = self::string($record, 'giftData', $path, true);
            $values = $record['values'] ?? null;
            if (!is_array($values) || !self::isList($values)) {
                throw new ProjectMigrationFailure('INVALID_FILE', "$path.values must be a list");
            }
            $expiresAt = $record['expiresAt'] ?? null;
            if ($expiresAt !== null && (!is_string($expiresAt) || !preg_match(self::INSTANT, $expiresAt))) {
                throw new ProjectMigrationFailure('INVALID_FILE', "$path.expiresAt must be a UTC instant or null");
            }
            foreach (['maxTotalRedemptions', 'maxRedemptionsPerUser'] as $limit) {
                $value = $record[$limit] ?? null;
                if ($value !== null && (!is_int($value) || $value < 1 || $value > 2147483647)) {
                    throw new ProjectMigrationFailure('INVALID_FILE', "$path.$limit must be a positive Int32 or null");
                }
            }
            if ($values) {
                self::skip($plan, 'giftCodes', $code, 'Rewards player values, which need the player values catalog (not part of V1)');
                continue;
            }
            if (PlayerProfileController::parseGrants($giftData)) {
                self::skip($plan, 'giftCodes', $code, 'Grants cosmetics, which need the cosmetics catalog (not part of V1)');
                continue;
            }
            if ($expiresAt !== null) {
                $expiresAt = (new DateTimeImmutable($expiresAt))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            }
            $rows[$code] = ['code' => $code, 'request' => [
                'giftData' => $giftData === '' ? null : $giftData, 'expiresAt' => $expiresAt,
                'maxTotalRedemptions' => $record['maxTotalRedemptions'] ?? null, 'maxRedemptionsPerUser' => $record['maxRedemptionsPerUser'] ?? null,
            ]];
        }
        return array_values($rows);
    }

    private static function emailTemplates(array $records, array &$plan): array
    {
        $rows = [];
        foreach ($records as $index => $record) {
            $path = "emailTemplates[$index]";
            $slug = self::string($record, 'slug', $path);
            $name = self::string($record, 'name', $path);
            $subject = self::languageMap($record, 'subject', $path);
            $body = self::languageMap($record, 'body', $path);
            $variables = $record['variables'] ?? null;
            if (!is_array($variables) || !self::isList($variables) || array_filter($variables, static function ($v) { return !is_string($v); })) {
                throw new ProjectMigrationFailure('INVALID_FILE', "$path.variables must be a list of strings");
            }
            if (isset($rows[$slug])) {
                throw new ProjectMigrationFailure('DUPLICATE_RECORD', "$path repeats a slug");
            }
            $subjectJson = json_encode((object)$subject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $bodyJson = json_encode((object)$body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (mb_strlen($slug) > 100 || mb_strlen($name) > 200 || strlen($subjectJson) > self::TEXT_BYTES || strlen($bodyJson) > 16777215) {
                self::skip($plan, 'emailTemplates', $slug, 'Slug, name, subject or body exceeds the Simple Server columns');
                continue;
            }
            $rows[$slug] = [Database::uuid(), $slug, $name, $subjectJson, $bodyJson, json_encode($variables, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        }
        return array_values($rows);
    }

    // ==================== Target ====================

    /** Default target is empty, and a migrationId is imported at most once. */
    private function assertTarget(string $migrationId): void
    {
        $fence = $this->pdo->prepare('SELECT COUNT(*) FROM project_migrations WHERE migration_id = ?');
        $fence->execute([$migrationId]);
        if ((int)$fence->fetchColumn() > 0) {
            throw new ProjectMigrationFailure('ALREADY_IMPORTED', 'This migrationId was already imported');
        }
        $used = (int)$this->pdo->query("SELECT COUNT(*) FROM remote_configs WHERE config_key <> 'smtp_config'")->fetchColumn()
            + (int)$this->pdo->query('SELECT COUNT(*) FROM localizations')->fetchColumn()
            + (int)$this->pdo->query('SELECT COUNT(*) FROM news')->fetchColumn()
            + (int)$this->pdo->query('SELECT COUNT(*) FROM gift_codes')->fetchColumn()
            + (int)$this->pdo->query('SELECT COUNT(*) FROM email_templates WHERE deleted = 0')->fetchColumn();
        if ($used > 0) {
            throw new ProjectMigrationFailure('TARGET_NOT_EMPTY', 'Remote Config, localization, news, gift codes or email templates already hold data');
        }
    }

    private function write(array $plan, SqlTransaction $transactions): void
    {
        $insert = $this->pdo->prepare('INSERT INTO remote_configs (config_key, config_value) VALUES (?, ?)');
        foreach ($plan['rows']['remoteConfig'] as $row) {
            $insert->execute($row);
        }
        $insert = $this->pdo->prepare('INSERT INTO localizations (localization_key, lang, value) VALUES (?, ?, ?)');
        foreach ($plan['rows']['localization'] as $row) {
            $insert->execute($row);
        }
        $insert = $this->pdo->prepare('INSERT INTO news (id, title, message, language_code, published_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($plan['rows']['news'] as $row) {
            $insert->execute(array_merge([Database::uuid()], $row));
        }
        $gifts = new GiftCodesService($this->pdo, null, $transactions);
        foreach ($plan['rows']['giftCodes'] as $row) {
            $gifts->create(array_merge(['code' => $row['code']], $row['request']));
        }
        $insert = $this->pdo->prepare('INSERT INTO email_templates (id, slug, name, subject, body, variables) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($plan['rows']['emailTemplates'] as $row) {
            $insert->execute($row);
        }
        $this->pdo->prepare('INSERT INTO project_migrations (migration_id, imported_at, summary) VALUES (?, ?, ?)')
            ->execute([$plan['migrationId'], gmdate('Y-m-d H:i:s'), json_encode(self::counts($plan), JSON_THROW_ON_ERROR)]);
    }

    // ==================== Report ====================

    private static function report(array $plan, bool $dryRun): array
    {
        return [
            'migrationId' => $plan['migrationId'],
            'dryRun' => $dryRun,
            'imported' => self::counts($plan),
            'transformed' => $plan['transformed'],
            'skipped' => $plan['skipped'],
            'sourceExcluded' => $plan['sourceExcluded'],
            'sourceWarnings' => $plan['sourceWarnings'],
        ];
    }

    private static function counts(array $plan): array
    {
        return array_map('count', $plan['rows']);
    }

    private static function skip(array &$plan, string $type, string $ref, string $reason): void
    {
        $plan['skipped'][] = ['type' => $type, 'ref' => $ref, 'reason' => $reason];
    }

    private static function transform(array &$plan, string $type, string $ref, string $reason): void
    {
        $plan['transformed'][] = ['type' => $type, 'ref' => $ref, 'reason' => $reason];
    }

    /** Excluded and warning notes of the export, passed through as plain text only. */
    private static function notes($notes): array
    {
        if (!is_array($notes) || !self::isList($notes)) {
            throw new ProjectMigrationFailure('INVALID_FILE', 'excluded and warnings must be lists');
        }
        $result = [];
        foreach ($notes as $note) {
            if (!is_array($note)) {
                throw new ProjectMigrationFailure('INVALID_FILE', 'excluded and warnings hold objects');
            }
            $entry = [];
            foreach (['type', 'ref', 'count', 'reason', 'impact'] as $field) {
                if (isset($note[$field]) && (is_string($note[$field]) || is_int($note[$field]))) {
                    $entry[$field] = is_string($note[$field]) ? mb_substr($note[$field], 0, 300) : $note[$field];
                }
            }
            $result[] = $entry;
        }
        return $result;
    }

    // ==================== Helpers ====================

    private static function string($record, string $field, string $path, bool $allowEmpty = false): string
    {
        $value = is_array($record) ? ($record[$field] ?? null) : null;
        if (!is_string($value) || (!$allowEmpty && trim($value) === '') || !mb_check_encoding($value, 'UTF-8')) {
            throw new ProjectMigrationFailure('INVALID_FILE', "$path.$field must be a non-empty UTF-8 string");
        }
        return $value;
    }

    private static function languageMap($record, string $field, string $path): array
    {
        $map = is_array($record) ? ($record[$field] ?? null) : null;
        if (!is_array($map) || ($map && self::isList($map))) {
            throw new ProjectMigrationFailure('INVALID_FILE', "$path.$field must be an object of languages");
        }
        foreach ($map as $lang => $value) {
            if (!in_array($lang, self::LANGUAGES, true) || !is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new ProjectMigrationFailure('INVALID_FILE', "$path.$field has an unknown language or a non-string value");
            }
        }
        return $map;
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
