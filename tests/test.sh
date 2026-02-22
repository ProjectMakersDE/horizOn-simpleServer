#!/bin/bash
#
# horizOn Simple Server - Integration Test Suite
#
# Starts a PHP built-in server, creates a temporary .env and SQLite database,
# then runs curl tests against every endpoint. Reports pass/fail with colors.
#

set -euo pipefail

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

PASS=0
FAIL=0
TEST_PORT=8765
BASE_URL="http://localhost:${TEST_PORT}/api/v1/app"
API_KEY="test-key-integration-12345"
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_PID=""

echo -e "${BOLD}=== horizOn Simple Server Integration Tests ===${NC}"
echo ""

# ---- Setup ----

cd "$PROJECT_DIR"

# Kill any existing PHP server on the test port
if lsof -i :"$TEST_PORT" > /dev/null 2>&1; then
    echo -e "${YELLOW}Killing existing process on port ${TEST_PORT}...${NC}"
    lsof -ti :"$TEST_PORT" | xargs kill -9 2>/dev/null || true
    sleep 1
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

# ---- 7. News ----
echo ""
echo -e "${BOLD}--- News ---${NC}"

NEWS_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/news?limit=5" -H "X-API-Key: $API_KEY")
NEWS_BODY=$(echo "$NEWS_RESP" | sed '$d')
NEWS_STATUS=$(echo "$NEWS_RESP" | tail -1)
assert_status "GET /news" 200 "$NEWS_STATUS"
# Empty database should return empty array
assert_contains "news returns empty array" '[]' "$NEWS_BODY"

# ---- 8. Gift Codes ----
echo ""
echo -e "${BOLD}--- Gift Codes ---${NC}"

# Validate non-existent code
VALIDATE_RESP=$(curl -s "$BASE_URL/gift-codes/validate" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
assert_contains "POST /gift-codes/validate invalid code returns false" '"valid":false' "$VALIDATE_RESP"

# Redeem non-existent code
REDEEM_RESP=$(curl -s "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
assert_contains "POST /gift-codes/redeem not found returns success false" '"success":false' "$REDEEM_RESP"
assert_contains "POST /gift-codes/redeem returns not found message" 'not found' "$REDEEM_RESP"

# ---- 9. User Feedback ----
echo ""
echo -e "${BOLD}--- User Feedback ---${NC}"

FB_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-feedback/submit" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"title\":\"Great game\",\"message\":\"Really enjoying it!\",\"category\":\"praise\",\"email\":\"test@example.com\"}")
FB_BODY=$(echo "$FB_RESP" | sed '$d')
FB_STATUS=$(echo "$FB_RESP" | tail -1)
assert_status "POST /user-feedback/submit" 200 "$FB_STATUS"
assert_contains "feedback returns ok" '"ok"' "$FB_BODY"

# ---- 10. User Logs ----
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

# ---- 11. Crash Reporting ----
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

# ---- 12. 404 Handling ----
echo ""
echo -e "${BOLD}--- Error Handling ---${NC}"

STATUS_404=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/nonexistent-endpoint" -H "X-API-Key: $API_KEY")
assert_status "GET nonexistent endpoint returns 404" 404 "$STATUS_404"

BODY_404=$(curl -s "$BASE_URL/totally-invalid" -H "X-API-Key: $API_KEY")
assert_contains "404 response contains error" '"error":true' "$BODY_404"
assert_contains "404 response contains NOT_FOUND code" '"code":"NOT_FOUND"' "$BODY_404"

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
