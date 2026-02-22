# horizOn Simple Server - Design Document

**Date:** 2026-02-22
**Status:** Approved

## Overview

A lightweight, self-hostable PHP server as an open-source drop-in replacement for the horizOn backend. Designed for indie developers who need a free alternative to the hosted horizOn service. Anonymous-auth only, configurable with SQLite or MySQL, zero external dependencies.

## Goals

- 1:1 API compatibility with horizOn backend (`/api/v1/app/*` endpoints)
- Self-hostable on any PHP webhosting (Apache, Nginx, built-in PHP server)
- Configurable database backend (SQLite or MySQL via PDO)
- Clean, well-written code without over-engineering
- No Composer, no framework, no external dependencies

## Architecture

**Approach:** Single entry point with feature-organized controllers.

### File Structure

```
horizOn-simpleServer/
├── index.php                          # Entry point + bootstrap
├── .htaccess                          # Apache URL rewriting
├── .env.example                       # Config template
├── src/
│   ├── Core/
│   │   ├── Router.php                 # URL pattern matching + dispatching
│   │   ├── Request.php                # JSON body, query params, headers
│   │   ├── Response.php               # JSON responses with HTTP status
│   │   ├── Database.php               # PDO wrapper (SQLite/MySQL)
│   │   ├── Config.php                 # .env parser
│   │   ├── Auth.php                   # API-Key validation (X-API-Key header)
│   │   └── RateLimit.php              # Per-IP rate limiting
│   ├── UserManagement/
│   │   └── UserManagementController.php
│   ├── Leaderboard/
│   │   └── LeaderboardController.php
│   ├── CloudSave/
│   │   └── CloudSaveController.php
│   ├── RemoteConfig/
│   │   └── RemoteConfigController.php
│   ├── News/
│   │   └── NewsController.php
│   ├── GiftCodes/
│   │   └── GiftCodesController.php
│   ├── UserFeedback/
│   │   └── UserFeedbackController.php
│   ├── UserLogs/
│   │   └── UserLogsController.php
│   └── CrashReporting/
│       └── CrashReportingController.php
├── migrations/
│   ├── sqlite.sql
│   └── mysql.sql
├── nginx.conf.example
└── README.md
```

### Request Flow

```
Client Request
    -> index.php
    -> API-Key check (Auth.php)
    -> Rate Limit check (RateLimit.php)
    -> Route match (Router.php)
    -> Controller method
    -> DB operations (Database.php)
    -> JSON Response
```

## Configuration (.env)

```env
# Server
API_KEY=your-secret-api-key-here

# Database (sqlite or mysql)
DB_DRIVER=sqlite
DB_PATH=./data/horizon.db
DB_HOST=localhost
DB_PORT=3306
DB_NAME=horizon
DB_USER=root
DB_PASS=

# Rate Limiting
RATE_LIMIT_PER_SECOND=10
RATE_LIMIT_ENABLED=true
```

## API Endpoints

All endpoints under `/api/v1/app/`. All require `X-API-Key` header.

### User Management (Anonymous Only)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/user-management/signup` | Create anonymous user (displayName) |
| POST | `/user-management/signin` | Sign in with anonymous token |
| POST | `/user-management/check-auth` | Validate session token |

### Leaderboard

| Method | Path | Description |
|--------|------|-------------|
| POST | `/leaderboard/submit` | Submit score (userId, score) |
| GET | `/leaderboard/top?userId=&limit=` | Get top entries |
| GET | `/leaderboard/rank?userId=` | Get user rank |
| GET | `/leaderboard/around?userId=&range=` | Get entries around user |

### Cloud Save (JSON only)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/cloud-save/save` | Save data (userId, data) |
| POST | `/cloud-save/load` | Load data (userId) |

### Remote Config

| Method | Path | Description |
|--------|------|-------------|
| GET | `/remote-config/{key}` | Get single config value |
| GET | `/remote-config/all` | Get all config values |

### News

| Method | Path | Description |
|--------|------|-------------|
| GET | `/news?limit=&languageCode=` | Get news articles |

### Gift Codes

| Method | Path | Description |
|--------|------|-------------|
| POST | `/gift-codes/validate` | Validate code without redeeming |
| POST | `/gift-codes/redeem` | Redeem code for user |

### User Feedback

| Method | Path | Description |
|--------|------|-------------|
| POST | `/user-feedback/submit` | Submit feedback |

### User Logs

| Method | Path | Description |
|--------|------|-------------|
| POST | `/user-logs/create` | Create log entry |

### Crash Reporting

| Method | Path | Description |
|--------|------|-------------|
| POST | `/crash-reports/create` | Submit crash report |
| POST | `/crash-reports/session` | Register session |

### Health

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Server health check |

## Database Schema

### users

| Column | Type | Description |
|--------|------|-------------|
| id | UUID (PK) | User ID |
| display_name | VARCHAR(30) | Display name |
| anonymous_token | VARCHAR(32) UNIQUE | Token for anonymous login |
| session_token | VARCHAR(256) | Active session token |
| session_expires_at | DATETIME | Session expiry |
| created_at | DATETIME | Created at |

### leaderboard

| Column | Type |
|--------|------|
| id | UUID (PK) |
| user_id | UUID (FK, UNIQUE) |
| score | BIGINT |
| updated_at | DATETIME |

### cloud_saves

| Column | Type |
|--------|------|
| user_id | UUID (PK) |
| data | TEXT (max ~300KB) |
| updated_at | DATETIME |

### remote_configs

| Column | Type |
|--------|------|
| key | VARCHAR(256) (PK) |
| value | TEXT |

### news

| Column | Type |
|--------|------|
| id | UUID (PK) |
| title | VARCHAR(200) |
| content | TEXT |
| language_code | CHAR(2) |
| published_at | DATETIME |
| created_at | DATETIME |

### gift_codes

| Column | Type |
|--------|------|
| id | UUID (PK) |
| code | VARCHAR(50) UNIQUE |
| reward_type | VARCHAR(50) |
| reward_data | TEXT |
| max_redemptions | INT |
| current_redemptions | INT DEFAULT 0 |
| expires_at | DATETIME (nullable) |
| created_at | DATETIME |

### gift_code_redemptions

| Column | Type |
|--------|------|
| id | UUID (PK) |
| gift_code_id | UUID (FK) |
| user_id | UUID (FK) |
| redeemed_at | DATETIME |

### user_feedback

| Column | Type |
|--------|------|
| id | UUID (PK) |
| user_id | UUID |
| title | VARCHAR(100) |
| message | TEXT |
| category | VARCHAR(50) |
| email | VARCHAR(255) |
| device_info | VARCHAR(500) |
| created_at | DATETIME |

### user_logs

| Column | Type |
|--------|------|
| id | UUID (PK) |
| user_id | UUID |
| message | VARCHAR(1000) |
| type | VARCHAR(5) -- INFO, WARN, ERROR |
| error_code | VARCHAR(50) |
| created_at | DATETIME |

### crash_reports

| Column | Type |
|--------|------|
| id | UUID (PK) |
| type | VARCHAR(10) -- CRASH, NON_FATAL, ANR |
| message | TEXT |
| stack_trace | TEXT |
| fingerprint | VARCHAR(128) |
| app_version | VARCHAR(50) |
| sdk_version | VARCHAR(50) |
| platform | VARCHAR(50) |
| os | VARCHAR(100) |
| device_model | VARCHAR(100) |
| device_memory_mb | INT |
| session_id | VARCHAR(100) |
| user_id | VARCHAR(36) |
| breadcrumbs | TEXT -- JSON array |
| custom_keys | TEXT -- JSON object |
| created_at | DATETIME |

### crash_groups

| Column | Type |
|--------|------|
| id | UUID (PK) |
| fingerprint | VARCHAR(128) UNIQUE |
| title | VARCHAR(200) |
| status | VARCHAR(10) -- OPEN, RESOLVED, REGRESSED |
| type | VARCHAR(10) |
| first_seen_at | DATETIME |
| last_seen_at | DATETIME |
| occurrence_count | INT DEFAULT 0 |
| affected_user_count | INT DEFAULT 0 |
| affected_versions | TEXT -- JSON array |
| latest_stack_trace | TEXT |
| platform | VARCHAR(50) |
| notes | TEXT |
| resolved_at | DATETIME |
| resolved_in_version | VARCHAR(50) |

### crash_sessions

| Column | Type |
|--------|------|
| id | UUID (PK) |
| session_id | VARCHAR(100) UNIQUE |
| user_id | VARCHAR(36) |
| app_version | VARCHAR(50) |
| platform | VARCHAR(50) |
| has_crash | BOOLEAN DEFAULT FALSE |
| created_at | DATETIME |

### rate_limits

| Column | Type |
|--------|------|
| ip_address | VARCHAR(45) (PK) |
| request_count | INT |
| window_start | INT -- Unix timestamp |

## Core Components

### Router

Simple pattern-matching router supporting `GET`, `POST`, `PUT`, `DELETE` with URL parameter extraction (e.g., `{key}` in `/remote-config/{key}`).

### Database

PDO-based abstraction. Configured via `DB_DRIVER` in `.env`. Auto-creates tables on first run using migration SQL files. All queries use prepared statements.

### Auth

Validates `X-API-Key` header against configured `API_KEY`. Returns 401 on missing/invalid key. Health endpoint is exempt.

### Rate Limiter

Per-IP rate limiting. Tracks request counts in `rate_limits` table with sliding window. Configurable via `RATE_LIMIT_PER_SECOND`. Returns 429 on exceed.

### Crash Reporting Logic

- **create**: Accept crash report, upsert crash group by fingerprint, mark session as crashed
- **session**: Register app session, deduplicate by sessionId
- Auto-regression: RESOLVED group gets REGRESSED status if new crash arrives in a newer app version
- Breadcrumbs and custom keys stored as JSON strings

## Error Response Format

```json
{
    "error": true,
    "message": "Invalid API key",
    "code": "UNAUTHORIZED"
}
```

HTTP status codes: 200, 201, 400, 401, 404, 429, 500

## Requirements

- PHP 7.4+ (8.0+ recommended)
- PDO extension with sqlite or mysql driver
- Apache with mod_rewrite OR Nginx
- No Composer, no external dependencies

## Non-Goals

- No admin dashboard endpoints (use horizOn Dashboard with the hosted backend for admin features)
- No email/password authentication (anonymous only)
- No Google/OAuth authentication
- No binary cloud save format
- No email verification/password reset
- No SMTP integration
