<?php

namespace justinholtweb\controltower\jobs;

use craft\queue\BaseJob;
use justinholtweb\controltower\Plugin;

/**
 * Periodic server metrics collection job.
 *
 * Push this to the queue on a schedule (e.g., every minute via cron):
 *   Craft::$app->getQueue()->push(new CollectMetricsJob());
 */
class CollectMetricsJob extends BaseJob
{
    protected function defaultDescription(): ?string
    {
        return 'Control Tower: collect server metrics';
    }

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getSettings()->trackServerMetrics) {
            return;
        }

        $plugin->metricsCollector->collectSample();
    }
}
