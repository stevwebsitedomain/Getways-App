<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Safe automatic / manual payout record.
 *
 * @property int $id
 * @property int $payment_id
 * @property string $payout_reference
 * @property string $destination_type
 * @property string|null $destination_masked
 * @property string $amount
 * @property string|null $fee
 * @property string $currency
 * @property string|null $provider
 * @property string $payout_status
 * @property int $retry_count
 * @property string|null $last_error
 * @property string|null $raw_request
 * @property string|null $raw_response
 * @property int|null $processed_at
 * @property int|null $next_retry_at
 * @property int $created_at
 * @property int $updated_at
 *
 * @property-read ClickPesaTransaction|null $payment
 */
class ClickPesaPayout extends ActiveRecord
{
    public const STATUS_QUEUED = 'QUEUED';
    public const STATUS_PREVIEWED = 'PREVIEWED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';
    public const STATUS_REFUNDED = 'REFUNDED';
    public const STATUS_REVERSED = 'REVERSED';
    public const STATUS_AWAITING_APPROVAL = 'AWAITING_APPROVAL';

    public const MAX_RETRIES = 3;

    public static function tableName(): string
    {
        return '{{%clickpesa_payout}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['payment_id', 'payout_reference', 'amount', 'payout_status'], 'required'],
            [['payment_id', 'retry_count', 'processed_at', 'next_retry_at', 'created_at', 'updated_at'], 'integer'],
            [['amount', 'fee'], 'number', 'min' => 0],
            [['last_error', 'raw_request', 'raw_response'], 'string'],
            [['payout_reference'], 'string', 'max' => 64],
            [['destination_type', 'payout_status', 'provider'], 'string', 'max' => 64],
            [['destination_masked'], 'string', 'max' => 32],
            [['currency'], 'string', 'max' => 8],
            [['payout_reference'], 'unique'],
            [['payment_id'], 'unique'],
            [
                'payout_status',
                'in',
                'range' => [
                    self::STATUS_QUEUED,
                    self::STATUS_PREVIEWED,
                    self::STATUS_PROCESSING,
                    self::STATUS_PENDING,
                    self::STATUS_SUCCESS,
                    self::STATUS_FAILED,
                    self::STATUS_REFUNDED,
                    self::STATUS_REVERSED,
                    self::STATUS_AWAITING_APPROVAL,
                ],
            ],
        ];
    }

    public function getPayment(): ActiveQuery
    {
        return $this->hasOne(ClickPesaTransaction::class, ['id' => 'payment_id']);
    }

    public function isFinal(): bool
    {
        return in_array($this->payout_status, [
            self::STATUS_SUCCESS,
            self::STATUS_REFUNDED,
            self::STATUS_REVERSED,
        ], true);
    }

    public function canRetry(): bool
    {
        return $this->payout_status === self::STATUS_FAILED
            && (int) $this->retry_count < self::MAX_RETRIES
            && $this->isRetryableError();
    }

    public function isRetryableError(): bool
    {
        $err = strtolower((string) $this->last_error);
        if ($err === '') {
            return true;
        }

        $nonRetryable = [
            'invalid phone',
            'invalid destination',
            'invalid checksum',
            'insufficient',
            'unauthorized',
            'duplicate',
            'duplicate reference',
            'already exists',
            'forbidden',
        ];

        foreach ($nonRetryable as $needle) {
            if (str_contains($err, $needle)) {
                return false;
            }
        }

        return true;
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'paymentId' => $this->payment_id,
            'payoutReference' => $this->payout_reference,
            'destinationType' => $this->destination_type,
            'destinationMasked' => $this->destination_masked,
            'amount' => (float) $this->amount,
            'fee' => $this->fee !== null ? (float) $this->fee : null,
            'currency' => $this->currency,
            'provider' => $this->provider,
            'status' => $this->payout_status,
            'retryCount' => (int) $this->retry_count,
            'lastError' => $this->last_error,
            'processedAt' => $this->processed_at,
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }
}
