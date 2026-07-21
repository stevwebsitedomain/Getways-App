<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Idempotent ClickPesa webhook event store.
 *
 * @property int $id
 * @property string $event_hash
 * @property string|null $event_type
 * @property string|null $reference
 * @property int $signature_valid
 * @property string $payload
 * @property string $processing_status
 * @property int|null $processed_at
 * @property int $created_at
 */
class ClickPesaWebhookEvent extends ActiveRecord
{
    public const STATUS_RECEIVED = 'RECEIVED';
    public const STATUS_PROCESSED = 'PROCESSED';
    public const STATUS_DUPLICATE = 'DUPLICATE';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_FAILED = 'FAILED';

    public static function tableName(): string
    {
        return '{{%clickpesa_webhook_event}}';
    }

    public function rules(): array
    {
        return [
            [['event_hash', 'payload', 'processing_status', 'created_at'], 'required'],
            [['signature_valid', 'processed_at', 'created_at'], 'integer'],
            [['payload'], 'string'],
            [['event_hash'], 'string', 'max' => 64],
            [['event_type', 'processing_status'], 'string', 'max' => 64],
            [['reference'], 'string', 'max' => 128],
            [['event_hash'], 'unique'],
        ];
    }

    public static function hashPayload(string $rawBody, array $payload): string
    {
        $eventId = (string) ($payload['id']
            ?? $payload['eventId']
            ?? $payload['event_id']
            ?? $payload['data']['id']
            ?? '');

        if ($eventId !== '') {
            return hash('sha256', 'id:' . $eventId);
        }

        return hash('sha256', $rawBody !== '' ? $rawBody : json_encode($payload));
    }
}
