<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Payout v2: audit logs, emergency stop, approval thresholds, extra payout columns.
 */
class m260820_130000_clickpesa_payout_v2 extends Migration
{
    public function safeUp(): void
    {
        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $setting = '{{%clickpesa_setting}}';
        $settingSchema = $this->db->getTableSchema($setting, true);
        if ($settingSchema !== null) {
            if ($settingSchema->getColumn('emergency_stop') === null) {
                $this->addColumn($setting, 'emergency_stop', $this->boolean()->notNull()->defaultValue(0)->after('auto_payout_enabled'));
            }
            if ($settingSchema->getColumn('maximum_amount') === null) {
                $this->addColumn($setting, 'maximum_amount', $this->decimal(18, 2)->notNull()->defaultValue(0)->after('minimum_amount'));
            }
            if ($settingSchema->getColumn('manual_approval_threshold') === null) {
                $this->addColumn($setting, 'manual_approval_threshold', $this->decimal(18, 2)->notNull()->defaultValue(0)->after('maximum_amount'));
            }
            if ($settingSchema->getColumn('last_changed_by') === null) {
                $this->addColumn($setting, 'last_changed_by', $this->integer()->null()->after('last_synced_at'));
            }
        }

        $payout = '{{%clickpesa_payout}}';
        $payoutSchema = $this->db->getTableSchema($payout, true);
        if ($payoutSchema !== null) {
            // Manual payouts may not link to a collection transaction.
            try {
                $this->alterColumn($payout, 'payment_id', $this->integer()->null());
            } catch (\Throwable) {
            }

            $columns = [
                'phone_number' => $this->string(16)->null()->after('destination_masked'),
                'total_deduction' => $this->decimal(18, 2)->null()->after('fee'),
                'clickpesa_payout_id' => $this->string(128)->null()->after('payout_reference'),
                'channel' => $this->string(32)->null()->after('provider'),
                'failure_reason' => $this->text()->null()->after('last_error'),
                'initiated_by' => $this->string(64)->null()->after('raw_response'),
                'approved_by' => $this->integer()->null()->after('initiated_by'),
                'approved_at' => $this->integer()->null()->after('approved_by'),
                'completed_at' => $this->integer()->null()->after('processed_at'),
                'preview_token' => $this->string(64)->null()->after('approved_at'),
                'internal_note' => $this->text()->null()->after('preview_token'),
            ];
            foreach ($columns as $name => $type) {
                if ($payoutSchema->getColumn($name) === null) {
                    $this->addColumn($payout, $name, $type);
                }
            }

            try {
                $this->createIndex('idx_clickpesa_payout_phone', $payout, 'phone_number');
            } catch (\Throwable) {
            }
            try {
                $this->createIndex('idx_clickpesa_payout_created_at', $payout, 'created_at');
            } catch (\Throwable) {
            }
        }

        if ($this->db->getTableSchema('{{%clickpesa_payout_audit_log}}', true) === null) {
            $this->createTable('{{%clickpesa_payout_audit_log}}', [
                'id' => $this->primaryKey(),
                'payout_id' => $this->integer()->notNull(),
                'event' => $this->string(64)->notNull(),
                'old_status' => $this->string(32)->null(),
                'new_status' => $this->string(32)->null(),
                'actor_type' => $this->string(32)->notNull()->defaultValue('system'),
                'actor_id' => $this->integer()->null(),
                'metadata_safe' => $this->text()->null(),
                'ip_address' => $this->string(64)->null(),
                'user_agent' => $this->string(255)->null(),
                'created_at' => $this->integer()->notNull(),
            ], $tableOptions);

            $this->createIndex('idx_payout_audit_payout_id', '{{%clickpesa_payout_audit_log}}', 'payout_id');
            $this->createIndex('idx_payout_audit_event', '{{%clickpesa_payout_audit_log}}', 'event');
            $this->createIndex('idx_payout_audit_created_at', '{{%clickpesa_payout_audit_log}}', 'created_at');
            $this->addForeignKey(
                'fk_payout_audit_payout',
                '{{%clickpesa_payout_audit_log}}',
                'payout_id',
                '{{%clickpesa_payout}}',
                'id',
                'CASCADE',
                'CASCADE'
            );
        }
    }

    public function safeDown(): void
    {
        if ($this->db->getTableSchema('{{%clickpesa_payout_audit_log}}', true) !== null) {
            $this->dropTable('{{%clickpesa_payout_audit_log}}');
        }

        $payout = '{{%clickpesa_payout}}';
        $payoutSchema = $this->db->getTableSchema($payout, true);
        if ($payoutSchema !== null) {
            foreach ([
                'idx_clickpesa_payout_phone',
                'idx_clickpesa_payout_created_at',
            ] as $index) {
                try {
                    $this->dropIndex($index, $payout);
                } catch (\Throwable) {
                }
            }
            foreach ([
                'internal_note', 'preview_token', 'completed_at', 'approved_at', 'approved_by',
                'initiated_by', 'failure_reason', 'channel', 'clickpesa_payout_id',
                'total_deduction', 'phone_number',
            ] as $column) {
                if ($payoutSchema->getColumn($column) !== null) {
                    $this->dropColumn($payout, $column);
                }
            }
        }

        $setting = '{{%clickpesa_setting}}';
        $settingSchema = $this->db->getTableSchema($setting, true);
        if ($settingSchema !== null) {
            foreach (['last_changed_by', 'manual_approval_threshold', 'maximum_amount', 'emergency_stop'] as $column) {
                if ($settingSchema->getColumn($column) !== null) {
                    $this->dropColumn($setting, $column);
                }
            }
        }
    }
}
