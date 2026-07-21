<?php

declare(strict_types=1);

namespace common\models;

use yii\db\ActiveRecord;

/**
 * Audit trail for payout-setting changes.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $changes_json
 * @property string|null $ip_address
 * @property int $created_at
 */
class ClickPesaSettingAudit extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%clickpesa_setting_audit}}';
    }

    public function rules(): array
    {
        return [
            [['action', 'created_at'], 'required'],
            [['user_id', 'created_at'], 'integer'],
            [['changes_json'], 'string'],
            [['action', 'ip_address'], 'string', 'max' => 64],
        ];
    }

    public static function log(string $action, array $changes = [], ?int $userId = null, ?string $ip = null): void
    {
        $row = new self([
            'user_id' => $userId,
            'action' => $action,
            'changes_json' => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_SLASHES),
            'ip_address' => $ip,
            'created_at' => time(),
        ]);
        $row->save(false);
    }
}
