<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds dashboard sync support, stronger uniqueness, and richer payout settings.
 */
class m260720_121500_clickpesa_dashboard_support extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $tx = '{{%clickpesa_transactions}}';
        $txSchema = $this->db->getTableSchema($tx, true);
        if ($txSchema !== null) {
            if ($txSchema->getColumn('statement_date') === null) {
                $this->addColumn($tx, 'statement_date', $this->string(32)->null()->after('description'));
            }
            if ($txSchema->getColumn('statement_balance') === null) {
                $this->addColumn($tx, 'statement_balance', $this->decimal(18, 2)->null()->after('received_amount'));
            }
            if ($txSchema->getColumn('sync_source') === null) {
                $this->addColumn($tx, 'sync_source', $this->string(32)->null()->after('channel'));
            }
            if ($txSchema->getColumn('last_synced_at') === null) {
                $this->addColumn($tx, 'last_synced_at', $this->integer()->null()->after('paid_at'));
            }

            try {
                $this->createIndex('uniq_clickpesa_transaction_cp_id', $tx, 'clickpesa_transaction_id', true);
            } catch (\Throwable) {
            }
            try {
                $this->createIndex('idx_clickpesa_statement_date', $tx, 'statement_date');
            } catch (\Throwable) {
            }
            try {
                $this->createIndex('idx_clickpesa_sync_source', $tx, 'sync_source');
            } catch (\Throwable) {
            }
        }

        $setting = '{{%clickpesa_setting}}';
        $settingSchema = $this->db->getTableSchema($setting, true);
        if ($settingSchema !== null) {
            if ($settingSchema->getColumn('mode') === null) {
                $this->addColumn($setting, 'mode', $this->string(32)->notNull()->defaultValue('TEST')->after('auto_payout_enabled'));
            }
            if ($settingSchema->getColumn('mobile_provider') === null) {
                $this->addColumn($setting, 'mobile_provider', $this->string(64)->null()->after('encrypted_destination'));
            }
            if ($settingSchema->getColumn('bank_bic_swift') === null) {
                $this->addColumn($setting, 'bank_bic_swift', $this->string(64)->null()->after('bank_name'));
            }
            if ($settingSchema->getColumn('last_synced_at') === null) {
                $this->addColumn($setting, 'last_synced_at', $this->integer()->null()->after('require_manual_approval'));
            }
        }

        if ($this->db->getTableSchema('{{%clickpesa_sync_log}}', true) === null) {
            $this->createTable('{{%clickpesa_sync_log}}', [
                'id' => $this->primaryKey(),
                'sync_type' => $this->string(64)->notNull(),
                'status' => $this->string(32)->notNull()->defaultValue('SUCCESS'),
                'records_inserted' => $this->integer()->notNull()->defaultValue(0),
                'records_updated' => $this->integer()->notNull()->defaultValue(0),
                'records_skipped' => $this->integer()->notNull()->defaultValue(0),
                'message' => $this->text()->null(),
                'metadata_json' => $this->text()->null(),
                'synced_at' => $this->integer()->null(),
                'created_at' => $this->integer()->notNull(),
                'updated_at' => $this->integer()->notNull(),
            ], $tableOptions);

            $this->createIndex('idx_clickpesa_sync_log_type', '{{%clickpesa_sync_log}}', 'sync_type');
            $this->createIndex('idx_clickpesa_sync_log_synced_at', '{{%clickpesa_sync_log}}', 'synced_at');
        }
    }

    public function safeDown(): void
    {
        if ($this->db->getTableSchema('{{%clickpesa_sync_log}}', true) !== null) {
            $this->dropTable('{{%clickpesa_sync_log}}');
        }

        $setting = '{{%clickpesa_setting}}';
        $settingSchema = $this->db->getTableSchema($setting, true);
        if ($settingSchema !== null) {
            foreach (['last_synced_at', 'bank_bic_swift', 'mobile_provider', 'mode'] as $column) {
                if ($settingSchema->getColumn($column) !== null) {
                    $this->dropColumn($setting, $column);
                }
            }
        }

        $tx = '{{%clickpesa_transactions}}';
        $txSchema = $this->db->getTableSchema($tx, true);
        if ($txSchema !== null) {
            foreach ([
                'idx_clickpesa_sync_source',
                'idx_clickpesa_statement_date',
                'uniq_clickpesa_transaction_cp_id',
            ] as $index) {
                try {
                    $this->dropIndex($index, $tx);
                } catch (\Throwable) {
                }
            }

            foreach (['last_synced_at', 'sync_source', 'statement_balance', 'statement_date'] as $column) {
                if ($txSchema->getColumn($column) !== null) {
                    $this->dropColumn($tx, $column);
                }
            }
        }
    }
}
