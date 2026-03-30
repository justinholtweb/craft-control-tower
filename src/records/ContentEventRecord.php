<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $elementType
 * @property int $elementId
 * @property string $action
 * @property string|null $sectionHandle
 * @property int|null $userId
 * @property string $recordedAt
 */
class ContentEventRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_content_events}}';
    }
}
