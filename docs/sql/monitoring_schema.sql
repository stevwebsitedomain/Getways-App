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

-- Columns added on existing installs by monitoring-api/_lib.php monEnsureColumn():
-- control_mode, blocked_message, control_version, sms_alerts_enabled,
-- daily_download_limit, last_sync_at, last_command_received_at, last_command_applied_at
-- device_control_audit.field_name, old_value, new_value

CREATE TABLE IF NOT EXISTS remote_searches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    local_search_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT NULL,
    username VARCHAR(100) NULL,
    search_term TEXT NOT NULL,
    search_type VARCHAR(100) NULL,
    filters_json JSON NULL,
    results_count INT UNSIGNED DEFAULT 0,
    status ENUM('started','completed','failed') DEFAULT 'started',
    error_message TEXT NULL,
    searched_at DATETIME NOT NULL,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_remote_search (device_id, local_search_id),
    INDEX idx_device_date (device_id, searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_search_results (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    local_search_id BIGINT UNSIGNED NOT NULL,
    local_result_id BIGINT UNSIGNED NOT NULL,
    result_type VARCHAR(100) NULL,
    title VARCHAR(255) NULL,
    username VARCHAR(255) NULL,
    full_name VARCHAR(255) NULL,
    phone VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    location VARCHAR(255) NULL,
    profile_url TEXT NULL,
    website_url TEXT NULL,
    description TEXT NULL,
    result_data_json JSON NULL,
    created_at DATETIME NOT NULL,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_remote_result (device_id, local_search_id, local_result_id),
    INDEX idx_search (device_id, local_search_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_downloads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    local_download_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT NULL,
    username VARCHAR(100) NULL,
    search_id BIGINT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NULL,
    file_size BIGINT NULL,
    rows_count INT UNSIGNED NULL,
    download_type VARCHAR(100) NULL,
    source_description TEXT NULL,
    downloaded_records_json JSON NULL,
    downloaded_at DATETIME NOT NULL,
    received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_remote_download (device_id, local_download_id),
    INDEX idx_device_download_date (device_id, downloaded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_alert_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    remote_search_id BIGINT UNSIGNED NULL,
    recipient VARCHAR(30) NOT NULL,
    message TEXT NOT NULL,
    provider_message_id VARCHAR(255) NULL,
    delivery_status VARCHAR(50) DEFAULT 'pending',
    provider_response TEXT NULL,
    error_message TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    UNIQUE KEY unique_search_sms (device_id, remote_search_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
