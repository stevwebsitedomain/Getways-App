<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Monitoring API: devices + activity logs from localhost installations.
 */
class m260921_220000_create_monitoring_tables extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        if ($this->db->getTableSchema('{{%monitoring_devices}}', true) === null) {
            $this->createTable('{{%monitoring_devices}}', [
                'id' => $this->primaryKey(),
                'device_id' => $this->string(64)->notNull()->unique(),
                'device_uuid' => $this->string(64)->null(),
                'api_key_hash' => $this->string(255)->notNull(),
                'status' => $this->string(16)->notNull()->defaultValue('active'),
                'license_expires_at' => $this->dateTime()->null(),
                'daily_search_limit' => $this->integer()->null(),
                'maintenance_mode' => $this->boolean()->notNull()->defaultValue(0),
                'message' => $this->text()->null(),
                'last_seen_at' => $this->dateTime()->null(),
                'created_at' => $this->dateTime()->notNull(),
                'updated_at' => $this->dateTime()->null(),
            ], $tableOptions);
            $this->createIndex('idx_monitoring_devices_status', '{{%monitoring_devices}}', 'status');
        }

        if ($this->db->getTableSchema('{{%monitoring_activity_logs}}', true) === null) {
            $this->createTable('{{%monitoring_activity_logs}}', [
                'id' => $this->primaryKey(),
                'device_id' => $this->string(64)->notNull(),
                'local_id' => $this->bigInteger()->null(),
                'user_id' => $this->string(64)->null(),
                'username' => $this->string(128)->null(),
                'action' => $this->string(64)->null(),
                'description' => $this->text()->null(),
                'endpoint' => $this->string(255)->null(),
                'request_method' => $this->string(16)->null(),
                'status' => $this->string(32)->null(),
                'ip_address' => $this->string(64)->null(),
                'metadata_json' => $this->text()->null(),
                'created_at_client' => $this->string(64)->null(),
                'received_at' => $this->dateTime()->notNull(),
            ], $tableOptions);
            $this->createIndex('idx_monitoring_logs_device', '{{%monitoring_activity_logs}}', 'device_id');
            $this->createIndex('idx_monitoring_logs_action', '{{%monitoring_activity_logs}}', 'action');
            $this->createIndex('idx_monitoring_logs_received', '{{%monitoring_activity_logs}}', 'received_at');
            $this->createIndex(
                'uq_monitoring_logs_device_local',
                '{{%monitoring_activity_logs}}',
                ['device_id', 'local_id'],
                true
            );
        }

        // Seed BOSS-PC-001 (api key hashed; plaintext only in install docs / .env of client)
        $exists = (new \yii\db\Query())
            ->from('{{%monitoring_devices}}')
            ->where(['device_id' => 'BOSS-PC-001'])
            ->exists($this->db);
        if (!$exists) {
            $this->insert('{{%monitoring_devices}}', [
                'device_id' => 'BOSS-PC-001',
                'device_uuid' => null,
                'api_key_hash' => password_hash('qbp385A4STrK6hRattuLqI7NM2peQNVHLACwJ3go', PASSWORD_DEFAULT),
                'status' => 'active',
                'license_expires_at' => '2026-12-31 23:59:59',
                'daily_search_limit' => 100,
                'maintenance_mode' => 0,
                'message' => null,
                'last_seen_at' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public function safeDown(): void
    {
        if ($this->db->getTableSchema('{{%monitoring_activity_logs}}', true) !== null) {
            $this->dropTable('{{%monitoring_activity_logs}}');
        }
        if ($this->db->getTableSchema('{{%monitoring_devices}}', true) !== null) {
            $this->dropTable('{{%monitoring_devices}}');
        }
    }
}
