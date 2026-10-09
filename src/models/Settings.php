<?php

namespace justinholtweb\controltower\models;

use craft\base\Model;
use craft\helpers\App;
use justinholtweb\controltower\services\HealthService;

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

    /**
     * Bypass Craftnet license validation entirely.
     *
     * Deliberately absent from the settings screen — set it in
     * `config/control-tower.php` so it lives in version control and can't be
     * flipped from the CP:
     *
     *     return ['disableLicenseEnforcement' => true];
     *
     * Intended for CI, and for staging environments Craft doesn't recognise as
     * testable domains.
     */
    public bool $disableLicenseEnforcement = false;

    /**
     * Allow webhooks to private, loopback and link-local addresses.
     *
     * Off by default: a webhook URL is typed into the CP, and a server that will POST to any
     * address is a way into the cloud metadata service and the private network. Set it in
     * `config/control-tower.php` for a site that genuinely alerts an internal endpoint:
     *
     *     return ['allowPrivateWebhookHosts' => true];
     *
     * Deliberately absent from the settings screen, like `disableLicenseEnforcement`.
     */
    public bool $allowPrivateWebhookHosts = false;

    /**
     * Token for the `/control-tower/health` endpoint. Empty (the default) keeps the endpoint off.
     *
     * Set it to an environment variable reference such as `$CONTROL_TOWER_HEALTH_TOKEN` so the
     * secret stays out of project config. It is resolved with `App::parseEnv()`, must be at least
     * 32 characters, and an undefined variable keeps the endpoint off rather than opening it.
     */
    public ?string $healthToken = null;

    /**
     * Minutes after the last alert sweep before the health report counts the checks as stalled
     * (status `stale`, HTTP 503). 0 turns the staleness check off.
     */
    public int $healthMaxCheckAge = 30;

    public function defineRules(): array
    {
        return [
            [['healthMaxCheckAge'], 'integer', 'min' => 0],
            [['healthToken'], 'filter', 'filter' => fn($value) => is_string($value) && trim($value) !== '' ? trim($value) : null],
            [['healthToken'], 'validateHealthToken'],
            [['refreshInterval', 'visitorTimeout', 'editorTimeout'], 'integer', 'min' => 1],
            [['visitorRetentionDays', 'editorRetentionDays', 'contentEventRetentionDays', 'metricRetentionDays', 'alertRetentionDays'], 'integer', 'min' => 1],
            [['staleContentDays'], 'integer', 'min' => 1],
            [['metricsPollingInterval'], 'integer', 'min' => 10],
            [['queueFailureAlertThreshold', 'errorRateAlertThreshold', 'notFoundAlertThreshold'], 'integer', 'min' => 1],
        ];
    }

    /**
     * Only a literal token is checked here. An environment variable can be set after the setting
     * is saved, and a token too short to use simply keeps the endpoint closed.
     */
    public function validateHealthToken(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === null || $value === '' || str_starts_with($value, '$')) {
            return;
        }

        $resolved = App::parseEnv($value);
        if (is_string($resolved) && strlen($resolved) < HealthService::MIN_TOKEN_LENGTH) {
            $this->addError($attribute, 'The health token must be at least ' . HealthService::MIN_TOKEN_LENGTH . ' characters. Better still, set it to an environment variable such as $CONTROL_TOWER_HEALTH_TOKEN.');
        }
    }
}
