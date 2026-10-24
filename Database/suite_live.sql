-- NEW API-owned schema only. Never apply to a company/source EAI schema.
CREATE TABLE api_suite_state (
 tenant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
 captured_utc DATETIME NULL,
 bundle_json MEDIUMTEXT NULL,
 bundle_sha256 CHAR(64) CHARACTER SET ascii NULL,
 scope_json MEDIUMTEXT NULL,
 scope_sha256 CHAR(64) CHARACTER SET ascii NULL,
 cursors_json TEXT NOT NULL,
 in_flight TINYINT NOT NULL DEFAULT 0,
 last_attempt_utc DATETIME NULL,
 status VARCHAR(64) CHARACTER SET ascii NOT NULL
) ENGINE=InnoDB;
CREATE TABLE api_suite_snapshots (
 tenant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 endpoint VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload_json MEDIUMTEXT NOT NULL,
 payload_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
 PRIMARY KEY(tenant_key,endpoint)
) ENGINE=InnoDB;
CREATE TABLE api_suite_feeds (
 tenant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 endpoint VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 last_present_utc DATETIME NOT NULL,
 first_present_utc DATETIME NOT NULL,
 PRIMARY KEY(tenant_key,endpoint)
) ENGINE=InnoDB;
CREATE TABLE api_suite_history (
 tenant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 endpoint VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 record_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 vehicle_id INT NOT NULL,
 measured_at DATETIME NOT NULL,
 payload_json TEXT NOT NULL,
 payload_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
 PRIMARY KEY(tenant_key,endpoint,record_id),
 KEY ix_suite_vehicle_time(tenant_key,endpoint,vehicle_id,measured_at,record_id)
) ENGINE=InnoDB;
-- Explicitly requested historical-page discovery uses independent cursors.
-- Empty pages do NOT establish completion or upstream retention.
CREATE TABLE api_suite_backfill_state (
 tenant_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 cursors_json TEXT NOT NULL,
 pages_accepted BIGINT UNSIGNED NOT NULL DEFAULT 0,
 first_capture_utc DATETIME NULL,
 last_capture_utc DATETIME NULL,
 last_attempt_utc DATETIME NULL,
 last_page_counts_json TEXT NOT NULL,
 complete_history_verified TINYINT NOT NULL DEFAULT 0,
 status VARCHAR(64) CHARACTER SET ascii NOT NULL
) ENGINE=InnoDB;
