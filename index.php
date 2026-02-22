<?php

declare(strict_types=1);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => true,
        'message' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
    ]);
    error_log("horizOn Simple Server Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    exit;
});

// CORS headers for all responses
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Load core
$baseDir = __DIR__;
require_once $baseDir . '/src/Core/Config.php';
require_once $baseDir . '/src/Core/Request.php';
require_once $baseDir . '/src/Core/Response.php';
require_once $baseDir . '/src/Core/Database.php';
require_once $baseDir . '/src/Core/Router.php';
require_once $baseDir . '/src/Core/Auth.php';
require_once $baseDir . '/src/Core/RateLimit.php';

// Load controllers
require_once $baseDir . '/src/UserManagement/UserManagementController.php';
require_once $baseDir . '/src/Leaderboard/LeaderboardController.php';
require_once $baseDir . '/src/CloudSave/CloudSaveController.php';
require_once $baseDir . '/src/RemoteConfig/RemoteConfigController.php';
require_once $baseDir . '/src/News/NewsController.php';
require_once $baseDir . '/src/GiftCodes/GiftCodesController.php';
require_once $baseDir . '/src/UserFeedback/UserFeedbackController.php';
require_once $baseDir . '/src/UserLogs/UserLogsController.php';
require_once $baseDir . '/src/CrashReporting/CrashReportingController.php';

// Initialize
$envPath = $baseDir . '/.env';
if (!file_exists($envPath)) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'Missing .env file. Copy .env.example to .env and configure it.', 'code' => 'CONFIG_ERROR']);
    exit;
}

Config::load($envPath);
Database::migrate();

$request = new Request();
$router = new Router();

// --- Health endpoint (no auth required) ---
$router->get('/api/v1/app/health', function (Request $req) {
    Response::json(['status' => 'ok', 'timestamp' => Database::now()]);
});

// --- Middleware: Auth + Rate Limit for all /api/v1/app/* except health ---
$prefix = '/api/v1/app';

// Auth + rate limit check (runs before route dispatch for non-health routes)
if ($request->path() !== $prefix . '/health' && strpos($request->path(), $prefix) === 0 && $request->method() !== 'OPTIONS') {
    Auth::validateApiKey($request);
    RateLimit::check($request);
}

// --- User Management ---
$router->post($prefix . '/user-management/signup', [UserManagementController::class, 'signup']);
$router->post($prefix . '/user-management/signin', [UserManagementController::class, 'signin']);
$router->post($prefix . '/user-management/check-auth', [UserManagementController::class, 'checkAuth']);

// --- Leaderboard ---
$router->post($prefix . '/leaderboard/submit', [LeaderboardController::class, 'submit']);
$router->get($prefix . '/leaderboard/top', [LeaderboardController::class, 'top']);
$router->get($prefix . '/leaderboard/rank', [LeaderboardController::class, 'rank']);
$router->get($prefix . '/leaderboard/around', [LeaderboardController::class, 'around']);

// --- Cloud Save ---
$router->post($prefix . '/cloud-save/save', [CloudSaveController::class, 'save']);
$router->post($prefix . '/cloud-save/load', [CloudSaveController::class, 'load']);

// --- Remote Config ---
$router->get($prefix . '/remote-config/all', [RemoteConfigController::class, 'all']);
$router->get($prefix . '/remote-config/{key}', [RemoteConfigController::class, 'get']);

// --- News ---
$router->get($prefix . '/news', [NewsController::class, 'list']);

// --- Gift Codes ---
$router->post($prefix . '/gift-codes/validate', [GiftCodesController::class, 'validate']);
$router->post($prefix . '/gift-codes/redeem', [GiftCodesController::class, 'redeem']);

// --- User Feedback ---
$router->post($prefix . '/user-feedback/submit', [UserFeedbackController::class, 'submit']);

// --- User Logs ---
$router->post($prefix . '/user-logs/create', [UserLogsController::class, 'create']);

// --- Crash Reporting ---
$router->post($prefix . '/crash-reports/create', [CrashReportingController::class, 'create']);
$router->post($prefix . '/crash-reports/session', [CrashReportingController::class, 'session']);

// Dispatch
$router->dispatch($request);
