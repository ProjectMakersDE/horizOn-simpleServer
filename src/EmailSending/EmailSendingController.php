<?php

declare(strict_types=1);

class EmailSendingController
{
    /**
     * POST /api/v1/app/email-sending/send
     * Queue an email to a registered user.
     */
    public static function send(Request $request): void
    {
        $userId = $request->body('userId', '');
        $templateSlug = $request->body('templateSlug', '');
        $variables = $request->body('variables');
        $language = $request->body('language', '');
        $scheduledAt = $request->body('scheduledAt');

        if ($userId === '' || $templateSlug === '' || $language === '') {
            Response::badRequest('userId, templateSlug, and language are required');
            return;
        }

        if ($variables === null || !is_array($variables)) {
            $variables = [];
        }

        $pdo = Database::connect();

        // Check SMTP is configured
        $smtp = self::getSmtpConfig($pdo);
        if ($smtp === null) {
            Response::badRequest('SMTP not configured');
            return;
        }

        // Find template by slug
        $stmt = $pdo->prepare('SELECT id, subject, body, variables FROM email_templates WHERE slug = ? AND deleted = 0');
        $stmt->execute([$templateSlug]);
        $template = $stmt->fetch();

        if ($template === false) {
            Response::badRequest('Template not found: ' . $templateSlug);
            return;
        }

        // Find user
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            Response::badRequest('User not found');
            return;
        }

        if ($user['email'] === null || $user['email'] === '') {
            Response::badRequest('User has no verified email');
            return;
        }

        // Check language availability
        $subjectMap = json_decode($template['subject'], true) ?: [];
        $bodyMap = json_decode($template['body'], true) ?: [];

        if (!isset($subjectMap[$language]) && !isset($subjectMap['en'])) {
            Response::badRequest('Language not available for this template');
            return;
        }

        // Validate required variables
        $templateVars = json_decode($template['variables'], true) ?: [];
        foreach ($templateVars as $varName) {
            if (!isset($variables[$varName]) || $variables[$varName] === '') {
                Response::badRequest('Missing required variable: ' . $varName);
                return;
            }
        }

        // Validate variable value length
        foreach ($variables as $value) {
            if (is_string($value) && strlen($value) > 500) {
                Response::badRequest('Variable value too long (max: 500 chars)');
                return;
            }
        }

        // Validate scheduledAt
        $scheduledAtValue = null;
        if ($scheduledAt !== null && $scheduledAt !== '') {
            $scheduledTime = strtotime($scheduledAt);
            if ($scheduledTime === false) {
                Response::badRequest('Invalid scheduledAt format');
                return;
            }
            if ($scheduledTime <= time()) {
                Response::badRequest('Scheduled time must be in the future');
                return;
            }
            $maxSchedule = time() + (30 * 24 * 3600);
            if ($scheduledTime > $maxSchedule) {
                Response::badRequest('Scheduled time too far ahead (max: 30 days)');
                return;
            }
            $scheduledAtValue = gmdate('Y-m-d\TH:i:s\Z', $scheduledTime);
        }

        $emailId = Database::uuid();
        $now = Database::now();

        $stmt = $pdo->prepare(
            'INSERT INTO email_queue (id, account_id, template_id, user_id, variables, language, status, scheduled_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $emailId,
            'default',
            $template['id'],
            $userId,
            json_encode($variables),
            $language,
            'pending',
            $scheduledAtValue,
            $now,
        ]);

        Response::created([
            'id' => $emailId,
            'status' => 'pending',
            'scheduledAt' => $scheduledAtValue,
        ]);
    }

    /**
     * DELETE /api/v1/app/email-sending/{emailId}
     * Cancel a pending email.
     */
    public static function cancel(Request $request): void
    {
        $emailId = $request->param('emailId');
        if ($emailId === null || $emailId === '') {
            Response::badRequest('emailId is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, status FROM email_queue WHERE id = ?');
        $stmt->execute([$emailId]);
        $email = $stmt->fetch();

        if ($email === false) {
            Response::notFound('Email not found');
            return;
        }

        if ($email['status'] !== 'pending') {
            Response::badRequest('Email is not in pending status');
            return;
        }

        $stmt = $pdo->prepare('DELETE FROM email_queue WHERE id = ?');
        $stmt->execute([$emailId]);

        Response::json(['message' => 'Email cancelled']);
    }

    /**
     * GET /api/v1/app/email-sending/{emailId}
     * Get the status of a specific email.
     */
    public static function status(Request $request): void
    {
        $emailId = $request->param('emailId');
        if ($emailId === null || $emailId === '') {
            Response::badRequest('emailId is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT eq.id, eq.status, et.slug as template_slug, eq.user_id, eq.language,
                    eq.scheduled_at, eq.processed_at, eq.error_reason, eq.created_at
             FROM email_queue eq
             JOIN email_templates et ON eq.template_id = et.id
             WHERE eq.id = ?'
        );
        $stmt->execute([$emailId]);
        $email = $stmt->fetch();

        if ($email === false) {
            Response::notFound('Email not found');
            return;
        }

        Response::json([
            'id' => $email['id'],
            'status' => $email['status'],
            'templateSlug' => $email['template_slug'],
            'userId' => $email['user_id'],
            'language' => $email['language'],
            'scheduledAt' => $email['scheduled_at'],
            'processedAt' => $email['processed_at'],
            'errorReason' => $email['error_reason'],
            'createdAt' => $email['created_at'],
        ]);
    }

    /**
     * POST /api/v1/app/email-sending/ticker
     * Process pending emails. Called by cron job.
     */
    public static function ticker(Request $request): void
    {
        $pdo = Database::connect();
        $now = Database::now();

        // Get SMTP config
        $smtp = self::getSmtpConfig($pdo);
        if ($smtp === null) {
            Response::json(['processed' => 0, 'message' => 'SMTP not configured']);
            return;
        }

        // Claim pending emails that are ready to send
        // (scheduledAt is null = immediate, or scheduledAt <= now)
        $stmt = $pdo->prepare(
            "SELECT eq.id, eq.template_id, eq.user_id, eq.variables, eq.language
             FROM email_queue eq
             WHERE eq.status = 'pending'
               AND (eq.scheduled_at IS NULL OR eq.scheduled_at <= ?)
             LIMIT 50"
        );
        $stmt->execute([$now]);
        $emails = $stmt->fetchAll();

        if (empty($emails)) {
            Response::json(['processed' => 0, 'message' => 'No pending emails']);
            return;
        }

        // Mark all as processing
        $ids = array_column($emails, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE email_queue SET status = 'processing' WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        $sent = 0;
        $failed = 0;

        foreach ($emails as $email) {
            try {
                // Load template
                $stmt = $pdo->prepare('SELECT slug, subject, body, variables FROM email_templates WHERE id = ? AND deleted = 0');
                $stmt->execute([$email['template_id']]);
                $template = $stmt->fetch();

                if ($template === false) {
                    self::markFailed($pdo, $email['id'], 'Template not found or deleted');
                    $failed++;
                    continue;
                }

                // Load user email
                $stmt = $pdo->prepare('SELECT email, display_name FROM users WHERE id = ?');
                $stmt->execute([$email['user_id']]);
                $user = $stmt->fetch();

                if ($user === false || $user['email'] === null || $user['email'] === '') {
                    self::markFailed($pdo, $email['id'], 'User not found or has no email');
                    $failed++;
                    continue;
                }

                // Render template
                $language = $email['language'];
                $subjectMap = json_decode($template['subject'], true) ?: [];
                $bodyMap = json_decode($template['body'], true) ?: [];
                $variables = json_decode($email['variables'], true) ?: [];

                $subject = $subjectMap[$language] ?? $subjectMap['en'] ?? '';
                $body = $bodyMap[$language] ?? $bodyMap['en'] ?? '';

                // Replace variables
                foreach ($variables as $key => $value) {
                    $subject = str_replace('{{' . $key . '}}', $value, $subject);
                    $body = str_replace('{{' . $key . '}}', $value, $body);
                }

                // Send via SMTP
                $success = self::sendSmtp($smtp, $user['email'], $user['display_name'], $subject, $body);

                if ($success) {
                    self::markSent($pdo, $email['id']);
                    $sent++;
                } else {
                    self::markFailed($pdo, $email['id'], 'SMTP delivery failed');
                    $failed++;
                }
            } catch (\Throwable $e) {
                self::markFailed($pdo, $email['id'], 'Error: ' . $e->getMessage());
                $failed++;
            }
        }

        Response::json([
            'processed' => $sent + $failed,
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    // --- Private helpers ---

    private static function getSmtpConfig(PDO $pdo): ?array
    {
        $stmt = $pdo->prepare("SELECT config_value FROM remote_configs WHERE config_key = 'smtp_config'");
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row === false || $row['config_value'] === null || $row['config_value'] === '') {
            return null;
        }

        $config = json_decode($row['config_value'], true);
        if (!is_array($config) || empty($config['host']) || empty($config['from_email'])) {
            return null;
        }

        return $config;
    }

    private static function markSent(PDO $pdo, string $emailId): void
    {
        $now = Database::now();
        $stmt = $pdo->prepare("UPDATE email_queue SET status = 'sent', processed_at = ? WHERE id = ?");
        $stmt->execute([$now, $emailId]);
    }

    private static function markFailed(PDO $pdo, string $emailId, string $reason): void
    {
        $now = Database::now();
        $stmt = $pdo->prepare("UPDATE email_queue SET status = 'failed', error_reason = ?, processed_at = ? WHERE id = ?");
        $stmt->execute([$reason, $now, $emailId]);
    }

    /**
     * Send an email via SMTP using PHP's built-in mail() or direct socket connection.
     * Uses fsockopen for SMTP to avoid external dependencies.
     */
    private static function sendSmtp(array $config, string $toEmail, string $toName, string $subject, string $body): bool
    {
        $host = $config['host'];
        $port = (int)($config['port'] ?? 587);
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $fromEmail = $config['from_email'];
        $fromName = $config['from_name'] ?? 'horizOn';
        $encryption = $config['encryption'] ?? 'tls';

        // Use fsockopen for SMTP
        $hostname = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $timeout = 30;

        $socket = @fsockopen($hostname, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            error_log("horizOn SMTP: Connection failed: {$errstr} ({$errno})");
            return false;
        }

        // Helper to read SMTP response
        $readResponse = function () use ($socket): string {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') {
                    break;
                }
            }
            return $response;
        };

        // Helper to send SMTP command
        $sendCommand = function (string $cmd) use ($socket, $readResponse): string {
            fwrite($socket, $cmd . "\r\n");
            return $readResponse();
        };

        try {
            // Read greeting
            $readResponse();

            // EHLO
            $sendCommand('EHLO localhost');

            // STARTTLS if needed
            if ($encryption === 'tls') {
                $sendCommand('STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    error_log('horizOn SMTP: STARTTLS failed');
                    return false;
                }
                $sendCommand('EHLO localhost');
            }

            // AUTH LOGIN
            if ($username !== '' && $password !== '') {
                $sendCommand('AUTH LOGIN');
                $sendCommand(base64_encode($username));
                $response = $sendCommand(base64_encode($password));
                if (strpos($response, '235') === false) {
                    error_log('horizOn SMTP: Authentication failed');
                    return false;
                }
            }

            // MAIL FROM
            $response = $sendCommand("MAIL FROM:<{$fromEmail}>");
            if (strpos($response, '250') === false) {
                error_log('horizOn SMTP: MAIL FROM rejected');
                return false;
            }

            // RCPT TO
            $response = $sendCommand("RCPT TO:<{$toEmail}>");
            if (strpos($response, '250') === false) {
                error_log('horizOn SMTP: RCPT TO rejected');
                return false;
            }

            // DATA
            $response = $sendCommand('DATA');
            if (strpos($response, '354') === false) {
                error_log('horizOn SMTP: DATA not accepted');
                return false;
            }

            // Build email headers and body
            $date = gmdate('r');
            $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $encodedToName = '=?UTF-8?B?' . base64_encode($toName) . '?=';
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

            $message = "Date: {$date}\r\n";
            $message .= "From: {$encodedFromName} <{$fromEmail}>\r\n";
            $message .= "To: {$encodedToName} <{$toEmail}>\r\n";
            $message .= "Subject: {$encodedSubject}\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "\r\n";
            $message .= chunk_split(base64_encode($body));
            $message .= "\r\n.";

            $response = $sendCommand($message);
            if (strpos($response, '250') === false) {
                error_log('horizOn SMTP: Message not accepted');
                return false;
            }

            $sendCommand('QUIT');
            return true;
        } finally {
            fclose($socket);
        }
    }
}
