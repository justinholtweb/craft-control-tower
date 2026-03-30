<?php

namespace justinholtweb\controltower\jobs;

use craft\queue\BaseJob;
use justinholtweb\controltower\Plugin;

/**
 * Periodic alert checks job.
 *
 * Push to the queue on a schedule (e.g., every 5 minutes via cron):
 *   Craft::$app->getQueue()->push(new RunAlertChecksJob());
 */
class RunAlertChecksJob extends BaseJob
{
    protected function defaultDescription(): ?string
    {
        return 'Control Tower: run alert checks';
    }

    public function execute($queue): void
    {
        Plugin::getInstance()->alerts->runChecks();
    }
}
