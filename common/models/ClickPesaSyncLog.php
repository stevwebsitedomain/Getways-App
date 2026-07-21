<?php

declare(strict_types=1);

namespace common\models;

use yii\behaviors\TimestampBehavior;
use yii\db\ActiveRecord;

/**
 * ClickPesa sync execution log.
 *
 * @property int $id
 * @property string $sync_type
 * @property string $status
 * @property int $records_inserted
 * @property int $records_updated
 * @property int $records_skipped
 * @property string|null $message
 * @property string|null $metadata_json
 * @property int|null $synced_at
 * @property int $created_at
 * @property int $updated_at
 */
class ClickPesaSyncLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%clickpesa_sync_log}}';
    }

    public function behaviors(): array
    {
        return [TimestampBehavior::class];
    }

    public function rules(): array
    {
        return [
            [['sync_type', 'status'], 'required'],
            [['records_inserted', 'records_updated', 'records_skipped', 'synced_at', 'created_at', 'updated_at'], 'integer'],
            [['message', 'metadata_json'], 'string'],
            [['sync_type', 'status'], 'string', 'max' => 64],
        ];
    }
}
