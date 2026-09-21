-- Monitoring API tables (ACS Portal ↔ localhost installations)
-- Safe to re-run: uses IF NOT EXISTS / insert-if-missing pattern via app seed.

CREATE TABLE IF NOT EXISTS monitoring_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  device_uuid VARCHAR(64) NULL,
  api_key_hash VARCHAR(255) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  license_expires_at DATETIME NULL,
  daily_search_limit INT NULL,
  maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
  message TEXT NULL,
  last_seen_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY uq_monitoring_device_id (device_id),
  KEY idx_monitoring_devices_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id VARCHAR(64) NOT NULL,
  local_id BIGINT NULL,
  user_id VARCHAR(64) NULL,
  username VARCHAR(128) NULL,
  action VARCHAR(64) NULL,
  description TEXT NULL,
  endpoint VARCHAR(255) NULL,
  request_method VARCHAR(16) NULL,
  status VARCHAR(32) NULL,
  ip_address VARCHAR(64) NULL,
  metadata_json MEDIUMTEXT NULL,
  created_at_client VARCHAR(64) NULL,
  received_at DATETIME NOT NULL,
  UNIQUE KEY uq_monitoring_logs_device_local (device_id, local_id),
  KEY idx_monitoring_logs_device (device_id),
  KEY idx_monitoring_logs_action (action),
  KEY idx_monitoring_logs_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed BOSS-PC-001: prefer app auto-seed (password_hash) via first API hit,
-- or Yii migration m260921_220000_create_monitoring_tables.
-- Do NOT store plaintext API keys in SQL committed to git.
