<?php

namespace justinholtweb\controltower\notifications;

use Craft;
use craft\helpers\UrlHelper;
use justinholtweb\controltower\metrics\MetricInterface;
use justinholtweb\controltower\models\AlertRule;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\AlertRecord;

/**
 * Read-only bundle of data passed to formatters / email templates.
 */
class NotificationContext
{
    public string $event;
    public AlertRecord $alert;
    public AlertRule $rule;
    public string $siteName;
    public string $environment;
    public string $alertUrl;
    /** The rule's metric, or null when it is no longer registered. */
    public ?MetricInterface $metric;

    public function __construct(string $event, AlertRecord $alert, AlertRule $rule)
    {
        $this->event = $event;
        $this->alert = $alert;
        $this->rule = $rule;
        $this->siteName = Craft::$app->getSystemName() ?: 'Craft CMS';
        $this->environment = (string) (Craft::$app->env ?? 'production');
        // Alerts are sent from a queue job, which usually runs on the console — where the request
        // has no host. cpUrl() builds from baseCpUrl / the primary site instead.
        $this->alertUrl = UrlHelper::cpUrl('control-tower/alerts');
        $this->metric = Plugin::getInstance()?->metricRegistry->get($rule->metric);
    }

    /**
     * The metric's label, falling back to its handle once the metric is no longer registered.
     */
    public function metricLabel(): string
    {
        return $this->metric?->getLabel() ?? $this->rule->metric;
    }

    public function severityColorHex(): string
    {
        return match ($this->rule->severity) {
            'critical' => 'C0392B',
            'warning' => 'D68910',
            default => '2E86C1',
        };
    }

    public function severityEmoji(): string
    {
        return match ($this->rule->severity) {
            'critical' => '🚨',
            'warning' => '⚠️',
            default => 'ℹ️',
        };
    }

    public function decodedContext(): array
    {
        if (empty($this->alert->context)) {
            return [];
        }
        $decoded = json_decode((string) $this->alert->context, true);
        return is_array($decoded) ? $decoded : [];
    }
}
