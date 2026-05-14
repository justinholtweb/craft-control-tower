<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $metric
 * @property string $operator
 * @property float $threshold
 * @property string $severity
 * @property bool $isEnabled
 * @property bool $notifyAdmins
 * @property string|null $notifyEmails
 * @property string|null $webhookIds
 * @property bool $notifyOnResolve
 * @property int $minNotifyInterval
 * @property string|null $lastNotifiedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class AlertRuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_alert_rules}}';
    }
}
