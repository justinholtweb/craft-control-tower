<?php

namespace justinholtweb\controltower\jobs;

use craft\queue\BaseJob;
use justinholtweb\controltower\Plugin;

/**
 * Periodic data retention cleanup job.
 *
 * Push this to the queue on a schedule (e.g., daily via cron or console command):
 *   Craft::$app->getQueue()->push(new CleanupJob());
 */
class CleanupJob extends BaseJob
{
    protected function defaultDescription(): ?string
    {
        return 'Control Tower: data retention cleanup';
    }

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->setProgress($queue, 0.0, 'Cleaning visitor data…');
        $plugin->visitorTracking->cleanup($settings->visitorRetentionDays);

        $this->setProgress($queue, 0.2, 'Cleaning editor data…');
        $plugin->editorTracking->cleanup($settings->editorRetentionDays);

        $this->setProgress($queue, 0.4, 'Cleaning content events…');
        $plugin->contentHealth->cleanup($settings->contentEventRetentionDays);

        $this->setProgress($queue, 0.6, 'Cleaning metric samples…');
        $plugin->metricsCollector->cleanup($settings->metricRetentionDays);

        $this->setProgress($queue, 0.8, 'Cleaning alerts…');
        $plugin->alerts->cleanup($settings->alertRetentionDays);

        $this->setProgress($queue, 1.0, 'Done');
    }
}
