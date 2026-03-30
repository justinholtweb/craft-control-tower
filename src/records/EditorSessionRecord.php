<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $userId
 * @property string $startedAt
 * @property string $lastSeenAt
 * @property string|null $currentRoute
 * @property string|null $currentUrl
 * @property string|null $sectionHandle
 * @property string|null $elementType
 * @property int|null $elementId
 * @property int|null $draftId
 * @property string $action
 */
class EditorSessionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_editor_sessions}}';
    }
}
