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
- Cloud save data (up to 300KB per user)
- Remote configuration key-value store
- In-app news system with language filtering
- Gift code validation and redemption
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
| Leaderboard statistics & management | :x: | :white_check_mark: |
| **Cloud Saves** | | |
| Save & load | :white_check_mark: | :white_check_mark: |
| **Remote Config** | | |
| Key-value store | :white_check_mark: | :white_check_mark: |
| **News** | | |
| News with language filtering | :white_check_mark: | :white_check_mark: |
| LLM-powered auto-translation (15 languages) | :x: | :white_check_mark: |
| **Gift Codes** | | |
| Validate & redeem | :white_check_mark: | :white_check_mark: |
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
| GET | `/leaderboard/top` | Get top leaderboard entries |
| GET | `/leaderboard/rank` | Get a user's rank |
| GET | `/leaderboard/around` | Get entries around a user's position |

### Cloud Save

| Method | Endpoint | Description |
|---|---|---|
| POST | `/cloud-save/save` | Save user data (max 300KB) |
| POST | `/cloud-save/load` | Load user data |

### Remote Config

| Method | Endpoint | Description |
|---|---|---|
| GET | `/remote-config/all` | Get all configuration key-value pairs |
| GET | `/remote-config/{key}` | Get a single configuration value |

### News

| Method | Endpoint | Description |
|---|---|---|
| GET | `/news` | List news articles (supports `limit` and `languageCode` query params) |

### Gift Codes

| Method | Endpoint | Description |
|---|---|---|
| POST | `/gift-codes/validate` | Check if a gift code is valid |
| POST | `/gift-codes/redeem` | Redeem a gift code |

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

## Email Sending Setup

The Email Sending feature lets your app send template-based transactional emails to registered users via your own SMTP server. Templates and SMTP credentials are stored in the local database.

### 1. Configure SMTP Credentials

SMTP credentials are stored in the `remote_configs` table with the key `smtp_config`. Insert a JSON object with your SMTP server details:

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

An integration test script is included that starts a temporary PHP server, runs curl tests against every endpoint, and reports results:

```bash
bash tests/test.sh
```

The test script:
- Creates a temporary `.env` with a test API key and SQLite database
- Starts a PHP built-in server on port 8765
- Runs 60 tests covering all endpoints
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
│   │   ├── Auth.php          # API key validation
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
│   ├── News/
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
