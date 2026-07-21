<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates clickpesa_transactions table for ClickPesa collection + payout tracking.
 */
class m260716_150000_create_clickpesa_transactions_table extends Migration
{
    public function safeUp(): void
    {
        if ($this->db->getTableSchema('{{%clickpesa_transactions}}', true) !== null) {
            return;
        }

        $tableOptions = null;
        if ($this->db->driverName === 'mysql') {
            $tableOptions = 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB';
        }

        $this->createTable('{{%clickpesa_transactions}}', [
            'id' => $this->primaryKey(),
            'order_reference' => $this->string(64)->notNull(),
            'control_number' => $this->string(64)->null(),
            'amount' => $this->decimal(18, 2)->notNull()->defaultValue(0),
            'currency' => $this->string(8)->notNull()->defaultValue('TZS'),
            'phone' => $this->string(32)->null(),
            'customer_name' => $this->string(255)->null(),
            'description' => $this->string(512)->null(),
            'payment_status' => $this->string(32)->notNull()->defaultValue('PENDING'),
            'payout_status' => $this->string(32)->null(),
            'payout_reference' => $this->string(64)->null(),
            'payout_phone' => $this->string(32)->null(),
            'payout_amount' => $this->decimal(18, 2)->null(),
            'transaction_type' => $this->string(32)->notNull()->defaultValue('collection'),
            'event_type' => $this->string(64)->null(),
            'channel' => $this->string(64)->null(),
            'checksum' => $this->string(128)->null(),
            'raw_payload' => $this->text()->null(),
            'payout_payload' => $this->text()->null(),
            'created_at' => $this->integer()->notNull(),
            'updated_at' => $this->integer()->notNull(),
        ], $tableOptions);

        $this->createIndex(
            'uniq_clickpesa_order_reference',
            '{{%clickpesa_transactions}}',
            'order_reference',
            true
        );
        $this->createIndex(
            'idx_clickpesa_control_number',
            '{{%clickpesa_transactions}}',
            'control_number'
        );
        $this->createIndex(
            'idx_clickpesa_payment_status',
            '{{%clickpesa_transactions}}',
            'payment_status'
        );
        $this->createIndex(
            'idx_clickpesa_payout_reference',
            '{{%clickpesa_transactions}}',
            'payout_reference'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%clickpesa_transactions}}');
    }
}
