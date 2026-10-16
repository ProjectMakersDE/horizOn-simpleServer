<?php

declare(strict_types=1);
// No operator implementation is exposed through direct Apache/CGI file dispatch.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../src/Chat/ChatController.php';
fwrite(STDERR, json_encode(['error' => true, 'message' => ChatController::MESSAGE,
    'code' => 'NOT_SUPPORTED', 'status' => 501], JSON_UNESCAPED_SLASHES) . "\n");
exit(1);
