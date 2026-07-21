<?php

declare(strict_types=1);

namespace common\models;

use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * Automatic payout settings (encrypted destination at rest).
 *
 * @property int $id
 * @property int $auto_payout_enabled
 * @property string $mode
 * @property string $destination_type
 * @property string|null $encrypted_destination
 * @property string|null $mobile_provider
 * @property string|null $bank_account_name
 * @property string|null $bank_account_number_enc
 * @property string|null $bank_name
 * @property string|null $bank_bic_swift
 * @property string $payout_percentage
 * @property string $minimum_amount
 * @property string $daily_limit
 * @property int $delay_seconds
 * @property int $require_manual_approval
 * @property int|null $last_synced_at
 * @property int $created_at
 * @property int $updated_at
 */
class ClickPesaSetting extends ActiveRecord
{
    public const MODE_TEST = 'TEST';
    public const MODE_MANUAL_APPROVAL = 'MANUAL_APPROVAL';
    public const MODE_LIVE_AUTO = 'LIVE_AUTO';

    public const DESTINATION_MOBILE = 'MOBILE_MONEY';
    public const DESTINATION_BANK = 'BANK';

    /** Default destination: +255715296092 */
    public const DEFAULT_PHONE = '255715296092';

    public static function tableName(): string
    {
        return '{{%clickpesa_setting}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['destination_type', 'mode'], 'required'],
            [['auto_payout_enabled', 'require_manual_approval', 'delay_seconds', 'last_synced_at', 'created_at', 'updated_at'], 'integer'],
            [['payout_percentage', 'minimum_amount', 'daily_limit'], 'number'],
            [['encrypted_destination', 'bank_account_number_enc'], 'string'],
            [['mode'], 'in', 'range' => [self::MODE_TEST, self::MODE_MANUAL_APPROVAL, self::MODE_LIVE_AUTO]],
            [['destination_type'], 'in', 'range' => [self::DESTINATION_MOBILE, self::DESTINATION_BANK]],
            [['bank_account_name', 'bank_name'], 'string', 'max' => 255],
            [['mobile_provider', 'bank_bic_swift'], 'string', 'max' => 64],
            [['payout_percentage'], 'number', 'min' => 0, 'max' => 100],
            [['minimum_amount', 'daily_limit'], 'number', 'min' => 0],
            [['delay_seconds'], 'integer', 'min' => 0],
        ];
    }

    public static function current(): self
    {
        $row = static::find()->orderBy(['id' => SORT_ASC])->one();
        if ($row instanceof self) {
            return $row;
        }

        $row = new self([
            'auto_payout_enabled' => 0,
            'mode' => self::MODE_TEST,
            'destination_type' => self::DESTINATION_MOBILE,
            'payout_percentage' => 100,
            'minimum_amount' => 1000,
            'daily_limit' => 0,
            'delay_seconds' => 60,
            'require_manual_approval' => 1,
        ]);
        $row->setDestinationPhone(self::DEFAULT_PHONE);
        $row->save(false);

        return $row;
    }

    public function setDestinationPhone(string $phone): void
    {
        $normalized = self::normalizePhoneStatic($phone);
        $this->encrypted_destination = self::encryptValue($normalized);
    }

    public function getDestinationPhone(): ?string
    {
        if ($this->encrypted_destination === null || $this->encrypted_destination === '') {
            return null;
        }

        return self::decryptValue($this->encrypted_destination);
    }

    public function getMaskedDestination(): string
    {
        $phone = $this->getDestinationPhone() ?: '';
        if ($phone === '') {
            return '—';
        }

        return self::maskPhone($phone);
    }

    public function isLiveAutoEnabled(): bool
    {
        return (bool) $this->auto_payout_enabled && $this->mode === self::MODE_LIVE_AUTO;
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (strlen($digits) < 6) {
            return str_repeat('*', strlen($digits));
        }

        return substr($digits, 0, 4) . str_repeat('*', max(0, strlen($digits) - 6)) . substr($digits, -2);
    }

    public static function normalizePhoneStatic(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '255' . substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            $digits = '255' . $digits;
        }

        return $digits;
    }

    public static function encryptValue(string $plain): string
    {
        return base64_encode(Yii::$app->security->encryptByKey($plain, self::encryptionKey()));
    }

    public static function decryptValue(string $cipher): ?string
    {
        try {
            $plain = Yii::$app->security->decryptByKey(base64_decode($cipher, true) ?: $cipher, self::encryptionKey());

            return $plain === false ? null : $plain;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function encryptionKey(): string
    {
        $params = Yii::$app->params['clickpesa'] ?? [];
        $key = (string) ($params['encryptionKey'] ?? getenv('CLICKPESA_ENCRYPTION_KEY') ?: '');
        if ($key !== '') {
            return $key;
        }

        if (!empty(Yii::$app->params['cookieValidationKey'])) {
            return (string) Yii::$app->params['cookieValidationKey'];
        }

        try {
            if (Yii::$app->has('request', true) && Yii::$app->request instanceof \yii\web\Request) {
                $ck = Yii::$app->request->cookieValidationKey;
                if (is_string($ck) && $ck !== '') {
                    return $ck;
                }
            }
        } catch (\Throwable) {
            // console / early boot
        }

        return 'clickpesa-local-encryption-key-change-me';
    }
}
