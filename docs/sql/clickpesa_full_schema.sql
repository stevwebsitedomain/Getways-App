-- =============================================================================
-- Getway / ClickPesa — FULL DATABASE SETUP (XAMPP / phpMyAdmin)
-- =============================================================================
-- 1) Start MySQL in XAMPP Control Panel (must be green / Running)
-- 2) Open phpMyAdmin → SQL tab → paste & run this whole file
-- 3) Or: mysql -uroot < docs/sql/clickpesa_full_schema.sql
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `tis_clickpesa`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `tis_clickpesa`;

-- -----------------------------------------------------------------------------
-- 1) Collections / BillPay control numbers
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clickpesa_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` varchar(64) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `order_reference` varchar(64) NOT NULL,
  `control_number` varchar(64) DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `expected_amount` decimal(18,2) DEFAULT NULL,
  `received_amount` decimal(18,2) DEFAULT NULL,
  `statement_balance` decimal(18,2) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'TZS',
  `payment_mode` varchar(64) DEFAULT 'EXACT',
  `phone` varchar(32) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `description` varchar(512) DEFAULT NULL,
  `statement_date` varchar(32) DEFAULT NULL,
  `payment_status` varchar(32) NOT NULL DEFAULT 'PENDING',
  `clickpesa_transaction_id` varchar(128) DEFAULT NULL,
  `payout_status` varchar(32) DEFAULT NULL,
  `payout_reference` varchar(64) DEFAULT NULL,
  `payout_phone` varchar(32) DEFAULT NULL,
  `payout_amount` decimal(18,2) DEFAULT NULL,
  `transaction_type` varchar(32) NOT NULL DEFAULT 'collection',
  `event_type` varchar(64) DEFAULT NULL,
  `channel` varchar(64) DEFAULT NULL,
  `sync_source` varchar(32) DEFAULT NULL,
  `checksum` varchar(128) DEFAULT NULL,
  `raw_request` text DEFAULT NULL,
  `raw_payload` text DEFAULT NULL,
  `paid_at` int(11) DEFAULT NULL,
  `last_synced_at` int(11) DEFAULT NULL,
  `inventory_updated` tinyint(1) NOT NULL DEFAULT 0,
  `payout_payload` text DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clickpesa_order_reference` (`order_reference`),
  UNIQUE KEY `uniq_clickpesa_transaction_cp_id` (`clickpesa_transaction_id`),
  KEY `idx_clickpesa_payment_status` (`payment_status`),
  KEY `idx_clickpesa_order_id` (`order_id`),
  KEY `idx_clickpesa_user_id` (`user_id`),
  KEY `idx_clickpesa_control_number` (`control_number`),
  KEY `idx_clickpesa_statement_date` (`statement_date`),
  KEY `idx_clickpesa_sync_source` (`sync_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2) Automatic payouts (one row per paid collection)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clickpesa_payout` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `payout_reference` varchar(64) NOT NULL,
  `destination_type` varchar(32) NOT NULL DEFAULT 'MOBILE_MONEY',
  `destination_masked` varchar(32) DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL,
  `fee` decimal(18,2) DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'TZS',
  `provider` varchar(64) DEFAULT NULL,
  `payout_status` varchar(32) NOT NULL DEFAULT 'QUEUED',
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `raw_request` text DEFAULT NULL,
  `raw_response` text DEFAULT NULL,
  `processed_at` int(11) DEFAULT NULL,
  `next_retry_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clickpesa_payout_reference` (`payout_reference`),
  UNIQUE KEY `uniq_clickpesa_payout_payment_id` (`payment_id`),
  KEY `idx_clickpesa_payout_status` (`payout_status`),
  CONSTRAINT `fk_clickpesa_payout_payment` FOREIGN KEY (`payment_id`) REFERENCES `clickpesa_transactions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3) Webhook audit log (idempotent processing)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clickpesa_webhook_event` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_hash` varchar(64) NOT NULL,
  `event_type` varchar(64) DEFAULT NULL,
  `reference` varchar(128) DEFAULT NULL,
  `signature_valid` tinyint(1) NOT NULL DEFAULT 0,
  `payload` text NOT NULL,
  `processing_status` varchar(32) NOT NULL DEFAULT 'RECEIVED',
  `processed_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clickpesa_webhook_event_hash` (`event_hash`),
  KEY `idx_clickpesa_webhook_status` (`processing_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4) Payout settings (auto payout OFF by default = TEST mode)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clickpesa_setting` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auto_payout_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mode` varchar(32) NOT NULL DEFAULT 'TEST',
  `destination_type` varchar(32) NOT NULL DEFAULT 'MOBILE_MONEY',
  `encrypted_destination` text DEFAULT NULL,
  `mobile_provider` varchar(64) DEFAULT NULL,
  `bank_account_name` varchar(255) DEFAULT NULL,
  `bank_account_number_enc` text DEFAULT NULL,
  `bank_name` varchar(128) DEFAULT NULL,
  `bank_bic_swift` varchar(64) DEFAULT NULL,
  `payout_percentage` decimal(5,2) NOT NULL DEFAULT 100.00,
  `minimum_amount` decimal(18,2) NOT NULL DEFAULT 1000.00,
  `daily_limit` decimal(18,2) NOT NULL DEFAULT 0.00,
  `delay_seconds` int(11) NOT NULL DEFAULT 60,
  `require_manual_approval` tinyint(1) NOT NULL DEFAULT 1,
  `last_synced_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4b) Sync log (account statement sync)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clickpesa_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sync_type` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'SUCCESS',
  `records_inserted` int(11) NOT NULL DEFAULT 0,
  `records_updated` int(11) NOT NULL DEFAULT 0,
  `records_skipped` int(11) NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `metadata_json` text DEFAULT NULL,
  `synced_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_clickpesa_sync_log_type` (`sync_type`),
  KEY `idx_clickpesa_sync_log_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5) Settings change audit
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clickpesa_setting_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `changes_json` text DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Default settings row (phone 255715296092 — encrypted by PHP on first save)
-- Run after import:  php scripts/seed-clickpesa-settings.php
-- -----------------------------------------------------------------------------
INSERT INTO `clickpesa_setting` (
  `auto_payout_enabled`, `mode`, `destination_type`, `encrypted_destination`,
  `payout_percentage`, `minimum_amount`, `daily_limit`, `delay_seconds`,
  `require_manual_approval`, `created_at`, `updated_at`
)
SELECT 0, 'TEST', 'MOBILE_MONEY', NULL, 100.00, 1000.00, 0.00, 60, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `clickpesa_setting` LIMIT 1);

-- -----------------------------------------------------------------------------
-- Yii migration tracking (optional — if you use php yii migrate)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `migration` (
  `version` varchar(180) NOT NULL,
  `apply_time` int(11) DEFAULT NULL,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `migration` (`version`, `apply_time`) VALUES
  ('m130524_201442_init', UNIX_TIMESTAMP()),
  ('m190124_110200_add_verification_token_column_to_user_table', UNIX_TIMESTAMP()),
  ('m260716_150000_create_clickpesa_transactions_table', UNIX_TIMESTAMP()),
  ('m260719_200000_clickpesa_billpay_payout_tables', UNIX_TIMESTAMP()),
  ('m260720_121500_clickpesa_dashboard_support', UNIX_TIMESTAMP());
