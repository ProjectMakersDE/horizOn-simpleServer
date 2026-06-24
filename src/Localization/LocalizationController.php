<?php

declare(strict_types=1);

class LocalizationController
{
    /** Supported languages (mirrors horizOn BaaS auto-translation set). */
    private const LANGUAGES = [
        'en', 'de', 'es', 'fr', 'it', 'pt', 'nl', 'pl',
        'ru', 'ja', 'zh', 'ar', 'ko', 'tr', 'id',
    ];

    private const DEFAULT_LANGUAGE = 'en';

    public static function get(Request $request): void
    {
        $key = $request->param('key');

        if ($key === null || $key === '') {
            Response::badRequest('Localization key is required');
            return;
        }

        $lang = $request->query('lang', self::DEFAULT_LANGUAGE);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT value FROM localizations WHERE localization_key = ? AND lang = ?');
        $stmt->execute([$key, $lang]);
        $row = $stmt->fetch();

        // Fall back to English if the requested language has no value.
        // `language` reports the language actually served (en on fallback),
        // matching the hosted backend contract.
        $servedLang = $lang;
        if ($row === false && $lang !== self::DEFAULT_LANGUAGE) {
            $stmt->execute([$key, self::DEFAULT_LANGUAGE]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $servedLang = self::DEFAULT_LANGUAGE;
            }
        }

        Response::json([
            'localizationKey' => $key,
            'value' => $row !== false ? $row['value'] : null,
            'language' => $servedLang,
            'found' => $row !== false,
        ]);
    }

    public static function all(Request $request): void
    {
        $lang = $request->query('lang', self::DEFAULT_LANGUAGE);

        $pdo = Database::connect();
        // Mirror the hosted backend: each key resolves to its value in the requested
        // language, falling back to English; keys with neither are omitted.
        $stmt = $pdo->prepare(
            'SELECT k.localization_key AS localization_key, '
            . 'COALESCE(req.value, en.value) AS value '
            . 'FROM (SELECT DISTINCT localization_key FROM localizations) k '
            . 'LEFT JOIN localizations req ON req.localization_key = k.localization_key AND req.lang = ? '
            . 'LEFT JOIN localizations en ON en.localization_key = k.localization_key AND en.lang = ? '
            . 'WHERE COALESCE(req.value, en.value) IS NOT NULL'
        );
        $stmt->execute([$lang, self::DEFAULT_LANGUAGE]);
        $rows = $stmt->fetchAll();

        $translations = [];
        foreach ($rows as $row) {
            $translations[$row['localization_key']] = $row['value'];
        }

        Response::json([
            'translations' => $translations,
            'language' => $lang,
            'total' => count($translations),
        ]);
    }

    public static function languages(Request $request): void
    {
        // Mirror the hosted backend: return only languages that actually have content.
        $pdo = Database::connect();
        $present = $pdo->query('SELECT DISTINCT lang FROM localizations')->fetchAll(PDO::FETCH_COLUMN);

        // Keep canonical order, drop languages with no rows.
        $languages = array_values(array_intersect(self::LANGUAGES, $present));

        Response::json([
            'languages' => $languages,
            'total' => count($languages),
        ]);
    }
}
