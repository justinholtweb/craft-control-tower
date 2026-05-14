<?php

namespace justinholtweb\controltower\notifications;

use Craft;
use justinholtweb\controltower\models\AlertRule;
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

    public function __construct(string $event, AlertRecord $alert, AlertRule $rule)
    {
        $this->event = $event;
        $this->alert = $alert;
        $this->rule = $rule;
        $this->siteName = Craft::$app->getSystemName() ?: 'Craft CMS';
        $this->environment = (string) (Craft::$app->env ?? 'production');
        $this->alertUrl = rtrim(Craft::$app->getRequest()->getHostInfo() ?? '', '/')
            . '/' . trim((string) Craft::$app->getConfig()->getGeneral()->cpTrigger, '/')
            . '/control-tower/alerts';
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
