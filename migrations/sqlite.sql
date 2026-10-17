-- horizOn Simple Server - SQLite Schema

CREATE TABLE IF NOT EXISTS achievement_catalog (id INTEGER PRIMARY KEY, data TEXT NOT NULL);

CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    display_name TEXT NOT NULL,
    anonymous_token TEXT UNIQUE NOT NULL,
    session_token TEXT,
    session_expires_at TEXT,
    email TEXT,
    apple_user_id TEXT,
    is_private_relay_email INTEGER NOT NULL DEFAULT 0,
    avatar_id TEXT,
    frame_id TEXT,
    badges TEXT,
    unlocks TEXT,
    created_at_millis INTEGER,
    last_login_millis INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_users_apple_user_id ON users(apple_user_id);

CREATE TABLE IF NOT EXISTS achievement_state (
    user_id TEXT PRIMARY KEY, revision INTEGER NOT NULL, data TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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

CREATE TABLE IF NOT EXISTS localizations (
    localization_key TEXT NOT NULL,
    lang TEXT NOT NULL,
    value TEXT,
    PRIMARY KEY (localization_key, lang)
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
    values_json TEXT,
    max_redemptions INTEGER NOT NULL DEFAULT 1,
    current_redemptions INTEGER NOT NULL DEFAULT 0,
    v2_id BLOB,
    protocol_version INTEGER NOT NULL DEFAULT 2,
    config_revision INTEGER NOT NULL DEFAULT 1,
    max_per_user INTEGER DEFAULT 1,
    unlimited_total INTEGER NOT NULL DEFAULT 0,
    migration_hash TEXT,
    expires_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS gift_code_redemptions (
    id TEXT PRIMARY KEY,
    gift_code_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    redeemed_at TEXT NOT NULL DEFAULT (datetime('now')),
    idempotency_id TEXT,
    gift_data TEXT,
    granted_values TEXT,
    value_granted_at INTEGER,
    FOREIGN KEY (gift_code_id) REFERENCES gift_codes(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE(gift_code_id, user_id)
);

-- One constant row serializes explicit campaign identity creation/migration, never player state.
CREATE TABLE IF NOT EXISTS gift_code_identity_lock (id INTEGER PRIMARY KEY CHECK(id=1));

-- Permanent compact source guard; intentionally survives campaign/user row deletion.
CREATE TABLE IF NOT EXISTS gift_code_counts (
    campaign_id BLOB NOT NULL CHECK(typeof(campaign_id)='blob' AND length(campaign_id)=16),
    player_id BLOB NOT NULL CHECK(typeof(player_id)='blob' AND length(player_id)=16),
    count INTEGER NOT NULL CHECK(typeof(count)='integer' AND count>=0),
    PRIMARY KEY(campaign_id,player_id)
) WITHOUT ROWID;

-- Player profile cosmetics catalog (one per installation), filled by SQL.
-- cosmetic_id: 1 to 32 characters a-z 0-9 . _ - (starts with a letter or digit)
-- type: avatar | frame | badge; locked: 0 free for every player, 1 needs an unlock
CREATE TABLE IF NOT EXISTS cosmetics (
    cosmetic_id TEXT PRIMARY KEY,
    type TEXT NOT NULL,
    locked INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
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

-- Email Sending

CREATE TABLE IF NOT EXISTS email_templates (
    id TEXT PRIMARY KEY,
    slug TEXT NOT NULL,
    name TEXT NOT NULL,
    subject TEXT NOT NULL DEFAULT '{}',
    body TEXT NOT NULL DEFAULT '{}',
    variables TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    deleted INTEGER NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_email_templates_slug ON email_templates(slug) WHERE deleted = 0;

CREATE TABLE IF NOT EXISTS email_queue (
    id TEXT PRIMARY KEY,
    account_id TEXT NOT NULL DEFAULT 'default',
    template_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    variables TEXT NOT NULL DEFAULT '{}',
    language TEXT NOT NULL DEFAULT 'en',
    status TEXT NOT NULL DEFAULT 'pending',
    error_reason TEXT,
    scheduled_at TEXT,
    processed_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (template_id) REFERENCES email_templates(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_email_queue_status_scheduled ON email_queue(status, scheduled_at);
CREATE INDEX IF NOT EXISTS idx_email_queue_user_status ON email_queue(user_id, status);

-- TASK-1005: one bounded catalog and one statistics document per player.
CREATE TABLE IF NOT EXISTS statistics_catalog (
    id INTEGER PRIMARY KEY CHECK (id = 1), version INTEGER NOT NULL, definitions TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS statistics_state (
    user_id TEXT PRIMARY KEY, data TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- TASK-1004: bounded server-owned balances and a separate offer catalogue.
CREATE TABLE IF NOT EXISTS player_values_config (
    id INTEGER PRIMARY KEY CHECK (id = 1), revision INTEGER NOT NULL,
    definitions TEXT NOT NULL, known_keys TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS player_value_offers (offer_key TEXT COLLATE BINARY PRIMARY KEY, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS player_values (
    user_id TEXT PRIMARY KEY, data TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Bounded inbox state; no broadcast fanout.
CREATE TABLE IF NOT EXISTS inbox_catalog (id INTEGER PRIMARY KEY, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS inbox_state (user_id TEXT PRIMARY KEY, data TEXT NOT NULL,next_expiry INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE);

-- Friends: nullable active pointer retains an indexed deletion anchor, including block-only targets.
CREATE TABLE IF NOT EXISTS friends_state (
    user_id TEXT PRIMARY KEY, active_user_id TEXT, revision INTEGER NOT NULL,
    friends TEXT NOT NULL, incoming TEXT NOT NULL, outgoing TEXT NOT NULL, blocked TEXT NOT NULL,
    FOREIGN KEY (active_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_friends_deleted ON friends_state(active_user_id,user_id);
CREATE TABLE IF NOT EXISTS friends_references (
    owner_user_id TEXT NOT NULL, related_user_id TEXT NOT NULL,
    PRIMARY KEY(owner_user_id,related_user_id),
    FOREIGN KEY(owner_user_id) REFERENCES friends_state(user_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_friends_related ON friends_references(related_user_id,owner_user_id);

-- Groups retain player revisions and indexed deletion anchors after ordinary leave/delete.
CREATE TABLE IF NOT EXISTS groups_state (id TEXT PRIMARY KEY, revision INTEGER NOT NULL, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS player_groups (
    user_id TEXT PRIMARY KEY, active_user_id TEXT, revision INTEGER NOT NULL, data TEXT NOT NULL,
    FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_groups_deleted ON player_groups(active_user_id,user_id);
CREATE TABLE IF NOT EXISTS group_members (
    group_id TEXT NOT NULL, user_id TEXT NOT NULL, role TEXT NOT NULL, joined_millis INTEGER NOT NULL,
    PRIMARY KEY(group_id,user_id), FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_group_members_player ON group_members(user_id,group_id);
CREATE INDEX IF NOT EXISTS idx_group_succession ON group_members(group_id,role,joined_millis,user_id);
CREATE TABLE IF NOT EXISTS group_pending_references (
    user_id TEXT NOT NULL, group_id TEXT NOT NULL, PRIMARY KEY(user_id,group_id),
    FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_group_pending_group ON group_pending_references(group_id,user_id);

-- One project catalog header; indexed templates and one bounded inventory per player.
CREATE TABLE IF NOT EXISTS item_catalog (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, total INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS item_templates (item_key TEXT PRIMARY KEY COLLATE BINARY, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS player_items (
    user_id TEXT PRIMARY KEY, revision INTEGER NOT NULL, data TEXT NOT NULL,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Immutable item bases and stable UInt16 template identities. Existing data is not backfilled.
CREATE TABLE IF NOT EXISTS item_storage_catalog(id INTEGER PRIMARY KEY,next_slot INTEGER NOT NULL,next_version INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS item_bases(version INTEGER PRIMARY KEY,slot INTEGER NOT NULL,data TEXT NOT NULL,shape BLOB NOT NULL,ref_count INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS idx_item_basis_shape ON item_bases(slot,shape,version);
CREATE TABLE IF NOT EXISTS item_template_storage(item_key TEXT PRIMARY KEY COLLATE BINARY,slot INTEGER NOT NULL UNIQUE,version INTEGER NOT NULL);

-- Shared Objects: metadata seeks never read opaque payload blobs. Quota/usage survive deletion until bounded cleanup.
CREATE TABLE IF NOT EXISTS shared_object_catalog (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, total INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS shared_object_collections (collection_key TEXT PRIMARY KEY COLLATE BINARY, revision INTEGER NOT NULL, data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS shared_object_quota (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, charged_bytes INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS shared_object_usage (
 collection_key TEXT NOT NULL COLLATE BINARY, user_id TEXT NOT NULL, active_user_id TEXT, revision INTEGER NOT NULL, count INTEGER NOT NULL, charged_bytes INTEGER NOT NULL,
 PRIMARY KEY(collection_key,user_id), FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_shared_usage_user ON shared_object_usage(user_id,collection_key);
CREATE INDEX IF NOT EXISTS idx_shared_usage_deleted ON shared_object_usage(active_user_id,user_id,collection_key);
CREATE TABLE IF NOT EXISTS shared_objects (
 id TEXT PRIMARY KEY COLLATE BINARY, collection_key TEXT NOT NULL COLLATE BINARY, partition_key TEXT NOT NULL COLLATE BINARY, owner_id TEXT NOT NULL, group_id TEXT,
 revision INTEGER NOT NULL, payload_bytes INTEGER NOT NULL, charged_bytes INTEGER NOT NULL, search_json TEXT NOT NULL, position_json TEXT,
 slot0 TEXT COLLATE BINARY, slot1 TEXT COLLATE BINARY, slot2 TEXT COLLATE BINARY, position_x TEXT COLLATE BINARY,
 created_millis INTEGER NOT NULL, updated_millis INTEGER NOT NULL, expires_millis INTEGER
);
CREATE INDEX IF NOT EXISTS idx_shared_id_page ON shared_objects(collection_key,partition_key,id);
CREATE INDEX IF NOT EXISTS idx_shared_owner_page ON shared_objects(collection_key,partition_key,owner_id,id);
CREATE INDEX IF NOT EXISTS idx_shared_slot0_page ON shared_objects(collection_key,partition_key,slot0,id);
CREATE INDEX IF NOT EXISTS idx_shared_slot1_page ON shared_objects(collection_key,partition_key,slot1,id);
CREATE INDEX IF NOT EXISTS idx_shared_slot2_page ON shared_objects(collection_key,partition_key,slot2,id);
CREATE INDEX IF NOT EXISTS idx_shared_position_x_page ON shared_objects(collection_key,partition_key,position_x,id);
CREATE INDEX IF NOT EXISTS idx_shared_expiry ON shared_objects(expires_millis,id);
CREATE INDEX IF NOT EXISTS idx_shared_deleted_owner ON shared_objects(owner_id,id);
CREATE TABLE IF NOT EXISTS shared_object_payloads (
 id TEXT PRIMARY KEY COLLATE BINARY, collection_key TEXT NOT NULL COLLATE BINARY, revision INTEGER NOT NULL, payload BLOB NOT NULL,
 FOREIGN KEY(id) REFERENCES shared_objects(id) ON DELETE CASCADE
);

-- Matches: summary-only seeks, paired opaque bytes and retained player revision/deletion anchors.
CREATE TABLE IF NOT EXISTS matches_state (
 id TEXT PRIMARY KEY COLLATE BINARY, revision INTEGER NOT NULL, store_revision INTEGER NOT NULL,
 payload_revision INTEGER NOT NULL, payload_bytes INTEGER NOT NULL, creator_id TEXT NOT NULL,
 status TEXT NOT NULL, visibility TEXT NOT NULL, strength INTEGER NOT NULL, open_for_join INTEGER NOT NULL,
 lobby_deadline INTEGER NOT NULL, due_millis INTEGER NOT NULL, data TEXT NOT NULL, summary TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_matches_open ON matches_state(open_for_join,strength,id);
CREATE INDEX IF NOT EXISTS idx_matches_status ON matches_state(status,id);
CREATE INDEX IF NOT EXISTS idx_matches_due ON matches_state(due_millis,id);
CREATE TABLE IF NOT EXISTS match_payloads (
 id TEXT PRIMARY KEY COLLATE BINARY, revision INTEGER NOT NULL, payload BLOB NOT NULL,
 FOREIGN KEY(id) REFERENCES matches_state(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS player_matches (
 user_id TEXT PRIMARY KEY, active_user_id TEXT, revision INTEGER NOT NULL, data TEXT NOT NULL, cleanup_after TEXT,
 FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_matches_deleted ON player_matches(active_user_id,user_id);
CREATE TABLE IF NOT EXISTS match_config (id INTEGER PRIMARY KEY CHECK(id=1),revision INTEGER NOT NULL,board_key TEXT);
CREATE TABLE IF NOT EXISTS match_maintenance (
 kind TEXT PRIMARY KEY, revision INTEGER NOT NULL, cutoff_millis INTEGER NOT NULL, after_due INTEGER, after_id TEXT
);

-- Marketplace preserves original escrow after player deletion; no valuable row has a TTL.
CREATE TABLE IF NOT EXISTS market_header(id INTEGER PRIMARY KEY,version INTEGER NOT NULL,revision INTEGER NOT NULL,data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS market_rules(item_key TEXT PRIMARY KEY COLLATE BINARY,data TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS player_market(user_id TEXT PRIMARY KEY,active_user_id TEXT,revision INTEGER NOT NULL,data TEXT NOT NULL,
 FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL);
CREATE INDEX IF NOT EXISTS idx_market_deleted ON player_market(active_user_id,user_id);
CREATE TABLE IF NOT EXISTS market_listings(id TEXT PRIMARY KEY COLLATE BINARY,revision INTEGER NOT NULL,status TEXT NOT NULL COLLATE BINARY,
 item_key TEXT NOT NULL COLLATE BINARY,price INTEGER NOT NULL,created_millis INTEGER NOT NULL,due_kind INTEGER NOT NULL,due_millis INTEGER NOT NULL,
 return_owner TEXT NOT NULL,create_actor TEXT COLLATE BINARY,create_operation TEXT COLLATE BINARY,
 close_actor TEXT COLLATE BINARY,close_operation TEXT COLLATE BINARY,return_entry TEXT COLLATE BINARY,data TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_market_price ON market_listings(status,price,id);
CREATE INDEX IF NOT EXISTS idx_market_item_price ON market_listings(status,item_key,price,id);
CREATE INDEX IF NOT EXISTS idx_market_admin ON market_listings(created_millis,id);
CREATE INDEX IF NOT EXISTS idx_market_admin_status ON market_listings(status,created_millis,id);
CREATE INDEX IF NOT EXISTS idx_market_due ON market_listings(due_kind,due_millis,id);
CREATE INDEX IF NOT EXISTS idx_market_owner ON market_listings(return_owner,id);
CREATE TABLE IF NOT EXISTS market_maintenance(kind TEXT PRIMARY KEY,revision INTEGER NOT NULL,data TEXT NOT NULL);

-- Request identity index only, no duplicated pending payload or player list.
CREATE TABLE IF NOT EXISTS group_inbox_sources(request_id TEXT PRIMARY KEY COLLATE BINARY,group_id TEXT NOT NULL,FOREIGN KEY(group_id) REFERENCES groups_state(id) ON DELETE CASCADE);
CREATE INDEX IF NOT EXISTS idx_group_inbox_group ON group_inbox_sources(group_id,request_id);
