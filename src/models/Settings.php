<?php

namespace justinholtweb\controltower\models;

use craft\base\Model;

class Settings extends Model
{
    /** Enable front-end visitor tracking */
    public bool $trackVisitors = true;

    /** Enable editor activity tracking */
    public bool $trackEditors = true;

    /** Enable server metrics collection */
    public bool $trackServerMetrics = true;

    /** Widget auto-refresh interval in seconds */
    public int $refreshInterval = 30;

    /** How many minutes before a visitor is considered inactive */
    public int $visitorTimeout = 2;

    /** How many minutes before an editor session is considered inactive */
    public int $editorTimeout = 5;

    /** Days to retain visitor tracking data */
    public int $visitorRetentionDays = 30;

    /** Days to retain editor activity data */
    public int $editorRetentionDays = 90;

    /** Days to retain content event data */
    public int $contentEventRetentionDays = 90;

    /** Days to retain metric samples */
    public int $metricRetentionDays = 30;

    /** Days to retain alert records */
    public int $alertRetentionDays = 90;

    /** Stale content threshold in days */
    public int $staleContentDays = 90;

    /** Server metrics polling interval in seconds */
    public int $metricsPollingInterval = 60;

    /** Enable collision detection for editors on same entry */
    public bool $enableCollisionDetection = true;

    /** Alert on queue failure count threshold */
    public int $queueFailureAlertThreshold = 3;

    /** Alert on 5xx error rate per minute threshold */
    public int $errorRateAlertThreshold = 10;

    /** Alert on 404 spike per minute threshold */
    public int $notFoundAlertThreshold = 50;

    public function defineRules(): array
    {
        return [
            [['refreshInterval', 'visitorTimeout', 'editorTimeout'], 'integer', 'min' => 1],
            [['visitorRetentionDays', 'editorRetentionDays', 'contentEventRetentionDays', 'metricRetentionDays', 'alertRetentionDays'], 'integer', 'min' => 1],
            [['staleContentDays'], 'integer', 'min' => 1],
            [['metricsPollingInterval'], 'integer', 'min' => 10],
            [['queueFailureAlertThreshold', 'errorRateAlertThreshold', 'notFoundAlertThreshold'], 'integer', 'min' => 1],
        ];
    }
}
