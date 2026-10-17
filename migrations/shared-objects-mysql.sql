CREATE TABLE IF NOT EXISTS shared_object_catalog(id INT PRIMARY KEY,r BIGINT NOT NULL,n INT NOT NULL,s BIGINT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS shared_object_collections(c INT PRIMARY KEY,k VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,r BIGINT NOT NULL,d TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_object_quota(id INT PRIMARY KEY,r BIGINT NOT NULL,b BIGINT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS shared_object_usage(c INT NOT NULL,u BINARY(16) NOT NULL,active_user_id CHAR(36) NULL,r BIGINT NOT NULL,n BIGINT NOT NULL,b BIGINT NULL,
 PRIMARY KEY(c,u),INDEX idx_shared_v1_usage_user(u,c),INDEX idx_shared_v1_usage_deleted(active_user_id,u,c),FOREIGN KEY(active_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shared_objects(i BINARY(16) PRIMARY KEY,c INT NOT NULL,f SMALLINT NOT NULL,q VARBINARY(16) NOT NULL,o BINARY(16) NULL,g BINARY(16) NULL,r BIGINT NOT NULL,l INT NOT NULL,b BIGINT NULL,
 s0 VARBINARY(96) NULL,s1 VARBINARY(96) NULL,s2 VARBINARY(96) NULL,x BIGINT NULL,y BIGINT NULL,a BIGINT NOT NULL,u BIGINT NOT NULL,e BIGINT NULL,
 INDEX idx_shared_v1_id_page(c,q,i),INDEX idx_shared_v1_owner_page(c,q,o,i),INDEX idx_shared_v1_slot0_page(c,q,s0,i),INDEX idx_shared_v1_slot1_page(c,q,s1,i),INDEX idx_shared_v1_slot2_page(c,q,s2,i),
 INDEX idx_shared_v1_position_x_page(c,q,x,i),INDEX idx_shared_v1_expiry(e,i),INDEX idx_shared_v1_deleted_owner(o,i),INDEX idx_shared_v1_partition_owner(f,q,i)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS shared_object_payloads(i BINARY(16) PRIMARY KEY,r BIGINT NOT NULL,b MEDIUMBLOB NOT NULL,FOREIGN KEY(i) REFERENCES shared_objects(i) ON DELETE CASCADE) ENGINE=InnoDB;
