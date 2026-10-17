-- horizOn Simple Server - MySQL Schema

CREATE TABLE IF NOT EXISTS achievement_catalog (id INT PRIMARY KEY, data MEDIUMTEXT NOT NULL)
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id CHAR(36) PRIMARY KEY,
    display_name VARCHAR(30) NOT NULL,
    anonymous_token VARCHAR(64) UNIQUE NOT NULL,
    session_token VARCHAR(256),
    session_expires_at DATETIME,
    email VARCHAR(254),
    apple_user_id VARCHAR(255),
    is_private_relay_email TINYINT(1) NOT NULL DEFAULT 0,
    avatar_id VARCHAR(32) NULL,
    frame_id VARCHAR(32) NULL,
    badges TEXT NULL,
    unlocks TEXT NULL,
    created_at_millis BIGINT NULL,
    last_login_millis BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_apple_user_id (apple_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS achievement_state (
    user_id CHAR(36) PRIMARY KEY, revision BIGINT NOT NULL, data MEDIUMTEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS localizations (
    localization_key VARCHAR(256) NOT NULL,
    lang VARCHAR(10) NOT NULL,
    value TEXT,
    PRIMARY KEY (localization_key, lang)
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
    values_json TEXT,
    max_redemptions INT NOT NULL DEFAULT 1,
    current_redemptions BIGINT NOT NULL DEFAULT 0,
    v2_id BINARY(16),
    protocol_version TINYINT NOT NULL DEFAULT 2,
    config_revision BIGINT NOT NULL DEFAULT 1,
    max_per_user INT DEFAULT 1,
    unlimited_total TINYINT NOT NULL DEFAULT 0,
    migration_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    expires_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gift_code_redemptions (
    id CHAR(36) PRIMARY KEY,
    gift_code_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    idempotency_id VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    gift_data TEXT,
    granted_values TEXT,
    value_granted_at BIGINT NULL,
    FOREIGN KEY (gift_code_id) REFERENCES gift_codes(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_redemption (gift_code_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One constant row serializes explicit campaign identity creation/migration, never player state.
CREATE TABLE IF NOT EXISTS gift_code_identity_lock (id TINYINT PRIMARY KEY);

-- Permanent compact source guard; intentionally survives campaign/user row deletion.
CREATE TABLE IF NOT EXISTS gift_code_counts (
    campaign_id BINARY(16) NOT NULL,
    player_id BINARY(16) NOT NULL,
    count BIGINT NOT NULL,
    PRIMARY KEY(campaign_id,player_id),
    CHECK(count>=0)
) ENGINE=InnoDB;

-- Player profile cosmetics catalog (one per installation), filled by SQL.
-- cosmetic_id: 1 to 32 characters a-z 0-9 . _ - (starts with a letter or digit)
-- type: avatar | frame | badge; locked: 0 free for every player, 1 needs an unlock
CREATE TABLE IF NOT EXISTS cosmetics (
    cosmetic_id VARCHAR(32) NOT NULL PRIMARY KEY,
    type VARCHAR(10) NOT NULL,
    locked TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

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

-- Email Sending

CREATE TABLE IF NOT EXISTS email_templates (
    id CHAR(36) PRIMARY KEY,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(200) NOT NULL,
    subject TEXT NOT NULL,
    body MEDIUMTEXT NOT NULL,
    variables TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY idx_email_templates_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_queue (
    id CHAR(36) PRIMARY KEY,
    account_id CHAR(36) NOT NULL DEFAULT 'default',
    template_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    variables TEXT NOT NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_reason TEXT,
    scheduled_at DATETIME,
    processed_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_queue_status_scheduled (status, scheduled_at),
    INDEX idx_email_queue_user_status (user_id, status),
    FOREIGN KEY (template_id) REFERENCES email_templates(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TASK-1004: bounded server-owned balances and a separate offer catalogue.
CREATE TABLE IF NOT EXISTS player_values_config (
    id TINYINT PRIMARY KEY, revision BIGINT NOT NULL,
    definitions MEDIUMTEXT NOT NULL, known_keys TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS player_value_offers (
    offer_key VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, data TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS player_values (
    user_id CHAR(36) PRIMARY KEY, data MEDIUMTEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- TASK-1005: one bounded catalog and one statistics document per player.
CREATE TABLE IF NOT EXISTS statistics_catalog (
    id TINYINT PRIMARY KEY, version BIGINT NOT NULL, definitions MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS statistics_state (
    user_id CHAR(36) PRIMARY KEY, data MEDIUMTEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bounded JSON rows may exceed64KiB at500 broadcasts/markers.
CREATE TABLE IF NOT EXISTS inbox_catalog (id INT PRIMARY KEY, data MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS inbox_state (user_id CHAR(36) PRIMARY KEY, data MEDIUMTEXT NOT NULL,next_expiry BIGINT NULL,INDEX idx_inbox_expiry(next_expiry,user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nullable active pointers and reverse references retain bounded/resumable deletion anchors.
CREATE TABLE IF NOT EXISTS friends_state (
    user_id CHAR(36) PRIMARY KEY, active_user_id CHAR(36) NULL, revision BIGINT NOT NULL,
    friends TEXT NOT NULL, incoming TEXT NOT NULL, outgoing TEXT NOT NULL, blocked TEXT NOT NULL,
    INDEX idx_friends_deleted(active_user_id,user_id),
    FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS friends_references (
    owner_user_id CHAR(36) NOT NULL, related_user_id CHAR(36) NOT NULL,
    PRIMARY KEY(owner_user_id,related_user_id),
    INDEX idx_friends_related(related_user_id,owner_user_id),
    FOREIGN KEY(owner_user_id) REFERENCES friends_state(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Explicit connection-bound groups; player state is a permanent revision/deletion anchor.
CREATE TABLE IF NOT EXISTS groups_state (id CHAR(36) PRIMARY KEY, revision BIGINT NOT NULL, data MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS player_groups (
    user_id CHAR(36) PRIMARY KEY, active_user_id CHAR(36) NULL, revision BIGINT NOT NULL, data TEXT NOT NULL,
    INDEX idx_groups_deleted(active_user_id,user_id), FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS group_members (
    group_id CHAR(36) NOT NULL, user_id CHAR(36) NOT NULL, role VARCHAR(7) NOT NULL, joined_millis BIGINT NOT NULL,
    PRIMARY KEY(group_id,user_id), INDEX idx_group_members_player(user_id,group_id), INDEX idx_group_succession(group_id,role,joined_millis,user_id),
    FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS group_pending_references (
    user_id CHAR(36) NOT NULL, group_id CHAR(36) NOT NULL, PRIMARY KEY(user_id,group_id),
    INDEX idx_group_pending_group(group_id,user_id), FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reserved catalog identities, bounded pages and same-PDO inventory grants.
CREATE TABLE IF NOT EXISTS item_catalog (id INT PRIMARY KEY, revision BIGINT NOT NULL, total INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS item_templates (item_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, data TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS player_items (
    user_id CHAR(36) PRIMARY KEY, revision BIGINT NOT NULL, data MEDIUMTEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Immutable item bases and stable UInt16 template identities. Existing data is not backfilled.
CREATE TABLE IF NOT EXISTS item_storage_catalog(id INT PRIMARY KEY,next_slot INT NOT NULL,next_version BIGINT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS item_bases(version BIGINT PRIMARY KEY,slot INT NOT NULL,data TEXT NOT NULL,shape BINARY(32) NOT NULL,ref_count BIGINT NOT NULL,INDEX idx_item_basis_shape(slot,shape,version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS item_template_storage(item_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,slot INT NOT NULL UNIQUE,version BIGINT NOT NULL) ENGINE=InnoDB;

-- Shared Objects: schema bootstrap is explicit in SharedObjectSchema (legacy data is never converted here).

-- Matches metadata and bytes have independent revisions; player revisions survive ordinary leave.
CREATE TABLE IF NOT EXISTS matches_state (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, revision BIGINT NOT NULL, store_revision BIGINT NOT NULL,
 payload_revision BIGINT NOT NULL, payload_bytes INT NOT NULL, creator_id CHAR(36) NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, visibility VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 strength BIGINT NOT NULL, open_for_join TINYINT NOT NULL, lobby_deadline BIGINT NOT NULL, due_millis BIGINT NOT NULL,
 data TEXT NOT NULL, summary TEXT NOT NULL,
 INDEX idx_matches_open(open_for_join,strength,id), INDEX idx_matches_status(status,id), INDEX idx_matches_due(due_millis,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS match_payloads (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, revision BIGINT NOT NULL, payload BLOB NOT NULL,
 FOREIGN KEY(id) REFERENCES matches_state(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS player_matches (
 user_id CHAR(36) PRIMARY KEY, active_user_id CHAR(36) NULL, revision BIGINT NOT NULL, data TEXT NOT NULL, cleanup_after CHAR(36) NULL,
 INDEX idx_matches_deleted(active_user_id,user_id), FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS match_config (id INT PRIMARY KEY, revision BIGINT NOT NULL, board_key VARCHAR(128) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS match_maintenance (
 kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, revision BIGINT NOT NULL, cutoff_millis BIGINT NOT NULL,
 after_due BIGINT NULL, after_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Marketplace: indexed metadata plus original item and listing operations; no cascading escrow deletion.
CREATE TABLE IF NOT EXISTS market_header(id INT PRIMARY KEY,version BIGINT NOT NULL,revision BIGINT NOT NULL,data TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS market_rules(item_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,data TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS player_market(user_id CHAR(36) PRIMARY KEY,active_user_id CHAR(36) NULL,revision BIGINT NOT NULL,data MEDIUMTEXT NOT NULL,
 INDEX idx_market_deleted(active_user_id,user_id),FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS market_listings(id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,revision BIGINT NOT NULL,
 status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,item_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 price BIGINT NOT NULL,created_millis BIGINT NOT NULL,due_kind INT NOT NULL,due_millis BIGINT NOT NULL,return_owner CHAR(36) NOT NULL,
 create_actor CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,create_operation CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
 close_actor CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,close_operation CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,return_entry VARCHAR(46) CHARACTER SET ascii COLLATE ascii_bin NULL,INDEX idx_market_inbox_return(return_owner,return_entry),data TEXT NOT NULL,
 INDEX idx_market_price(status,price,id),INDEX idx_market_item_price(status,item_key,price,id),INDEX idx_market_admin(created_millis,id),
 INDEX idx_market_admin_status(status,created_millis,id),INDEX idx_market_due(due_kind,due_millis,id),INDEX idx_market_owner(return_owner,id),
 UNIQUE INDEX idx_market_create_operation(create_actor,create_operation),UNIQUE INDEX idx_market_close_operation(close_actor,close_operation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS market_maintenance(kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,revision BIGINT NOT NULL,data TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Request identity index only, no duplicated pending payload or player list.
CREATE TABLE IF NOT EXISTS group_inbox_sources(request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,group_id CHAR(36) NOT NULL,INDEX idx_group_inbox_group(group_id,request_id),FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE) ENGINE=InnoDB;
