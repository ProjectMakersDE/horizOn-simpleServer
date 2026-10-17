-- Shared Objects: bounded binary keyset metadata plus separate opaque bytes, explicit atomic refunds.
CREATE TABLE IF NOT EXISTS shared_object_catalog (id INT PRIMARY KEY, revision BIGINT NOT NULL, total INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_object_collections (collection_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, revision BIGINT NOT NULL, data TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_object_quota (id INT PRIMARY KEY, revision BIGINT NOT NULL, charged_bytes BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_object_usage (
 collection_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, user_id CHAR(36) NOT NULL, active_user_id CHAR(36) NULL,
 revision BIGINT NOT NULL, count INT NOT NULL, charged_bytes BIGINT NOT NULL,
 PRIMARY KEY(collection_key,user_id), INDEX idx_shared_usage_user(user_id,collection_key), INDEX idx_shared_usage_deleted(active_user_id,user_id,collection_key),
 FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_objects (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, collection_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 partition_key VARCHAR(42) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, owner_id CHAR(36) NOT NULL, group_id CHAR(36) NULL,
 revision BIGINT NOT NULL, payload_bytes INT NOT NULL, charged_bytes BIGINT NOT NULL, search_json TEXT NOT NULL, position_json TEXT NULL,
 slot0 VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NULL, slot1 VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NULL, slot2 VARCHAR(192) CHARACTER SET ascii COLLATE ascii_bin NULL,
 position_x VARCHAR(17) CHARACTER SET ascii COLLATE ascii_bin NULL, created_millis BIGINT NOT NULL, updated_millis BIGINT NOT NULL, expires_millis BIGINT NULL,
 INDEX idx_shared_id_page(collection_key,partition_key,id), INDEX idx_shared_owner_page(collection_key,partition_key,owner_id,id),
 INDEX idx_shared_slot0_page(collection_key,partition_key,slot0,id), INDEX idx_shared_slot1_page(collection_key,partition_key,slot1,id), INDEX idx_shared_slot2_page(collection_key,partition_key,slot2,id),
 INDEX idx_shared_position_x_page(collection_key,partition_key,position_x,id), INDEX idx_shared_expiry(expires_millis,id), INDEX idx_shared_deleted_owner(owner_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_object_payloads (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, collection_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 revision BIGINT NOT NULL, payload MEDIUMBLOB NOT NULL, FOREIGN KEY(id) REFERENCES shared_objects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

