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
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, If-Match');

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
require_once $baseDir . '/src/Localization/LocalizationController.php';
require_once $baseDir . '/src/News/NewsController.php';
require_once $baseDir . '/src/GiftCodes/GiftCodesController.php';
require_once $baseDir . '/src/PlayerProfile/PlayerProfileController.php';
require_once $baseDir . '/src/PlayerValues/PlayerValuesController.php';
require_once $baseDir . '/src/Statistics/StatisticsController.php';
require_once $baseDir . '/src/Inbox/InboxController.php';
require_once $baseDir . '/src/Achievements/AchievementController.php';
require_once $baseDir . '/src/Friends/FriendsController.php';
require_once $baseDir . '/src/Groups/GroupController.php';
require_once $baseDir . '/src/Items/ItemController.php';
require_once $baseDir . '/src/SharedObjects/SharedObjectController.php';
require_once $baseDir . '/src/UserFeedback/UserFeedbackController.php';
require_once $baseDir . '/src/UserLogs/UserLogsController.php';
require_once $baseDir . '/src/CrashReporting/CrashReportingController.php';
require_once $baseDir . '/src/EmailSending/EmailSendingController.php';
require_once $baseDir . '/src/AppleSignIn/AppleIdTokenVerifier.php';
require_once $baseDir . '/src/AppleSignIn/AppleSignInController.php';

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

// --- Public Apple Sign-In (no API key required) ---
$router->post('/api/v1/public/auth/apple', [AppleSignInController::class, 'publicAuth']);

// --- Middleware: Auth + Rate Limit for all /api/v1/app/* except health ---
$prefix = '/api/v1/app';

// Auth + rate limit check (runs before route dispatch for non-health routes)
if ($request->path() !== $prefix . '/health' && strpos($request->path(), $prefix) === 0 && $request->method() !== 'OPTIONS') {
    Auth::validateApiKey($request);
    RateLimit::check($request);
}

// Realtime requires the Cloud gateway. SDKs can detect this before attempting a WebSocket.
$realtimeUnsupported = function (Request $req) {
    Response::error('Realtime is available in horizOn Cloud only', 'NOT_SUPPORTED', 501);
};
$router->post($prefix . '/realtime/session', $realtimeUnsupported);
$router->post($prefix . '/realtime/subscriptions', $realtimeUnsupported);
$router->get($prefix . '/realtime/socket', $realtimeUnsupported);

// --- Inbox: authenticated REST parity, no public operator/admin routes ---
$router->get($prefix . '/inbox', [InboxController::class, 'get']);
$router->post($prefix . '/inbox/read', [InboxController::class, 'read']);
$router->post($prefix . '/inbox/claim', [InboxController::class, 'claim']);
$router->post($prefix . '/inbox/remove', [InboxController::class, 'remove']);

// --- Friends: explicit player revisions, one atomic bilateral relationship/Inbox scope ---
$router->get($prefix . '/friends', [FriendsController::class, 'get']);
$router->get($prefix . '/friends/find', [FriendsController::class, 'find']);
foreach (FriendsRules::ACTIONS as $friendsAction) {
    $router->post($prefix . '/friends/' . $friendsAction, static function (Request $req) use ($friendsAction) { FriendsController::change($req, $friendsAction); });
}

// --- Groups: explicit revisions, guarded membership and same-PDO Inbox transactions ---
$router->get($prefix . '/groups', [GroupController::class, 'get']);
$router->post($prefix . '/groups', [GroupController::class, 'create']);
$router->get($prefix . '/groups/{groupId}', [GroupController::class, 'detail']);
$router->put($prefix . '/groups/{groupId}', [GroupController::class, 'update']);
$router->get($prefix . '/groups/{groupId}/members', [GroupController::class, 'members']);
$router->get($prefix . '/groups/{groupId}/pending', [GroupController::class, 'pending']);
foreach (GroupRules::ACTIONS as $groupAction) {
    $router->post($prefix . '/groups/{groupId}/' . $groupAction, static function (Request $req) use ($groupAction) { GroupController::change($req,$groupAction); });
}

// --- Items: explicit inventory CAS, paged catalog and no public grant/admin route ---
$router->get($prefix . '/items', [ItemController::class, 'get']);
$router->get($prefix . '/items/catalog', [ItemController::class, 'catalog']);
$router->post($prefix . '/items/adjust', [ItemController::class, 'adjust']);
$router->post($prefix . '/items/equip', [ItemController::class, 'equip']);
$router->post($prefix . '/items/discard', [ItemController::class, 'discard']);

// --- Shared Objects: opaque bytes, bounded metadata pages, exact If-Match and no public admin/grant route ---
$router->get($prefix . '/shared-objects/collections', [SharedObjectController::class, 'collections']);
$router->get($prefix . '/shared-objects/{collection}/usage', [SharedObjectController::class, 'usage']);
$router->get($prefix . '/shared-objects/{collection}/objects/{objectId}', [SharedObjectController::class, 'get']);
$router->post($prefix . '/shared-objects/{collection}/query', [SharedObjectController::class, 'queryObjects']);
$router->post($prefix . '/shared-objects/{collection}/objects', [SharedObjectController::class, 'create']);
$router->put($prefix . '/shared-objects/{collection}/objects/{objectId}', [SharedObjectController::class, 'update']);
$router->delete($prefix . '/shared-objects/{collection}/objects/{objectId}', [SharedObjectController::class, 'delete']);

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
$router->get($prefix . '/remote-config/filter', [RemoteConfigController::class, 'filter']);
$router->get($prefix . '/remote-config/{key}', [RemoteConfigController::class, 'get']);

// --- Localization ---
$router->get($prefix . '/localization/all', [LocalizationController::class, 'all']);
$router->get($prefix . '/localization/languages', [LocalizationController::class, 'languages']);
$router->get($prefix . '/localization/{key}', [LocalizationController::class, 'get']);

// --- News ---
$router->get($prefix . '/news', [NewsController::class, 'list']);

// --- Gift Codes ---
$router->post($prefix . '/gift-codes/validate', [GiftCodesController::class, 'validate']);
$router->post($prefix . '/gift-codes/redeem', [GiftCodesController::class, 'redeem']);

// --- Player Profile (needs the player's Bearer session) ---
$router->get($prefix . '/player-profile', [PlayerProfileController::class, 'get']);
$router->put($prefix . '/player-profile', [PlayerProfileController::class, 'set']);

// --- Player Values (Bearer session, exact integers, stable operation IDs) ---
$router->get($prefix . '/player-values', [PlayerValuesController::class, 'get']);
$router->get($prefix . '/player-values/offers', [PlayerValuesController::class, 'offers']);
$router->post($prefix . '/player-values/purchase', [PlayerValuesController::class, 'purchase']);
$router->post($prefix . '/player-values/adjust', [PlayerValuesController::class, 'adjust']);

// --- Statistics: one explicit atomic session batch, matching Bearer required ---
$router->get($prefix . '/statistics/definitions', [StatisticsController::class, 'definitions']);
$router->get($prefix . '/statistics', [StatisticsController::class, 'get']);
$router->post($prefix . '/statistics', [StatisticsController::class, 'update']);

// --- Achievements: player-owned snapshots, explicit manual unlock and bounded Inbox handoff ---
$router->get($prefix . '/achievements', [AchievementController::class, 'get']);
$router->post($prefix . '/achievements/unlock', [AchievementController::class, 'unlock']);
$router->post($prefix . '/achievements/rewards/deliver', [AchievementController::class, 'deliver']);

// --- User Feedback ---
$router->post($prefix . '/user-feedback/submit', [UserFeedbackController::class, 'submit']);

// --- User Logs ---
$router->post($prefix . '/user-logs/create', [UserLogsController::class, 'create']);

// --- Crash Reporting ---
$router->post($prefix . '/crash-reports/create', [CrashReportingController::class, 'create']);
$router->post($prefix . '/crash-reports/session', [CrashReportingController::class, 'session']);

// --- Email Sending ---
$router->post($prefix . '/email-sending/send', [EmailSendingController::class, 'send']);
$router->delete($prefix . '/email-sending/{emailId}', [EmailSendingController::class, 'cancel']);
$router->get($prefix . '/email-sending/{emailId}', [EmailSendingController::class, 'status']);
$router->post($prefix . '/email-sending/ticker', [EmailSendingController::class, 'ticker']);

// Dispatch
$router->dispatch($request);
