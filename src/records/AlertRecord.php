<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $type
 * @property string $severity
 * @property string $message
 * @property string|null $context
 * @property bool $isActive
 * @property string|null $resolvedAt
 * @property string $createdAt
 */
class AlertRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_alerts}}';
    }
}
