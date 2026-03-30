<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $userId
 * @property string $route
 * @property string|null $url
 * @property string|null $sectionHandle
 * @property string|null $elementType
 * @property int|null $elementId
 * @property int|null $draftId
 * @property string $action
 * @property string $recordedAt
 */
class EditorActivityRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_editor_activity}}';
    }
}
