<?php

declare(strict_types=1);

class NewsController
{
    public static function list(Request $request): void
    {
        $limit = $request->queryInt('limit', 20);
        $languageCode = $request->query('languageCode');

        $limit = max(0, min(100, $limit));

        $pdo = Database::connect();

        if ($languageCode !== null && $languageCode !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, title, message, published_at as releaseDate, language_code as languageCode
                 FROM news
                 WHERE language_code = ?
                 ORDER BY published_at DESC
                 LIMIT ?'
            );
            $stmt->execute([$languageCode, $limit]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, title, message, published_at as releaseDate, language_code as languageCode
                 FROM news
                 ORDER BY published_at DESC
                 LIMIT ?'
            );
            $stmt->execute([$limit]);
        }

        $rows = $stmt->fetchAll();

        // The real server returns a flat array (not wrapped in an object)
        Response::json($rows);
    }
}
