<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sessionHash
 * @property string|null $ipHash
 * @property string|null $userAgentHash
 * @property string $url
 * @property string|null $referrer
 * @property string $method
 * @property bool $isGuest
 * @property bool $isBot
 * @property int|null $responseCode
 * @property string $firstSeenAt
 * @property string $lastSeenAt
 */
class VisitorRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_visitors}}';
    }
}
