#!/bin/bash
#
# horizOn Simple Server - Integration Test Suite
#
# Starts a PHP built-in server, creates a temporary .env and SQLite database,
# then runs curl tests across the core endpoint groups. Reports pass/fail with colors.
#

set -euo pipefail

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

PASS=0
FAIL=0
TEST_PORT="${HORIZON_TEST_PORT:-8765}"
BASE_URL="http://localhost:${TEST_PORT}/api/v1/app"
API_KEY="test-key-integration-12345"
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_PID=""

echo -e "${BOLD}=== horizOn Simple Server Integration Tests ===${NC}"
echo ""

# ---- Setup ----

cd "$PROJECT_DIR"

# Never stop another test suite or application that owns the requested port.
if lsof -i :"$TEST_PORT" > /dev/null 2>&1; then
    echo -e "${RED}ERROR: Port ${TEST_PORT} is already in use. Set HORIZON_TEST_PORT to a free port.${NC}"
    exit 1
fi

# Backup existing .env if present
if [ -f .env ]; then
    cp .env .env.backup.$$
    RESTORE_ENV=1
else
    RESTORE_ENV=0
fi

# Create test .env
cat > .env <<EOF
API_KEY=${API_KEY}
DB_DRIVER=sqlite
DB_PATH=./data/test_horizon_integration.db
RATE_LIMIT_ENABLED=false
RATE_LIMIT_PER_SECOND=100
APPLE_SIGN_IN_ENABLED=false
APPLE_TEAM_ID=
APPLE_SERVICE_ID=
APPLE_BUNDLE_ID=
EOF

# Clean previous test DB
rm -f ./data/test_horizon_integration.db
mkdir -p ./data

# Cleanup function - runs on EXIT (success or failure)
cleanup() {
    echo ""
    echo -e "${BOLD}--- Cleanup ---${NC}"

    # Kill PHP server
    if [ -n "$PHP_PID" ] && kill -0 "$PHP_PID" 2>/dev/null; then
        kill "$PHP_PID" 2>/dev/null || true
        wait "$PHP_PID" 2>/dev/null || true
        echo "  Stopped PHP server (PID $PHP_PID)"
    fi

    # Remove test DB
    rm -f ./data/test_horizon_integration.db

    # Restore or remove .env
    if [ "$RESTORE_ENV" -eq 1 ] && [ -f ".env.backup.$$" ]; then
        mv ".env.backup.$$" .env
        echo "  Restored original .env"
    else
        rm -f .env
        rm -f ".env.backup.$$"
        echo "  Removed test .env"
    fi
}
trap cleanup EXIT

# Start PHP built-in server
php -S "localhost:${TEST_PORT}" index.php > /dev/null 2>&1 &
PHP_PID=$!
echo "  Started PHP server on port ${TEST_PORT} (PID $PHP_PID)"

# Wait for server to be ready
MAX_WAIT=10
for i in $(seq 1 $MAX_WAIT); do
    if curl -s -o /dev/null "http://localhost:${TEST_PORT}/api/v1/app/health" 2>/dev/null; then
        break
    fi
    if [ "$i" -eq "$MAX_WAIT" ]; then
        echo -e "${RED}ERROR: Server did not start within ${MAX_WAIT} seconds${NC}"
        exit 1
    fi
    sleep 1
done
echo "  Server is ready"
echo ""

# ---- Helper Functions ----

assert_status() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" -eq "$expected" ]; then
        echo -e "  ${GREEN}PASS${NC} $name (HTTP $actual)"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}FAIL${NC} $name (expected HTTP $expected, got HTTP $actual)"
        FAIL=$((FAIL + 1))
    fi
}

assert_contains() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    if echo "$actual" | grep -qF "$expected"; then
        echo -e "  ${GREEN}PASS${NC} $name (contains '$expected')"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}FAIL${NC} $name (expected to contain '$expected')"
        echo "       Got: $actual"
        FAIL=$((FAIL + 1))
    fi
}

assert_not_contains() {
    local name="$1"
    local unexpected="$2"
    local actual="$3"
    if echo "$actual" | grep -qF "$unexpected"; then
        echo -e "  ${RED}FAIL${NC} $name (should NOT contain '$unexpected')"
        echo "       Got: $actual"
        FAIL=$((FAIL + 1))
    else
        echo -e "  ${GREEN}PASS${NC} $name (does not contain '$unexpected')"
        PASS=$((PASS + 1))
    fi
}

# ---- 1. Health Endpoint (no auth needed) ----
echo -e "${BOLD}--- Health ---${NC}"

HEALTH_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/health")
HEALTH_BODY=$(echo "$HEALTH_RESP" | sed '$d')
HEALTH_STATUS=$(echo "$HEALTH_RESP" | tail -1)
assert_status "GET /health" 200 "$HEALTH_STATUS"
assert_contains "health returns status ok" '"status":"ok"' "$HEALTH_BODY"
assert_contains "health returns timestamp" '"timestamp"' "$HEALTH_BODY"

# ---- 2. Auth Rejection (missing API key) ----
echo ""
echo -e "${BOLD}--- Auth Rejection ---${NC}"

STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/user-management/signup" \
    -X POST -H "Content-Type: application/json" -d '{"type":"ANONYMOUS","username":"Hacker"}')
assert_status "POST without API key returns 401" 401 "$STATUS"

STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/leaderboard/top?userId=test&limit=10")
assert_status "GET without API key returns 401" 401 "$STATUS"

# ---- 3. User Management ----
echo ""
echo -e "${BOLD}--- User Management ---${NC}"

# Signup
SIGNUP_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/signup" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d '{"type":"ANONYMOUS","username":"TestPlayer"}')
SIGNUP_BODY=$(echo "$SIGNUP_RESP" | sed '$d')
SIGNUP_STATUS=$(echo "$SIGNUP_RESP" | tail -1)
assert_status "POST /user-management/signup" 201 "$SIGNUP_STATUS"
assert_contains "signup returns userId" '"userId"' "$SIGNUP_BODY"
assert_contains "signup returns anonymousToken" '"anonymousToken"' "$SIGNUP_BODY"
assert_contains "signup returns isAnonymous true" '"isAnonymous":true' "$SIGNUP_BODY"
assert_contains "signup returns username" '"username":"TestPlayer"' "$SIGNUP_BODY"

# Extract anonymousToken and userId using PHP
ANON_TOKEN=$(echo "$SIGNUP_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->anonymousToken;')
USER_ID=$(echo "$SIGNUP_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->userId;')

if [ -z "$USER_ID" ] || [ -z "$ANON_TOKEN" ]; then
    echo -e "  ${RED}FATAL: Could not extract userId or anonymousToken from signup response${NC}"
    echo "  Response was: $SIGNUP_BODY"
    exit 1
fi

# Signin
SIGNIN_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/signin" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"type\":\"ANONYMOUS\",\"anonymousToken\":\"$ANON_TOKEN\"}")
SIGNIN_BODY=$(echo "$SIGNIN_RESP" | sed '$d')
SIGNIN_STATUS=$(echo "$SIGNIN_RESP" | tail -1)
assert_status "POST /user-management/signin" 200 "$SIGNIN_STATUS"
assert_contains "signin returns accessToken" '"accessToken"' "$SIGNIN_BODY"
assert_contains "signin returns AUTHENTICATED" '"authStatus":"AUTHENTICATED"' "$SIGNIN_BODY"
assert_contains "signin returns userId" "\"userId\":\"$USER_ID\"" "$SIGNIN_BODY"

# Extract session token
SESSION_TOKEN=$(echo "$SIGNIN_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->accessToken;')

# Check auth - valid
CHECK_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/check-auth" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"sessionToken\":\"$SESSION_TOKEN\"}")
CHECK_BODY=$(echo "$CHECK_RESP" | sed '$d')
CHECK_STATUS=$(echo "$CHECK_RESP" | tail -1)
assert_status "POST /user-management/check-auth (valid)" 200 "$CHECK_STATUS"
assert_contains "check-auth returns isAuthenticated true" '"isAuthenticated":true' "$CHECK_BODY"
assert_contains "check-auth returns AUTHENTICATED" '"authStatus":"AUTHENTICATED"' "$CHECK_BODY"

# Check auth - invalid token
CHECK_BAD_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/check-auth" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"sessionToken\":\"invalid-token-abc\"}")
CHECK_BAD_BODY=$(echo "$CHECK_BAD_RESP" | sed '$d')
CHECK_BAD_STATUS=$(echo "$CHECK_BAD_RESP" | tail -1)
assert_status "POST /user-management/check-auth (invalid token)" 200 "$CHECK_BAD_STATUS"
assert_contains "check-auth invalid returns isAuthenticated false" '"isAuthenticated":false' "$CHECK_BAD_BODY"

# ---- 4. Leaderboard ----
echo ""
echo -e "${BOLD}--- Leaderboard ---${NC}"

# Submit score
SUBMIT_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/leaderboard/submit" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"score\":1500}")
SUBMIT_STATUS=$(echo "$SUBMIT_RESP" | tail -1)
assert_status "POST /leaderboard/submit" 200 "$SUBMIT_STATUS"

# Get top
TOP_RESP=$(curl -s "$BASE_URL/leaderboard/top?userId=$USER_ID&limit=10" -H "X-API-Key: $API_KEY")
assert_contains "GET /leaderboard/top returns entries" '"entries"' "$TOP_RESP"
assert_contains "top contains TestPlayer" 'TestPlayer' "$TOP_RESP"
assert_contains "top contains score 1500" '1500' "$TOP_RESP"

# Get rank
RANK_RESP=$(curl -s "$BASE_URL/leaderboard/rank?userId=$USER_ID" -H "X-API-Key: $API_KEY")
assert_contains "GET /leaderboard/rank returns position" '"position"' "$RANK_RESP"
assert_contains "rank shows position 1" '"position":1' "$RANK_RESP"
assert_contains "rank shows score 1500" '"score":1500' "$RANK_RESP"

# Get around
AROUND_RESP=$(curl -s "$BASE_URL/leaderboard/around?userId=$USER_ID&range=5" -H "X-API-Key: $API_KEY")
assert_contains "GET /leaderboard/around returns entries" '"entries"' "$AROUND_RESP"
assert_contains "around contains TestPlayer" 'TestPlayer' "$AROUND_RESP"

# Submit higher score - should update
curl -s -o /dev/null "$BASE_URL/leaderboard/submit" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"score\":2000}"

RANK_RESP2=$(curl -s "$BASE_URL/leaderboard/rank?userId=$USER_ID" -H "X-API-Key: $API_KEY")
assert_contains "leaderboard updated to higher score" '"score":2000' "$RANK_RESP2"

# ---- 5. Cloud Save ----
echo ""
echo -e "${BOLD}--- Cloud Save ---${NC}"

# Save
SAVE_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/cloud-save/save" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"saveData\":\"{\\\"level\\\":5,\\\"coins\\\":100}\"}")
SAVE_BODY=$(echo "$SAVE_RESP" | sed '$d')
SAVE_STATUS=$(echo "$SAVE_RESP" | tail -1)
assert_status "POST /cloud-save/save" 200 "$SAVE_STATUS"
assert_contains "save returns success" '"success":true' "$SAVE_BODY"
assert_contains "save returns dataSizeBytes" '"dataSizeBytes"' "$SAVE_BODY"

# Load
LOAD_RESP=$(curl -s "$BASE_URL/cloud-save/load" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\"}")
assert_contains "POST /cloud-save/load returns found true" '"found":true' "$LOAD_RESP"
assert_contains "cloud-save load contains level data" 'level' "$LOAD_RESP"

# Updated SDKs send the signed-in player session on every Cloud Save call.
SESSION_SAVE_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/cloud-save/save" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"saveData\":\"session-save\"}")
assert_status "Cloud Save accepts SDK POST save with Bearer session" 200 "$SESSION_SAVE_STATUS"
SESSION_LOAD_RESP=$(curl -s "$BASE_URL/cloud-save/load" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\"}")
assert_contains "Cloud Save accepts SDK POST load with Bearer session" '"saveData":"session-save"' "$SESSION_LOAD_RESP"

# Load non-existent user
LOAD_MISSING_RESP=$(curl -s "$BASE_URL/cloud-save/load" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d '{"userId":"00000000-0000-0000-0000-000000000000"}')
assert_contains "cloud-save load missing user returns found false" '"found":false' "$LOAD_MISSING_RESP"

# ---- 6. Remote Config ----
echo ""
echo -e "${BOLD}--- Remote Config ---${NC}"

# Get all (empty)
ALL_RESP=$(curl -s "$BASE_URL/remote-config/all" -H "X-API-Key: $API_KEY")
assert_contains "GET /remote-config/all returns configs" '"configs"' "$ALL_RESP"
assert_contains "remote-config all returns total" '"total":0' "$ALL_RESP"

# Get single (not found)
GET_RESP=$(curl -s "$BASE_URL/remote-config/nonexistent" -H "X-API-Key: $API_KEY")
assert_contains "GET /remote-config/{key} returns configKey" '"configKey":"nonexistent"' "$GET_RESP"
assert_contains "GET /remote-config/{key} returns found false" '"found":false' "$GET_RESP"

# Seed configs, including an internal SMTP credential that app endpoints must never expose.
php -r '
$pdo = new PDO("sqlite:./data/test_horizon_integration.db");
$stmt = $pdo->prepare("INSERT INTO remote_configs (config_key, config_value) VALUES (?, ?)");
foreach ([
    ["game.max_players", "4"],
    ["game.mode", "survival"],
    ["ui.theme", "dark"],
    ["smtp_config", "{\"password\":\"do-not-leak\"}"],
    ["SMTP_CONFIG", "{\"password\":\"uppercase-do-not-leak\"}"],
    ["smtp_config ", "{\"password\":\"whitespace-do-not-leak\"}"],
] as $row) {
    $stmt->execute($row);
}
'

ALL_SEEDED_RESP=$(curl -s "$BASE_URL/remote-config/all" -H "X-API-Key: $API_KEY")
assert_contains "remote-config all returns public configs" '"total":3' "$ALL_SEEDED_RESP"
assert_not_contains "remote-config all hides smtp_config" 'smtp_config' "$ALL_SEEDED_RESP"
assert_not_contains "remote-config all hides uppercase SMTP_CONFIG" 'SMTP_CONFIG' "$ALL_SEEDED_RESP"
assert_not_contains "remote-config all hides whitespace SMTP config key" 'smtp_config ' "$ALL_SEEDED_RESP"
assert_not_contains "remote-config all hides SMTP password" 'do-not-leak' "$ALL_SEEDED_RESP"
assert_not_contains "remote-config all hides uppercase SMTP password" 'uppercase-do-not-leak' "$ALL_SEEDED_RESP"
assert_not_contains "remote-config all hides whitespace SMTP password" 'whitespace-do-not-leak' "$ALL_SEEDED_RESP"

GET_EXISTING_RESP=$(curl -s "$BASE_URL/remote-config/game.max_players" -H "X-API-Key: $API_KEY")
assert_contains "GET existing remote config returns value" '"configValue":"4"' "$GET_EXISTING_RESP"

GET_RESERVED_RESP=$(curl -s "$BASE_URL/remote-config/smtp_config" -H "X-API-Key: $API_KEY")
assert_contains "GET smtp_config reports not found" '"found":false' "$GET_RESERVED_RESP"
assert_not_contains "GET smtp_config hides SMTP password" 'do-not-leak' "$GET_RESERVED_RESP"

GET_UPPERCASE_RESERVED_RESP=$(curl -s "$BASE_URL/remote-config/SMTP_CONFIG" -H "X-API-Key: $API_KEY")
assert_contains "GET uppercase SMTP_CONFIG reports not found" '"found":false' "$GET_UPPERCASE_RESERVED_RESP"
assert_not_contains "GET uppercase SMTP_CONFIG hides SMTP password" 'uppercase-do-not-leak' "$GET_UPPERCASE_RESERVED_RESP"

FILTER_PLAIN_PREFIX_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=game" -H "X-API-Key: $API_KEY")
assert_contains "remote-config plain prefix filter returns two configs" '"total":2' "$FILTER_PLAIN_PREFIX_RESP"
assert_contains "remote-config plain prefix filter reports match type" '"matchType":"PREFIX"' "$FILTER_PLAIN_PREFIX_RESP"

FILTER_PREFIX_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=game*" -H "X-API-Key: $API_KEY")
assert_contains "remote-config prefix filter returns two configs" '"total":2' "$FILTER_PREFIX_RESP"
assert_contains "remote-config prefix filter reports match type" '"matchType":"PREFIX"' "$FILTER_PREFIX_RESP"
assert_not_contains "remote-config prefix filter excludes unrelated config" 'ui.theme' "$FILTER_PREFIX_RESP"

FILTER_SUFFIX_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=*theme" -H "X-API-Key: $API_KEY")
assert_contains "remote-config suffix filter returns ui.theme" '"ui.theme":"dark"' "$FILTER_SUFFIX_RESP"
assert_contains "remote-config suffix filter reports match type" '"matchType":"SUFFIX"' "$FILTER_SUFFIX_RESP"

FILTER_CONTAINS_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=*max*" -H "X-API-Key: $API_KEY")
assert_contains "remote-config contains filter returns max key" '"game.max_players":"4"' "$FILTER_CONTAINS_RESP"
assert_contains "remote-config contains filter reports match type" '"matchType":"CONTAINS"' "$FILTER_CONTAINS_RESP"

FILTER_GLOB_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=game.*players" -H "X-API-Key: $API_KEY")
assert_contains "remote-config glob filter returns matching key" '"game.max_players":"4"' "$FILTER_GLOB_RESP"
assert_contains "remote-config glob filter reports match type" '"matchType":"GLOB"' "$FILTER_GLOB_RESP"

FILTER_RESERVED_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=smtp*" -H "X-API-Key: $API_KEY")
assert_contains "remote-config filter omits reserved config" '"total":0' "$FILTER_RESERVED_RESP"
assert_not_contains "remote-config filter hides SMTP password" 'do-not-leak' "$FILTER_RESERVED_RESP"

FILTER_UPPERCASE_RESERVED_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=SMTP*" -H "X-API-Key: $API_KEY")
assert_contains "remote-config filter omits uppercase reserved config" '"total":0' "$FILTER_UPPERCASE_RESERVED_RESP"
assert_not_contains "remote-config filter hides uppercase SMTP password" 'uppercase-do-not-leak' "$FILTER_UPPERCASE_RESERVED_RESP"

FILTER_WHITESPACE_RESERVED_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=smtp_config*" -H "X-API-Key: $API_KEY")
assert_contains "remote-config filter omits whitespace reserved config" '"total":0' "$FILTER_WHITESPACE_RESERVED_RESP"
assert_not_contains "remote-config filter hides whitespace SMTP password" 'whitespace-do-not-leak' "$FILTER_WHITESPACE_RESERVED_RESP"

FILTER_INVALID_RESP=$(curl --globoff -s -w "\n%{http_code}" "$BASE_URL/remote-config/filter?pattern=*" -H "X-API-Key: $API_KEY")
FILTER_INVALID_BODY=$(echo "$FILTER_INVALID_RESP" | sed '$d')
FILTER_INVALID_STATUS=$(echo "$FILTER_INVALID_RESP" | tail -1)
assert_status "remote-config wildcard-only filter returns 400" 400 "$FILTER_INVALID_STATUS"
assert_contains "invalid remote-config filter returns BAD_REQUEST" '"code":"BAD_REQUEST"' "$FILTER_INVALID_BODY"

FILTER_CONSECUTIVE_RESP=$(curl --globoff -s -w "\n%{http_code}" "$BASE_URL/remote-config/filter?pattern=game**" -H "X-API-Key: $API_KEY")
FILTER_CONSECUTIVE_BODY=$(echo "$FILTER_CONSECUTIVE_RESP" | sed '$d')
FILTER_CONSECUTIVE_STATUS=$(echo "$FILTER_CONSECUTIVE_RESP" | tail -1)
assert_status "remote-config consecutive wildcards return 400" 400 "$FILTER_CONSECUTIVE_STATUS"
assert_contains "consecutive wildcard filter returns BAD_REQUEST" '"code":"BAD_REQUEST"' "$FILTER_CONSECUTIVE_BODY"

# Validation rules mirror RemoteConfigService.parseFilterPattern in the hosted
# server (source checked 2026-10-01), including literal dots and normalized text.
FILTER_TRIM_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=%20game%20" -H "X-API-Key: $API_KEY")
assert_contains "remote-config filter trims the pattern" '"pattern":"game"' "$FILTER_TRIM_RESP"
assert_contains "trimmed remote-config prefix still matches" '"total":2' "$FILTER_TRIM_RESP"

FILTER_LITERAL_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=game.max" -H "X-API-Key: $API_KEY")
assert_contains "remote-config filter treats dots literally" '"total":1' "$FILTER_LITERAL_RESP"

FILTER_NO_MATCH_RESP=$(curl --globoff -s "$BASE_URL/remote-config/filter?pattern=GAME*" -H "X-API-Key: $API_KEY")
assert_contains "remote-config filtering is case sensitive" '"total":0' "$FILTER_NO_MATCH_RESP"

FILTER_LONG_PATTERN=$(printf 'a%.0s' {1..103})
FILTER_LONG_LITERAL=$(printf 'a%.0s' {1..101})
for INVALID_PATTERN in "" "%20%20" "game%5B" "game%0A*" "$FILTER_LONG_PATTERN" "$FILTER_LONG_LITERAL" "a*a*a*a*a*a*a*a*a*a*a*a"; do
    FILTER_BOUNDARY_STATUS=$(curl --globoff -s -o /dev/null -w "%{http_code}" \
        "$BASE_URL/remote-config/filter?pattern=$INVALID_PATTERN" -H "X-API-Key: $API_KEY")
    assert_status "remote-config rejects invalid pattern '$INVALID_PATTERN'" 400 "$FILTER_BOUNDARY_STATUS"
done

# ---- 7. Localization ----
echo ""
echo -e "${BOLD}--- Localization ---${NC}"

php -r '
$pdo = new PDO("sqlite:./data/test_horizon_integration.db");
$stmt = $pdo->prepare("INSERT INTO localizations (localization_key, lang, value) VALUES (?, ?, ?)");
foreach ([
    ["menu.play", "en", "Play"],
    ["menu.play", "de", "Spielen"],
    ["menu.quit", "en", "Quit"],
    ["menu.only_de", "de", "Nur Deutsch"],
    ["menu.language", "zh", "Chinese"],
    ["menu.japanese", "ja", "Japanese"],
] as $row) {
    $stmt->execute($row);
}
'

LOC_DE_RESP=$(curl -s "$BASE_URL/localization/menu.play?lang=de" -H "X-API-Key: $API_KEY")
assert_contains "localization returns requested language" '"value":"Spielen"' "$LOC_DE_RESP"
assert_contains "localization reports requested language" '"language":"de"' "$LOC_DE_RESP"

LOC_REGION_RESP=$(curl -s "$BASE_URL/localization/menu.play?lang=DE-de" -H "X-API-Key: $API_KEY")
assert_contains "localization normalizes regional language" '"value":"Spielen"' "$LOC_REGION_RESP"
assert_contains "regional language response reports normalized language" '"language":"de"' "$LOC_REGION_RESP"

LOC_FALLBACK_RESP=$(curl -s "$BASE_URL/localization/menu.quit?lang=fr" -H "X-API-Key: $API_KEY")
assert_contains "localization falls back to English" '"value":"Quit"' "$LOC_FALLBACK_RESP"
assert_contains "localization reports served fallback language" '"language":"en"' "$LOC_FALLBACK_RESP"

LOC_UNKNOWN_RESP=$(curl -s "$BASE_URL/localization/menu.play?lang=xx" -H "X-API-Key: $API_KEY")
assert_contains "localization normalizes unknown language to English" '"value":"Play"' "$LOC_UNKNOWN_RESP"
assert_contains "unknown language response reports English" '"language":"en"' "$LOC_UNKNOWN_RESP"

LOC_ALL_RESP=$(curl -s "$BASE_URL/localization/all?lang=de-DE" -H "X-API-Key: $API_KEY")
assert_contains "localization all normalizes language" '"language":"de"' "$LOC_ALL_RESP"
assert_contains "localization all includes direct value" '"menu.play":"Spielen"' "$LOC_ALL_RESP"
assert_contains "localization all includes English fallback" '"menu.quit":"Quit"' "$LOC_ALL_RESP"
assert_contains "localization all reports resolved count" '"total":3' "$LOC_ALL_RESP"
assert_not_contains "localization all omits unresolved key" 'menu.language' "$LOC_ALL_RESP"

LOC_LANGUAGES_RESP=$(curl -s "$BASE_URL/localization/languages" -H "X-API-Key: $API_KEY")
assert_contains "localization languages returns canonical present languages" '"languages":["en","de","zh","ja"]' "$LOC_LANGUAGES_RESP"
assert_contains "localization languages returns total" '"total":4' "$LOC_LANGUAGES_RESP"

LOC_MISSING_RESP=$(curl -s "$BASE_URL/localization/missing?lang=xx" -H "X-API-Key: $API_KEY")
assert_contains "missing localization returns found false" '"found":false' "$LOC_MISSING_RESP"
assert_contains "missing localization reports normalized language" '"language":"en"' "$LOC_MISSING_RESP"

LOC_INVALID_KEY_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/localization/invalid%20key" -H "X-API-Key: $API_KEY")
LOC_INVALID_KEY_BODY=$(echo "$LOC_INVALID_KEY_RESP" | sed '$d')
LOC_INVALID_KEY_STATUS=$(echo "$LOC_INVALID_KEY_RESP" | tail -1)
assert_status "unusual localization key keeps hosted read contract" 200 "$LOC_INVALID_KEY_STATUS"
assert_contains "unusual missing localization key returns found false" '"found":false' "$LOC_INVALID_KEY_BODY"

LOC_LONG_KEY=$(printf 'a%.0s' {1..101})
LOC_LONG_KEY_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/localization/$LOC_LONG_KEY" -H "X-API-Key: $API_KEY")
LOC_LONG_KEY_BODY=$(echo "$LOC_LONG_KEY_RESP" | sed '$d')
LOC_LONG_KEY_STATUS=$(echo "$LOC_LONG_KEY_RESP" | tail -1)
assert_status "localization key over 100 characters returns 400" 400 "$LOC_LONG_KEY_STATUS"
assert_contains "oversized localization key returns BAD_REQUEST" '"code":"BAD_REQUEST"' "$LOC_LONG_KEY_BODY"

# Array-valued query input must not trigger a PHP TypeError/500.
LOC_ARRAY_QUERY_RESP=$(curl --globoff -s -w "\n%{http_code}" "$BASE_URL/localization/menu.play?lang[]=de" -H "X-API-Key: $API_KEY")
LOC_ARRAY_QUERY_BODY=$(echo "$LOC_ARRAY_QUERY_RESP" | sed '$d')
LOC_ARRAY_QUERY_STATUS=$(echo "$LOC_ARRAY_QUERY_RESP" | tail -1)
assert_status "array-valued language query stays safe" 200 "$LOC_ARRAY_QUERY_STATUS"
assert_contains "array-valued language query uses English default" '"value":"Play"' "$LOC_ARRAY_QUERY_BODY"

# ---- 8. News ----
echo ""
echo -e "${BOLD}--- News ---${NC}"

NEWS_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/news?limit=5" -H "X-API-Key: $API_KEY")
NEWS_BODY=$(echo "$NEWS_RESP" | sed '$d')
NEWS_STATUS=$(echo "$NEWS_RESP" | tail -1)
assert_status "GET /news" 200 "$NEWS_STATUS"
# Empty database should return empty array
assert_contains "news returns empty array" '[]' "$NEWS_BODY"

# ---- 9. Gift Codes ----
echo ""
echo -e "${BOLD}--- Gift Codes ---${NC}"

# Validate non-existent code
VALIDATE_RESP=$(curl -s "$BASE_URL/gift-codes/validate" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
assert_contains "POST /gift-codes/validate invalid code returns false" '"valid":false' "$VALIDATE_RESP"

# Redeem non-existent code (with the player's session)
REDEEM_RESP=$(curl -s "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
assert_contains "POST /gift-codes/redeem not found returns success false" '"success":false' "$REDEEM_RESP"
assert_contains "POST /gift-codes/redeem returns not found message" 'not found' "$REDEEM_RESP"

# Redeem without session -> 401 SESSION_REQUIRED, no transition window, no Deprecation/Sunset
NOSESSION_RESP=$(curl -s -D - -w "\n%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
NOSESSION_STATUS=$(echo "$NOSESSION_RESP" | tail -1)
assert_status "POST /gift-codes/redeem without session" 401 "$NOSESSION_STATUS"
assert_contains "redeem without session returns SESSION_REQUIRED" '"code":"SESSION_REQUIRED"' "$NOSESSION_RESP"
assert_not_contains "redeem without session has no Deprecation header" 'Deprecation:' "$NOSESSION_RESP"
assert_not_contains "redeem without session has no Sunset header" 'Sunset:' "$NOSESSION_RESP"

# Second player with its own session
SIGNUP2_BODY=$(curl -s "$BASE_URL/user-management/signup" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d '{"type":"ANONYMOUS","username":"OtherPlayer"}')
ANON_TOKEN_2=$(echo "$SIGNUP2_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->anonymousToken;')
USER_ID_2=$(echo "$SIGNUP2_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->userId;')
SIGNIN2_BODY=$(curl -s "$BASE_URL/user-management/signin" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"type\":\"ANONYMOUS\",\"anonymousToken\":\"$ANON_TOKEN_2\"}")
SESSION_TOKEN_2=$(echo "$SIGNIN2_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->accessToken;')

# Seed a redeemable gift code directly in the test database
php -r '$pdo = new PDO("sqlite:./data/test_horizon_integration.db");
$stmt = $pdo->prepare("INSERT INTO gift_codes (id, code, reward_type, reward_data, max_redemptions, current_redemptions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(["gift-886", "SESSION886", "currency", "{\"gold\":5}", 10, 0, gmdate("Y-m-d\\TH:i:s")]);'

# Invalid session -> 401
REDEEM_BAD_SESSION_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -H "Authorization: Bearer invalid-session-token" \
    -d "{\"code\":\"SESSION886\",\"userId\":\"$USER_ID\"}")
assert_status "POST /gift-codes/redeem with invalid session" 401 "$REDEEM_BAD_SESSION_STATUS"

# Session of another player -> 403, nothing is redeemed for the victim
REDEEM_FOREIGN_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -H "Authorization: Bearer $SESSION_TOKEN_2" \
    -d "{\"code\":\"SESSION886\",\"userId\":\"$USER_ID\"}")
assert_status "POST /gift-codes/redeem with another player's session" 403 "$REDEEM_FOREIGN_STATUS"

# Own session -> redeemed
REDEEM_OWN_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"code\":\"SESSION886\",\"userId\":\"$USER_ID\"}")
REDEEM_OWN_BODY=$(echo "$REDEEM_OWN_RESP" | sed '$d')
REDEEM_OWN_STATUS=$(echo "$REDEEM_OWN_RESP" | tail -1)
assert_status "POST /gift-codes/redeem with own session" 200 "$REDEEM_OWN_STATUS"
assert_contains "own-session redeem succeeds" '"success":true' "$REDEEM_OWN_BODY"

# Existing code without session -> 401 and the code is not used up for that player
REDEEM_STRICT_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"SESSION886\",\"userId\":\"$USER_ID_2\"}")
assert_status "POST /gift-codes/redeem of an existing code without session" 401 "$REDEEM_STRICT_STATUS"
STRICT_STILL_VALID=$(curl -s "$BASE_URL/gift-codes/validate" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"SESSION886\",\"userId\":\"$USER_ID_2\"}")
assert_contains "code is not redeemed for a request without session" '"valid":true' "$STRICT_STILL_VALID"

# ---- 8b. Player Profile ----
echo ""
echo -e "${BOLD}--- Player Profile ---${NC}"

# Seed the cosmetics catalog directly in the test database (simpleServer fills it by SQL)
php -r '$pdo = new PDO("sqlite:./data/test_horizon_integration.db");
$stmt = $pdo->prepare("INSERT INTO cosmetics (cosmetic_id, type, locked, created_at) VALUES (?, ?, ?, ?)");
$now = gmdate("Y-m-d\\TH:i:s");
foreach ([["avatar.zombie", "avatar", 0], ["frame.gold", "frame", 1], ["badge.supporter", "badge", 1],
          ["badge.a", "badge", 0], ["badge.b", "badge", 0], ["badge.c", "badge", 0], ["badge.d", "badge", 0]] as $c) {
    $stmt->execute([$c[0], $c[1], $c[2], $now]);
}'

# GET without session -> 401 SESSION_REQUIRED
PP_NOSESSION_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/player-profile?userId=$USER_ID" -H "X-API-Key: $API_KEY")
PP_NOSESSION_BODY=$(echo "$PP_NOSESSION_RESP" | sed '$d')
PP_NOSESSION_STATUS=$(echo "$PP_NOSESSION_RESP" | tail -1)
assert_status "GET /player-profile without session" 401 "$PP_NOSESSION_STATUS"
assert_contains "profile without session returns SESSION_REQUIRED" '"code":"SESSION_REQUIRED"' "$PP_NOSESSION_BODY"

# GET with an expired or unknown session -> 401
PP_BADSESSION_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/player-profile?userId=$USER_ID" \
    -H "X-API-Key: $API_KEY" -H "Authorization: Bearer invalid-session-token")
assert_status "GET /player-profile with invalid session" 401 "$PP_BADSESSION_STATUS"

# GET with another player's session -> 403 SESSION_FORBIDDEN
PP_FOREIGN_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/player-profile?userId=$USER_ID" \
    -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN_2")
PP_FOREIGN_BODY=$(echo "$PP_FOREIGN_RESP" | sed '$d')
PP_FOREIGN_STATUS=$(echo "$PP_FOREIGN_RESP" | tail -1)
assert_status "GET /player-profile with another player's session" 403 "$PP_FOREIGN_STATUS"
assert_contains "foreign session returns SESSION_FORBIDDEN" '"code":"SESSION_FORBIDDEN"' "$PP_FOREIGN_BODY"

# GET own profile -> empty profile, catalog with available flags, limits
PP_GET_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/player-profile?userId=$USER_ID" \
    -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN")
PP_GET_BODY=$(echo "$PP_GET_RESP" | sed '$d')
PP_GET_STATUS=$(echo "$PP_GET_RESP" | tail -1)
assert_status "GET /player-profile with own session" 200 "$PP_GET_STATUS"
assert_contains "profile is empty at first" '"profile":{"avatarId":null,"frameId":null,"badges":[]}' "$PP_GET_BODY"
assert_contains "profile has no unlocks at first" '"unlocks":[]' "$PP_GET_BODY"
assert_contains "catalog marks a free avatar available" '{"id":"avatar.zombie","type":"avatar","locked":false,"available":true}' "$PP_GET_BODY"
assert_contains "catalog marks a locked frame unavailable" '{"id":"frame.gold","type":"frame","locked":true,"available":false}' "$PP_GET_BODY"
assert_contains "profile returns limits" '"limits":{"maxBadges":3,"maxUnlocks":25}' "$PP_GET_BODY"

# PUT a locked frame -> 403 COSMETIC_LOCKED
PP_LOCKED_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"frameId\":\"frame.gold\"}")
PP_LOCKED_BODY=$(echo "$PP_LOCKED_RESP" | sed '$d')
PP_LOCKED_STATUS=$(echo "$PP_LOCKED_RESP" | tail -1)
assert_status "PUT /player-profile with a locked frame" 403 "$PP_LOCKED_STATUS"
assert_contains "locked frame returns COSMETIC_LOCKED" '"code":"COSMETIC_LOCKED"' "$PP_LOCKED_BODY"

# PUT a badge into the avatar slot -> 400 COSMETIC_TYPE_MISMATCH
PP_TYPE_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"avatarId\":\"badge.a\"}")
PP_TYPE_BODY=$(echo "$PP_TYPE_RESP" | sed '$d')
PP_TYPE_STATUS=$(echo "$PP_TYPE_RESP" | tail -1)
assert_status "PUT /player-profile with a type mismatch" 400 "$PP_TYPE_STATUS"
assert_contains "type mismatch returns COSMETIC_TYPE_MISMATCH" '"code":"COSMETIC_TYPE_MISMATCH"' "$PP_TYPE_BODY"

# PUT four badges -> 400 INVALID_BADGES
PP_BADGES_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"badges\":[\"badge.a\",\"badge.b\",\"badge.c\",\"badge.d\"]}")
PP_BADGES_BODY=$(echo "$PP_BADGES_RESP" | sed '$d')
PP_BADGES_STATUS=$(echo "$PP_BADGES_RESP" | tail -1)
assert_status "PUT /player-profile with too many badges" 400 "$PP_BADGES_STATUS"
assert_contains "too many badges returns INVALID_BADGES" '"code":"INVALID_BADGES"' "$PP_BADGES_BODY"

# PUT a badge twice -> 400 INVALID_BADGES
PP_DUP_BODY=$(curl -s "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"badges\":[\"badge.a\",\"badge.a\"]}")
assert_contains "duplicate badge returns INVALID_BADGES" '"code":"INVALID_BADGES"' "$PP_DUP_BODY"

# PUT a malformed ID -> 400 INVALID_COSMETIC_ID
PP_INVALID_BODY=$(curl -s "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"avatarId\":\"Bad ID\"}")
assert_contains "malformed ID returns INVALID_COSMETIC_ID" '"code":"INVALID_COSMETIC_ID"' "$PP_INVALID_BODY"

# PUT an ID that is not in the catalog -> 400 COSMETIC_NOT_FOUND
PP_UNKNOWN_BODY=$(curl -s "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"avatarId\":\"avatar.unknown\"}")
assert_contains "unknown ID returns COSMETIC_NOT_FOUND" '"code":"COSMETIC_NOT_FOUND"' "$PP_UNKNOWN_BODY"

# PUT with another player's session -> 403
PP_PUT_FOREIGN_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN_2" \
    -d "{\"userId\":\"$USER_ID\",\"avatarId\":\"avatar.zombie\"}")
assert_status "PUT /player-profile with another player's session" 403 "$PP_PUT_FOREIGN_STATUS"

# PUT free avatar and badge -> 200 with the new profile
PP_SET_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"avatarId\":\"avatar.zombie\",\"frameId\":null,\"badges\":[\"badge.a\"]}")
PP_SET_BODY=$(echo "$PP_SET_RESP" | sed '$d')
PP_SET_STATUS=$(echo "$PP_SET_RESP" | tail -1)
assert_status "PUT /player-profile with free cosmetics" 200 "$PP_SET_STATUS"
assert_contains "PUT returns the new profile" '"profile":{"avatarId":"avatar.zombie","frameId":null,"badges":["badge.a"]}' "$PP_SET_BODY"

# Leaderboard entries carry the profile
PP_TOP_RESP=$(curl -s "$BASE_URL/leaderboard/top?userId=$USER_ID&limit=10" -H "X-API-Key: $API_KEY")
assert_contains "leaderboard top entry contains profile" '"profile":{"avatarId":"avatar.zombie","frameId":null,"badges":["badge.a"]}' "$PP_TOP_RESP"
PP_RANK_RESP=$(curl -s "$BASE_URL/leaderboard/rank?userId=$USER_ID" -H "X-API-Key: $API_KEY")
assert_contains "leaderboard rank contains profile" '"profile":{"avatarId":"avatar.zombie"' "$PP_RANK_RESP"
PP_AROUND_RESP=$(curl -s "$BASE_URL/leaderboard/around?userId=$USER_ID&range=5" -H "X-API-Key: $API_KEY")
assert_contains "leaderboard around entry contains profile" '"profile":{"avatarId":"avatar.zombie"' "$PP_AROUND_RESP"
PP_RANK_EMPTY_RESP=$(curl -s "$BASE_URL/leaderboard/rank?userId=$USER_ID_2" -H "X-API-Key: $API_KEY")
assert_contains "empty rank result contains an empty profile" '"profile":{"avatarId":null,"frameId":null,"badges":[]}' "$PP_RANK_EMPTY_RESP"

# CORS preflight allows the Authorization header
PP_CORS_HEADERS=$(curl -s -D - -o /dev/null -X OPTIONS "$BASE_URL/player-profile")
assert_contains "CORS preflight allows Authorization" 'Authorization' "$PP_CORS_HEADERS"

# Gift codes with cosmetic grants
php -r '$pdo = new PDO("sqlite:./data/test_horizon_integration.db");
$stmt = $pdo->prepare("INSERT INTO gift_codes (id, code, reward_type, reward_data, max_redemptions, current_redemptions, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
$now = gmdate("Y-m-d\\TH:i:s");
$stmt->execute(["gift-881", "GRANT881", "cosmetic", "{\"gold\":1,\"grants\":[\"frame.gold\",\"badge.supporter\",\"gone.item\"]}", 10, 0, $now]);
$stmt->execute(["gift-881-cap", "CAP881", "cosmetic", "{\"grants\":[\"badge.supporter\"]}", 10, 0, $now]);'

# Code with grants without session -> 401, code not used up
GRANT_NOSESSION_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"GRANT881\",\"userId\":\"$USER_ID\"}")
GRANT_NOSESSION_BODY=$(echo "$GRANT_NOSESSION_RESP" | sed '$d')
GRANT_NOSESSION_STATUS=$(echo "$GRANT_NOSESSION_RESP" | tail -1)
assert_status "redeem a code with grants without session" 401 "$GRANT_NOSESSION_STATUS"
assert_contains "code with grants without session returns SESSION_REQUIRED" '"code":"SESSION_REQUIRED"' "$GRANT_NOSESSION_BODY"
GRANT_STILL_VALID=$(curl -s "$BASE_URL/gift-codes/validate" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"GRANT881\",\"userId\":\"$USER_ID\"}")
assert_contains "code with grants is not used up without session" '"valid":true' "$GRANT_STILL_VALID"

# Code with grants with session -> unlocks written, unknown grant skipped
GRANT_OWN_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"code\":\"GRANT881\",\"userId\":\"$USER_ID\"}")
GRANT_OWN_BODY=$(echo "$GRANT_OWN_RESP" | sed '$d')
GRANT_OWN_STATUS=$(echo "$GRANT_OWN_RESP" | tail -1)
assert_status "redeem a code with grants with own session" 200 "$GRANT_OWN_STATUS"
assert_contains "redeem returns grantedUnlocks" '"grantedUnlocks":["frame.gold","badge.supporter"]' "$GRANT_OWN_BODY"
assert_not_contains "grant missing from the catalog is skipped" 'gone.item"]' "$GRANT_OWN_BODY"

# Redeem without grants returns an empty grantedUnlocks
GRANT_NONE_BODY=$(curl -s "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
assert_contains "failed redeem returns empty grantedUnlocks" '"grantedUnlocks":[]' "$GRANT_NONE_BODY"

# The unlocked frame is now available and can be selected
PP_AFTER_GRANT_BODY=$(curl -s "$BASE_URL/player-profile?userId=$USER_ID" \
    -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN")
assert_contains "profile lists the granted unlocks" '"unlocks":["frame.gold","badge.supporter"]' "$PP_AFTER_GRANT_BODY"
assert_contains "granted frame is available" '{"id":"frame.gold","type":"frame","locked":true,"available":true}' "$PP_AFTER_GRANT_BODY"
PP_SET_UNLOCKED_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/player-profile" \
    -X PUT -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN" \
    -d "{\"userId\":\"$USER_ID\",\"avatarId\":\"avatar.zombie\",\"frameId\":\"frame.gold\",\"badges\":[\"badge.supporter\",\"badge.a\"]}")
assert_status "PUT /player-profile with unlocked cosmetics" 200 "$PP_SET_UNLOCKED_STATUS"

# Unlock cap: a player with 25 unlocks cannot redeem another grant (409), code not used up
php -r '$pdo = new PDO("sqlite:./data/test_horizon_integration.db");
$ids = array_map(function ($i) { return "old.item" . $i; }, range(1, 25));
$stmt = $pdo->prepare("UPDATE users SET unlocks = ? WHERE id = ?");
$stmt->execute([json_encode($ids), $argv[1]]);' "$USER_ID_2"
GRANT_CAP_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -H "Authorization: Bearer $SESSION_TOKEN_2" \
    -d "{\"code\":\"CAP881\",\"userId\":\"$USER_ID_2\"}")
GRANT_CAP_BODY=$(echo "$GRANT_CAP_RESP" | sed '$d')
GRANT_CAP_STATUS=$(echo "$GRANT_CAP_RESP" | tail -1)
assert_status "redeem over the unlock cap" 409 "$GRANT_CAP_STATUS"
assert_contains "unlock cap returns UNLOCK_LIMIT_REACHED" '"code":"UNLOCK_LIMIT_REACHED"' "$GRANT_CAP_BODY"
GRANT_CAP_VALID=$(curl -s "$BASE_URL/gift-codes/validate" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"CAP881\",\"userId\":\"$USER_ID_2\"}")
assert_contains "code over the unlock cap is not used up" '"valid":true' "$GRANT_CAP_VALID"

# ---- 10. User Feedback ----
echo ""
echo -e "${BOLD}--- User Feedback ---${NC}"

FB_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-feedback/submit" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"title\":\"Great game\",\"message\":\"Really enjoying it!\",\"category\":\"praise\",\"email\":\"test@example.com\"}")
FB_BODY=$(echo "$FB_RESP" | sed '$d')
FB_STATUS=$(echo "$FB_RESP" | tail -1)
assert_status "POST /user-feedback/submit" 200 "$FB_STATUS"
assert_contains "feedback returns ok" '"ok"' "$FB_BODY"

# ---- 11. User Logs ----
echo ""
echo -e "${BOLD}--- User Logs ---${NC}"

LOG_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-logs/create" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"message\":\"Player started level 5\",\"type\":\"INFO\"}")
LOG_BODY=$(echo "$LOG_RESP" | sed '$d')
LOG_STATUS=$(echo "$LOG_RESP" | tail -1)
assert_status "POST /user-logs/create" 201 "$LOG_STATUS"
assert_contains "user log returns id" '"id"' "$LOG_BODY"
assert_contains "user log returns createdAt" '"createdAt"' "$LOG_BODY"

# Test with error code
LOG_ERR_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-logs/create" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"message\":\"Failed to load asset\",\"type\":\"ERROR\",\"errorCode\":\"ASSET_404\"}")
LOG_ERR_STATUS=$(echo "$LOG_ERR_RESP" | tail -1)
assert_status "POST /user-logs/create with errorCode" 201 "$LOG_ERR_STATUS"

# ---- 12. Crash Reporting ----
echo ""
echo -e "${BOLD}--- Crash Reporting ---${NC}"

# Create session
SESSION_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/crash-reports/session" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"sessionId\":\"sess-integration-001\",\"appVersion\":\"1.0.0\",\"platform\":\"Android\",\"userId\":\"$USER_ID\"}")
SESSION_BODY=$(echo "$SESSION_RESP" | sed '$d')
SESSION_STATUS=$(echo "$SESSION_RESP" | tail -1)
assert_status "POST /crash-reports/session (new)" 201 "$SESSION_STATUS"
assert_contains "session returns status ok" '"status":"ok"' "$SESSION_BODY"

# Duplicate session should return 200 (not 201)
SESSION_DUP_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/crash-reports/session" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"sessionId\":\"sess-integration-001\",\"appVersion\":\"1.0.0\",\"platform\":\"Android\"}")
SESSION_DUP_STATUS=$(echo "$SESSION_DUP_RESP" | tail -1)
assert_status "POST /crash-reports/session (duplicate)" 200 "$SESSION_DUP_STATUS"

# Create crash report with full payload including breadcrumbs and customKeys
CRASH_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/crash-reports/create" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d '{
        "type": "CRASH",
        "message": "NullPointerException at GameManager.update()",
        "stackTrace": "at GameManager.update(GameManager.java:42)\nat Engine.tick(Engine.java:100)",
        "fingerprint": "fp-nullptr-gamemanager-001",
        "appVersion": "1.0.0",
        "sdkVersion": "0.5.0",
        "platform": "Android",
        "os": "Android 14",
        "deviceModel": "Pixel 8",
        "deviceMemoryMb": 8192,
        "sessionId": "sess-integration-001",
        "userId": "'"$USER_ID"'",
        "breadcrumbs": [
            {"timestamp": "2026-02-22T10:00:00", "type": "navigation", "message": "Opened main menu"},
            {"timestamp": "2026-02-22T10:01:00", "type": "navigation", "message": "Started level 5"},
            {"timestamp": "2026-02-22T10:01:30", "type": "error", "message": "Asset load failed"}
        ],
        "customKeys": {
            "build": "release",
            "flavor": "production",
            "userId": "'"$USER_ID"'"
        }
    }')
CRASH_BODY=$(echo "$CRASH_RESP" | sed '$d')
CRASH_STATUS=$(echo "$CRASH_RESP" | tail -1)
assert_status "POST /crash-reports/create" 201 "$CRASH_STATUS"
assert_contains "crash report returns id" '"id"' "$CRASH_BODY"
assert_contains "crash report returns groupId" '"groupId"' "$CRASH_BODY"
assert_contains "crash report returns createdAt" '"createdAt"' "$CRASH_BODY"

# ---- 13. Apple Sign-In (disabled + bad token) ----
echo ""
echo -e "${BOLD}--- Apple Sign-In ---${NC}"

# With APPLE_SIGN_IN_ENABLED unset, public endpoint returns APPLE_NOT_CONFIGURED.
APPLE_PUB_URL="http://localhost:${TEST_PORT}/api/v1/public/auth/apple"
APPLE_RESP=$(curl -s -w "\n%{http_code}" "$APPLE_PUB_URL" \
    -X POST -H "Content-Type: application/json" \
    -d '{"identityToken":"not-a-real-jwt"}')
APPLE_BODY=$(echo "$APPLE_RESP" | sed '$d')
APPLE_STATUS=$(echo "$APPLE_RESP" | tail -1)
assert_status "POST /api/v1/public/auth/apple (disabled)" 200 "$APPLE_STATUS"
assert_contains "apple disabled returns APPLE_NOT_CONFIGURED" '"authStatus":"APPLE_NOT_CONFIGURED"' "$APPLE_BODY"

# Missing identityToken — while Apple is disabled, server short-circuits with APPLE_NOT_CONFIGURED.
# (When Apple is enabled, this same request returns 400 "identityToken is required".)
APPLE_MISSING_RESP=$(curl -s -w "\n%{http_code}" "$APPLE_PUB_URL" \
    -X POST -H "Content-Type: application/json" -d '{}')
APPLE_MISSING_BODY=$(echo "$APPLE_MISSING_RESP" | sed '$d')
APPLE_MISSING_STATUS=$(echo "$APPLE_MISSING_RESP" | tail -1)
assert_status "POST /api/v1/public/auth/apple empty body (disabled)" 200 "$APPLE_MISSING_STATUS"
assert_contains "empty-body apple returns APPLE_NOT_CONFIGURED" '"authStatus":"APPLE_NOT_CONFIGURED"' "$APPLE_MISSING_BODY"

# App signup with appleIdentityToken (malformed) when Apple disabled -> APPLE_NOT_CONFIGURED
APPLE_SIGNUP_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/signup" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d '{"appleIdentityToken":"not-a-real-jwt"}')
APPLE_SIGNUP_BODY=$(echo "$APPLE_SIGNUP_RESP" | sed '$d')
APPLE_SIGNUP_STATUS=$(echo "$APPLE_SIGNUP_RESP" | tail -1)
assert_status "POST signup with appleIdentityToken (disabled)" 200 "$APPLE_SIGNUP_STATUS"
assert_contains "signup apple disabled returns APPLE_NOT_CONFIGURED" '"authStatus":"APPLE_NOT_CONFIGURED"' "$APPLE_SIGNUP_BODY"

# ---- 14. Error Handling ----
echo ""
echo -e "${BOLD}--- Error Handling ---${NC}"

SCALAR_JSON_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/user-management/signup" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -d '42')
assert_status "scalar JSON body returns 400 instead of 500" 400 "$SCALAR_JSON_STATUS"

ARRAY_PATTERN_STATUS=$(curl --globoff -s -o /dev/null -w "%{http_code}" \
    "$BASE_URL/remote-config/filter?pattern[]=game" -H "X-API-Key: $API_KEY")
assert_status "array-valued filter query returns 400 instead of 500" 400 "$ARRAY_PATTERN_STATUS"

# Request must not coerce an array query to an integer such as 1.
QUERY_INT_RESULT=$(php -r 'require "src/Core/Request.php"; $_GET = ["limit" => ["10"]]; echo (new Request())->queryInt("limit", 37);')
assert_contains "array-valued integer query uses the caller default" '37' "$QUERY_INT_RESULT"

for JSON_SCALAR in 'null' '"text"' 'true'; do
    SCALAR_BODY_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/user-management/signup" \
        -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -d "$JSON_SCALAR")
    assert_status "non-object JSON '$JSON_SCALAR' returns 400 instead of 500" 400 "$SCALAR_BODY_STATUS"
done

STATUS_404=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/nonexistent-endpoint" -H "X-API-Key: $API_KEY")
assert_status "GET nonexistent endpoint returns 404" 404 "$STATUS_404"

BODY_404=$(curl -s "$BASE_URL/totally-invalid" -H "X-API-Key: $API_KEY")
assert_contains "404 response contains error" '"error":true' "$BODY_404"
assert_contains "404 response contains NOT_FOUND code" '"code":"NOT_FOUND"' "$BODY_404"

# This in-memory fixture covers MySQL collation aliases without contacting SMTP.
if php tests/remote-config-collation.php; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
fi

# ---- Summary ----
echo ""
echo "================================"
TOTAL=$((PASS + FAIL))
echo -e "Results: ${GREEN}${PASS} passed${NC}, ${RED}${FAIL} failed${NC} (${TOTAL} total)"
echo "================================"

if [ "$FAIL" -gt 0 ]; then
    exit 1
fi

echo ""
echo -e "${GREEN}All tests passed!${NC}"
