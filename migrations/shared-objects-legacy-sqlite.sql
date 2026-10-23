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

