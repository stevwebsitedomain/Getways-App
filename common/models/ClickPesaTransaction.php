<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * ClickPesa collection / BillPay transaction.
 *
 * @property int $id
 * @property string|null $order_id
 * @property int|null $user_id
 * @property string $order_reference
 * @property string|null $control_number
 * @property string $amount
 * @property string|null $expected_amount
 * @property string|null $received_amount
 * @property string $currency
 * @property string|null $payment_mode
 * @property string|null $phone
 * @property string|null $customer_name
 * @property string|null $description
 * @property string|null $statement_date
 * @property string $payment_status
 * @property string|null $clickpesa_transaction_id
 * @property string|null $statement_balance
 * @property string|null $payout_status
 * @property string|null $payout_reference
 * @property string|null $payout_phone
 * @property string|null $payout_amount
 * @property string $transaction_type
 * @property string|null $event_type
 * @property string|null $channel
 * @property string|null $sync_source
 * @property string|null $checksum
 * @property string|null $raw_request
 * @property string|null $raw_payload
 * @property string|null $payout_payload
 * @property int|null $paid_at
 * @property int|null $last_synced_at
 * @property int $inventory_updated
 * @property int $created_at
 * @property int $updated_at
 *
 * @property-read ClickPesaPayout|null $payout
 */
class ClickPesaTransaction extends ActiveRecord
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_PAID = 'PAID';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REFUNDED = 'REFUNDED';

    public const TYPE_COLLECTION = 'collection';
    public const TYPE_PAYOUT = 'payout';

    public const MODE_EXACT = 'EXACT';
    public const MODE_PARTIAL_OVER = 'ALLOW_PARTIAL_AND_OVER_PAYMENT';

    public static function tableName(): string
    {
        return '{{%clickpesa_transactions}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['order_reference', 'payment_status', 'transaction_type'], 'required'],
            [['amount', 'payout_amount', 'expected_amount', 'received_amount', 'statement_balance'], 'number'],
            [['raw_payload', 'payout_payload', 'raw_request'], 'string'],
            [['created_at', 'updated_at', 'paid_at', 'last_synced_at', 'user_id', 'inventory_updated'], 'integer'],
            [['order_reference', 'control_number', 'payout_reference', 'order_id'], 'string', 'max' => 64],
            [['currency'], 'string', 'max' => 8],
            [['phone', 'payout_phone'], 'string', 'max' => 32],
            [['customer_name'], 'string', 'max' => 255],
            [['description'], 'string', 'max' => 512],
            [['statement_date'], 'string', 'max' => 32],
            [['payment_status', 'payout_status', 'transaction_type', 'payment_mode'], 'string', 'max' => 64],
            [['event_type', 'channel', 'clickpesa_transaction_id'], 'string', 'max' => 128],
            [['sync_source'], 'string', 'max' => 32],
            [['checksum'], 'string', 'max' => 128],
            [
                'payment_status',
                'in',
                'range' => [
                    self::STATUS_PENDING,
                    self::STATUS_SUCCESS,
                    self::STATUS_PAID,
                    self::STATUS_FAILED,
                    self::STATUS_REFUNDED,
                ],
            ],
            [['order_reference'], 'unique'],
            [['clickpesa_transaction_id'], 'unique'],
        ];
    }

    public function getPayout(): ActiveQuery
    {
        return $this->hasOne(ClickPesaPayout::class, ['payment_id' => 'id']);
    }

    public function isPaymentSuccessful(): bool
    {
        return in_array($this->payment_status, [self::STATUS_SUCCESS, self::STATUS_PAID], true);
    }

    public function hasSuccessfulPayout(): bool
    {
        $payout = $this->payout;
        if ($payout !== null && $payout->payout_status === ClickPesaPayout::STATUS_SUCCESS) {
            return true;
        }

        return $this->payout_status === self::STATUS_SUCCESS
            && $this->payout_reference !== null
            && $this->payout_reference !== '';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'orderReference' => $this->order_reference,
            'controlNumber' => $this->control_number,
            'amount' => (float) $this->amount,
            'expectedAmount' => $this->expected_amount !== null ? (float) $this->expected_amount : null,
            'receivedAmount' => $this->received_amount !== null ? (float) $this->received_amount : null,
            'currency' => $this->currency,
            'paymentMode' => $this->payment_mode,
            'phone' => $this->phone,
            'customerName' => $this->customer_name,
            'description' => $this->description,
            'paymentStatus' => $this->payment_status,
            'payoutStatus' => $this->payout_status,
            'payoutReference' => $this->payout_reference,
            'payoutPhone' => $this->payout_phone ? ClickPesaSetting::maskPhone((string) $this->payout_phone) : null,
            'payoutAmount' => $this->payout_amount !== null ? (float) $this->payout_amount : null,
            'transactionType' => $this->transaction_type,
            'eventType' => $this->event_type,
            'channel' => $this->channel,
            'paidAt' => $this->paid_at,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
