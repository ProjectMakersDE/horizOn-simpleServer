# horizOn Simple Server Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a lightweight, self-hostable PHP server as an open-source drop-in replacement for the horizOn backend.

**Architecture:** Single `index.php` entry point with feature-organized controllers. PDO-based database abstraction supporting SQLite and MySQL. No Composer, no external dependencies. All config via `.env` file.

**Tech Stack:** PHP 7.4+, PDO (SQLite/MySQL), Apache/Nginx

**Design Doc:** `docs/plans/2026-02-22-simple-server-design.md`

---

### Task 1: Config - .env Parser

**Files:**
- Create: `src/Core/Config.php`
- Create: `.env.example`

**Step 1: Create the .env.example file**

```env
# horizOn Simple Server Configuration

# API Key - clients must send this in X-API-Key header
API_KEY=change-me-to-a-secure-key

# Database Driver: sqlite or mysql
DB_DRIVER=sqlite

# SQLite settings (only used when DB_DRIVER=sqlite)
DB_PATH=./data/horizon.db

# MySQL settings (only used when DB_DRIVER=mysql)
DB_HOST=localhost
DB_PORT=3306
DB_NAME=horizon
DB_USER=root
DB_PASS=

# Rate Limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_PER_SECOND=10
```

**Step 2: Create Config.php**

```php
<?php

declare(strict_types=1);

class Config
{
    private static ?array $values = null;

    public static function load(string $envPath): void
    {
        if (!file_exists($envPath)) {
            throw new RuntimeException("Configuration file not found: {$envPath}");
        }

        self::$values = [];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Remove surrounding quotes
            if (strlen($value) >= 2) {
                if (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        if (self::$values === null) {
            throw new RuntimeException('Config not loaded. Call Config::load() first.');
        }
        return self::$values[$key] ?? $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? 'true' : 'false');
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key, (string)$default);
        return (int)$value;
    }
}
```

**Step 3: Verify syntax**

Run: `php -l src/Core/Config.php`
Expected: `No syntax errors detected`

**Step 4: Commit**

```bash
git add .env.example src/Core/Config.php
git commit -m "feat: add .env config parser"
```

---

### Task 2: Request and Response Objects

**Files:**
- Create: `src/Core/Request.php`
- Create: `src/Core/Response.php`

**Step 1: Create Request.php**

```php
<?php

declare(strict_types=1);

class Request
{
    private string $method;
    private string $path;
    private array $query;
    private array $headers;
    private ?array $body;
    private array $params = [];

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Parse path from REQUEST_URI, strip query string
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        $this->path = $pos !== false ? substr($uri, 0, $pos) : $uri;

        // Remove trailing slash (except root)
        if ($this->path !== '/' && substr($this->path, -1) === '/') {
            $this->path = rtrim($this->path, '/');
        }

        $this->query = $_GET;

        // Parse headers
        $this->headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace('_', '-', strtolower(substr($key, 5)));
                $this->headers[$header] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $this->headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        // Parse JSON body
        $this->body = null;
        $rawBody = file_get_contents('php://input');
        if ($rawBody !== '' && $rawBody !== false) {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->body = $decoded;
            }
        }
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    public function queryInt(string $key, int $default = 0): int
    {
        return isset($this->query[$key]) ? (int)$this->query[$key] : $default;
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function body(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function param(string $key): ?string
    {
        return $this->params[$key] ?? null;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function clientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '127.0.0.1';
    }
}
```

**Step 2: Create Response.php**

```php
<?php

declare(strict_types=1);

class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, string $code, int $status = 400): void
    {
        self::json([
            'error' => true,
            'message' => $message,
            'code' => $code,
        ], $status);
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::error($message, 'NOT_FOUND', 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): void
    {
        self::error($message, 'UNAUTHORIZED', 401);
    }

    public static function tooManyRequests(string $message = 'Rate limit exceeded'): void
    {
        self::error($message, 'RATE_LIMITED', 429);
    }

    public static function badRequest(string $message): void
    {
        self::error($message, 'BAD_REQUEST', 400);
    }

    public static function serverError(string $message = 'Internal server error'): void
    {
        self::error($message, 'INTERNAL_ERROR', 500);
    }

    public static function created($data): void
    {
        self::json($data, 201);
    }
}
```

**Step 3: Verify syntax**

Run: `php -l src/Core/Request.php && php -l src/Core/Response.php`
Expected: `No syntax errors detected` for both

**Step 4: Commit**

```bash
git add src/Core/Request.php src/Core/Response.php
git commit -m "feat: add Request and Response core classes"
```

---

### Task 3: Database Abstraction

**Files:**
- Create: `src/Core/Database.php`
- Create: `migrations/sqlite.sql`
- Create: `migrations/mysql.sql`

**Step 1: Create the SQLite migration**

Create `migrations/sqlite.sql` with all table definitions using SQLite-compatible syntax. Use `TEXT` for UUIDs (SQLite has no UUID type). Use `INTEGER` for booleans. Include all 12 tables from the design doc.

Key SQLite notes:
- No `ENUM` type - use `TEXT` with CHECK constraints or just `TEXT`
- `BOOLEAN` is `INTEGER` (0/1)
- `DATETIME` is `TEXT` (ISO-8601 strings)
- Auto-increment uses `INTEGER PRIMARY KEY AUTOINCREMENT` but we use UUID strings so just `TEXT PRIMARY KEY`

```sql
-- horizOn Simple Server - SQLite Schema

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    anonymous_token TEXT UNIQUE NOT NULL,
    session_token TEXT,
    session_expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS leaderboard (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL UNIQUE,
    score INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS cloud_saves (
    user_id TEXT PRIMARY KEY,
    data TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS remote_configs (
    config_key TEXT PRIMARY KEY,
    config_value TEXT
);

CREATE TABLE IF NOT EXISTS news (
    id TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    language_code TEXT NOT NULL DEFAULT 'en',
    published_at TEXT NOT NULL DEFAULT (datetime('now')),
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS gift_codes (
    id TEXT PRIMARY KEY,
    code TEXT UNIQUE NOT NULL,
    reward_type TEXT NOT NULL DEFAULT '',
    reward_data TEXT,
    max_redemptions INTEGER NOT NULL DEFAULT 1,
    current_redemptions INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS gift_code_redemptions (
    id TEXT PRIMARY KEY,
    gift_code_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    redeemed_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (gift_code_id) REFERENCES gift_codes(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE(gift_code_id, user_id)
);

CREATE TABLE IF NOT EXISTS user_feedback (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    category TEXT,
    email TEXT,
    device_info TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS user_logs (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    message TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'INFO',
    error_code TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS crash_reports (
    id TEXT PRIMARY KEY,
    type TEXT NOT NULL,
    message TEXT NOT NULL,
    stack_trace TEXT,
    fingerprint TEXT NOT NULL,
    app_version TEXT NOT NULL,
    sdk_version TEXT NOT NULL,
    platform TEXT NOT NULL,
    os TEXT NOT NULL,
    device_model TEXT NOT NULL,
    device_memory_mb INTEGER NOT NULL DEFAULT 0,
    session_id TEXT NOT NULL,
    user_id TEXT,
    breadcrumbs TEXT,
    custom_keys TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_crash_reports_fingerprint ON crash_reports(fingerprint);

CREATE TABLE IF NOT EXISTS crash_groups (
    id TEXT PRIMARY KEY,
    fingerprint TEXT UNIQUE NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'OPEN',
    type TEXT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    occurrence_count INTEGER NOT NULL DEFAULT 0,
    affected_user_count INTEGER NOT NULL DEFAULT 0,
    affected_versions TEXT NOT NULL DEFAULT '[]',
    latest_stack_trace TEXT,
    platform TEXT,
    notes TEXT,
    resolved_at TEXT,
    resolved_in_version TEXT
);

CREATE TABLE IF NOT EXISTS crash_sessions (
    id TEXT PRIMARY KEY,
    session_id TEXT UNIQUE NOT NULL,
    user_id TEXT,
    app_version TEXT NOT NULL,
    platform TEXT NOT NULL,
    has_crash INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_address TEXT PRIMARY KEY,
    request_count INTEGER NOT NULL DEFAULT 1,
    window_start INTEGER NOT NULL
);
```

**Step 2: Create the MySQL migration**

Create `migrations/mysql.sql` with MySQL-compatible syntax. Uses `CHAR(36)` for UUIDs, `TINYINT(1)` for booleans, `DATETIME` natively, `LONGTEXT` for large text fields.

```sql
-- horizOn Simple Server - MySQL Schema

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    display_name VARCHAR(30) NOT NULL,
    anonymous_token VARCHAR(32) UNIQUE NOT NULL,
    session_token VARCHAR(256),
    session_expires_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leaderboard (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL UNIQUE,
    score BIGINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_saves (
    user_id CHAR(36) PRIMARY KEY,
    data LONGTEXT,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS remote_configs (
    config_key VARCHAR(256) PRIMARY KEY,
    config_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS news (
    id CHAR(36) PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    language_code CHAR(2) NOT NULL DEFAULT 'en',
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_codes (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    reward_type VARCHAR(50) NOT NULL DEFAULT '',
    reward_data TEXT,
    max_redemptions INT NOT NULL DEFAULT 1,
    current_redemptions INT NOT NULL DEFAULT 0,
    expires_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_code_redemptions (
    id CHAR(36) PRIMARY KEY,
    gift_code_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gift_code_id) REFERENCES gift_codes(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_redemption (gift_code_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_feedback (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    category VARCHAR(50),
    email VARCHAR(254),
    device_info VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_logs (
    id CHAR(36) PRIMARY KEY,
    user_id CHAR(36) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    type VARCHAR(5) NOT NULL DEFAULT 'INFO',
    error_code VARCHAR(50),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crash_reports (
    id CHAR(36) PRIMARY KEY,
    type VARCHAR(10) NOT NULL,
    message TEXT NOT NULL,
    stack_trace TEXT,
    fingerprint VARCHAR(128) NOT NULL,
    app_version VARCHAR(50) NOT NULL,
    sdk_version VARCHAR(50) NOT NULL,
    platform VARCHAR(50) NOT NULL,
    os VARCHAR(100) NOT NULL,
    device_model VARCHAR(100) NOT NULL,
    device_memory_mb INT NOT NULL DEFAULT 0,
    session_id VARCHAR(100) NOT NULL,
    user_id VARCHAR(36),
    breadcrumbs TEXT,
    custom_keys TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_crash_reports_fingerprint (fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crash_groups (
    id CHAR(36) PRIMARY KEY,
    fingerprint VARCHAR(128) UNIQUE NOT NULL,
    title VARCHAR(200) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'OPEN',
    type VARCHAR(10) NOT NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    occurrence_count INT NOT NULL DEFAULT 0,
    affected_user_count INT NOT NULL DEFAULT 0,
    affected_versions TEXT NOT NULL,
    latest_stack_trace TEXT,
    platform VARCHAR(50),
    notes TEXT,
    resolved_at DATETIME,
    resolved_in_version VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crash_sessions (
    id CHAR(36) PRIMARY KEY,
    session_id VARCHAR(100) UNIQUE NOT NULL,
    user_id VARCHAR(36),
    app_version VARCHAR(50) NOT NULL,
    platform VARCHAR(50) NOT NULL,
    has_crash TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rate_limits (
    ip_address VARCHAR(45) PRIMARY KEY,
    request_count INT NOT NULL DEFAULT 1,
    window_start INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Step 3: Create Database.php**

```php
<?php

declare(strict_types=1);

class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'sqlite') {
            $dbPath = Config::get('DB_PATH', './data/horizon.db');
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            self::$pdo = new PDO("sqlite:{$dbPath}");
            self::$pdo->exec('PRAGMA journal_mode=WAL');
            self::$pdo->exec('PRAGMA foreign_keys=ON');
        } elseif ($driver === 'mysql') {
            $host = Config::get('DB_HOST', 'localhost');
            $port = Config::get('DB_PORT', '3306');
            $name = Config::get('DB_NAME', 'horizon');
            $user = Config::get('DB_USER', 'root');
            $pass = Config::get('DB_PASS', '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $user, $pass);
        } else {
            throw new RuntimeException("Unsupported DB_DRIVER: {$driver}");
        }

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return self::$pdo;
    }

    public static function migrate(): void
    {
        $pdo = self::connect();
        $driver = Config::get('DB_DRIVER', 'sqlite');
        $file = __DIR__ . "/../../migrations/{$driver}.sql";

        if (!file_exists($file)) {
            throw new RuntimeException("Migration file not found: {$file}");
        }

        $sql = file_get_contents($file);
        $pdo->exec($sql);
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s');
    }
}
```

**Step 4: Verify syntax**

Run: `php -l src/Core/Database.php && php -l migrations/sqlite.sql && php -l migrations/mysql.sql`
Expected: No syntax errors for PHP. SQL files may show parse warnings (expected, they're not PHP).

Actually just check the PHP file:
Run: `php -l src/Core/Database.php`
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add src/Core/Database.php migrations/
git commit -m "feat: add database abstraction with SQLite/MySQL migrations"
```

---

### Task 4: Router

**Files:**
- Create: `src/Core/Router.php`

**Step 1: Create Router.php**

```php
<?php

declare(strict_types=1);

class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->routes[] = ['PUT', $pattern, $handler];
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->routes[] = ['DELETE', $pattern, $handler];
    }

    public function dispatch(Request $request): void
    {
        // Handle CORS preflight
        if ($request->method() === 'OPTIONS') {
            http_response_code(204);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
            header('Access-Control-Max-Age: 86400');
            exit;
        }

        foreach ($this->routes as [$method, $pattern, $handler]) {
            if ($method !== $request->method()) {
                continue;
            }

            $params = $this->match($pattern, $request->path());
            if ($params !== null) {
                $request->setParams($params);
                $handler($request);
                return;
            }
        }

        Response::notFound('Endpoint not found');
    }

    private function match(string $pattern, string $path): ?array
    {
        // Convert pattern like /api/v1/app/remote-config/{key} to regex
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $path, $matches)) {
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return null;
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/Core/Router.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/Core/Router.php
git commit -m "feat: add URL pattern-matching router with CORS support"
```

---

### Task 5: Auth and Rate Limiting

**Files:**
- Create: `src/Core/Auth.php`
- Create: `src/Core/RateLimit.php`

**Step 1: Create Auth.php**

```php
<?php

declare(strict_types=1);

class Auth
{
    public static function validateApiKey(Request $request): void
    {
        $expected = Config::get('API_KEY');
        if ($expected === '' || $expected === 'change-me-to-a-secure-key') {
            Response::serverError('API_KEY not configured. Please set a secure API key in .env');
        }

        $provided = $request->header('x-api-key');
        if ($provided === null || $provided === '') {
            Response::unauthorized('Missing X-API-Key header');
        }

        if (!hash_equals($expected, $provided)) {
            Response::unauthorized('Invalid API key');
        }
    }
}
```

**Step 2: Create RateLimit.php**

```php
<?php

declare(strict_types=1);

class RateLimit
{
    public static function check(Request $request): void
    {
        if (!Config::getBool('RATE_LIMIT_ENABLED', true)) {
            return;
        }

        $maxPerSecond = Config::getInt('RATE_LIMIT_PER_SECOND', 10);
        $ip = $request->clientIp();
        $now = time();
        $pdo = Database::connect();

        // Get current rate limit entry
        $stmt = $pdo->prepare('SELECT request_count, window_start FROM rate_limits WHERE ip_address = ?');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();

        if ($row === false) {
            // First request from this IP
            $stmt = $pdo->prepare('INSERT INTO rate_limits (ip_address, request_count, window_start) VALUES (?, 1, ?)');
            $stmt->execute([$ip, $now]);
            return;
        }

        if ($row['window_start'] < $now) {
            // Window expired, reset
            $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = 1, window_start = ? WHERE ip_address = ?');
            $stmt->execute([$now, $ip]);
            return;
        }

        if ($row['request_count'] >= $maxPerSecond) {
            header('Retry-After: 1');
            Response::tooManyRequests('Rate limit exceeded. Max ' . $maxPerSecond . ' requests per second.');
        }

        // Increment counter
        $stmt = $pdo->prepare('UPDATE rate_limits SET request_count = request_count + 1 WHERE ip_address = ?');
        $stmt->execute([$ip]);
    }
}
```

**Step 3: Verify syntax**

Run: `php -l src/Core/Auth.php && php -l src/Core/RateLimit.php`
Expected: `No syntax errors detected` for both

**Step 4: Commit**

```bash
git add src/Core/Auth.php src/Core/RateLimit.php
git commit -m "feat: add API key auth and per-IP rate limiting"
```

---

### Task 6: Entry Point and Server Config

**Files:**
- Create: `index.php`
- Create: `.htaccess`
- Create: `nginx.conf.example`

**Step 1: Create .htaccess**

```apache
RewriteEngine On

# If the request is not for an existing file or directory, route to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

**Step 2: Create nginx.conf.example**

```nginx
# horizOn Simple Server - Nginx Configuration Example
#
# Place this inside your server {} block or adapt to your setup.

location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}

# Deny access to sensitive files
location ~ /\.(env|git|htaccess) {
    deny all;
}

location ~ ^/(src|migrations|docs|data)/ {
    deny all;
}
```

**Step 3: Create index.php**

This is the main entry point. It loads all core files and controllers, sets up routes, and dispatches the request. Note: we use `require_once` instead of an autoloader to keep it simple.

```php
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
```

**Step 4: Verify syntax**

Run: `php -l index.php`
Expected: `No syntax errors detected`

**Step 5: Commit**

```bash
git add index.php .htaccess nginx.conf.example
git commit -m "feat: add entry point with routing, .htaccess and nginx config"
```

---

### Task 7: User Management Controller

**Files:**
- Create: `src/UserManagement/UserManagementController.php`

**Step 1: Create UserManagementController.php**

This controller handles anonymous-only auth. The request format uses `type: "ANONYMOUS"` matching the real horizOn server format, but only ANONYMOUS is supported.

```php
<?php

declare(strict_types=1);

class UserManagementController
{
    public static function signup(Request $request): void
    {
        $type = $request->body('type');
        $username = $request->body('username', '');

        if ($type !== 'ANONYMOUS') {
            Response::badRequest('Only ANONYMOUS signup is supported by this server');
            return;
        }

        if ($username === '' || strlen($username) > 30) {
            Response::badRequest('username is required and must be max 30 characters');
            return;
        }

        $pdo = Database::connect();
        $userId = Database::uuid();
        $anonymousToken = bin2hex(random_bytes(16)); // 32 char hex token
        $now = Database::now();

        $stmt = $pdo->prepare('INSERT INTO users (id, display_name, anonymous_token, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $username, $anonymousToken, $now]);

        Response::created([
            'userId' => $userId,
            'username' => $username,
            'email' => null,
            'isAnonymous' => true,
            'isVerified' => false,
            'anonymousToken' => $anonymousToken,
            'googleId' => null,
            'message' => null,
            'createdAt' => $now,
        ]);
    }

    public static function signin(Request $request): void
    {
        $type = $request->body('type');
        $anonymousToken = $request->body('anonymousToken', '');

        if ($type !== 'ANONYMOUS') {
            Response::badRequest('Only ANONYMOUS signin is supported by this server');
            return;
        }

        if ($anonymousToken === '') {
            Response::badRequest('anonymousToken is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, display_name FROM users WHERE anonymous_token = ?');
        $stmt->execute([$anonymousToken]);
        $user = $stmt->fetch();

        if ($user === false) {
            Response::json([
                'userId' => null,
                'username' => null,
                'email' => null,
                'accessToken' => null,
                'authStatus' => 'USER_NOT_FOUND',
                'message' => 'No user found with this anonymous token',
            ], 404);
            return;
        }

        // Generate session token
        $sessionToken = bin2hex(random_bytes(32)); // 64 char hex token
        $expiresAt = gmdate('Y-m-d\TH:i:s', time() + 86400 * 30); // 30 days

        $stmt = $pdo->prepare('UPDATE users SET session_token = ?, session_expires_at = ? WHERE id = ?');
        $stmt->execute([$sessionToken, $expiresAt, $user['id']]);

        Response::json([
            'userId' => $user['id'],
            'username' => $user['display_name'],
            'email' => null,
            'accessToken' => $sessionToken,
            'authStatus' => 'AUTHENTICATED',
            'message' => null,
        ]);
    }

    public static function checkAuth(Request $request): void
    {
        $userId = $request->body('userId', '');
        $sessionToken = $request->body('sessionToken', '');

        if ($userId === '' || $sessionToken === '') {
            Response::badRequest('userId and sessionToken are required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT session_token, session_expires_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            Response::json([
                'userId' => null,
                'isAuthenticated' => false,
                'authStatus' => 'USER_NOT_FOUND',
                'message' => 'User not found',
            ]);
            return;
        }

        if ($user['session_token'] === null || !hash_equals($user['session_token'], $sessionToken)) {
            Response::json([
                'userId' => $userId,
                'isAuthenticated' => false,
                'authStatus' => 'INVALID_TOKEN',
                'message' => 'Invalid session token',
            ]);
            return;
        }

        if ($user['session_expires_at'] !== null && $user['session_expires_at'] < Database::now()) {
            Response::json([
                'userId' => $userId,
                'isAuthenticated' => false,
                'authStatus' => 'TOKEN_EXPIRED',
                'message' => 'Session token has expired',
            ]);
            return;
        }

        Response::json([
            'userId' => $userId,
            'isAuthenticated' => true,
            'authStatus' => 'AUTHENTICATED',
            'message' => null,
        ]);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/UserManagement/UserManagementController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/UserManagement/
git commit -m "feat: add user management controller (anonymous auth)"
```

---

### Task 8: Leaderboard Controller

**Files:**
- Create: `src/Leaderboard/LeaderboardController.php`

**Step 1: Create LeaderboardController.php**

```php
<?php

declare(strict_types=1);

class LeaderboardController
{
    public static function submit(Request $request): void
    {
        $userId = $request->body('userId', '');
        $score = $request->body('score');

        if ($userId === '' || $score === null) {
            Response::badRequest('userId and score are required');
            return;
        }

        $score = (int)$score;
        if ($score < 0) {
            Response::badRequest('score must be non-negative');
            return;
        }

        $pdo = Database::connect();
        $now = Database::now();

        // Upsert: insert or update if new score is higher
        $stmt = $pdo->prepare('SELECT id, score FROM leaderboard WHERE user_id = ?');
        $stmt->execute([$userId]);
        $existing = $stmt->fetch();

        if ($existing === false) {
            $stmt = $pdo->prepare('INSERT INTO leaderboard (id, user_id, score, updated_at) VALUES (?, ?, ?, ?)');
            $stmt->execute([Database::uuid(), $userId, $score, $now]);
        } else {
            if ($score > (int)$existing['score']) {
                $stmt = $pdo->prepare('UPDATE leaderboard SET score = ?, updated_at = ? WHERE user_id = ?');
                $stmt->execute([$score, $now, $userId]);
            }
        }

        Response::json(null);
    }

    public static function top(Request $request): void
    {
        $userId = $request->query('userId', '');
        $limit = $request->queryInt('limit', 10);

        if ($userId === '') {
            Response::badRequest('userId query parameter is required');
            return;
        }

        $limit = max(1, min(100, $limit));

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT l.score, u.display_name as username
             FROM leaderboard l
             JOIN users u ON l.user_id = u.id
             ORDER BY l.score DESC
             LIMIT ?'
        );
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll();

        $entries = [];
        foreach ($rows as $i => $row) {
            $entries[] = [
                'position' => $i + 1,
                'username' => $row['username'],
                'score' => (int)$row['score'],
            ];
        }

        Response::json(['entries' => $entries]);
    }

    public static function rank(Request $request): void
    {
        $userId = $request->query('userId', '');

        if ($userId === '') {
            Response::badRequest('userId query parameter is required');
            return;
        }

        $pdo = Database::connect();

        // Get user's score
        $stmt = $pdo->prepare(
            'SELECT l.score, u.display_name as username
             FROM leaderboard l
             JOIN users u ON l.user_id = u.id
             WHERE l.user_id = ?'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if ($user === false) {
            Response::json([
                'position' => 0,
                'username' => '',
                'score' => 0,
            ]);
            return;
        }

        // Count users with higher score
        $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM leaderboard WHERE score > ?');
        $stmt->execute([$user['score']]);
        $rank = (int)$stmt->fetch()['cnt'] + 1;

        Response::json([
            'position' => $rank,
            'username' => $user['username'],
            'score' => (int)$user['score'],
        ]);
    }

    public static function around(Request $request): void
    {
        $userId = $request->query('userId', '');
        $range = $request->queryInt('range', 10);

        if ($userId === '') {
            Response::badRequest('userId query parameter is required');
            return;
        }

        $range = max(1, min(50, $range));

        $pdo = Database::connect();

        // Get user's score first
        $stmt = $pdo->prepare('SELECT score FROM leaderboard WHERE user_id = ?');
        $stmt->execute([$userId]);
        $userRow = $stmt->fetch();

        if ($userRow === false) {
            Response::json(['entries' => []]);
            return;
        }

        $userScore = (int)$userRow['score'];

        // Get user's rank
        $stmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM leaderboard WHERE score > ?');
        $stmt->execute([$userScore]);
        $userRank = (int)$stmt->fetch()['cnt'] + 1;

        // Get entries around (range above and range below)
        $offset = max(0, $userRank - $range - 1);
        $limit = $range * 2 + 1;

        $stmt = $pdo->prepare(
            'SELECT l.score, u.display_name as username
             FROM leaderboard l
             JOIN users u ON l.user_id = u.id
             ORDER BY l.score DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        $rows = $stmt->fetchAll();

        $entries = [];
        foreach ($rows as $i => $row) {
            $entries[] = [
                'position' => $offset + $i + 1,
                'username' => $row['username'],
                'score' => (int)$row['score'],
            ];
        }

        Response::json(['entries' => $entries]);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/Leaderboard/LeaderboardController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/Leaderboard/
git commit -m "feat: add leaderboard controller (submit, top, rank, around)"
```

---

### Task 9: Cloud Save Controller

**Files:**
- Create: `src/CloudSave/CloudSaveController.php`

**Step 1: Create CloudSaveController.php**

```php
<?php

declare(strict_types=1);

class CloudSaveController
{
    public static function save(Request $request): void
    {
        $userId = $request->body('userId', '');
        $saveData = $request->body('saveData');

        if ($userId === '') {
            Response::badRequest('userId is required');
            return;
        }

        if ($saveData === null) {
            Response::badRequest('saveData is required');
            return;
        }

        $dataStr = is_string($saveData) ? $saveData : json_encode($saveData);

        if (strlen($dataStr) > 300000) {
            Response::badRequest('saveData exceeds maximum size of 300,000 characters');
            return;
        }

        $pdo = Database::connect();
        $now = Database::now();
        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'INSERT INTO cloud_saves (user_id, data, updated_at) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO cloud_saves (user_id, data, updated_at) VALUES (?, ?, ?)
                 ON CONFLICT(user_id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at'
            );
        }

        $stmt->execute([$userId, $dataStr, $now]);

        Response::json([
            'success' => true,
            'dataSizeBytes' => strlen($dataStr),
        ]);
    }

    public static function load(Request $request): void
    {
        $userId = $request->body('userId', '');

        if ($userId === '') {
            Response::badRequest('userId is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT data FROM cloud_saves WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::json([
                'found' => false,
                'saveData' => null,
            ]);
            return;
        }

        Response::json([
            'found' => true,
            'saveData' => $row['data'],
        ]);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/CloudSave/CloudSaveController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/CloudSave/
git commit -m "feat: add cloud save controller (save, load)"
```

---

### Task 10: Remote Config Controller

**Files:**
- Create: `src/RemoteConfig/RemoteConfigController.php`

**Step 1: Create RemoteConfigController.php**

```php
<?php

declare(strict_types=1);

class RemoteConfigController
{
    public static function get(Request $request): void
    {
        $key = $request->param('key');

        if ($key === null || $key === '') {
            Response::badRequest('Config key is required');
            return;
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT config_value FROM remote_configs WHERE config_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        Response::json([
            'configKey' => $key,
            'configValue' => $row !== false ? $row['config_value'] : null,
            'found' => $row !== false,
        ]);
    }

    public static function all(Request $request): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('SELECT config_key, config_value FROM remote_configs');
        $rows = $stmt->fetchAll();

        $configs = [];
        foreach ($rows as $row) {
            $configs[$row['config_key']] = $row['config_value'];
        }

        Response::json([
            'configs' => $configs,
            'total' => count($configs),
        ]);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/RemoteConfig/RemoteConfigController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/RemoteConfig/
git commit -m "feat: add remote config controller (get, all)"
```

---

### Task 11: News Controller

**Files:**
- Create: `src/News/NewsController.php`

**Step 1: Create NewsController.php**

```php
<?php

declare(strict_types=1);

class NewsController
{
    public static function list(Request $request): void
    {
        $limit = $request->queryInt('limit', 20);
        $languageCode = $request->query('languageCode');

        $limit = max(0, min(100, $limit));

        $pdo = Database::connect();

        if ($languageCode !== null && $languageCode !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, title, message, published_at as releaseDate, language_code as languageCode
                 FROM news
                 WHERE language_code = ?
                 ORDER BY published_at DESC
                 LIMIT ?'
            );
            $stmt->execute([$languageCode, $limit]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, title, message, published_at as releaseDate, language_code as languageCode
                 FROM news
                 ORDER BY published_at DESC
                 LIMIT ?'
            );
            $stmt->execute([$limit]);
        }

        $rows = $stmt->fetchAll();

        // The real server returns a flat array (not wrapped in an object)
        Response::json($rows);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/News/NewsController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/News/
git commit -m "feat: add news controller"
```

---

### Task 12: Gift Codes Controller

**Files:**
- Create: `src/GiftCodes/GiftCodesController.php`

**Step 1: Create GiftCodesController.php**

```php
<?php

declare(strict_types=1);

class GiftCodesController
{
    public static function validate(Request $request): void
    {
        $code = $request->body('code', '');
        $userId = $request->body('userId', '');

        if ($code === '' || $userId === '') {
            Response::badRequest('code and userId are required');
            return;
        }

        $valid = self::isCodeValid($code, $userId);

        Response::json(['valid' => $valid]);
    }

    public static function redeem(Request $request): void
    {
        $code = $request->body('code', '');
        $userId = $request->body('userId', '');

        if ($code === '' || $userId === '') {
            Response::badRequest('code and userId are required');
            return;
        }

        $pdo = Database::connect();

        // Find the gift code
        $stmt = $pdo->prepare('SELECT * FROM gift_codes WHERE code = ?');
        $stmt->execute([$code]);
        $giftCode = $stmt->fetch();

        if ($giftCode === false) {
            Response::json([
                'success' => false,
                'message' => 'Gift code not found',
                'giftData' => null,
            ]);
            return;
        }

        // Check expiry
        if ($giftCode['expires_at'] !== null && $giftCode['expires_at'] < Database::now()) {
            Response::json([
                'success' => false,
                'message' => 'Gift code has expired',
                'giftData' => null,
            ]);
            return;
        }

        // Check max redemptions
        if ((int)$giftCode['current_redemptions'] >= (int)$giftCode['max_redemptions']) {
            Response::json([
                'success' => false,
                'message' => 'Gift code has reached maximum redemptions',
                'giftData' => null,
            ]);
            return;
        }

        // Check if user already redeemed
        $stmt = $pdo->prepare('SELECT id FROM gift_code_redemptions WHERE gift_code_id = ? AND user_id = ?');
        $stmt->execute([$giftCode['id'], $userId]);
        if ($stmt->fetch() !== false) {
            Response::json([
                'success' => false,
                'message' => 'You have already redeemed this gift code',
                'giftData' => null,
            ]);
            return;
        }

        // Redeem
        $now = Database::now();
        $stmt = $pdo->prepare('INSERT INTO gift_code_redemptions (id, gift_code_id, user_id, redeemed_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([Database::uuid(), $giftCode['id'], $userId, $now]);

        $stmt = $pdo->prepare('UPDATE gift_codes SET current_redemptions = current_redemptions + 1 WHERE id = ?');
        $stmt->execute([$giftCode['id']]);

        Response::json([
            'success' => true,
            'message' => 'Gift code redeemed successfully',
            'giftData' => $giftCode['reward_data'],
        ]);
    }

    private static function isCodeValid(string $code, string $userId): bool
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare('SELECT * FROM gift_codes WHERE code = ?');
        $stmt->execute([$code]);
        $giftCode = $stmt->fetch();

        if ($giftCode === false) {
            return false;
        }

        // Check expiry
        if ($giftCode['expires_at'] !== null && $giftCode['expires_at'] < Database::now()) {
            return false;
        }

        // Check max redemptions
        if ((int)$giftCode['current_redemptions'] >= (int)$giftCode['max_redemptions']) {
            return false;
        }

        // Check if user already redeemed
        $stmt = $pdo->prepare('SELECT id FROM gift_code_redemptions WHERE gift_code_id = ? AND user_id = ?');
        $stmt->execute([$giftCode['id'], $userId]);
        if ($stmt->fetch() !== false) {
            return false;
        }

        return true;
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/GiftCodes/GiftCodesController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/GiftCodes/
git commit -m "feat: add gift codes controller (validate, redeem)"
```

---

### Task 13: User Feedback Controller

**Files:**
- Create: `src/UserFeedback/UserFeedbackController.php`

**Step 1: Create UserFeedbackController.php**

```php
<?php

declare(strict_types=1);

class UserFeedbackController
{
    public static function submit(Request $request): void
    {
        $title = $request->body('title', '');
        $message = $request->body('message', '');
        $userId = $request->body('userId', '');

        if ($title === '' || $message === '' || $userId === '') {
            Response::badRequest('title, message, and userId are required');
            return;
        }

        if (strlen($title) > 100) {
            Response::badRequest('title must be max 100 characters');
            return;
        }

        if (strlen($message) > 2048) {
            Response::badRequest('message must be max 2048 characters');
            return;
        }

        $email = $request->body('email');
        $category = $request->body('category');
        $deviceInfo = $request->body('deviceInfo');

        $pdo = Database::connect();
        $now = Database::now();

        $stmt = $pdo->prepare(
            'INSERT INTO user_feedback (id, user_id, title, message, category, email, device_info, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            Database::uuid(), $userId, $title, $message,
            $category, $email, $deviceInfo, $now,
        ]);

        // The real server returns just "ok" as a string
        Response::json('ok');
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/UserFeedback/UserFeedbackController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/UserFeedback/
git commit -m "feat: add user feedback controller"
```

---

### Task 14: User Logs Controller

**Files:**
- Create: `src/UserLogs/UserLogsController.php`

**Step 1: Create UserLogsController.php**

```php
<?php

declare(strict_types=1);

class UserLogsController
{
    private const VALID_TYPES = ['INFO', 'WARN', 'ERROR'];

    public static function create(Request $request): void
    {
        $message = $request->body('message', '');
        $type = $request->body('type', '');
        $userId = $request->body('userId', '');

        if ($message === '' || $type === '' || $userId === '') {
            Response::badRequest('message, type, and userId are required');
            return;
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            Response::badRequest('type must be one of: INFO, WARN, ERROR');
            return;
        }

        if (strlen($message) > 1000) {
            Response::badRequest('message must be max 1000 characters');
            return;
        }

        $errorCode = $request->body('errorCode');

        $pdo = Database::connect();
        $id = Database::uuid();
        $now = Database::now();

        $stmt = $pdo->prepare(
            'INSERT INTO user_logs (id, user_id, message, type, error_code, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $userId, $message, $type, $errorCode, $now]);

        Response::created([
            'id' => $id,
            'createdAt' => $now,
        ]);
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/UserLogs/UserLogsController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/UserLogs/
git commit -m "feat: add user logs controller"
```

---

### Task 15: Crash Reporting Controller

**Files:**
- Create: `src/CrashReporting/CrashReportingController.php`

**Step 1: Create CrashReportingController.php**

This is the most complex controller. It handles crash report creation with group upsert, auto-regression logic, and session management.

```php
<?php

declare(strict_types=1);

class CrashReportingController
{
    private const VALID_TYPES = ['CRASH', 'NON_FATAL', 'ANR'];

    public static function create(Request $request): void
    {
        $body = $request->body();
        if ($body === null) {
            Response::badRequest('Request body is required');
            return;
        }

        // Validate required fields
        $type = $body['type'] ?? '';
        $message = $body['message'] ?? '';
        $fingerprint = $body['fingerprint'] ?? '';
        $appVersion = $body['appVersion'] ?? '';
        $sdkVersion = $body['sdkVersion'] ?? '';
        $platform = $body['platform'] ?? '';
        $os = $body['os'] ?? '';
        $deviceModel = $body['deviceModel'] ?? '';
        $sessionId = $body['sessionId'] ?? '';

        if ($type === '' || $message === '' || $fingerprint === '' || $appVersion === '' ||
            $sdkVersion === '' || $platform === '' || $os === '' || $deviceModel === '' || $sessionId === '') {
            Response::badRequest('Missing required fields: type, message, fingerprint, appVersion, sdkVersion, platform, os, deviceModel, sessionId');
            return;
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            Response::badRequest('type must be one of: CRASH, NON_FATAL, ANR');
            return;
        }

        $stackTrace = $body['stackTrace'] ?? null;
        $deviceMemoryMb = (int)($body['deviceMemoryMb'] ?? 0);
        $userId = $body['userId'] ?? null;
        $breadcrumbs = isset($body['breadcrumbs']) ? json_encode($body['breadcrumbs']) : null;
        $customKeys = isset($body['customKeys']) ? json_encode($body['customKeys']) : null;

        $pdo = Database::connect();
        $reportId = Database::uuid();
        $now = Database::now();

        // Insert crash report
        $stmt = $pdo->prepare(
            'INSERT INTO crash_reports (id, type, message, stack_trace, fingerprint, app_version, sdk_version,
             platform, os, device_model, device_memory_mb, session_id, user_id, breadcrumbs, custom_keys, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $reportId, $type, $message, $stackTrace, $fingerprint, $appVersion, $sdkVersion,
            $platform, $os, $deviceModel, $deviceMemoryMb, $sessionId, $userId, $breadcrumbs, $customKeys, $now,
        ]);

        // Upsert crash group
        $groupId = self::upsertCrashGroup($pdo, $fingerprint, $type, $message, $stackTrace, $appVersion, $platform, $userId, $now);

        // Mark session as crashed
        $stmt = $pdo->prepare('UPDATE crash_sessions SET has_crash = 1 WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        Response::created([
            'id' => $reportId,
            'groupId' => $groupId,
            'createdAt' => $now,
        ]);
    }

    public static function session(Request $request): void
    {
        $sessionId = $request->body('sessionId', '');
        $appVersion = $request->body('appVersion', '');
        $platform = $request->body('platform', '');

        if ($sessionId === '' || $appVersion === '' || $platform === '') {
            Response::badRequest('sessionId, appVersion, and platform are required');
            return;
        }

        $userId = $request->body('userId');
        $pdo = Database::connect();

        // Check if session already exists (deduplicate)
        $stmt = $pdo->prepare('SELECT id FROM crash_sessions WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        if ($stmt->fetch() !== false) {
            Response::json(['status' => 'ok']);
            return;
        }

        $now = Database::now();
        $stmt = $pdo->prepare(
            'INSERT INTO crash_sessions (id, session_id, user_id, app_version, platform, has_crash, created_at)
             VALUES (?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([Database::uuid(), $sessionId, $userId, $appVersion, $platform, $now]);

        Response::created(['status' => 'ok']);
    }

    private static function upsertCrashGroup(
        PDO $pdo, string $fingerprint, string $type, string $message,
        ?string $stackTrace, string $appVersion, string $platform, ?string $userId, string $now
    ): string {
        $stmt = $pdo->prepare('SELECT * FROM crash_groups WHERE fingerprint = ?');
        $stmt->execute([$fingerprint]);
        $group = $stmt->fetch();

        if ($group === false) {
            // Create new group
            $groupId = Database::uuid();
            $title = mb_substr($message, 0, 200);
            $versions = json_encode([$appVersion]);

            $stmt = $pdo->prepare(
                'INSERT INTO crash_groups (id, fingerprint, title, status, type, first_seen_at, last_seen_at,
                 occurrence_count, affected_user_count, affected_versions, latest_stack_trace, platform)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)'
            );
            $affectedUsers = $userId !== null ? 1 : 0;
            $stmt->execute([
                $groupId, $fingerprint, $title, 'OPEN', $type,
                $now, $now, $affectedUsers, $versions, $stackTrace, $platform,
            ]);

            return $groupId;
        }

        // Update existing group
        $groupId = $group['id'];

        // Update affected versions
        $versions = json_decode($group['affected_versions'], true) ?: [];
        if (!in_array($appVersion, $versions, true)) {
            $versions[] = $appVersion;
        }

        // Auto-regression: if RESOLVED and new crash is in a newer version
        $status = $group['status'];
        if ($status === 'RESOLVED' && $group['resolved_in_version'] !== null) {
            if (version_compare($appVersion, $group['resolved_in_version'], '>')) {
                $status = 'REGRESSED';
            }
        }

        // Update affected user count (simple: count distinct user_ids in crash_reports for this fingerprint)
        $affectedUserCount = (int)$group['affected_user_count'];
        if ($userId !== null) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(DISTINCT user_id) as cnt FROM crash_reports WHERE fingerprint = ? AND user_id IS NOT NULL'
            );
            $stmt->execute([$fingerprint]);
            $affectedUserCount = (int)$stmt->fetch()['cnt'];
        }

        $stmt = $pdo->prepare(
            'UPDATE crash_groups SET
             status = ?, last_seen_at = ?, occurrence_count = occurrence_count + 1,
             affected_user_count = ?, affected_versions = ?, latest_stack_trace = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $status, $now, $affectedUserCount,
            json_encode($versions), $stackTrace, $groupId,
        ]);

        return $groupId;
    }
}
```

**Step 2: Verify syntax**

Run: `php -l src/CrashReporting/CrashReportingController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/CrashReporting/
git commit -m "feat: add crash reporting controller with group upsert and auto-regression"
```

---

### Task 16: Integration Test with PHP Built-in Server

**Files:**
- Create: `tests/test.sh`

**Step 1: Create a test script**

Create a bash script that starts the PHP built-in server, creates a `.env` from `.env.example`, and runs curl requests against every endpoint to verify they work.

```bash
#!/bin/bash
set -e

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

PASS=0
FAIL=0
BASE_URL="http://localhost:8765/api/v1/app"
API_KEY="test-key-12345"
PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== horizOn Simple Server Integration Tests ==="
echo ""

# Setup
cd "$PROJECT_DIR"

# Create test .env
cat > .env <<EOF
API_KEY=${API_KEY}
DB_DRIVER=sqlite
DB_PATH=./data/test_horizon.db
RATE_LIMIT_ENABLED=false
RATE_LIMIT_PER_SECOND=100
EOF

# Clean previous test DB
rm -f ./data/test_horizon.db

# Start PHP built-in server
php -S localhost:8765 index.php > /dev/null 2>&1 &
PHP_PID=$!
sleep 1

# Cleanup function
cleanup() {
    kill $PHP_PID 2>/dev/null || true
    rm -f .env
    rm -f ./data/test_horizon.db
}
trap cleanup EXIT

# Helper function
assert_status() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    if [ "$actual" -eq "$expected" ]; then
        echo -e "  ${GREEN}PASS${NC} $name (HTTP $actual)"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}FAIL${NC} $name (expected $expected, got $actual)"
        FAIL=$((FAIL + 1))
    fi
}

assert_contains() {
    local name="$1"
    local expected="$2"
    local actual="$3"
    if echo "$actual" | grep -q "$expected"; then
        echo -e "  ${GREEN}PASS${NC} $name (contains '$expected')"
        PASS=$((PASS + 1))
    else
        echo -e "  ${RED}FAIL${NC} $name (expected to contain '$expected')"
        echo "       Got: $actual"
        FAIL=$((FAIL + 1))
    fi
}

# ---- Health ----
echo "--- Health ---"
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/health")
assert_status "GET /health" 200 "$STATUS"

# ---- Auth: Missing API key ----
echo ""
echo "--- Auth ---"
STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/user-management/signup" -X POST -H "Content-Type: application/json" -d '{}')
assert_status "POST without API key returns 401" 401 "$STATUS"

# ---- User Management ----
echo ""
echo "--- User Management ---"

SIGNUP_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/signup" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d '{"type":"ANONYMOUS","username":"TestPlayer"}')
SIGNUP_BODY=$(echo "$SIGNUP_RESP" | head -1)
SIGNUP_STATUS=$(echo "$SIGNUP_RESP" | tail -1)
assert_status "POST /user-management/signup" 201 "$SIGNUP_STATUS"
assert_contains "signup returns userId" "userId" "$SIGNUP_BODY"
assert_contains "signup returns anonymousToken" "anonymousToken" "$SIGNUP_BODY"

# Extract anonymousToken and userId
ANON_TOKEN=$(echo "$SIGNUP_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->anonymousToken;')
USER_ID=$(echo "$SIGNUP_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->userId;')

# Sign in
SIGNIN_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/signin" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"type\":\"ANONYMOUS\",\"anonymousToken\":\"$ANON_TOKEN\"}")
SIGNIN_BODY=$(echo "$SIGNIN_RESP" | head -1)
SIGNIN_STATUS=$(echo "$SIGNIN_RESP" | tail -1)
assert_status "POST /user-management/signin" 200 "$SIGNIN_STATUS"
assert_contains "signin returns accessToken" "accessToken" "$SIGNIN_BODY"
assert_contains "signin returns AUTHENTICATED" "AUTHENTICATED" "$SIGNIN_BODY"

# Extract session token
SESSION_TOKEN=$(echo "$SIGNIN_BODY" | php -r 'echo json_decode(file_get_contents("php://stdin"))->accessToken;')

# Check auth
CHECK_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-management/check-auth" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"sessionToken\":\"$SESSION_TOKEN\"}")
CHECK_BODY=$(echo "$CHECK_RESP" | head -1)
CHECK_STATUS=$(echo "$CHECK_RESP" | tail -1)
assert_status "POST /user-management/check-auth" 200 "$CHECK_STATUS"
assert_contains "check-auth returns isAuthenticated true" "true" "$CHECK_BODY"

# ---- Leaderboard ----
echo ""
echo "--- Leaderboard ---"

STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/leaderboard/submit" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"score\":1500}")
assert_status "POST /leaderboard/submit" 200 "$STATUS"

TOP_RESP=$(curl -s "$BASE_URL/leaderboard/top?userId=$USER_ID&limit=10" -H "X-API-Key: $API_KEY")
assert_contains "GET /leaderboard/top returns entries" "entries" "$TOP_RESP"
assert_contains "top contains TestPlayer" "TestPlayer" "$TOP_RESP"

RANK_RESP=$(curl -s "$BASE_URL/leaderboard/rank?userId=$USER_ID" -H "X-API-Key: $API_KEY")
assert_contains "GET /leaderboard/rank returns position" "position" "$RANK_RESP"

AROUND_RESP=$(curl -s "$BASE_URL/leaderboard/around?userId=$USER_ID&range=5" -H "X-API-Key: $API_KEY")
assert_contains "GET /leaderboard/around returns entries" "entries" "$AROUND_RESP"

# ---- Cloud Save ----
echo ""
echo "--- Cloud Save ---"

STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/cloud-save/save" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"saveData\":\"{\\\"level\\\":5,\\\"coins\\\":100}\"}")
assert_status "POST /cloud-save/save" 200 "$STATUS"

LOAD_RESP=$(curl -s "$BASE_URL/cloud-save/load" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\"}")
assert_contains "POST /cloud-save/load returns data" "found" "$LOAD_RESP"
assert_contains "cloud-save contains saved data" "level" "$LOAD_RESP"

# ---- Remote Config ----
echo ""
echo "--- Remote Config ---"

ALL_RESP=$(curl -s "$BASE_URL/remote-config/all" -H "X-API-Key: $API_KEY")
assert_contains "GET /remote-config/all returns configs" "configs" "$ALL_RESP"

GET_RESP=$(curl -s "$BASE_URL/remote-config/nonexistent" -H "X-API-Key: $API_KEY")
assert_contains "GET /remote-config/{key} returns found=false" "false" "$GET_RESP"

# ---- News ----
echo ""
echo "--- News ---"

NEWS_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/news?limit=5" -H "X-API-Key: $API_KEY")
assert_status "GET /news" 200 "$NEWS_STATUS"

# ---- Gift Codes ----
echo ""
echo "--- Gift Codes ---"

VALIDATE_RESP=$(curl -s "$BASE_URL/gift-codes/validate" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
assert_contains "POST /gift-codes/validate invalid code" "false" "$VALIDATE_RESP"

REDEEM_RESP=$(curl -s "$BASE_URL/gift-codes/redeem" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"code\":\"NONEXISTENT\",\"userId\":\"$USER_ID\"}")
assert_contains "POST /gift-codes/redeem not found" "not found" "$REDEEM_RESP"

# ---- User Feedback ----
echo ""
echo "--- User Feedback ---"

FB_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/user-feedback/submit" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"title\":\"Great game\",\"message\":\"Really enjoying it!\"}")
assert_status "POST /user-feedback/submit" 200 "$FB_STATUS"

# ---- User Logs ----
echo ""
echo "--- User Logs ---"

LOG_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/user-logs/create" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"userId\":\"$USER_ID\",\"message\":\"Player started level 5\",\"type\":\"INFO\"}")
LOG_STATUS=$(echo "$LOG_RESP" | tail -1)
assert_status "POST /user-logs/create" 201 "$LOG_STATUS"

# ---- Crash Reporting ----
echo ""
echo "--- Crash Reporting ---"

SESSION_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/crash-reports/session" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d "{\"sessionId\":\"sess-001\",\"appVersion\":\"1.0.0\",\"platform\":\"Android\"}")
SESSION_STATUS=$(echo "$SESSION_RESP" | tail -1)
assert_status "POST /crash-reports/session" 201 "$SESSION_STATUS"

CRASH_RESP=$(curl -s -w "\n%{http_code}" "$BASE_URL/crash-reports/create" \
    -X POST -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" \
    -d '{
        "type": "CRASH",
        "message": "NullPointerException at GameManager.update()",
        "stackTrace": "at GameManager.update(GameManager.java:42)",
        "fingerprint": "fp-nullptr-gamemanager",
        "appVersion": "1.0.0",
        "sdkVersion": "0.5.0",
        "platform": "Android",
        "os": "Android 14",
        "deviceModel": "Pixel 8",
        "deviceMemoryMb": 8192,
        "sessionId": "sess-001",
        "userId": "'"$USER_ID"'",
        "breadcrumbs": [{"timestamp": "2026-02-22T10:00:00", "type": "navigation", "message": "Opened level 5"}],
        "customKeys": {"build": "release"}
    }')
CRASH_STATUS=$(echo "$CRASH_RESP" | tail -1)
CRASH_BODY=$(echo "$CRASH_RESP" | head -1)
assert_status "POST /crash-reports/create" 201 "$CRASH_STATUS"
assert_contains "crash report returns groupId" "groupId" "$CRASH_BODY"

# ---- 404 ----
echo ""
echo "--- Error Handling ---"

STATUS=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/nonexistent" -H "X-API-Key: $API_KEY")
assert_status "GET nonexistent endpoint returns 404" 404 "$STATUS"

# ---- Summary ----
echo ""
echo "================================"
echo -e "Results: ${GREEN}${PASS} passed${NC}, ${RED}${FAIL} failed${NC}"
echo "================================"

if [ $FAIL -gt 0 ]; then
    exit 1
fi
```

**Step 2: Make executable**

Run: `chmod +x tests/test.sh`

**Step 3: Run the tests**

Run: `cd /path/to/project && bash tests/test.sh`
Expected: All tests pass (green). Fix any failures before proceeding.

**Step 4: Commit**

```bash
git add tests/
git commit -m "test: add integration test script for all endpoints"
```

---

### Task 17: README

**Files:**
- Create: `README.md`

**Step 1: Create README.md**

Write a clear README with:
- Project description (what it is, who it's for)
- Quick start (3 steps: clone, copy .env, start server)
- Configuration reference (.env variables)
- API endpoint reference table
- Deployment guide (Apache, Nginx, PHP built-in server)
- Database setup (SQLite default, MySQL optional)
- License (MIT)

Keep it concise but complete. This is the first thing people see on GitHub.

**Step 2: Create .gitignore**

```
.env
data/
*.db
.DS_Store
```

**Step 3: Commit**

```bash
git add README.md .gitignore
git commit -m "docs: add README and .gitignore"
```

---

### Task 18: Final Verification

**Step 1: Run integration tests one final time**

Run: `bash tests/test.sh`
Expected: All tests pass.

**Step 2: Review file structure**

Run: `find . -type f -not -path './.git/*' | sort`
Expected: Matches the design document structure exactly.

**Step 3: Check all PHP files for syntax**

Run: `find . -name '*.php' -not -path './.git/*' -exec php -l {} \;`
Expected: `No syntax errors detected` for every file.

**Step 4: Final commit if any fixes were needed**

```bash
git add -A
git commit -m "chore: final cleanup and verification"
```
