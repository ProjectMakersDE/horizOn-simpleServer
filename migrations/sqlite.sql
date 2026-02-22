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
