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
- Global leaderboards (submit, top, rank, around)
- Cloud save data (up to 300KB per user)
- Remote configuration key-value store
- In-app news system with language filtering
- Gift code validation and redemption
- User feedback collection
- User log ingestion (INFO, WARN, ERROR)
- Crash reporting with automatic grouping, fingerprinting, and regression detection
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

## API Endpoints

All endpoints are prefixed with `/api/v1/app`. Except for `/health`, all endpoints require the `X-API-Key` header.

### Health

| Method | Endpoint | Description |
|---|---|---|
| GET | `/health` | Health check (no auth required) |

### User Management

| Method | Endpoint | Description |
|---|---|---|
| POST | `/user-management/signup` | Create a new anonymous user |
| POST | `/user-management/signin` | Sign in with anonymous token |
| POST | `/user-management/check-auth` | Verify session validity |

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
