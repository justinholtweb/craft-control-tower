<?php

namespace modules\opsmetrics;

use Craft;
use craft\elements\Entry;
use justinholtweb\controltower\events\RegisterMetricsEvent;
use justinholtweb\controltower\metrics\CallbackMetric;
use justinholtweb\controltower\metrics\MetricPeriod;
use justinholtweb\controltower\services\MetricRegistryService;
use yii\base\Event;
use yii\base\Module as BaseModule;

/**
 * Example module adding two alert metrics to Control Tower.
 *
 * Copy this folder to `modules/opsmetrics/` and register it in `config/app.php`:
 *
 *     'modules' => ['ops-metrics' => \modules\opsmetrics\Module::class],
 *     'bootstrap' => ['ops-metrics'],
 *
 * with `"modules\\opsmetrics\\": "modules/opsmetrics/"` in composer.json's autoload.psr-4
 * (then `composer dump-autoload`). Both metrics then appear in the alert rule editor, on
 * System Pulse, in the widget's metric picker and in webhook payloads.
 */
class Module extends BaseModule
{
    public function init(): void
    {
        Craft::setAlias('@modules/opsmetrics', __DIR__);

        parent::init();

        // Keep the module harmless if Control Tower is ever uninstalled.
        if (!class_exists(MetricRegistryService::class)) {
            return;
        }

        Event::on(
            MetricRegistryService::class,
            MetricRegistryService::EVENT_REGISTER_METRICS,
            function(RegisterMetricsEvent $event) {
                // A callback metric: quickest for a one-liner. The window makes it "in the last
                // 15 minutes"; the evaluator receives that span as a MetricPeriod.
                $event->metrics[] = new CallbackMetric([
                    'handle' => 'ops:entries_created',
                    'label' => 'Content: entries created',
                    'unit' => 'entries',
                    'description' => 'Entries created in the last 15 minutes — a runaway import or spam shows up here.',
                    'window' => 900,
                    'operators' => ['>', '>=', '<', '<='],
                    'evaluator' => fn(MetricPeriod $period) => Entry::find()
                        ->status(null)
                        ->dateCreated(['and', '>= ' . $period->start->format(DATE_ATOM), '< ' . $period->end->format(DATE_ATOM)])
                        ->count(),
                ]);

                // A class: better when the metric has logic of its own, or ships in a plugin.
                $event->metrics[] = PendingUsersMetric::class;
            }
        );
    }
}
