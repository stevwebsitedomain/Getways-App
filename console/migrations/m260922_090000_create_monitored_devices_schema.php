<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Monitoring schema on MONITORING / app DB (monitored_devices + remote logs + audit).
 * Prefer applying docs/sql/monitoring_schema.sql on MONITORING_DB_NAME.
 * This migration mirrors the same tables when run against Yii's configured DB.
 */
class m260922_090000_create_monitored_devices_schema extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        if ($this->db->getTableSchema('{{%monitored_devices}}', true) === null) {
            $this->createTable('{{%monitored_devices}}', [
                'id' => $this->bigPrimaryKey()->unsigned(),
                'device_id' => $this->string(100)->notNull()->unique(),
                'device_name' => $this->string(150)->notNull(),
                'api_key_hash' => $this->string(255)->notNull(),
                'status' => "ENUM('active','suspended','expired') NOT NULL DEFAULT 'active'",
                'license_expires_at' => $this->dateTime()->null(),
                'daily_search_limit' => $this->integer()->unsigned()->null(),
                'maintenance_mode' => $this->tinyInteger(1)->notNull()->defaultValue(0),
                'maintenance_message' => $this->text()->null(),
                'last_seen_at' => $this->dateTime()->null(),
                'last_ip_address' => $this->string(45)->null(),
                'created_at' => $this->dateTime()->notNull(),
                'updated_at' => $this->dateTime()->notNull(),
            ], $tableOptions);
        }

        if ($this->db->getTableSchema('{{%remote_activity_logs}}', true) === null) {
            $this->createTable('{{%remote_activity_logs}}', [
                'id' => $this->bigPrimaryKey()->unsigned(),
                'device_id' => $this->string(100)->notNull(),
                'local_record_id' => $this->bigInteger()->unsigned()->notNull(),
                'user_id' => $this->bigInteger()->null(),
                'username' => $this->string(100)->null(),
                'action' => $this->string(100)->notNull(),
                'description' => $this->text()->null(),
                'endpoint' => $this->string(255)->null(),
                'request_method' => $this->string(10)->null(),
                'status' => $this->string(50)->null(),
                'results_count' => $this->integer()->null(),
                'ip_address' => $this->string(45)->null(),
                'metadata_json' => 'JSON NULL',
                'occurred_at' => $this->dateTime()->notNull(),
                'received_at' => $this->dateTime()->notNull(),
            ], $tableOptions);
            $this->createIndex('unique_device_log', '{{%remote_activity_logs}}', ['device_id', 'local_record_id'], true);
            $this->createIndex('idx_device_date', '{{%remote_activity_logs}}', ['device_id', 'occurred_at']);
            $this->createIndex('idx_action', '{{%remote_activity_logs}}', ['action']);
        }

        if ($this->db->getTableSchema('{{%device_control_audit}}', true) === null) {
            $this->createTable('{{%device_control_audit}}', [
                'id' => $this->bigPrimaryKey()->unsigned(),
                'device_id' => $this->string(100)->notNull(),
                'admin_user_id' => $this->bigInteger()->null(),
                'old_status' => $this->string(30)->null(),
                'new_status' => $this->string(30)->null(),
                'description' => $this->text()->null(),
                'created_at' => $this->dateTime()->notNull(),
            ], $tableOptions);
            $this->createIndex('idx_audit_device', '{{%device_control_audit}}', ['device_id']);
            $this->createIndex('idx_audit_created', '{{%device_control_audit}}', ['created_at']);
        }
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%device_control_audit}}');
        $this->dropTable('{{%remote_activity_logs}}');
        $this->dropTable('{{%monitored_devices}}');
    }
}
