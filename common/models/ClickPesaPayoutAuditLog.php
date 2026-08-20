<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Audit trail for payout lifecycle events (no secrets stored).
 *
 * @property int $id
 * @property int $payout_id
 * @property string $event
 * @property string|null $old_status
 * @property string|null $new_status
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string|null $metadata_safe
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property int $created_at
 *
 * @property-read ClickPesaPayout|null $payout
 */
class ClickPesaPayoutAuditLog extends ActiveRecord
{
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_WEBHOOK = 'webhook';
    public const ACTOR_WORKER = 'worker';

    public static function tableName(): string
    {
        return '{{%clickpesa_payout_audit_log}}';
    }

    public function behaviors(): array
    {
        return [
            [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => false,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            [['payout_id', 'event', 'actor_type'], 'required'],
            [['payout_id', 'actor_id', 'created_at'], 'integer'],
            [['metadata_safe'], 'string'],
            [['event'], 'string', 'max' => 64],
            [['old_status', 'new_status', 'actor_type'], 'string', 'max' => 32],
            [['ip_address'], 'string', 'max' => 64],
            [['user_agent'], 'string', 'max' => 255],
        ];
    }

    public function getPayout(): ActiveQuery
    {
        return $this->hasOne(ClickPesaPayout::class, ['id' => 'payout_id']);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public static function record(
        ClickPesaPayout $payout,
        string $event,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        string $actorType = self::ACTOR_SYSTEM,
        ?int $actorId = null,
        ?array $metadata = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): self {
        $log = new self([
            'payout_id' => $payout->id,
            'event' => $event,
            'old_status' => $oldStatus,
            'new_status' => $newStatus ?? $payout->payout_status,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata_safe' => $metadata !== null
                ? json_encode(self::sanitizeMetadata($metadata), JSON_UNESCAPED_SLASHES)
                : null,
            'ip_address' => $ip,
            'user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
        ]);
        $log->save(false);

        return $log;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function sanitizeMetadata(array $metadata): array
    {
        $blocked = [
            'apiKey', 'api_key', 'token', 'accessToken', 'access_token',
            'checksum', 'checksumSecret', 'checksum_secret', 'authorization',
            'password', 'pin', 'otp', 'secret',
        ];
        $out = [];
        foreach ($metadata as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($blocked as $needle) {
                if (str_contains($lower, strtolower($needle))) {
                    continue 2;
                }
            }
            if (is_string($value) && preg_match('/^255\d{9}$/', $value)) {
                $out[$key] = ClickPesaSetting::maskPhone($value);
            } elseif (is_array($value)) {
                $out[$key] = self::sanitizeMetadata($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
