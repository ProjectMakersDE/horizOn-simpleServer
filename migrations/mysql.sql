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
