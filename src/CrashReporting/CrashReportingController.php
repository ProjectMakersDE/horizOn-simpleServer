<?php

declare(strict_types=1);

class CrashReportingController
{
    private const VALID_TYPES = ['CRASH', 'NON_FATAL', 'ANR'];

    public static function create(Request $request): void
    {
        $body = $request->body();
        if ($body === null) {
            Response::badRequest('Request body is required');
            return;
        }

        // Validate required fields
        $type = $body['type'] ?? '';
        $message = $body['message'] ?? '';
        $fingerprint = $body['fingerprint'] ?? '';
        $appVersion = $body['appVersion'] ?? '';
        $sdkVersion = $body['sdkVersion'] ?? '';
        $platform = $body['platform'] ?? '';
        $os = $body['os'] ?? '';
        $deviceModel = $body['deviceModel'] ?? '';
        $sessionId = $body['sessionId'] ?? '';

        if ($type === '' || $message === '' || $fingerprint === '' || $appVersion === '' ||
            $sdkVersion === '' || $platform === '' || $os === '' || $deviceModel === '' || $sessionId === '') {
            Response::badRequest('Missing required fields: type, message, fingerprint, appVersion, sdkVersion, platform, os, deviceModel, sessionId');
            return;
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            Response::badRequest('type must be one of: CRASH, NON_FATAL, ANR');
            return;
        }

        $stackTrace = $body['stackTrace'] ?? null;
        $deviceMemoryMb = (int)($body['deviceMemoryMb'] ?? 0);
        $userId = $body['userId'] ?? null;
        $breadcrumbs = isset($body['breadcrumbs']) ? json_encode($body['breadcrumbs']) : null;
        $customKeys = isset($body['customKeys']) ? json_encode($body['customKeys']) : null;

        $pdo = Database::connect();
        $reportId = Database::uuid();
        $now = Database::now();

        // Insert crash report
        $stmt = $pdo->prepare(
            'INSERT INTO crash_reports (id, type, message, stack_trace, fingerprint, app_version, sdk_version,
             platform, os, device_model, device_memory_mb, session_id, user_id, breadcrumbs, custom_keys, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $reportId, $type, $message, $stackTrace, $fingerprint, $appVersion, $sdkVersion,
            $platform, $os, $deviceModel, $deviceMemoryMb, $sessionId, $userId, $breadcrumbs, $customKeys, $now,
        ]);

        // Upsert crash group
        $groupId = self::upsertCrashGroup($pdo, $fingerprint, $type, $message, $stackTrace, $appVersion, $platform, $userId, $now);

        // Mark session as crashed
        $stmt = $pdo->prepare('UPDATE crash_sessions SET has_crash = 1 WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        Response::created([
            'id' => $reportId,
            'groupId' => $groupId,
            'createdAt' => $now,
        ]);
    }

    public static function session(Request $request): void
    {
        $sessionId = $request->body('sessionId', '');
        $appVersion = $request->body('appVersion', '');
        $platform = $request->body('platform', '');

        if ($sessionId === '' || $appVersion === '' || $platform === '') {
            Response::badRequest('sessionId, appVersion, and platform are required');
            return;
        }

        $userId = $request->body('userId');
        $pdo = Database::connect();

        // Check if session already exists (deduplicate)
        $stmt = $pdo->prepare('SELECT id FROM crash_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        if ($stmt->fetch() !== false) {
            Response::json(['status' => 'ok']);
            return;
        }

        $now = Database::now();
        $stmt = $pdo->prepare(
            'INSERT INTO crash_sessions (id, session_id, user_id, app_version, platform, has_crash, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([Database::uuid(), $sessionId, $userId, $appVersion, $platform, $now]);

        Response::created(['status' => 'ok']);
    }

    private static function upsertCrashGroup(
        PDO $pdo, string $fingerprint, string $type, string $message,
        ?string $stackTrace, string $appVersion, string $platform, ?string $userId, string $now
    ): string {
        $stmt = $pdo->prepare('SELECT * FROM crash_groups WHERE fingerprint = ?');
        $stmt->execute([$fingerprint]);
        $group = $stmt->fetch();

        if ($group === false) {
            // Create new group
            $groupId = Database::uuid();
            $title = mb_substr($message, 0, 200);
            $versions = json_encode([$appVersion]);

            $stmt = $pdo->prepare(
                'INSERT INTO crash_groups (id, fingerprint, title, status, type, first_seen_at, last_seen_at,
                 occurrence_count, affected_user_count, affected_versions, latest_stack_trace, platform)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
            );
            $affectedUsers = $userId !== null ? 1 : 0;
            $stmt->execute([
                $groupId, $fingerprint, $title, 'OPEN', $type,
                $now, $now, $affectedUsers, $versions, $stackTrace, $platform,
            ]);

            return $groupId;
        }

        // Update existing group
        $groupId = $group['id'];

        // Update affected versions
        $versions = json_decode($group['affected_versions'], true) ?: [];
        if (!in_array($appVersion, $versions, true)) {
            $versions[] = $appVersion;
        }

        // Auto-regression: if RESOLVED and new crash is in a newer version
        $status = $group['status'];
        if ($status === 'RESOLVED' && $group['resolved_in_version'] !== null) {
            if (version_compare($appVersion, $group['resolved_in_version'], '>')) {
                $status = 'REGRESSED';
            }
        }

        // Update affected user count (simple: count distinct user_ids in crash_reports for this fingerprint)
        $affectedUserCount = (int)$group['affected_user_count'];
        if ($userId !== null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT user_id) as cnt FROM crash_reports WHERE fingerprint = ? AND user_id IS NOT NULL'
            );
            $stmt->execute([$fingerprint]);
            $affectedUserCount = (int)$stmt->fetch()['cnt'];
        }

        $stmt = $pdo->prepare(
            'UPDATE crash_groups SET
             status = ?, last_seen_at = ?, occurrence_count = occurrence_count + 1,
             affected_user_count = ?, affected_versions = ?, latest_stack_trace = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $status, $now, $affectedUserCount,
            json_encode($versions), $stackTrace, $groupId,
        ]);

        return $groupId;
    }
}
