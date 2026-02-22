<?php

declare(strict_types=1);

class RateLimit
{
    public static function check(Request $request): void
    {
        if (!Config::getBool('RATE_LIMIT_ENABLED', true)) {
            return;
        }

        $maxPerSecond = Config::getInt('RATE_LIMIT_PER_SECOND', 10);
        $ip = $request->clientIp();
        $now = time();
        $pdo = Database::connect();

        // Get current rate limit entry
        $stmt = $pdo->prepare('SELECT request_count, window_start FROM rate_limits WHERE ip_address = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row === false) {
            // First request from this IP
            $stmt = $pdo->prepare('INSERT INTO rate_limits (ip_address, request_count, window_start) VALUES (?, 1, ?)');
            $stmt->execute([$ip, $now]);
            return;
        }

        if ($row['window_start'] < $now) {
            // Window expired, reset
            $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = 1, window_start = ? WHERE ip_address = ?');
            $stmt->execute([$now, $ip]);
            return;
        }

        if ($row['request_count'] >= $maxPerSecond) {
            header('Retry-After: 1');
            Response::tooManyRequests('Rate limit exceeded. Max ' . $maxPerSecond . ' requests per second.');
        }

        // Increment counter
        $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ?');
        $stmt->execute([$ip]);
    }
}
