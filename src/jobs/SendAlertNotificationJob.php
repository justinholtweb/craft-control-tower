<?php

namespace justinholtweb\controltower\jobs;

use craft\queue\BaseJob;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\services\AlertNotifierService;

class SendAlertNotificationJob extends BaseJob
{
    public int $alertId;
    public string $event = AlertNotifierService::EVENT_FIRING;

    protected function defaultDescription(): ?string
    {
        return "Control Tower: send alert notification (#{$this->alertId}, {$this->event})";
    }

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->license->getIsValid()) {
            return;
        }

        $plugin->alertNotifier->notify($this->alertId, $this->event);
    }
}
