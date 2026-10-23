CREATE TABLE IF NOT EXISTS shared_object_catalog(id INTEGER PRIMARY KEY,r INTEGER NOT NULL,n INTEGER NOT NULL,s INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS shared_object_collections(c INTEGER PRIMARY KEY,k TEXT NOT NULL COLLATE BINARY UNIQUE,r INTEGER NOT NULL,d TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS shared_object_quota(id INTEGER PRIMARY KEY,r INTEGER NOT NULL,b INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS shared_object_usage(c INTEGER NOT NULL,u BLOB NOT NULL,active_user_id TEXT,r INTEGER NOT NULL,n INTEGER NOT NULL,b INTEGER,
 PRIMARY KEY(c,u),FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL);
CREATE INDEX IF NOT EXISTS idx_shared_v1_usage_user ON shared_object_usage(u,c);
CREATE INDEX IF NOT EXISTS idx_shared_v1_usage_deleted ON shared_object_usage(active_user_id,u,c);
CREATE TABLE IF NOT EXISTS shared_objects(i BLOB PRIMARY KEY,c INTEGER NOT NULL,f INTEGER NOT NULL,q BLOB NOT NULL,o BLOB,g BLOB,r INTEGER NOT NULL,l INTEGER NOT NULL,b INTEGER,
 s0 BLOB,s1 BLOB,s2 BLOB,x INTEGER,y INTEGER,a INTEGER NOT NULL,u INTEGER NOT NULL,e INTEGER);
CREATE INDEX IF NOT EXISTS idx_shared_v1_id_page ON shared_objects(c,q,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_owner_page ON shared_objects(c,q,o,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_slot0_page ON shared_objects(c,q,s0,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_slot1_page ON shared_objects(c,q,s1,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_slot2_page ON shared_objects(c,q,s2,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_position_x_page ON shared_objects(c,q,x,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_expiry ON shared_objects(e,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_deleted_owner ON shared_objects(o,i);
CREATE INDEX IF NOT EXISTS idx_shared_v1_partition_owner ON shared_objects(f,q,i);
CREATE TABLE IF NOT EXISTS shared_object_payloads(i BLOB PRIMARY KEY,r INTEGER NOT NULL,b BLOB NOT NULL,FOREIGN KEY(i) REFERENCES shared_objects(i) ON DELETE CASCADE);
