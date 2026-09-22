-- Monitoring schema for ACS Portal ↔ localhost installations (Tra-Masaki)
-- Target database: MONITORING_DB_NAME (e.g. reacrisc_tra_masaki)
-- Safe to re-run: IF NOT EXISTS. Do NOT store plaintext API keys here.

CREATE TABLE IF NOT EXISTS monitored_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL UNIQUE,
    device_name VARCHAR(150) NOT NULL,
    api_key_hash VARCHAR(255) NOT NULL,
    status ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
    license_expires_at DATETIME NULL,
    daily_search_limit INT UNSIGNED NULL,
    maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
    maintenance_message TEXT NULL,
    last_seen_at DATETIME NULL,
    last_ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_activity_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    local_record_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT NULL,
    username VARCHAR(100) NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT NULL,
    endpoint VARCHAR(255) NULL,
    request_method VARCHAR(10) NULL,
    status VARCHAR(50) NULL,
    results_count INT NULL,
    ip_address VARCHAR(45) NULL,
    metadata_json JSON NULL,
    occurred_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_device_log (device_id, local_record_id),
    INDEX idx_device_date (device_id, occurred_at),
    INDEX idx_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_control_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    admin_user_id BIGINT NULL,
    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_device (device_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed device via: php scripts/seed-monitoring-device.php
-- Plain API key is printed once by that script and never stored in SQL/git.
