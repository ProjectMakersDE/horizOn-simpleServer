<p align="center">
  <a href="https://horizon.pm">
    <img src="https://horizon.pm/media/images/og-image.png" alt="horizOn - Game Backend & Live-Ops Dashboard" />
  </a>
</p>

# horizOn Simple Server

A lightweight, self-hostable PHP backend server — the **free, open-source edition** of the [horizOn](https://horizon.pm) game backend. Drop it on any PHP hosting and you have a fully functional backend for your game in minutes.

Built for indie game developers and small studios who want full control over their backend without cloud costs or vendor lock-in. Fully API-compatible with the horizOn SDKs (Unity, Godot, Unreal).

**No Composer. No frameworks. No external dependencies.** Just PHP 7.4+ and a database.

## Features

- Anonymous user authentication with session management
- Apple Sign-In (iOS / Web) with local JWT verification and JWKS caching
- Global leaderboards (submit, top, rank, around)
- Cloud save data (up to 300 KB per user on simpleServer, horizOn Cloud limits depend on the plan, see https://horizon.pm/pricing)
- Remote configuration key-value store with wildcard filtering
- Localization key-value store with per-language values and English fallback
- In-app news system with language filtering
- Gift code validation and redemption (with cosmetic unlocks via `grants`)
- Player profile: avatar, frame and up to 3 badges from a cosmetics catalog, shown in leaderboard entries
- Explicit atomic player-statistics batches: sum/max/min/last, UTC resets and safe original-ID retries
- Friends: bilateral requests, explicit revisions, blocklists and atomic Inbox notifications
- Groups: open/request/invite membership, leader/officer/member roles, private object data and bounded member pages
- Items: indexed catalog, unique inventory instances, rule-bound properties, equip/discard and atomic paid item grants
- Shared Objects: opaque binary data, collection permissions, indexed queries, explicit revisions and bounded cleanup
- User feedback collection
- User log ingestion (INFO, WARN, ERROR)
- Crash reporting with automatic grouping, fingerprinting, and regression detection
- Email sending with template-based delivery and scheduled emails
- Per-IP rate limiting
- SQLite (zero-config) or MySQL support

## Simple Server vs. horizOn BaaS

This table compares the self-hosted Simple Server with the fully managed [horizOn](https://horizon.pm) Backend-as-a-Service.

| Feature | Simple Server | horizOn BaaS |
|---|:---:|:---:|
| **Authentication** | | |
| Anonymous auth | :white_check_mark: | :white_check_mark: |
| Email / password auth | :x: | :white_check_mark: |
| Google Sign-In (OAuth) | :x: | :white_check_mark: |
| Apple Sign-In | :white_check_mark: | :white_check_mark: |
| Account linking (multiple auth methods) | :x: | :white_check_mark: |
| Email verification & password reset | :x: | :white_check_mark: |
| **Leaderboards** | | |
| Submit, top, rank, around | :white_check_mark: | :white_check_mark: |
| Player profile in top, rank, around entries | :white_check_mark: | :white_check_mark: |
| Leaderboard statistics & management | :x: | :white_check_mark: |
| Validated Actions (run tickets, server rules, server-owned state, evidence; cloud only) | :x: | :white_check_mark: |
| **Cloud Saves** | | |
| Save & load | :white_check_mark: | :white_check_mark: |
| Size per user | 300 KB | per plan, see https://horizon.pm/pricing |
| **Remote Config** | | |
| Key-value store | :white_check_mark: | :white_check_mark: |
| Wildcard key filtering | :white_check_mark: | :white_check_mark: |
| **Localization** | | |
| Key-value translations with language fallback (read) | :white_check_mark: | :white_check_mark: |
| LLM-powered auto-translation (15 languages) | :x: | :white_check_mark: |
| **News** | | |
| News with language filtering | :white_check_mark: | :white_check_mark: |
| LLM-powered auto-translation (15 languages) | :x: | :white_check_mark: |
| **Gift Codes** | | |
| Validate & redeem | :white_check_mark: | :white_check_mark: |
| Cosmetic unlocks via `grants` (session bound) | :white_check_mark: | :white_check_mark: |
| Grants editor with catalog validation | :x: (SQL) | :white_check_mark: |
| **Player Profile** | | |
| Get & set avatar, frame, badges (same endpoints and error codes) | :white_check_mark: | :white_check_mark: |
| Cosmetics catalog | :white_check_mark: (SQL table, no size limit) | :white_check_mark: (dashboard, per API key, tier limit) |
| Grant & revoke unlocks per player | :x: (SQL) | :white_check_mark: |
| **Statistics** | | |
| Atomic session batches, conditional catalog, UTC day/week resets | :white_check_mark: | :white_check_mark: |
| Definition management | local operator CLI / SQL | dashboard, per-project tier limits |
| **Friends** | | |
| Requests, friends, blocks and Inbox notifications (session bound) | :white_check_mark: | :white_check_mark: |
| Relationship inspection | local operator CLI | scoped dashboard / Account Key |
| Realtime invalidation | :x: | :white_check_mark: |
| **Groups** | | |
| Membership, roles, private data and Inbox notifications (session bound) | :white_check_mark: | :white_check_mark: |
| Inspection and deleted-user cleanup | local operator CLI | scoped dashboard / Account Key |
| Realtime invalidation | :x: | :white_check_mark: |
| **Chat** | | |
| Direct/group/project messages, realtime sync and moderation | :x: (HTTP 501 `NOT_SUPPORTED`, no polling fallback) | :white_check_mark: |
| **Items** | | |
| Catalog pages, unique inventory, rule-bound properties and atomic paid grants | :white_check_mark: | :white_check_mark: |
| Catalog administration / inventory inspection | local operator CLI | scoped dashboard / Account Key |
| **Shared Objects** | | |
| Opaque bytes, indexed queries and owner/friends/group permissions | :white_check_mark: | :white_check_mark: |
| Collection administration and ordinary trusted game notices | local operator CLI | scoped dashboard / trusted backend |
| **User Feedback** | | |
| Feedback submission | :white_check_mark: | :white_check_mark: |
| **User Logs** | | |
| INFO / WARN / ERROR | :white_check_mark: | :white_check_mark: |
| **Crash Reporting** | | |
| Crash report submission | :white_check_mark: | :white_check_mark: |
| Session tracking & breadcrumbs | :white_check_mark: | :white_check_mark: |
| Crash group management & statistics | :x: | :white_check_mark: |
| **Email Sending** | | |
| Template-based email delivery | :white_check_mark: | :white_check_mark: |
| Scheduled emails | :white_check_mark: | :white_check_mark: |
| Automatic template translation | :x: | :white_check_mark: |
| SMTP connection pooling | :x: | :white_check_mark: |
| **Admin & Dashboard** | | |
| Web dashboard | :x: | :white_check_mark: |
| User management UI | :x: | :white_check_mark: |
| API key management | :x: | :white_check_mark: |
| **Community & Support** | | |
| Discord integration & role sync | :x: | :white_check_mark: |
| Support ticket system | :x: | :white_check_mark: |
| Blog / CMS | :x: | :white_check_mark: |
| **Infrastructure** | | |
| Self-hosted | :white_check_mark: | :x: |
| Zero dependencies (no Docker/Java) | :white_check_mark: | :x: |
| SQLite support | :white_check_mark: | :x: |
| Runs on shared PHP hosting | :white_check_mark: | :x: |

> **SDK compatibility:** Both versions use the same API contract, so you can switch between Simple Server and horizOn BaaS without changing your game code.

## Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/ProjectMakersDE/horizOn-simpleServer.git
cd horizOn-simpleServer

# 2. Copy and edit the environment file
cp .env.example .env
# Edit .env and set a secure API_KEY

# 3. Start the server
php -S localhost:8080 index.php
```

The server will automatically create the SQLite database and run migrations on first request.

## Configuration

All configuration is done via the `.env` file. Copy `.env.example` to `.env` and adjust the values.

| Variable | Default | Description |
|---|---|---|
| `API_KEY` | `change-me-to-a-secure-key` | API key clients must send in the `X-API-Key` header |
| `DB_DRIVER` | `sqlite` | Database driver: `sqlite` or `mysql` |
| `DB_PATH` | `./data/horizon.db` | Path to SQLite database file (SQLite only) |
| `DB_HOST` | `localhost` | MySQL host (MySQL only) |
| `DB_PORT` | `3306` | MySQL port (MySQL only) |
| `DB_NAME` | `horizon` | MySQL database name (MySQL only) |
| `DB_USER` | `root` | MySQL username (MySQL only) |
| `DB_PASS` | *(empty)* | MySQL password (MySQL only) |
| `RATE_LIMIT_ENABLED` | `true` | Enable per-IP rate limiting |
| `RATE_LIMIT_PER_SECOND` | `10` | Maximum requests per second per IP |
| `APPLE_SIGN_IN_ENABLED` | `false` | Enable Apple Sign-In (see "Apple Sign-In Self-Hosted Setup") |
| `APPLE_TEAM_ID` | *(empty)* | 10-character Apple Team ID |
| `APPLE_SERVICE_ID` | *(empty)* | Apple Services ID — used as `aud` for web logins |
| `APPLE_BUNDLE_ID` | *(empty)* | Apple Bundle ID — used as `aud` for native iOS logins |

## API Endpoints

All endpoints are prefixed with `/api/v1/app`. Except for `/health`, all endpoints require the `X-API-Key` header.

### Health

| Method | Endpoint | Description |
|---|---|---|
| GET | `/health` | Health check (no auth required) |

### User Management

| Method | Endpoint | Description |
|---|---|---|
| POST | `/user-management/signup` | Create a new anonymous user (or Apple user via `appleIdentityToken`) |
| POST | `/user-management/signin` | Sign in with anonymous token (or Apple via `appleIdentityToken`) |
| POST | `/user-management/check-auth` | Verify session validity |

### Apple Sign-In (Public)

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/public/auth/apple` | Log in / register a user with an Apple identity token (no API key) |

### Leaderboard

| Method | Endpoint | Description |
|---|---|---|
| POST | `/leaderboard/submit` | Submit or update a score |
| GET | `/leaderboard/top` | Get top leaderboard entries (each with the player `profile`) |
| GET | `/leaderboard/rank` | Get a user's rank (with `profile`) |
| GET | `/leaderboard/around` | Get entries around a user's position (each with `profile`) |

### Cloud Save

| Method | Endpoint | Description |
|---|---|---|
| POST | `/cloud-save/save` | Save user data (max 300 KB) |
| POST | `/cloud-save/load` | Load user data |

### Remote Config

| Method | Endpoint | Description |
|---|---|---|
| GET | `/remote-config/all` | Get all configuration key-value pairs |
| GET | `/remote-config/filter` | Get values matching `?pattern=` (`prefix`/`prefix*`, `*suffix`, `*contains*`, or a glob) |
| GET | `/remote-config/{key}` | Get a single configuration value |

The internal `smtp_config` key is never exposed by these app endpoints.

### Localization

App read endpoints only. Translations are managed directly in the `localizations` table (one row per key + language); there is no admin UI, just like Remote Config.

| Method | Endpoint | Description |
|---|---|---|
| GET | `/localization/all` | Get all translations for a language (`?lang=`, default `en`) |
| GET | `/localization/languages` | List the supported language codes (15 languages) |
| GET | `/localization/{key}` | Get a single translation (`?lang=`, default `en`; falls back to `en` if missing) |

Language values are normalized like the managed backend: for example `DE-de` becomes `de`, while unsupported values fall back to `en`.

### News

| Method | Endpoint | Description |
|---|---|---|
| GET | `/news` | List news articles (supports `limit` and `languageCode` query params) |

### Gift Codes

| Method | Endpoint | Description |
|---|---|---|
| POST | `/gift-codes/validate` | Check if a gift code is valid |
| POST | `/gift-codes/redeem` | Redeem a gift code (needs the player session, see below) |

`/gift-codes/redeem` is bound to the player's session: send `Authorization: Bearer <accessToken>` from sign-in. An invalid or expired session returns `401` (`SESSION_REQUIRED`), a session of another user `403` (`SESSION_FORBIDDEN`). A request without a session is rejected with `401` (`SESSION_REQUIRED`) as well; there is no transition window, so SDK versions that do not send the session (Unity 1.8.6, Godot 1.7.3 and MCP 1.5.5) cannot redeem.

Gift codes can additionally grant up to eight positive, unique Player Values rewards. The local operator sets them using the same configured keys and balance limits:

```sh
php bin/gift-codes.php values CODE
php bin/gift-codes.php set-values CODE <<'JSON'
{"values":[{"key":"gold","amount":5}]}
JSON
```

Omitting `values` preserves the current rewards; `[]` clears them and `null` is rejected. Amounts must be JSON integers from1 to9007199254740991 and fit the configured `maxBalance`. The operator command updates only `values_json`, preserving concurrent redemption counters. The script rejects HTTP before configuration or database bootstrap. Existing SQL-created codes with `values_json IS NULL` retain their opaque `reward_data` and cosmetic-grant behavior and perform no Player Values reads.

Add a stable `idempotencyId` of1..128 characters to the normal `{userId,code}` redemption body and retain it across a timeout. Older clients may omit it. A successful response additionally contains `grantedValues`, the current `playerValues` snapshot (or null for legacy codes), and `replayed`. A retained replay uses the original granted amounts and gift data, together with the current balance. Reusing an ID for a different code/player returns409 `OPERATION_CONFLICT`; an uncertain commit returns409 `STATE_CONFLICT` and requires retrying the same ID. The counter, permanent source receipt, values and cosmetic unlocks share one explicit SQL transaction; any later failure rolls them all back.

Value-bearing redemption receipts are permanent guards: never delete rows where `value_granted_at IS NOT NULL`, even after the bounded Player Values receipt has been evicted. If operating a90-day cleanup for ordinary history, its predicate must include `value_granted_at IS NULL`. Additive startup migration preserves old counters/history and provisions the unique case-sensitive idempotency index and guard-retention index. The existing one-redemption-per-code/player self-hosted rule remains unchanged. Run `php tests/gift-code-values.php` for real SQLite migration, rollback, replay and concurrent-process checks; the same suite supports an explicitly named temporary MySQL test database through its guarded `HORIZON_GIFT_TEST_DSN` fixture variables.

### User Feedback

| Method | Endpoint | Description |
|---|---|---|
| POST | `/user-feedback/submit` | Submit user feedback |

### User Logs

| Method | Endpoint | Description |
|---|---|---|
| POST | `/user-logs/create` | Create a log entry (INFO, WARN, ERROR) |

### Crash Reporting

| Method | Endpoint | Description |
|---|---|---|
| POST | `/crash-reports/session` | Register an app session |
| POST | `/crash-reports/create` | Submit a crash report |

### Email Sending

| Method | Endpoint | Description |
|---|---|---|
| POST | `/email-sending/send` | Send or schedule an email to a user |
| DELETE | `/email-sending/{emailId}` | Cancel a pending email |
| GET | `/email-sending/{emailId}` | Get email status |
| POST | `/email-sending/ticker` | Process pending emails (cron endpoint) |

### Player Profile

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/player-profile?userId=` | Profile, unlocks and the cosmetics catalog with an `available` flag per entry |
| PUT | `/player-profile` | Replace the whole profile (`userId`, `avatarId`, `frameId`, `badges`) |

Both need `Authorization: Bearer <accessToken>` of `userId` (no transition window). See "Player Profile Setup" below.

## Player Profile Setup

Players pick an avatar, an optional frame and up to 3 badges. The server stores IDs only; your game maps them to its own art and treats unknown IDs as "not set". Same JSON shapes, caps and error codes as horizOn; simpleServer has one catalog per installation, no catalog size limit and no dashboard.

### 1. Fill the Cosmetics Catalog

IDs: 1 to 32 characters, `a-z`, `0-9`, `.`, `_`, `-`, starting with a letter or digit. `type` is `avatar`, `frame` or `badge`. `locked = 0` is free for every player, `locked = 1` needs an unlock.

```sql
INSERT INTO cosmetics (cosmetic_id, type, locked, created_at) VALUES
  ('avatar.zombie_07', 'avatar', 0, '2026-09-29T00:00:00'),
  ('frame.gold',       'frame',  1, '2026-09-29T00:00:00'),
  ('badge.supporter',  'badge',  1, '2026-09-29T00:00:00');
```

Deleting a row removes it from the catalog; players who show it keep the ID until they change their profile.

### 2. Unlock Cosmetics with a Gift Code

Put the IDs into a `grants` array of the gift code's `reward_data` (1 to 10 IDs). Other keys stay your game's reward.

```sql
INSERT INTO gift_codes (id, code, reward_type, reward_data, max_redemptions, current_redemptions, created_at)
VALUES ('6f1c0e9e-0000-4000-8000-000000000001', 'SUPPORTER', 'cosmetic',
        '{"gold": 500, "grants": ["badge.supporter"]}', 1000, 0, '2026-09-29T00:00:00');
```

Redeeming with a Bearer session merges the grants that exist in `cosmetics` into `users.unlocks` and returns them as `grantedUnlocks` (`[]` for every other redeem response). A code with grants redeemed without session returns `401 SESSION_REQUIRED` and is not used up. A result above 25 unlocks returns `409 UNLOCK_LIMIT_REACHED`; nothing is redeemed.

### 3. Unlock for a Single Player (Support Cases)

`users.unlocks` is a JSON array (at most 25 IDs, `NULL` when empty):

```sql
UPDATE users SET unlocks = '["badge.supporter", "frame.gold"]' WHERE id = 'PLAYER-UUID';
```

### Error Codes

Errors use the simpleServer body `{"error": true, "message": "...", "code": "..."}`:

| HTTP | Code | When |
|------|------|------|
| 400 | `INVALID_BADGES` | More than 3 badges, or a badge listed twice |
| 400 | `INVALID_COSMETIC_ID` | ID does not match the pattern |
| 400 | `COSMETIC_NOT_FOUND` | ID is not in `cosmetics` |
| 400 | `COSMETIC_TYPE_MISMATCH` | ID exists with another type than the slot |
| 401 | `SESSION_REQUIRED` | Missing, invalid or expired session |
| 403 | `COSMETIC_LOCKED` | Locked and not in the player's unlocks |
| 403 | `SESSION_FORBIDDEN` | Session of another player |
| 404 | `PLAYER_NOT_FOUND` | Unknown player |
| 409 | `UNLOCK_LIMIT_REACHED` | Gift code redeem would exceed 25 unlocks |

## Email Sending Setup

The Email Sending feature lets your app send template-based transactional emails to registered users via your own SMTP server. Templates and SMTP credentials are stored in the local database.

### 1. Configure SMTP Credentials

SMTP credentials are stored in the `remote_configs` table with the key `smtp_config`. Insert a JSON object with your SMTP server details:

`smtp_config` is reserved for server-internal use and is excluded from all Remote Config app responses.

```sql
-- SQLite
INSERT INTO remote_configs (config_key, config_value) VALUES ('smtp_config', '{
  "host": "smtp.example.com",
  "port": 587,
  "username": "your-smtp-user",
  "password": "your-smtp-password",
  "from_email": "noreply@example.com",
  "from_name": "My Game",
  "encryption": "tls"
}');
```

Supported `encryption` values: `tls` (port 587), `ssl` (port 465), or `none` (port 25).

### 2. Create Email Templates

Templates are stored in the `email_templates` table. Subject and body support multiple languages via JSON maps, and variables use `{{variableName}}` syntax:

```sql
INSERT INTO email_templates (id, slug, name, subject, body, variables) VALUES (
  'your-uuid-here',
  'welcome',
  'Welcome Email',
  '{"en": "Welcome, {{username}}!", "de": "Willkommen, {{username}}!"}',
  '{"en": "<h1>Welcome!</h1><p>Hello {{username}}, thanks for joining.</p>", "de": "<h1>Willkommen!</h1><p>Hallo {{username}}, danke fürs Mitmachen.</p>"}',
  '["username"]'
);
```

### 3. Add Email Addresses to Users

The `users` table has an `email` column (added automatically by migration). Update your users with their email addresses:

```sql
UPDATE users SET email = 'player@example.com' WHERE id = 'user-uuid';
```

### 4. Set Up Cron Job for Ticker

The ticker endpoint processes pending and scheduled emails. Set up a cron job to call it regularly (every 5 minutes recommended):

```bash
# Add to crontab (crontab -e)
*/5 * * * * curl -s -X POST -H "X-API-Key: YOUR_API_KEY" http://localhost:8080/api/v1/app/email-sending/ticker > /dev/null 2>&1
```

If you use `wget` instead:
```bash
*/5 * * * * wget -q --method=POST --header="X-API-Key: YOUR_API_KEY" -O /dev/null http://localhost:8080/api/v1/app/email-sending/ticker 2>&1
```

Without the cron job, emails will remain in `pending` status and never be sent.

## Apple Sign-In Self-Hosted Setup

Apple Sign-In lets your players log in with their Apple ID on iOS, macOS, and Web. The Simple Server validates Apple identity tokens locally against Apple's public keys — no extra backend service required.

Because Simple Server is **single-tenant**, Apple credentials live in your `.env` file rather than a database table. There is no admin dashboard — all configuration is file-based.

### 1. Enroll in the Apple Developer Program

Apple Sign-In requires a paid Apple Developer Program membership ($99/year). Sign up at [developer.apple.com/programs](https://developer.apple.com/programs/).

### 2. Create an App ID and/or Services ID

In the Apple Developer portal (Certificates, IDs & Profiles → Identifiers):

- **For native iOS apps:** create an App ID, note the **Bundle ID** (e.g. `com.yourcompany.yourapp`), and enable the "Sign In with Apple" capability.
- **For Web logins:** create a Services ID (e.g. `com.yourcompany.web`). This is the value Apple sends as the `aud` claim in JWTs issued from your web popup flow.
- **Team ID:** find your 10-character Team ID at the top of [Apple Developer Account Membership](https://developer.apple.com/account/#/membership/).

Apple's official docs: [Configuring Sign in with Apple](https://developer.apple.com/documentation/sign_in_with_apple/configuring_your_environment_for_sign_in_with_apple).

### 3. Verify Your Web Domain (Web Logins Only)

If you use Apple Sign-In on the web, Apple requires domain verification via a well-known file.

1. In the Services ID configuration, click "Configure" next to Sign In with Apple and add your domain and return URL.
2. Apple generates a verification file; place it on your server at:
   ```
   https://yourdomain.com/.well-known/apple-developer-domain-association.txt
   ```
3. Click "Verify" in the Apple portal.

Native-only integrations (iOS app without a web fallback) can skip this step.

### 4. Configure `.env`

Add the Apple section to your `.env` (or copy from `.env.example`):

```env
# Apple Sign-In
APPLE_SIGN_IN_ENABLED=true
APPLE_TEAM_ID=ABC1234567
# Services ID — used as the JWT `aud` for web logins
APPLE_SERVICE_ID=com.yourcompany.web
# Bundle ID — used as the JWT `aud` for native iOS logins
APPLE_BUNDLE_ID=com.yourcompany.yourapp
```

Both `APPLE_SERVICE_ID` and `APPLE_BUNDLE_ID` are optional individually, but at least one must be set when `APPLE_SIGN_IN_ENABLED=true`. Leave the one you don't use empty.

None of these three identifiers are secrets — they appear in plaintext inside every Apple identity token. No private key (`.p8`) is required for login; that is only needed for advanced features (token revocation, server-to-server notifications) which are not part of this server.

### 5. Restart and Clear Cache

```bash
# Restart the PHP worker / web server
# e.g. for php-fpm:
sudo systemctl restart php-fpm

# Clear the JWKS cache so the next login fetches fresh keys
rm -rf .cache/
```

The server automatically creates `.cache/apple-jwks.json` on first login and refreshes it every 24 hours.

### 6. Use Apple Sign-In from a Client

Two entry points are available:

**A. Public endpoint — `POST /api/v1/public/auth/apple`** (no API key)

Intended for dashboard-style logins. Send the Apple identity token obtained from the Apple JS popup:

```http
POST /api/v1/public/auth/apple
Content-Type: application/json

{
  "identityToken": "<Apple JWT>",
  "firstName": "Jane",
  "lastName": "Doe"
}
```

Response:

```json
{
  "accessToken": "<session token>",
  "user": {
    "id": "uuid",
    "email": "jane@privaterelay.appleid.com",
    "name": "Jane Doe",
    "appleUserId": "000123.abc...",
    "isPrivateRelayEmail": true,
    "isVerified": true
  },
  "authStatus": "AUTHENTICATED"
}
```

**B. SDK path — extended `/api/v1/app/user-management/signup` and `/signin`** (API key required)

When a request body includes `appleIdentityToken`, the server treats it as an Apple login and creates or signs in the user. Example:

```http
POST /api/v1/app/user-management/signup
X-API-Key: <your api key>
Content-Type: application/json

{
  "appleIdentityToken": "<Apple JWT>",
  "appleFirstName": "Jane",
  "appleLastName": "Doe"
}
```

Error responses use `authStatus` for symmetry with horizOn BaaS:

| `authStatus` | Meaning |
|---|---|
| `AUTHENTICATED` | Token valid, user logged in or created. |
| `INVALID_APPLE_TOKEN` | Signature invalid, issuer wrong, audience mismatch, or token expired. |
| `APPLE_NOT_CONFIGURED` | `APPLE_SIGN_IN_ENABLED=false` or no Services/Bundle ID set. |
| `APPLE_EMAIL_CONFLICT` | Apple returned a real (non-relay) email that belongs to a different user. |

### Troubleshooting

- **`INVALID_APPLE_TOKEN` right after setup:** double-check that `APPLE_SERVICE_ID` or `APPLE_BUNDLE_ID` exactly matches the `aud` claim Apple puts in the token. Decode the JWT at [jwt.io](https://jwt.io) to inspect.
- **JWKS cache issues after Apple key rotation:** delete `.cache/apple-jwks.json`; the next request refetches it.
- **Network restrictions:** the server needs outbound HTTPS to `appleid.apple.com` at least once every 24 hours. Stale cached keys are served as a fallback if the network is briefly unavailable.

## Deployment

### PHP Built-in Server (Development)

```bash
php -S localhost:8080 index.php
```

### Apache

Place the project in your web root (e.g., `/var/www/horizOn`). The included `.htaccess` file handles URL rewriting automatically. Make sure `mod_rewrite` is enabled:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

Your Apache virtual host should allow `.htaccess` overrides:

```apache
<Directory /var/www/horizOn>
    AllowOverride All
</Directory>
```

### Nginx

Copy the example configuration into your server block:

```bash
cp nginx.conf.example /etc/nginx/snippets/horizon.conf
```

Then include it in your server block:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/horizOn;
    index index.php;

    include snippets/horizon.conf;
}
```

Restart Nginx:

```bash
sudo systemctl restart nginx
```

## Database Setup

### SQLite (Default)

SQLite requires zero configuration. The database file is created automatically at the path specified by `DB_PATH` (default: `./data/horizon.db`). Make sure the `data/` directory is writable by the web server.

### MySQL

1. Create a database:
   ```sql
   CREATE DATABASE horizon CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Update `.env`:
   ```env
   DB_DRIVER=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=horizon
   DB_USER=your_user
   DB_PASS=your_password
   ```

3. The schema is applied automatically on first request using `migrations/mysql.sql`.

## Running Tests

An integration test script is included that starts a temporary PHP server, runs curl tests across the core endpoint groups, and reports results:

```bash
bash tests/test.sh
```

The test script:
- Creates a temporary `.env` with a test API key and SQLite database
- Starts a PHP built-in server on port 8765 (override with `HORIZON_TEST_PORT` if the port is occupied)
- Runs more than 100 assertions across the supported endpoint groups
- Cleans up all temporary files on exit

## Project Structure

```
horizOn-simpleServer/
├── index.php                 # Single entry point
├── .env.example              # Environment configuration template
├── .htaccess                 # Apache URL rewriting
├── nginx.conf.example        # Nginx configuration example
├── migrations/
│   ├── sqlite.sql            # SQLite schema
│   └── mysql.sql             # MySQL schema
├── src/
│   ├── Core/
│   │   ├── Auth.php          # API key and player session validation
│   │   ├── Config.php        # .env parser
│   │   ├── Database.php      # PDO abstraction + migrations
│   │   ├── RateLimit.php     # Per-IP rate limiting
│   │   ├── Request.php       # HTTP request wrapper
│   │   ├── Response.php      # JSON response helper
│   │   └── Router.php        # URL pattern matching
│   ├── CloudSave/
│   ├── CrashReporting/
│   ├── EmailSending/
│   ├── GiftCodes/
│   ├── Leaderboard/
│   ├── Localization/
│   ├── News/
│   ├── PlayerProfile/
│   ├── RemoteConfig/
│   ├── UserFeedback/
│   ├── UserLogs/
│   └── UserManagement/
├── tests/
│   └── test.sh               # Integration test script
└── data/                      # SQLite database (auto-created)
```

## Why This Exists

[horizOn](https://horizon.pm) is a managed game backend service. This Simple Server is the self-hosted, open-source alternative for developers who:

- Need a free backend for prototyping or small projects
- Want to own their infrastructure and data
- Are on shared PHP hosting and can't run Java/Docker
- Want a fallback if the managed service is unavailable

The Simple Server implements the same API as the managed service, so you can start here and migrate to horizOn later (or vice versa) without changing your game code.

## License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.

## Player Values (TASK-1004)

Server-owned integer balances and atomic catalogue purchases use the hosted SDK contract:

- `GET /api/v1/app/player-values?userId=UUID`
- `GET /api/v1/app/player-values/offers?userId=UUID`
- `POST /api/v1/app/player-values/purchase` with `userId`, `offerKey`, `operationId`
- `POST /api/v1/app/player-values/adjust` with `userId`, `key`, `amount`, `reason`, `operationId`

All four require the project `X-API-Key` and that player's active Bearer session. Amounts
are exact JSON integers within ±9,007,199,254,740,991. Direct adjustments default to disabled.
The server checks balance ceilings and daily earning/adjustment caps. Per-offer daily
purchase caps are removed. A non-null `purchasesPerDay` in new configuration is rejected.
An offer checks and debits all costs before crediting any reward, within one SQL transaction.
Failures retain all values and include the current state where available.

Create one operation ID as `unixMillis:UUID` for an action and retain it for retries. A retained
guard returns `operation.replayed=true`, empty `applied`/`items` and the current value state.
Reusing its ID for another payload returns
`OPERATION_CONFLICT`. Old IDs outside the safe replay window return `OPERATION_EXPIRED` and
must not be silently replaced with a new ID. Read `operationWindow.serverTime` and
`oldestAcceptedAt` to account for client clock skew. By default the last 50 guards are retained for up to
30 days. Their eviction advances a permanent timestamp floor so forgotten operations never
credit again. Reads never reveal another player's balances.

Administration is local to the installation. The public app API key provides no operator route:

```sh
php bin/player-values.php config
php bin/player-values.php configure 0 < player-values.json
php bin/player-values.php player PLAYER_UUID
php bin/player-values.php correct PLAYER_UUID < correction.json
```

`configure` requires the current revision (zero only before the initial configuration).
A configuration has `definitions` and `offers`, matching the hosted Dashboard JSON. Example:

```json
{"definitions":[{"key":"gold","name":"Gold","initialBalance":100,"maxBalance":10000},{"key":"gem","name":"Gem","maxBalance":1000}],"offers":[{"key":"gems","name":"Buy gems","costs":[{"key":"gold","amount":30}],"rewards":[{"key":"gem","amount":2}]}]}
```

A correction has `values: [{"key":"gold","balance":250}]`, a mandatory `note` and a stable
`operationId`. The note participates in conflict detection but is not retained as history. Removed
values retain their balances and initialization markers. Restoring a definition never repeats
its initial balance. The operator sets `PLAYER_VALUES_DEFINITIONS`, `PLAYER_VALUES_OFFERS`
and `PLAYER_VALUES_RECEIPTS` in `.env` (defaults 64, 1000, 50). Raising them works beyond the
defaults; lowering them preserves existing data on reads. The UInt8 slot format permits 256
lifetime value keys. Configuration remains bounded to 1 MiB. The `player` command returns
current balances and an empty compatibility `receipts` list.

Storage adds one bounded player row and separate configuration/offer tables. A normal write
reads the small definition set, one player row and optionally one offer, then writes the player
row. SQLite uses `BEGIN IMMEDIATE`; MySQL locks the target user row even before its first
balance exists. Each nonzero balance or daily counter occupies 9 raw bytes (one project slot,
eight integer bytes). Initialized zeros need only a bit in the at-most 32-byte bitmap. A public
operation guard holds a format byte, UUID 16 bytes, timestamp 8 bytes and SHA-256 32 bytes, exactly
57 raw bytes. SQL stores these binary fields as Base64 in a compact JSON envelope for both
SQLite and MySQL. Base64 and the envelope are included in measurements, not guessed as a
reserve. These SQL byte counts differ from hosted MongoDB BSON counts and exclude SQL
page/index overhead. No per-offer purchase map or copied applied rewards/notes are persisted.

Gift-code and Inbox sources use `grantFromSource` within their existing explicit
`SqlTransaction` and own the durable replay decision. This writes balances without another
player-values guard. A standalone call fails; failures roll back the complete source action.
The compatibility internal `grant` method retains a thin guard for callers without that contract.
Never catch a failed nested grant and commit its surrounding business operation.

Legacy rows remain readable. Normal state mutations encode them compactly without losing
balances, initialization or replay identities. To convert inactive rows, stop all writers,
take a backup and verify its restoration, then run the explicit bounded operator command:

```sh
php bin/player-values.php compact-inspect - 100
php bin/player-values.php compact-apply - 100 --writes-stopped --backup-verified
```

Repeat using the returned `after` cursor until `scanned` is 0. The two flags are operator
attestations, not an automatic backup verification. Each page is atomic, checks the original
row bytes, preserves public revisions and rejects unknown data without advancing. Reruns are
idempotent. There is no login scan or automatic background migration. Retain the original backup
while any older feature payload still needs source reconciliation.

Run `bash tests/test.sh` for HTTP, authentication, integer validation and SQLite races. The
storage fixture also accepts `HORIZON_VALUES_TEST_DSN`, `HORIZON_VALUES_TEST_USER` and
`HORIZON_VALUES_TEST_PASSWORD` for an isolated MySQL database named `task1004_<hex>`.
Never point it at application data. SQLite and actual MySQL 8.4 both pass the rollback,
replay, receipt eviction and independent-process concurrent-purchase fixture. The compact
fixture `tests/player-values-compact.php` also exercises raised/lowered quotas, source rollback,
exact storage lengths and migration rejection. Run each SQL fixture in a fresh isolated database.

## Player Statistics (TASK-1005)

Statistics use the same explicit app REST contract and SDK managers as Cloud:

| Method | Route | Input |
|---|---|---|
| GET | `/api/v1/app/statistics` | Query `userId`, optional `definitionVersion` |
| GET | `/api/v1/app/statistics/definitions` | Optional query `definitionVersion` |
| POST | `/api/v1/app/statistics` | `{userId,operationId,updates:[{key,value}]}` |

All three require `X-API-Key` plus an active player Bearer session. Player-specific calls require the matching `userId`. One whole session batch updates all counters in one SQL transaction. Values must be exact JSON integers in ±9,007,199,254,740,991; duplicate keys, unknown keys, bounds and overflow reject the whole batch. This feature requires 64-bit PHP7.4+. No statistics request runs automatically at sign-in.

A snapshot carries `userId`, `definitionVersion`, `definitions`, `revision`, `values:[{key,value,hasValue,period}]`, `operationWindow:{serverTimeUnixMillis,maxAgeSeconds,maxFutureSkewSeconds}`, `operationId`, `replayed` and reserved `newlyUnlocked:[]`. Definitions null means unchanged at the supplied version; retain the cached definitions. An empty catalog is `[]`. Missing counters show zero with `hasValue:false`. Min/max/last initialize from their first report. Daily resets use UTC midnight, weekly resets use ISO Monday; `period` is a date or ISO week.

Persist one `<13-digit Unix milliseconds>:<UUID>` ID and the original updates before sending. After an uncertain timeout read a snapshot and retry that exact intent. Retained same-ID/same-payload receipts replay first, even after policy changes or24hours. Changed payload gives `409 OPERATION_CONFLICT`. Eight receipts are retained; their permanent eviction timestamp floor rejects old or delayed out-of-order unknown IDs with `410 OPERATION_EXPIRED`, even before24hours. Unknown IDs older than24hours or more than30seconds in the future are rejected. Never generate a replacement ID automatically for an uncertain outcome. The operation window supplies server time for clock alignment.

Other errors use hosted codes: `400 INVALID_REQUEST`, `401 SESSION_REQUIRED`, `403 SESSION_FORBIDDEN`, `404 PLAYER_NOT_FOUND`, `409 STATE_CONFLICT`, and `422 UNKNOWN_STATISTIC`, `UPDATE_ABOVE_LIMIT`, `VALUE_OVERFLOW` or `OPERATION_IN_FUTURE`. HTTP429 retains the installation's existing rate-limit behavior. A stale clock cannot restore older daily/weekly data; reads, replays and writes fail closed while it is behind a stored bucket.

Configuration belongs to the local installation operator. The catalog stores at most100 definitions; Cloud's per-tier billing/admin interface is hosted. Use the CLI to derive monotone catalog versions and counter generations safely:

```sh
php bin/statistics.php show
php bin/statistics.php configure 0 < statistics.json
```

```json
{"definitions":[{"key":"kills","name":"Kills","mode":"sum","maxPerUpdate":1000},{"key":"best_score","name":"Best score","mode":"max"},{"key":"rounds_today","name":"Rounds today","mode":"sum","reset":"daily"}]}
```

`configure` requires the current `definitionVersion`, including zero initially. Keys use1..24 lowercase ASCII letters/digits/`._-`, beginning with a letter or digit; names use1..80UTF-8bytes. Modes are `sum|max|min|last`, reset is `none|daily|weekly`, and `maxPerUpdate` is an optional positive absolute update bound. Renames and bound edits preserve counters. Mode/reset changes or delete/recreate create a new generation. Avoid editing SQL catalog generation/version fields manually; the CLI updates them atomically. There is no app/admin HTTP configuration route.

Storage uses one small singleton catalog row and one `statistics_state` JSON row per player. Existing users are not scanned or rewritten at reset/configuration time. A normal write reads the user lock, catalog and one state row, then writes one state row; the shared Bearer check is one additional read. MySQL locks only the target user plus a shared catalog lock. SQLite uses one writer transaction. The common PDO owner can roll back the counter, revision, receipt and eviction floor together. Deleting a user cascades its own statistics row. No cloud save or profile blob is read.

`bash tests/test.sh` covers the HTTP surface. `php tests/statistics.php` covers real SQLite concurrency, receipt eviction, UTC/generation fencing, atomic overflow and outer-transaction rollback. MySQL probes require an explicit `HORIZON_STATISTICS_TEST_DSN` naming a dedicated `task1005_<hex>` database, plus `HORIZON_STATISTICS_TEST_USER`/`HORIZON_STATISTICS_TEST_PASSWORD`; they never fall back to a real installation. Realtime/chat keep their Cloud-only boundary.

## Inbox (Develop)

The authenticated app routes are `GET /api/v1/app/inbox?userId=UUID&since=CURSOR`,
`POST /api/v1/app/inbox/read`, `/inbox/claim` and `/inbox/remove`. Actions take
`{userId,entryId}` and require that player's active Bearer session plus the installation API
key. A snapshot includes an opaque cursor, revisions, current `serverTime`, per-type counters,
limits and `pollAfterSeconds:120`. A matching cursor returns `unchanged:true` with empty
entries: preserve the existing client list. A changed snapshot replaces it, including removals.
Expiry transitions change the cursor without database writes. Realtime remains Cloud-only.

Each player has at most 50 targeted messages. One installation-wide catalogue retains at most
500 broadcasts without fanout. Per-player interaction markers are bounded to 500; reward
markers remain pinned. Messages have a serialized 1024-byte UTF-8 budget, at most 8 distinct
positive safe-integer value rewards, and at most 30 days normal lifetime. Unclaimed rewards
become overdue but remain visible/claimable. They cannot be removed. Reward broadcasts stay
retained and cannot be deleted, even after individual players claim them; they consume capacity.
A full inbox rejects delivery so the source can remain pending. Unknown reward kinds/keys or
amounts above the configured maximum balance are rejected before accepting delivery.

Claims use the entry ID as permanent action identity. The claim guard and player-value ledger
share one explicitly connection-bound `SqlTransaction`, including SQLite 7.4's SQL
`BEGIN IMMEDIATE`/`COMMIT`/`ROLLBACK`. A failed grant rolls both effects back. A lost commit
acknowledgement returns 409 `STATE_CONFLICT`: retry the same entry ID. The marker prevents
another grant after value receipts expire or are evicted. Removing a claimed entry never
allows regrant. JSON strings, fractions and unsafe amounts are rejected.

Broadcast/support administration is local CLI only; the public app key cannot authorize it:

```bash
php bin/inbox.php broadcasts
php bin/inbox.php player PLAYER_UUID
php bin/inbox.php publish EXPECTED_REVISION < persisted-publication.json
php bin/inbox.php remove-broadcast EXPECTED_REVISION broadcast:UUID
```

A publication JSON contains `operationId` (`unixMillis:UUID`), `type`, `reference`, `textKey`,
`parameters`, `expiresAt` (ISO instant), and optional `attachment:{values:[{key,amount}]}`.
Use the catalogue's current server time to create the ID/expiry, persist the whole intent before
publishing, and retry it unchanged. A retained identical operation replays before stale revision,
capacity or expiry checks. Delivery/publication retain 50 receipts, a 30-day window and a permanent
eviction floor; unknown old/out-of-order operations fail closed. New anonymous/Apple accounts
record millisecond registration times, so accounts created after publication are ineligible even
within the same second. Existing installations retain their legacy UTC registration date.

Internal source owners use `InboxService::deliver(userId, content, operationId, issuedAtMillis,
source)` with their persisted intent. When source state changes too, construct both services with
the same `SqlTransaction` instance. `deliverPending(..., complete)` is reserved for durable
pending returns: its adapter receives the exact PDO and proposed entry ID and must conditionally
mark that specific source/recipient delivered. A false guard rolls back the inbox write. Only this
path can deliver an old valuable return below the usual receipt floor/window. Never invent a new
operation ID to bypass an uncertain result. No public HTTP delivery/grant endpoint exists.

The PHP implementation reads the complete bounded catalogue rather than a cache. Snapshot
feature I/O is three small/bounded reads (player, catalogue, state); a normal interaction adds one
state write. A normal reward claim additionally uses the existing player-values reads and one
ledger write in the same transaction. Shared API authentication and migration checks are separate.
SQL state is deleted with its owning user through foreign-key cascade. Run `php tests/inbox.php`
for actual storage/rollback/concurrent-claim/publication/receipt/retention proofs, and
`HORIZON_TEST_PORT=18765 bash tests/test.sh` for authenticated HTTP parity and CLI isolation.

## Achievements (TASK-1008)

Authenticated app REST routes are `GET /achievements?userId=&definitionVersion=`, `POST /achievements/unlock {userId,key}` and `POST /achievements/rewards/deliver {userId,keys:[...]}`. Each requires the matching player Bearer session. Opaque metadata versions include installation/player/catalogue/progress; locked hidden details remain absent until unlocked. A null definitions list keeps the matching client cache. GET never unlocks or delivers rewards. Realtime remains hosted-only.

The local operator uses `php bin/achievements.php definitions`, `configure REVISION < definitions.json` and `player UUID`. `ACHIEVEMENTS_DEFINITIONS` (default250) and `ACHIEVEMENTS_LIFETIME_KEYS` (default500) are operator-configurable positive integers. Raising them works beyond the defaults. Lowering them preserves existing reads, archived keys and stable slots, while preventing further growth above the new limits. Definition fields are `key,name,description,imageUrl,hidden,active,mode,condition,rewards`. Modes are `manual` and `statistic`, with `{statisticKey,threshold}` for the latter. Rewards contain up to eight unique positive value amounts in `{values:[{key,amount}]}`. Keys are24ASCII characters, name/description120/512UTF-8bytes, optional HTTP(S) URL2048UTF-16units and the whole catalogue1MiB. There is no Inbox-message size restriction on immediate achievement rewards.

Statistics, newly unlocked bits and all resulting value credits commit in one explicit connection-bound `SqlTransaction`. Failure rolls all of them back. Rewards are aggregated by value key and applied once without a duplicate Player Values receipt. An unlock stores no timestamp, pending payload or notification copy. The public progress remains compatible with `unlockedAt:null`, `rewardState:"complete"`, `inboxEntryId:null` and `pendingReward:null`. `/rewards/deliver` is a read-only compatibility status query. A manual retry checks the permanent bit before current definition policy. After an uncertain commit, retrying that original key cannot grant twice.

SQL progress is one row only for an interacting player. Its JSON is `{s:2,r:revision,c:catalogRevision,b:base64Bitmap}`. Stable lifetime catalogue slots require one bit each, with trailing zero bytes omitted. The bitmap is at most63raw bytes for500 lifetime keys, encoded as84Base64 characters, plus actual JSON fields/revision digits and the SQL row/key/index overhead. These are encoding sizes, not physical compressed SQL disk measurements. No Cloud Save data, login path or run-start path is copied/read. An unrelated statistics update avoids the achievement progress row. A relevant report reads the shared catalogue, one progress row and, only for rewards, the value configuration/state; it writes progress and values in the same transaction.

Historical progress remains readable without implicit conversion. New unlocks on legacy rows return `MIGRATION_REQUIRED`; pending compatibility status does the same. Stop old writers and verify a restorable backup before explicit cold conversion: `php bin/achievements.php compact-inspect AFTER_UUID_OR_DASH PAGE_SIZE`, then `compact-apply AFTER_UUID_OR_DASH PAGE_SIZE --writes-stopped --backup-verified`. Pages are1..50 and resumable. The flags attest operator preparation; they do not perform or verify a backup. Original pending rewards are credited at their frozen amounts. Delivered rewards require their original Inbox message and source identity, with contradictory old value guards blocking conversion. Claimed originals are never reissued. Unknown or missing evidence leaves original state intact. Repeated conversion is a no-op. Schema startup does not backfill historical rows.

Use `php tests/achievements.php`, `php tests/achievements-compact.php`, `php tests/achievements-migration.php` and the complete `tests/test.sh`. The dedicated MySQL probe requires an explicit loopback `HORIZON_ACHIEVEMENTS_TEST_DSN` with a fresh database named `task1008_<hex>`; it never discovers a database from an environment file. Admin configuration remains CLI-only and rejects direct HTTP before bootstrap. Hosted System Config has separate per-tier defaults.

## Friends (self-hosted REST)

`GET /api/v1/app/friends?userId=...`, exact `/friends/find?userId=...&query=...`,
and `POST /friends/{request,accept,decline,cancel,remove,block,unblock}` use the
matching player Bearer session. Every mutation requires an explicit nonnegative
safe-integer `expectedRevision`; decisions additionally require the exact current
`requestId`. A timeout/409 needs a fresh read and an explicit decision. Never
substitute a revision and silently repeat. Friend codes encode the public UUID;
they do not authorize a write or search by name/email.

Self-hosted limits are200 friends,20 incoming,20 outgoing and100 blocks. Both
relationship CAS writes and ordinary30-day Inbox notices commit in one explicit
connection-bound transaction. Full Inbox rolls everything back. Crossing requests
need explicit acceptance. Blocking clears both directions without notification;
unblocking never restores relationships. A shared `FriendsBlockPolicy` joins the
caller's PDO scope and reads both blocklist projections in one normal consistent
SELECT, preserving an already established MySQL snapshot. Relationship mutations
and exact lookups use sorted user-row locks to fence deletion and block changes;
SQLite uses the existing
BEGIN IMMEDIATE scope. No global transaction manager, online flag or heartbeat.

Public displays use one bounded Player Profile projection, without auth fields,
email, unlocks, values or saves. Since this edition stores the current session on
the user row, its actual anonymous/Apple sign-in update also stores the monotone
maximum `last_login_millis`. No extra sign-in query is added. Legacy rows remain
null/unknown; ordinary HTTP traffic never changes this timestamp. The additive
bootstrap checks both activity columns in the existing single metadata query.

Operator inspection is local CLI only:

```bash
php bin/friends.php player PLAYER_UUID
php bin/friends.php limits
php bin/friends.php cleanup-deleted
```

Public Account-Key/admin impersonation and realtime/Chat remain unsupported.
When an operator physically deletes a user, a nullable foreign key retains its
relationship row as an indexed deletion anchor, including block-only targets at
revision0. The related-player index finds arbitrarily many incoming blocks.
Schedule the local cleanup command to process at most10 deleted anchors with at
most50 referencing players each per invocation; repeat until complete. A deliberate
operator restore rebinds only its FK anchor and preserves all relationships/revisions,
so a restored first item cannot starve later deleted players. Each
changed peer gets a CAS revision, and the source anchor is deleted only once no
references remain. Active/restored users are never silently purged. Directly
removing relationship tables or disabling foreign keys bypasses these guarantees.
All SQL services participating in a larger transaction must receive the same
PDO and explicit `SqlTransaction` instance.

Validation: `bash tests/test.sh` includes real HTTP/session/error cases, isolated
SQLite concurrency, second-CAS/Inbox/outer rollback, uncertain commit, indexed
resumable cleanup and old-schema bootstrap. The same SQL probe supports only an
explicit loopback MySQL `task1009_<hex>` database via `HORIZON_FRIENDS_TEST_DSN`,
`HORIZON_FRIENDS_TEST_USER` and `HORIZON_FRIENDS_TEST_PASSWORD`; it never infers a
host or database from application credentials. Real MySQL/PHP7.4 execution is
recorded separately after its isolated probe.

Isolated SQL statement counts, excluding HTTP authentication/bootstrap and showing
BEGIN/COMMIT separately: an empty snapshot uses two SELECTs, a populated snapshot
three, an exact lookup three plus its transaction, a standalone bilateral block
policy one plus its transaction, and a reviewed request no-op four plus its
transaction with zero writes. A first request or acceptance uses six SELECTs and
seven bounded DML statements including both reverse-index replacements and the
Inbox. Existing/missing peers and empty reference sets can reduce the write count.
The visible-profile fanout stays one selected bulk query for at most240 peers;
reverse references are at most340 per player. These are service statement counts,
not hosted Signup-relative latency or capacity measurements.


## Groups (self-hosted REST)

The20 player endpoints match the hosted Groups contract. All require the API key
and the exact `userId` owner’s Bearer session:

| Method | Relative endpoint | Purpose |
|---|---|---|
| GET | `/groups?userId=...` | Own memberships and pending invitations/requests |
| POST | `/groups` | Create with explicit `expectedPlayerRevision`; fresh server-generated UUID |
| GET | `/groups/{groupId}?userId=...` | Public metadata; private data/role only for members |
| PUT | `/groups/{groupId}` | Replace metadata with explicit `expectedRevision` |
| GET | `/groups/{groupId}/members?userId=...&limit=50&afterUserId=...` | Member-only UUID keyset page, at most50 |
| GET | `/groups/{groupId}/pending?userId=...` | Officer/leader view of current pending decisions |
| POST | `/groups/{groupId}/{join,request,invite,accept-invite,decline-invite,cancel-invite,accept-request,decline-request,cancel-request,leave,remove,role,transfer,delete}` | Explicit membership/role action |

Mutation bodies carry `userId` and a nonnegative safe-integer revision. Targeted
actions also carry `targetUserId`; decisions carry the original `requestId` UUID.
Roles are `leader`, `officer`, `member`; join modes are `open`, `request`, `invite`.
A name is at most120UTF-8 bytes, description1024 and `data` is an object whose
serialized JSON is at most2048UTF-8 bytes. Empty objects and arrays remain distinct;
arbitrary object keys survive storage. Officers may update metadata and invite or
remove ordinary members; only the leader may assign roles or transfer leadership.
A nonsole leader must transfer before leaving. Group creation cannot accept a
client-chosen ID. A timeout requires reading current state and an explicit new
decision; never automatically substitute a revision or repeat a creation.

The standalone limits are500 members and5 memberships per player, with at most20
incoming invitations and20 outgoing requests per player, and20 of each pending
kind per group. These maxima are separate from hosted tariff limits. Above-limit
existing memberships can still leave or be removed. Acceptance rechecks both
membership limits, exact invitation identity, original issuer and the shared
Friends block policy. Changed player/group revisions, membership rows, reverse
pending references and ordinary30-day Inbox notices commit atomically using one
PDO and its explicit `SqlTransaction`; a full Inbox or a CAS conflict rolls back
the entire action. MySQL mutations compare the established snapshot revisions to
narrow current locks before changing them; stale outer snapshots fail with409.
No automatic retry, realtime transport, chat or group currency is provided here.

Operator access is local CLI only, rejected over HTTP before database bootstrap:

```bash
php bin/groups.php player PLAYER_UUID
php bin/groups.php group GROUP_UUID
php bin/groups.php members GROUP_UUID [AFTER_USER_UUID]
php bin/groups.php pending GROUP_UUID
php bin/groups.php limits
php bin/groups.php cleanup-deleted [AFTER_USER_UUID]
```

Physical user deletion retains an indexed nullable-FK player anchor. Cleanup handles
at most10 deleted anchors and10 related groups per anchor per invocation; each
group contains at most40 pending entries. It finds historical invitations through
a reverse index including original issuers, rather than scanning lifetime history.
Follow `nextAfterUserId` until null, then start another sweep if incomplete results
remain. This cursor prevents early blocked leader successions from starving later
deleted successors. The oldest remaining officer, otherwise oldest member, becomes
leader (joined timestamp and UUID tie-break). A deleted successor stays pending
until that player’s own cleanup removes it. Restore rebinds the nullable FK only;
normal leave/delete preserves the player revision, so uncertain old creates cannot
rebook. Never bypass these fences by disabling foreign keys or deleting state rows.

`bash tests/test.sh` covers all20 authenticated HTTP routes, CLI isolation, SQLite
races/rollback/uncertain commits and the legacy-schema bootstrap. The SQL probe
`php tests/groups.php` accepts MySQL only through an explicit loopback
`HORIZON_GROUPS_TEST_DSN` naming `task1010_<hex>`, plus
`HORIZON_GROUPS_TEST_USER` and `HORIZON_GROUPS_TEST_PASSWORD`; no application
credentials or host-default database are discovered. `php tests/groups-migration.php`
checks an actual pre-feature schema. Real MySQL and PHP7.4 results are recorded
separately after isolated probes.

Service SQL statement counts exclude HTTP authentication and bootstrap. On SQLite,
an empty snapshot uses3 SELECTs, a populated snapshot4, public detail3 and a
50-member page5; an open join uses9 SELECTs plus4 bounded DML statements. Each
scope additionally uses BEGIN/COMMIT. MySQL uses the same3/4/3/5 read counts
and11 SELECTs plus4 DML for an open join, including its current-revision locks.
The probe records SQL text; MySQL PDO transaction-method calls are outside its
BEGIN/COMMIT SQL counters. Member pages select at most51 rows to derive an explicit continuation;
player snapshots batch only their bounded group IDs. These are SQL request costs,
not measured hosted latency or a maximum-throughput guarantee.


## Items (self-hosted REST)

Items are unique server-owned instances, such as a sword with durability. Stackable
quantities remain Player Values, and display-only avatars/frames/badges remain
Player Profile. All five routes require the installation API key and the matching
player Bearer session:

| Method | Route | Input |
|---|---|---|
| GET | `/api/v1/app/items` | `userId` |
| GET | `/api/v1/app/items/catalog` | `userId`, optional `limit`1..50, `afterKey`, `catalogRevision` |
| POST | `/api/v1/app/items/adjust` | `userId,instanceId,expectedRevision,changes:[{key,amount}]` |
| POST | `/api/v1/app/items/equip` | `userId,instanceId,expectedRevision,equipped` |
| POST | `/api/v1/app/items/discard` | `userId,instanceId,expectedRevision` |

Inventory responses contain `userId,revision,instances,limits,serverTime`. Each
instance contains `instanceId,itemKey,properties,equipped,createdAt`. Mutations
replace the complete current snapshot. Keep the exact reviewed revision and item
ID; after an uncertain result, read and reconcile rather than automatically
substituting a new revision. Discard explicitly removes that unique instance.
GET does not create an empty inventory. Physical player deletion cascades its
inventory; same-PDO user locks and exact inventory CAS prevent orphan recreation.

Catalog pages contain `revision,definitions,nextAfterKey,total,limits,serverTime`.
Continue explicitly with the original catalog revision; a changed revision returns
409 instead of mixing pages. Inactive templates remain readable. Existing keys
stay reserved forever, including archived ones. Updating/reactivating an existing
key is permitted after downgrade, while over-cap additions fail. No pagination
loop, polling, realtime or public grant/admin endpoint is added.

Local operator commands reject HTTP before bootstrap:

```bash
php bin/items.php catalog [AFTER_KEY] [CATALOG_REVISION]
php bin/items.php put sword EXPECTED_REVISION < sword.json
php bin/items.php player PLAYER_UUID
php bin/items.php limits
```

A complete template body (excluding its key) is:

```json
{"name":"Sword","type":"weapon","rarity":"common","active":true,"baseProperties":{"durability":80},"propertyRules":[{"key":"durability","min":0,"max":100,"maxChangePerCall":5}]}
```

Keys/type/rarity/property names follow `[a-z][a-z0-9_]{0,63}`. Names occupy1..120
UTF-8 bytes. Properties are a flat JSON object with at most16 keys, safe integers,
booleans or strings, at most256 canonical UTF-8 bytes. Numbers cannot be quoted,
fractional/exponential or outside±9007199254740991. Duplicate decoded JSON keys,
invalid Unicode, null and nested property values are rejected. Canonical JSON
sorts ASCII object keys, writes exact base-ten integers and literal Unicode/slash,
uses short control escapes and lowercase\u00xx for remaining controls. The complete
canonical template occupies at most2048 bytes. The256-byte property limit is
checked after every delta, including digit growth. Item commands accept at most
64KiB of request text; the existing PV operator catalog keeps its1MiB bound.

At most16 unique rules name existing numeric base properties with safe integer
min/max and positive maxChangePerCall. One call changes at most8 distinct nonzero
numeric deltas. Current bounds apply to the exact result; a new template property
never silently appears on an old instance. Archived templates prevent new grants
and property changes, while equip/unequip/discard remain available.

One installation is one project. Operator variables `ITEMS_CATALOG` (default5000),
`ITEMS_INVENTORY` (150) and `ITEMS_LEGACY_RECEIPTS` (50) are adjustable above those
defaults. Existing items and catalogues remain readable after a reduction, while
new additions above the limit fail. The stable UInt16 template format has an actual
65536-lifetime-identity bound, reported explicitly. These defaults are not shared
hosted-cluster capacity or throughput measurements.

A Player Values offer gains `itemRewards:[{itemKey,quantity}]`, absent meaning `[]`.
Costs stay positive; at least one value reward or item reward is required. Unique
item keys have quantity1..4 and total at most4. Purchase uses its original
`unixMillis:UUID` operation ID. Debit, the source-owned value guard and item
grant share one explicit `SqlTransaction` and PDO; full/invalid inventory rolls
everything back. Value-only offers issue no item SQL. The first successful purchase
returns `items:[{instanceId,itemKey}]`. A replay returns empty `applied` and `items`,
checks the retained PV guard before current offers/catalog and never recreates an
already discarded item. Explicitly refresh inventory after confirmed purchase.

Storage keeps each immutable template basis once. An instance stores16 UUID bytes,
8 basis-version bytes,8 creation-time bytes,1 flags byte and1 delta-count byte.
Each actually changed numeric property adds its1-byte slot and8-byte integer.
The raw instance is34 bytes with no changes and52 bytes with two changes. SQL uses
Base64 within JSON, so those raw figures are not physical SQL disk usage. Public
responses still contain the complete original properties and creation date.
Template rules use25 bytes each before Base64. Bases, indexes and old versions are
shared project costs and must be counted separately.

Source-owned Player Values purchases keep no second item receipt. They require the
same explicit SQL transaction and durable source guard, including after a lost
response or discarded item. Marketplace escrow stores that same compact original
and moves basis reference counts with ownership in the transaction. Catalog edits
never replace an old item's original string/type/base state.

Existing full inventories stay readable. Changing them requires explicit cold
conversion. Stop **all writers** and verify a restorable backup first. These flags
record that preparation; they do not perform a backup or stop the app:

```bash
php bin/items.php compact-inspect templates - 20
php bin/items.php compact-apply templates - 20 --writes-stopped --backup-verified
php bin/items.php compact-apply inventory - 20 --writes-stopped --backup-verified
php bin/items.php compact-apply escrow - 20 --writes-stopped --backup-verified
php bin/items.php collect-basis ORIGINAL_VERSION --writes-stopped --backup-verified
```

Follow each returned cursor until `complete:true`. Conversion validates the original
schema, preserves old property values and receipts, and commits each owner atomically.
Unknown fields stop conversion unchanged. No login/startup migration scans run.
`collect-basis` is cold maintenance. It checks the current template and scans all
owners in50-row pages to prove the version unused before deleting it. This also
handles conservative counts left by an operator's direct SQL user deletion. It is
not a request-path cleanup or an inexpensive constant-time operation. Used versions
stay intact. Do not run it alongside writers.

The server-only ItemService grant provider hashes original source, timestamp and
key/quantity intent, not mutable template properties. Retained receipts precede
catalog/cap/age checks. New unknown operations older than30 days, at/below the
permanent eviction floor or more than30 seconds ahead are refused. Retention
stores bounded references rather than full snapshots. No client chooses a new
instance UUID or its initial properties.

`bash tests/test.sh` includes all five real HTTP routes, actual local CLI calls,
strict JSON/session/revision checks, SQLite process races/rollback/replay and a
real legacy bootstrap. `php tests/items.php` and `php tests/items-migration.php`
accept MySQL only via explicit loopback `HORIZON_ITEMS_TEST_DSN` with a
`task1011_<hex>` database and `HORIZON_ITEMS_TEST_USER/PASSWORD`. They never discover
application credentials or a default host database. Isolated MySQL/PHP7.4 evidence
is recorded separately from local PHP and CI.

Service SQL counts exclude authentication and bootstrap. Inventory GET uses2
SELECTs; a nonempty catalog page uses5 around at most51 indexed templates and a basis batch.
Adjust uses5 SELECTs plus1 inventory CAS, equip/discard3 plus1 CAS. A one-template
paid item purchase uses8 SELECTs and3 DML statements including the basis reference
count inside one transaction. Larger distinct basis sets hydrate in128-entry batches. Direct grant
uses2 plus at most4 template reads and1 inventory CAS. SQLite adds BEGIN/COMMIT;
MySQL PDO transaction-method calls are outside the SQL-text counters. These are
statement counts, not hosted latency or measured maximum capacity.

## Shared Objects (self-hosted REST)

A collection stores opaque bytes and separately indexed metadata. The server never
interprets the payload as game state. Every App route requires the installation
API key and the exact `userId` player's Bearer session:

| Method | Relative route | Input / concurrency |
|---|---|---|
| GET | `/shared-objects/collections` | `userId`, optional `afterKey,limit,catalogRevision` |
| GET | `/shared-objects/{collection}/usage` | `userId`; own retained usage revision |
| GET | `/shared-objects/{collection}/objects/{objectId}` | `userId` |
| POST | `/shared-objects/{collection}/query` | `userId,mode,limit` and one indexed selector |
| POST | `/shared-objects/{collection}/objects` | `userId,dataBase64,search,position,groupId`; `If-Match` usage revision |
| PUT | `/shared-objects/{collection}/objects/{objectId}` | `userId,dataBase64,search,position`; `If-Match` object revision |
| DELETE | `/shared-objects/{collection}/objects/{objectId}` | `userId`; `If-Match` object revision |

`If-Match` accepts one unquoted or quoted decimal safe integer, including0 for an
unused owner ledger. Wildcards and weak validators are rejected. Create returns a
fresh server UUID. Keep the original input and revision after a timeout; inspect
usage and objects before making an explicit new decision. Never silently replace
the revision or repeat a create. Update replaces bytes/search/position, preserves
owner/group and the original expiration, and increments both object and usage
revisions. Delete retains the owner revision even at count0, preventing old creates
from becoming valid again. Reads never create usage or quota documents.

The object DTO is `{id,collection,ownerId,groupId,revision,dataBase64,search,
position,createdAt,updatedAt,expiresAt}`. Data is canonical padded RFC4648 Base64,
including `""` for empty bytes; binary NUL and nontext bytes survive unchanged.
`search` is always an object, including `{}`; `position`, `groupId` and `expiresAt`
retain null when absent. Integers must use exact JSON integer tokens within
±9007199254740991. Strings/fractions/exponents are not coerced, and decoded duplicate
JSON keys and invalid Unicode are refused.

Read policies are `owner`, `friends`, `group`, `all`, `dashboard`; write policies
are `owner`, `group`, `dashboard`. Friends means a current bilateral relationship
with neither block direction, and grants reads only. Group access checks current
membership; row revision locks fence leave/rejoin and group deletion on writes.
Owners retain access to their own group objects after leaving and can remove them.
Dashboard-only collections reject App access. Expired objects and deleted owners
are hidden from App reads. Operator CLI inspection is separate from player rights.

Queries use exactly one authorized partition: all, one owner, or one group. Modes
are `list`, `owner` (explicit `ownerId`), `field` (`field` plus `equal`, or both
`min,max`), `rectangle` (`minX,maxX,minY,maxY`) and `random` (integer `field,min,max`).
A friends collection needs an explicit authorized `ownerId`; a group collection
needs `groupId`. There is no friend/group fanout, OR search, substring or offset
scan. Random uses at most two pivoted seeks and returns varied, nonuniform results;
it has no continuation cursor. Normal pages return at most50 objects and1MiB of
decoded payload, examining at most256 indexed metadata candidates. A filtered
page may be empty with a `nextCursor`; continue explicitly with the unchanged
query and limit. Cursors bind collection revision, query, actor and partition;
they never grant access. A refresh suggests `pollAfterSeconds:120`; no automatic
polling or page loop is provided. Query metadata and selected payloads share one
snapshot; blobs are fetched only for selected IDs, with revision/length checks.

Local operator equivalents reject HTTP before configuration/database access:

```bash
php bin/shared-objects.php collections [AFTER_KEY] [CATALOG_REVISION]
php bin/shared-objects.php put world EXPECTED_CATALOG_REVISION < world.json
php bin/shared-objects.php usage world PLAYER_UUID
php bin/shared-objects.php get world OBJECT_UUID
php bin/shared-objects.php query world < indexed-query.json
php bin/shared-objects.php create world EXPECTED_USAGE_REVISION < object.json
php bin/shared-objects.php update world OBJECT_UUID EXPECTED_OBJECT_REVISION < object.json
php bin/shared-objects.php delete world OBJECT_UUID EXPECTED_OBJECT_REVISION
php bin/shared-objects.php limits
php bin/shared-objects.php cleanup-expired
php bin/shared-objects.php cleanup-deleted [AFTER_USER_UUID]
```

Operator object creation uses `ownerId` instead of `userId`. Query bodies omit
`userId` and still specify the required owner/group partition. An example world
map definition is:

```json
{"name":"World map","active":true,"readPolicy":"all","writePolicy":"owner","maxObjectBytes":8192,"maxObjectsPerOwner":10,"ttlSeconds":null,"searchFields":[{"key":"strength","type":"integer"}],"positionEnabled":true}
```

First create `{userId,dataBase64,search:{strength:100},position:{x:4,y:8}}` with the
reviewed usage revision. A world view queries a bounded rectangle and explicitly
follows its cursor. Friends/private maps use the matching collection policy and
one explicit partition. Rendering and decoding the bytes belongs to the game.

One installation is one project: fixed self-hosted caps are100 lifetime collection
keys,2GiB charged storage and64KiB decoded data per object. A definition permits
1..100 objects per owner, at most3 typed search fields (`integer` or UTF-8 string
at most96bytes), optional integer position and null or60..2592000-second TTL.
Read/write policy, search schema, position and TTL become permanently immutable
after the first object, even after emptying the collection. Archive/name/size/count
changes remain available; no hard-delete/reuse of collection keys. Retained
objects and owner revisions survive lower limits. Charge is decoded bytes plus
8192bytes per object and512bytes per owner/collection ledger; this conservative
accounting includes metadata/index reserve, not a measured hosted capacity claim.

The additive migration creates six tables: catalog, definitions, account quota,
owner usage, object metadata and separate BLOB payloads. Quota, usage, permission
fences and both halves of each object commit on the same PDO and explicit
`SqlTransaction`. Failed joins to an external native transaction use a savepoint,
so catching a refusal cannot publish partial quota or bytes. Public MySQL reads
require their own repeatable-read snapshot and refuse opaque external transactions:
a session default cannot prove an individual transaction's isolation override.
SQLite reads use a consistent deferred snapshot. Physical user deletion retains
an indexed nullable-FK usage anchor and keeps bytes charged until cleanup refunds
them. Schedule operator cleanup: expiry removes at most20 objects per call;
deleted-user cleanup handles at most10 owner anchors, each bounded to20 objects
and20 empty usage ledgers. Follow `nextAfterUserId`, then repeat remaining work.
Never disable foreign keys or manually erase usage/quota anchors.

### Trusted asynchronous PvP notice recipe

A game backend can store a defender's opaque defense object, query opponents by
indexed strength, and retain the selected defense UUID/revision. Combat and any
validated run/result must be independently verified by a trusted backend. Hosted
Validated Actions remains Cloud-only; this PHP edition neither simulates combat
nor offers run-ticket validation or anti-cheat guarantees. A player-written object
or result cannot authorize a victory, grant, currency change or trusted notice.

After verification, the backend freezes a single ordinary notice intent:
`apiKeyId,userId,operationId,issuedAt,expiresAt,textKey,reference,parameters`, together
with `{defenseObjectId,defenseRevision,runId}`. Use the installation Inbox catalog's
`apiKeyId`, a fresh once-persisted UUID, original issued time and a deadline no more
than7days later. There are no attachments or rewards. The local operator command
`php bin/shared-objects.php notice < immutable-intent.json` uses the normal Inbox
receipt/floor path and never the pending-source bypass.

The executable backend-only example stages the complete intent in an absolute
path under an existing private directory before delivery:

```bash
php examples/shared_objects_notice.php stage /private/game-state/defense-notice.json < verified-intent.json
php examples/shared_objects_notice.php deliver /private/game-state/defense-notice.json
```

The stage input is `{intent:{...},binding:{defenseObjectId,defenseRevision,runId}}`.
Its0700 lock and0600 atomic state file preserve the exact UUID, timestamps, content
and binding across restart. An attempt is durable before delivery. Only the
committed-send adapter can mark `delivered`; an already active SQL transaction is
refused so a later outer rollback cannot lose a falsely completed notice. Lost
responses, full Inbox or unavailable delivery retain the original pending intent;
retry until its fixed deadline. Receipt expiry/conflict becomes terminal
`unconfirmed`; never generate a replacement UUID or extend the deadline. An
interrupted `.next` file, stale lock or corrupt state blocks sending and requires
operator reconciliation with the original backup, not silent deletion/recreation.
Once delivered/expired/unconfirmed, the recipe makes no additional send. This is
an ordinary localized game notice and does not prove the reported outcome.

`bash tests/test.sh` covers all7 actual HTTP routes, operator/recipe HTTP isolation,
SQLite process races, permissions, rollback, opaque binary limits, bounded pages,
legacy-schema migration and durable lost-response recovery. The production seek
SQL also has a10k-row VM-work negative control: SQLite uses a compound tuple bound;
MySQL continuation splits equal-sort/ID and strictly-later ranges with one shared
256-candidate budget. `php tests/shared-objects.php` and migration tests accept
MySQL only via explicit loopback `HORIZON_SHARED_OBJECTS_TEST_DSN` naming
`task1012_<hex>` plus `HORIZON_SHARED_OBJECTS_TEST_USER/PASSWORD`; they never discover
application credentials or a default database. Actual MySQL/PHP7.4 and CI results
are recorded separately from local execution.

Service SQL counts exclude HTTP authentication and bootstrap. SQLite point GET
and rectangle query use5 SELECTs; create uses6 SELECTs plus6 bounded DML; update
and delete6 plus4 DML. Each scope additionally begins/commits a transaction. Query
continuations on MySQL may use two bounded metadata SELECTs plus one selected
payload fetch. These are statement counts and scan-plan bounds, not measured
hosted latency or a maximum-throughput promise.

## Asynchronous matches

`/api/v1/app/matches` implements the ordinary match lifecycle with the project's
API key and an active Bearer session for the exact `userId`. The14 app paths are:

| Method | Path | Precondition/input |
|---|---|---|
| GET | `/matches?userId=UUID` | Bounded player ledger and summaries |
| GET | `/matches/limits?userId=UUID` | Limits, configuration, capabilities |
| GET | `/matches/open?userId=UUID&minStrength=-100&maxStrength=100` | Optional `limit`1..50 and original `cursor` |
| GET | `/matches/UUID?userId=UUID` | Participant state or invited-only lobby metadata |
| POST | `/matches` | `If-Match` player revision; full create input |
| POST | `/matches/UUID/join` | `If-Match` match revision; `{userId}` |
| POST | `/matches/UUID/decline` | Same |
| POST | `/matches/UUID/start` | Same plus `turnOrder` containing all joined players |
| POST | `/matches/UUID/turn` | Same plus `dataBase64,finish` |
| POST | `/matches/UUID/timeout` | Same; advances exactly one overdue turn |
| POST | `/matches/UUID/leave` | Same; departure/forfeit |
| POST | `/matches/UUID/cancel` | Same; creator's lobby only |
| POST | `/matches/UUID/result` | `{userId,finalStateRevision,resultBase64,winnerIds}` |
| POST | `/matches/UUID/ranked-run` | Explicit501 `FEATURE_NOT_SUPPORTED` |

Create requires exactly
`{userId,name,capacity,visibility,strength,turnSeconds,timeoutPolicy,ranked,dataBase64,inviteeIds}`.
Name is nonblank and at most120 UTF-8 bytes; capacity2..8, strength a signed safe
JSON integer, turnSeconds60..604800, visibility `open|private`, timeoutPolicy
`skip|forfeit`, at most7 unique other invitees and fewer invitees than capacity.
Canonical padded Base64 includes the empty string; its decoded bytes are opaque.
Quoted numeric strings, fractions/exponents, unsafe integers, duplicate decoded
JSON keys, noncanonical Base64 and extra write fields are rejected. `If-Match`
is a decimal safe revision, optionally quoted; wildcard/weak headers are invalid.

This PHP edition reports `matches:true,matchesRanked:false,realtime:false`.
`ranked:true` create and ranked-run return501 before mutation. It does not create
run tickets, simulate combat or offer an anti-cheat guarantee. The installation
has no hosted board catalogue; nonnull ranked-board configuration is likewise
unsupported. Existing ordinary leaderboard submission is a separate feature.

Persist every intended mutation's project/player identity, original input and
exact revision before HTTP. Send once. After an unknown outcome, inspect the
original match or player ledger and reconcile; never silently substitute a newer
revision, create another match or send a new turn. Host/session changes fence
requests and responses without replacing the unresolved project/player intent.
Reports are immutable: persist the original final revision, result bytes and
sorted unique winner IDs. Identical reports replay, changed reports conflict.
Every participant must report the same bytes and winners for `confirmed`;
disagreement becomes `disputed`, missing reports become `unconfirmed`. No result
or timeout grants currency, items, rewards or an inferred winner.

The response envelopes match the hosted ordinary contract, with explicit nulls
and `serverTime`. Point/actions return `{match,pollAfterSeconds,serverTime}`;
create also returns `playerRevision`, result also `replayed`. MyMatches returns
`{userId,revision,active,invites,recent,limits,pollAfterSeconds,serverTime}` and
search/list returns `{matches,nextCursor,pollAfterSeconds,serverTime}`. Summaries
contain no bytes, reports or run IDs. `recent` can contain an active match when
the current player already forfeited. Invited-only lobby viewers and successful
decline/pre-start leave acknowledgements receive `dataBase64:null,reports:[]`;
their subsequent point GET returns404. Polling is explicit, with a120-second hint.

One installation is one project. The default self-hosted bounds are10 active
matches,8192 decoded state bytes,100 retained match references and20 pending
invitations per player. Deployments embedding `MatchService` can pass lower
limits; `MatchRules::limits()` defines all five hosted tiers:

| Tier | Active | State bytes | Retained | Pending invites |
|---|---:|---:|---:|---:|
| FREE | 2 | 1024 | 20 | 20 |
| BASIC | 3 | 2048 | 30 | 20 |
| PLUS | 4 | 3072 | 40 | 20 |
| PRO | 5 | 4096 | 50 | 20 |
| ENTERPRISE | 10 | 8192 | 100 | 20 |

Invitations reserve no active/history slot until accepted. Create/join reserve
both slots atomically. Lower limits block new reservations without deleting
retained history or active games. Lobbies expire after24 hours; started matches
have a30-day absolute deadline, reports24 hours and terminal history7 days.
Reads do not advance timeouts or perform cleanup. Player revision anchors survive
ordinary leave/cancel and empty histories, preventing uncertain-create ABA.

Operator equivalents reject HTTP before configuration or database access:

```bash
php bin/matches.php config
php bin/matches.php configure EXPECTED_CONFIG_REVISION < disabled-ranked.json
php bin/matches.php limits
php bin/matches.php list [STATUS|- [LIMIT [CURSOR]]]
php bin/matches.php get MATCH_UUID
php bin/matches.php cancel MATCH_UUID EXPECTED_MATCH_REVISION
php bin/matches.php cleanup
php bin/matches.php cleanup-scheduled
php bin/matches.php cleanup-deleted [AFTER_USER_UUID]
```

Configuration input is `{ "rankedLeaderboardKey": null }`. Schedule cleanup:
each ordinary sweep examines at most20 due rows with a persistent fixed-cutoff
`dueAt,UUID` cursor. Full Inbox, contention and failed rows do not prevent later
rows from being visited. Operator and scheduled sweeps have separate cursors.
Deleted-user housekeeping handles one indexed nullable-FK player anchor and at
most20 of its120 bounded references per call; retain its continuation until
complete. Do not disable foreign keys or erase retained revision anchors manually.

Metadata, summary pages and BLOBs use separate storage. A point read checks the
payload revision and byte length in one snapshot; list/search never fetch blobs.
Every mutation joins one explicit connection-bound `SqlTransaction`. MySQL uses
current row locks plus CAS against the initial snapshot; a failed nested mutation
rolls back to a savepoint even if its caller catches the error. Public reads own
their consistent snapshot and reject opaque external MySQL transactions.
Normal turns/reports compute membership differences before reading player
ledgers, so unchanged peers cause zero history reads or writes. Open search
consumes at most256 summary candidates and returns at most50; current bilateral
blocks and active owners are checked in bounded projections. A filtered empty
page can carry a continuation. Cursors bind project, actor and strength bounds.
MySQL continuation splits same-strength/ID and later-strength ranges; SQLite uses
compound tuple seeks. Neither uses offsets or rereads earlier IDs.

Each new turn freezes an ordinary Inbox intent with its original operation ID,
revision, recipient and turn deadline. Gameplay commits first; delivery uses a
separate transaction. A conditional source consume and the Inbox write share
the exact PDO/scope. A full Inbox retains pending without failing the committed
turn, later turns supersede old notices and expired notices are never sent. This
designated source-pending path can survive unrelated receipt eviction. Lost
delivery acknowledgement cannot create a second entry. Notice bookkeeping does
not change gameplay revision and never authorizes a grant.

`bash tests/test.sh` exercises all14 actual HTTP paths and local operator access,
strict wire values, migration, lifecycle/replay/ACL, real independent SQLite
process races, rollback/lost commit acknowledgement and20+3 fair cleanup with a
genuinely full Inbox. `tests/matches-seek.py` measures the production SQLite SQL
through10k equal keys with a slow negative control. SQL tests accept MySQL only
through explicit loopback `HORIZON_MATCHES_TEST_DSN` naming `task1013_<hex>` and
`HORIZON_MATCHES_TEST_USER/PASSWORD`, never discovered application credentials.
The MySQL branch also measures actual10k-row `EXPLAIN ANALYZE` production seeks.
Actual PHP7.4/MySQL/CI outcomes are recorded separately from local execution.

Measured service statement counts exclude existing HTTP authentication, rate
limiting and schema/bootstrap. SQLite point GET is3 SELECT/0 DML, open search
4/0, create with one invite5/4, start including Inbox delivery8/3, an ordinary
turn including delivery6/4 and immutable report3/1. Writes also begin/commit each
scope; an Inbox handoff adds its separate scope. PDO transaction-method calls are
not SQL statement counts. Block checks are bounded by256 candidates with up to8
participants each, each existing block list by100 IDs. These are bounded
statement/row envelopes, not latency, maximum capacity or hosted throughput claims.
The actual MySQL8.4 profiles are point3 SELECT/0 DML, open search4/0, create with
one invite8/4, start including Inbox10/3, ordinary turn including Inbox8/4 and
report4/1. Read snapshots additionally set their next transaction's isolation;
row-lock/current-version checks explain the extra mutation reads. The10k-row
MySQL seek probes visit256 open candidates or20 due rows; a deliberately
non-indexable ID negative control, with index-condition pushdown disabled only
for its measurement, visits9257/9021 rows. Runtime/fixture limits are not changed
by this diagnostic. Both drivers retain the same hard candidate budgets.


## Chat is hosted-only (TASK-1014)

All six Chat app routes explicitly return HTTP 501 `NOT_SUPPORTED` after the usual
project API-key/rate checks and a matching active player Bearer session:
`GET /chat/limits`, `POST /chat/channels/open`, `POST /chat/channels/{id}/sync`,
`POST /chat/channels/{id}/messages`, `GET /chat/operations/{operationId}` and
`POST /chat/channels/{id}/messages/{messageId}/report` under `/api/v1/app`.
Missing or foreign player sessions still return 401 `SESSION_REQUIRED` or 403
`SESSION_FORBIDDEN`. An Account Key cannot replace the app key/player session.

The twelve hosted `/api/v1/admin/features/chat/{apiKeyId}` routes return the same
constant 501 refusal, including without credentials: they disclose no project,
channel, message or moderation state and perform no Chat operation. This edition
has no Account-Key moderation implementation. `php bin/chat.php` likewise writes
`NOT_SUPPORTED`/status 501 to stderr and exits 1 without loading configuration or
opening a database. Direct HTTP access to the CLI file is 403 with an empty body.

No chat tables, retained text, send/report receipts, moderation, subscriptions,
background tasks or polling fallback are provided. In particular `/chat/limits`
does not fabricate a supported capability envelope. Existing authenticated REST
features continue normally. The unsupported controller adds only one normal
player-session lookup for app requests and no feature SQL for either family;
existing bootstrap/migrations/API-key/rate middleware remain additional.
`bash tests/test.sh` includes the real six-route/auth/foreign-user and twelve-route
operator refusal checks on the suite-owned temporary SQLite installation.

### Marketplace: original items in escrow

Marketplace starts disabled and implements the ten `/api/v1/app/marketplace` routes with
SQLite/MySQL transactions and `realtime: false`. Use the same project key and active player
session as Items/Player Values. Read `/marketplace/limits` and paged `/marketplace/rules`
before confirming an original create request. Creation binds `expectedConfigRevision` and
`expectedInventoryRevision`; purchase binds the exact listing revision, price and currency.
Persist the original UUID, canonical UTC millisecond `issuedAt` and complete body before HTTP.
Send once, reconcile `/marketplace/operations/{operationId}`, and replay only that same intent.
An absent receipt or expired operation never authorizes a replacement ID.

Local operator equivalents live in `php bin/marketplace.php`:

- `config`, `configure REV < JSON`, `limits`, `rules [AFTER|- [LIMIT]]`, `rule ITEM_KEY REV < JSON`;
- `list [STATUS|- [LIMIT [CURSOR]]]`, `get LISTING_ID`, `cancel LISTING_ID REV < {"note":"..."}`;
- `retry-return LISTING_ID`, `recover LISTING_ID REV < {"recipientId":"UUID","note":"..."}`;
- `player USER_ID`, `moderation USER_ID`, `moderate USER_ID REV < {"banUntil":null,"reason":""}`, `cleanup`.

First create an Items template and explicitly save its Player Values currency definition.
Set a price rule (`active`, positive safe-integer `minPrice`/`maxPrice`), then enable the config
with `enabled`, `currencyKey` and integer `feeBps`0..10000. Currency follows the existing PV key
grammar, including `chest.gold`, `gold-coin` and `1coin`. The quoted currency/fee remain frozen
on each listing. Fees use exact integer floor arithmetic;100% fee deliberately credits zero.
The local installation defaults to Enterprise bounds:25 open escrows/player,10000 retained
listings,100 history references/player,120 accepted create/buy per
project minute and1000ms/player cooldown. `MarketRules::limits()` exposes all five nominal
profiles; a downgrade never destroys held items. Matching retained originals precede current
ban/config/rate limits. Each listing stores at most two compact operation entries: actor, UUID,
kind, issued time, SHA256 hex64 intent hash, committed revision and time. The existing receipt
response is derived from these entries and the listing's immutable item/price/fee fields.
Player state retains open/history IDs, cooldown, moderation and a permanent timestamp floor.
The `receiptsPerPlayer` response field remains for SDK compatibility and does not allocate a
personal receipt ring. Two SQL indexes find actor/UUID operations across all action kinds.
Unknown intents older than30days or at/below the floor are refused. Retained matches replay
first, including after their timestamp is below that floor.

A successful buy atomically transfers the unchanged instance ID, original properties and
creation time, debits the full buyer price and credits the strict seller net under current
balance/day caps. No clamping or deferred payout. Both active-user, template and saved currency
configuration fences participate in the same explicit PDO scope. Internal original-item and
settlement providers require that scope; no public grant or operator HTTP API is added.

Cancel/expiry retains the item in `return_pending`. A separate transaction hands an immutable
trusted item-return intent to Inbox; full Inbox stays pending. Unclaimed original-item returns
remain valuable and cannot expire or be removed. Inbox claim keeps the existing `playerValues`
snapshot and adds `items: [{instanceId,itemKey}]` (empty for value-only claims). Replay returns
original references without recreating a discarded/resold item. Public broadcasts/composition
cannot create this attachment. Deleted-owner escrow remains recoverable only through explicit
revision-bound operator recovery to an active player, with at most eight audit records.

Run `cleanup-scheduled` every30minutes in the host scheduler and `cleanup-deleted [AFTER_USER]`
for bounded identity cleanup. Each scheduled/admin cursor is durable and independent, examines
at most20 indexed rows, alternates return/terminal priority and advances past full Inbox or
failed rows. Valuable nonterminal escrow is never TTL-deleted. Terminal audits last7days.
Before physically deleting a terminal listing, the same transaction raises each existing operation
actor's permanent floor through its issued time while the intent is within30days.
Deleted identities and already-expired intents need no new personal state.
A later retry of an old create cannot reopen escrow after that source is gone. Existing old
personal receipts remain a read-only replay fallback until the next committed player write.
That write removes all old receipts and raises the floor through every old issued time. Startup
migration adds nullable SQL index projections without scanning or copying old receipt/escrow JSON.
Search returns at most50 offers from at most256 indexed candidates, including explicit empty
continuation pages; callers advance pages explicitly. No automatic polling or sale fanout.

Local SQL command profiles (excluding HTTP auth/bootstrap) are measured separately from Mongo
profiles and capacity: initial create10 SELECT/4 DML and buy15 SELECT/7 DML in the functional
fixture. `tests/marketplace.php` prints the measured counts. Sparse database/index allocation
is not a per-player capacity guarantee. Run `bash tests/test.sh`; dedicated tests also support
an explicit loopback `HORIZON_MARKET_TEST_DSN` whose database must match `task1015_<hex>`.
