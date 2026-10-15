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
The server checks balance ceilings, daily earning/adjustment caps and offer purchase caps.
An offer checks and debits all costs before crediting any reward, within one SQL transaction.
Failures retain all values and include the current state where available.

Create one operation ID as `unixMillis:UUID` for an action and retain it for retries. A retained
receipt returns `operation.replayed=true`. Reusing its ID for another payload returns
`OPERATION_CONFLICT`. Old IDs outside the safe replay window return `OPERATION_EXPIRED` and
must not be silently replaced with a new ID. Read `operationWindow.serverTime` and
`oldestAcceptedAt` to account for client clock skew. The last 50 receipts are retained for up to
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
`operationId`. It records the operator source, note and original applied delta. Removed
values retain their balances and initialization markers. Restoring a definition never repeats
its initial balance. Limits are 64 definitions, 1000 offers, 256 distinct lifetime value keys,
1000 distinct purchased offer keys per UTC day, 1 MiB of configuration and 50 player receipts.
The operator can inspect preserved values before editing storage directly.

Storage adds one bounded player row and separate configuration/offer tables. A normal write
reads the small definition set, one player row and optionally one offer, then writes the player
row. SQLite uses `BEGIN IMMEDIATE`; MySQL locks the target user row even before its first
balance exists. An internal `PlayerValuesService::grant` reuses an outer transaction for a
future inbox/gift-code operation. Its caller must propagate failures and roll back that entire
transaction. Do not catch a failed grant and commit its surrounding business operation.

Run `bash tests/test.sh` for HTTP, authentication, integer validation and SQLite races. The
storage fixture also accepts `HORIZON_VALUES_TEST_DSN`, `HORIZON_VALUES_TEST_USER` and
`HORIZON_VALUES_TEST_PASSWORD` for an isolated MySQL database named `task1004_<hex>`.
Never point it at application data. SQLite and actual MySQL 8.4 both pass the rollback,
replay, receipt eviction and independent-process concurrent-purchase fixture.

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
