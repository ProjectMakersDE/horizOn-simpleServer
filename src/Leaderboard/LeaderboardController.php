<?php

declare(strict_types=1);

class LeaderboardController
{
    public static function submit(Request $request): void
    {
        $userId = $request->body('userId', '');
        $score = $request->body('score');

        if ($userId === '' || $score === null) {
            Response::badRequest('userId and score are required');
            return;
        }

        $score = (int)$score;
        if ($score < 0) {
            Response::badRequest('score must be non-negative');
            return;
        }

        $pdo = Database::connect();
        $now = Database::now();

        // Upsert: insert or update if new score is higher
        $stmt = $pdo->prepare('SELECT id, score FROM leaderboard WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existing = $stmt->fetch();

        if ($existing === false) {
            $stmt = $pdo->prepare('INSERT INTO leaderboard (id, user_id, score, updated_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([Database::uuid(), $userId, $score, $now]);
        } else {
            if ($score > (int)$existing['score']) {
                $stmt = $pdo->prepare('UPDATE leaderboard SET score = ?, updated_at = ? WHERE user_id = ?');
                $stmt->execute([$score, $now, $userId]);
            }
        }

        Response::json(null);
    }

    public static function top(Request $request): void
    {
        $userId = $request->query('userId', '');
        $limit = $request->queryInt('limit', 10);

        if ($userId === '') {
            Response::badRequest('userId query parameter is required');
            return;
        }

        $limit = max(1, min(100, $limit));

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT l.score, u.display_name as username
             FROM leaderboard l
             JOIN users u ON l.user_id = u.id
             ORDER BY l.score DESC
             LIMIT ?'
        );
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll();

        $entries = [];
        foreach ($rows as $i => $row) {
            $entries[] = [
                'position' => $i + 1,
                'username' => $row['username'],
                'score' => (int)$row['score'],
            ];
        }

        Response::json(['entries' => $entries]);
    }

    public static function rank(Request $request): void
    {
        $userId = $request->query('userId', '');

        if ($userId === '') {
            Response::badRequest('userId query parameter is required');
            return;
        }

        $pdo = Database::connect();

        // Get user's score
        $stmt = $pdo->prepare(
            'SELECT l.score, u.display_name as username
             FROM leaderboard l
             JOIN users u ON l.user_id = u.id
             WHERE l.user_id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            Response::json([
                'position' => 0,
                'username' => '',
                'score' => 0,
            ]);
            return;
        }

        // Count users with higher score
        $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM leaderboard WHERE score > ?');
        $stmt->execute([$user['score']]);
        $rank = (int)$stmt->fetch()['cnt'] + 1;

        Response::json([
            'position' => $rank,
            'username' => $user['username'],
            'score' => (int)$user['score'],
        ]);
    }

    public static function around(Request $request): void
    {
        $userId = $request->query('userId', '');
        $range = $request->queryInt('range', 10);

        if ($userId === '') {
            Response::badRequest('userId query parameter is required');
            return;
        }

        $range = max(1, min(50, $range));

        $pdo = Database::connect();

        // Get user's score first
        $stmt = $pdo->prepare('SELECT score FROM leaderboard WHERE user_id = ?');
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();

        if ($userRow === false) {
            Response::json(['entries' => []]);
            return;
        }

        $userScore = (int)$userRow['score'];

        // Get user's rank
        $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM leaderboard WHERE score > ?');
        $stmt->execute([$userScore]);
        $userRank = (int)$stmt->fetch()['cnt'] + 1;

        // Get entries around (range above and range below)
        $offset = max(0, $userRank - $range - 1);
        $limit = $range * 2 + 1;

        $stmt = $pdo->prepare(
            'SELECT l.score, u.display_name as username
             FROM leaderboard l
             JOIN users u ON l.user_id = u.id
             ORDER BY l.score DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        $rows = $stmt->fetchAll();

        $entries = [];
        foreach ($rows as $i => $row) {
            $entries[] = [
                'position' => $offset + $i + 1,
                'username' => $row['username'],
                'score' => (int)$row['score'],
            ];
        }

        Response::json(['entries' => $entries]);
    }
}
