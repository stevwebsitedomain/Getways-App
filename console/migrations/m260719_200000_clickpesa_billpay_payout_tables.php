<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Extends ClickPesa BillPay + safe automatic payout schema.
 * Reuses clickpesa_transactions; adds payout, webhook, settings, audit tables.
 */
class m260719_200000_clickpesa_billpay_payout_tables extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $tx = '{{%clickpesa_transactions}}';
        $schema = $this->db->getTableSchema($tx, true);
        if ($schema !== null) {
            if ($schema->getColumn('order_id') === null) {
                $this->addColumn($tx, 'order_id', $this->string(64)->null()->after('id'));
            }
            if ($schema->getColumn('expected_amount') === null) {
                $this->addColumn($tx, 'expected_amount', $this->decimal(18, 2)->null()->after('amount'));
            }
            if ($schema->getColumn('received_amount') === null) {
                $this->addColumn($tx, 'received_amount', $this->decimal(18, 2)->null()->after('expected_amount'));
            }
            if ($schema->getColumn('payment_mode') === null) {
                $this->addColumn($tx, 'payment_mode', $this->string(64)->null()->defaultValue('EXACT')->after('currency'));
            }
            if ($schema->getColumn('clickpesa_transaction_id') === null) {
                $this->addColumn($tx, 'clickpesa_transaction_id', $this->string(128)->null()->after('payment_status'));
            }
            if ($schema->getColumn('raw_request') === null) {
                $this->addColumn($tx, 'raw_request', $this->text()->null()->after('checksum'));
            }
            if ($schema->getColumn('paid_at') === null) {
                $this->addColumn($tx, 'paid_at', $this->integer()->null()->after('raw_payload'));
            }
            if ($schema->getColumn('user_id') === null) {
                $this->addColumn($tx, 'user_id', $this->integer()->null()->after('order_id'));
            }
            if ($schema->getColumn('inventory_updated') === null) {
                $this->addColumn($tx, 'inventory_updated', $this->boolean()->notNull()->defaultValue(0)->after('paid_at'));
            }

            try {
                $this->createIndex('idx_clickpesa_order_id', $tx, 'order_id');
            } catch (\Throwable) {
            }
            try {
                $this->createIndex('idx_clickpesa_user_id', $tx, 'user_id');
            } catch (\Throwable) {
            }
        }

        if ($this->db->getTableSchema('{{%clickpesa_payout}}', true) === null) {
            $this->createTable('{{%clickpesa_payout}}', [
                'id' => $this->primaryKey(),
                'payment_id' => $this->integer()->notNull(),
                'payout_reference' => $this->string(64)->notNull(),
                'destination_type' => $this->string(32)->notNull()->defaultValue('MOBILE_MONEY'),
                'destination_masked' => $this->string(32)->null(),
                'amount' => $this->decimal(18, 2)->notNull(),
                'fee' => $this->decimal(18, 2)->null(),
                'currency' => $this->string(8)->notNull()->defaultValue('TZS'),
                'provider' => $this->string(64)->null(),
                'payout_status' => $this->string(32)->notNull()->defaultValue('QUEUED'),
                'retry_count' => $this->integer()->notNull()->defaultValue(0),
                'last_error' => $this->text()->null(),
                'raw_request' => $this->text()->null(),
                'raw_response' => $this->text()->null(),
                'processed_at' => $this->integer()->null(),
                'next_retry_at' => $this->integer()->null(),
                'created_at' => $this->integer()->notNull(),
                'updated_at' => $this->integer()->notNull(),
            ], $tableOptions);

            $this->createIndex('uniq_clickpesa_payout_reference', '{{%clickpesa_payout}}', 'payout_reference', true);
            $this->createIndex('uniq_clickpesa_payout_payment_id', '{{%clickpesa_payout}}', 'payment_id', true);
            $this->createIndex('idx_clickpesa_payout_status', '{{%clickpesa_payout}}', 'payout_status');
            $this->addForeignKey(
                'fk_clickpesa_payout_payment',
                '{{%clickpesa_payout}}',
                'payment_id',
                '{{%clickpesa_transactions}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
        }

        if ($this->db->getTableSchema('{{%clickpesa_webhook_event}}', true) === null) {
            $this->createTable('{{%clickpesa_webhook_event}}', [
                'id' => $this->primaryKey(),
                'event_hash' => $this->string(64)->notNull(),
                'event_type' => $this->string(64)->null(),
                'reference' => $this->string(128)->null(),
                'signature_valid' => $this->boolean()->notNull()->defaultValue(0),
                'payload' => $this->text()->notNull(),
                'processing_status' => $this->string(32)->notNull()->defaultValue('RECEIVED'),
                'processed_at' => $this->integer()->null(),
                'created_at' => $this->integer()->notNull(),
            ], $tableOptions);

            $this->createIndex('uniq_clickpesa_webhook_event_hash', '{{%clickpesa_webhook_event}}', 'event_hash', true);
            $this->createIndex('idx_clickpesa_webhook_status', '{{%clickpesa_webhook_event}}', 'processing_status');
        }

        if ($this->db->getTableSchema('{{%clickpesa_setting}}', true) === null) {
            $this->createTable('{{%clickpesa_setting}}', [
                'id' => $this->primaryKey(),
                'auto_payout_enabled' => $this->boolean()->notNull()->defaultValue(0),
                'destination_type' => $this->string(32)->notNull()->defaultValue('MOBILE_MONEY'),
                'encrypted_destination' => $this->text()->null(),
                'bank_account_name' => $this->string(255)->null(),
                'bank_account_number_enc' => $this->text()->null(),
                'bank_name' => $this->string(128)->null(),
                'payout_percentage' => $this->decimal(5, 2)->notNull()->defaultValue(100),
                'minimum_amount' => $this->decimal(18, 2)->notNull()->defaultValue(1000),
                'daily_limit' => $this->decimal(18, 2)->notNull()->defaultValue(0),
                'delay_seconds' => $this->integer()->notNull()->defaultValue(60),
                'require_manual_approval' => $this->boolean()->notNull()->defaultValue(1),
                'created_at' => $this->integer()->notNull(),
                'updated_at' => $this->integer()->notNull(),
            ], $tableOptions);
        }

        if ($this->db->getTableSchema('{{%clickpesa_setting_audit}}', true) === null) {
            $this->createTable('{{%clickpesa_setting_audit}}', [
                'id' => $this->primaryKey(),
                'user_id' => $this->integer()->null(),
                'action' => $this->string(64)->notNull(),
                'changes_json' => $this->text()->null(),
                'ip_address' => $this->string(64)->null(),
                'created_at' => $this->integer()->notNull(),
            ], $tableOptions);
        }
    }

    public function safeDown(): void
    {
        if ($this->db->getTableSchema('{{%clickpesa_setting_audit}}', true) !== null) {
            $this->dropTable('{{%clickpesa_setting_audit}}');
        }
        if ($this->db->getTableSchema('{{%clickpesa_setting}}', true) !== null) {
            $this->dropTable('{{%clickpesa_setting}}');
        }
        if ($this->db->getTableSchema('{{%clickpesa_webhook_event}}', true) !== null) {
            $this->dropTable('{{%clickpesa_webhook_event}}');
        }
        if ($this->db->getTableSchema('{{%clickpesa_payout}}', true) !== null) {
            $this->dropTable('{{%clickpesa_payout}}');
        }

        $tx = '{{%clickpesa_transactions}}';
        $schema = $this->db->getTableSchema($tx, true);
        if ($schema === null) {
            return;
        }

        foreach ([
            'idx_clickpesa_user_id',
            'idx_clickpesa_order_id',
        ] as $index) {
            try {
                $this->dropIndex($index, $tx);
            } catch (\Throwable) {
                // index may not exist
            }
        }

        foreach ([
            'inventory_updated',
            'paid_at',
            'raw_request',
            'clickpesa_transaction_id',
            'payment_mode',
            'received_amount',
            'expected_amount',
            'user_id',
            'order_id',
        ] as $column) {
            if ($schema->getColumn($column) !== null) {
                $this->dropColumn($tx, $column);
            }
        }
    }
}
