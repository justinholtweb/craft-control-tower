<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string $url
 * @property bool $isEnabled
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class WebhookRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_webhooks}}';
    }
}
