<?php

namespace modules\opsmetrics;

use craft\elements\User;
use justinholtweb\controltower\metrics\Metric;
use justinholtweb\controltower\metrics\MetricPeriod;

/**
 * Accounts registered but never activated — a jump usually means a signup form is being farmed.
 */
class PendingUsersMetric extends Metric
{
    public string $handle = 'ops:pending_users';
    public string $label = 'Users: pending activation';
    public string $unit = 'users';
    public ?string $description = 'Accounts that have registered but not activated.';

    public function getValue(MetricPeriod $period): ?float
    {
        return (float) User::find()->status(User::STATUS_PENDING)->count();
    }
}
