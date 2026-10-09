# Alert metrics

Every alert rule watches one **metric**: a named number Control Tower can measure on demand. The
metric registry holds them all, the built-ins and any that other plugins or modules register,
and every registered metric shows up in the same places:

- the **Metric** select in the alert rule editor, limited to the operators that metric accepts
- alert checks (`RunAlertChecksJob`)
- the **Alert Metrics** panel on System Pulse, with each metric's current value and how many rules use it
- the dashboard widget, where you can pick metrics to show as stat cards
- the JSON API (`control-tower/api/system` returns them all; `control-tower/api/widget?metrics=a,b` returns the ones you name)
- notifications: alert messages, emails, and the Slack, Teams and generic webhook payloads

## Built-in metrics

| Handle | Unit | Window | What it measures |
|---|---|---|---|
| `queue_failed` | jobs | now | Jobs in the failed state. |
| `queue_pending` | jobs | now | Jobs waiting to run. |
| `cpu_percent` | % | now | CPU utilization (0–100), from the 1-minute load average. |
| `memory_percent` | % | now | Memory utilization (0–100). |
| `disk_percent` | % | now | Disk utilization (0–100) of the volume holding `storage/`. |
| `db_response_ms` | ms | now | Round trip of a trivial query to the database. |
| `editor_collisions` | collisions | now | Elements being edited by more than one user. |
| `active_editors` | editors | now | Editors active within the editor timeout. |
| `stale_content_count` | entries | now | Live entries not updated within `staleContentDays`. |
| `failed_logins` | accounts | last hour | User accounts with at least one failed login. Craft doesn't record attempts against usernames that don't exist, so those aren't counted. |
| `critical_updates` | packages | now | Craft and plugins with a critical release waiting, read from the update info Craft has cached. Control Tower never contacts craftcms.com itself: when Craft hasn't checked recently, the metric has no value and rules on it are skipped. |

The `%` and `ms` gauges accept `>`, `>=`, `<` and `<=`; an exact `=` on a continuous reading
almost never matches. Counts accept all five operators.

A metric that can't be measured right now (say, `memory_percent` on a host that hides its
memory figures) has no value, and rules on it are skipped for that check rather than compared
against zero.

Error rates and 404 counts aren't built in: Control Tower doesn't record response status codes,
so there is nothing honest to alert on yet.

## Registering your own

Listen for `MetricRegistryService::EVENT_REGISTER_METRICS`. The event's `$metrics` array arrives
holding the built-ins; add yours to it. Each entry can be:

- an instance of `MetricInterface`, usually a `CallbackMetric` or a subclass of `Metric`
- the class name of a `MetricInterface` implementation
- a config array: with a `class` key, or with an `evaluator` callable to make a `CallbackMetric`

### The contract

`justinholtweb\controltower\metrics\MetricInterface`:

| Method | Returns |
|---|---|
| `getHandle()` | The key stored on alert rules. Starts with a letter; letters, digits, `_ - . :`; at most 50 characters. Prefix it with your handle (`shop:failed_payments`) to stay clear of the built-ins. Treat it as permanent once rules use it. |
| `getLabel()` | Shown in the rule editor, dashboards and notifications. Required. |
| `getUnit()` | Printed after values and thresholds: `%`, `ms`, `orders`… Empty for a bare number. |
| `getDescription()` | Optional help text, shown under the Metric select and on System Pulse. |
| `getOperators()` | Which of `>`, `>=`, `<`, `<=`, `=` make sense. A rule can only be saved with one of these. |
| `getWindow()` | How far back the value looks, in seconds (`3600` = "in the last hour"), or `null` for a point-in-time gauge. |
| `getValue(MetricPeriod $period)` | The value as a float, or `null` when it can't be measured right now. |

`MetricPeriod` carries `start` and `end` (`DateTimeImmutable`). For a windowed metric, `start`
is `end` minus the window; for a gauge they are the same moment. Count whatever happened between
them.

`Metric` is an abstract base with public `handle`, `label`, `unit`, `description`, `operators`
(all five by default) and `window` properties: configure those and implement `getValue()`.
`CallbackMetric` adds an `evaluator` property and calls it with the period.

Registrations that break the contract (a bad handle, no label, an unknown operator, a window
that isn't positive, a class that doesn't exist or doesn't implement the interface) are skipped
and logged as warnings. A handle that's already registered keeps the first registration, so a
plugin can't replace a built-in by reusing its handle. It can remove one, though, by taking it
out of `$event->metrics`.

### Example module

A complete module lives in [`docs/examples/opsmetrics`](examples/opsmetrics). The integration
suite loads it, so it is known to work. It registers one metric each way:

```php
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
                // A callback metric: quickest for a one-liner.
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
```

```php
<?php

namespace modules\opsmetrics;

use craft\elements\User;
use justinholtweb\controltower\metrics\Metric;
use justinholtweb\controltower\metrics\MetricPeriod;

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
```

Register the module in `config/app.php`:

```php
return [
    'modules' => [
        'ops-metrics' => \modules\opsmetrics\Module::class,
    ],
    'bootstrap' => ['ops-metrics'],
];
```

and autoload it from `composer.json` (then run `composer dump-autoload`):

```json
"autoload": {
    "psr-4": {
        "modules\\opsmetrics\\": "modules/opsmetrics/"
    }
}
```

The module must be bootstrapped (or the listener attached from a plugin's `init()`) so the
handler is in place before anything reads the registry. The registry resolves its list once per
request; if you attach a handler later than that, call
`Plugin::getInstance()->metricRegistry->reset()`.

### Writing a good evaluator

- **Keep it cheap.** It runs on every alert check, on every System Pulse load, and on every widget
  refresh that shows it. Count with a query; don't load elements.
- **Never call out to the network** from an evaluator. If the number lives elsewhere, collect it
  on your own schedule and have the evaluator read what you stored.
- **Return `null` for "unknown"**, not `0`. A rule like "orders `<` 1 in the last hour" would
  otherwise fire every time your data source is down.
- **Throwing is safe but noisy.** An exception (or a `NAN`/`INF` result) is caught, logged as a
  warning, and treated as `null`. The rest of the alert sweep carries on.

## When a metric goes away

Rules store the metric's handle. If the plugin or module that registered it is removed:

- the rule stays in **Alert Rules**, marked **Unavailable**
- alert checks skip it, with a warning in the log, and never fail because of it
- opening it shows a warning and keeps the old handle selected; it won't save until you choose an
  available metric
- an alert it already raised stays as it was, and notifications for it fall back to the handle
  (`metricAvailable: false` in the generic webhook payload)

Reinstalling whatever provided the metric brings the rule back to life with no changes.

## In webhook payloads

The generic JSON payload carries the metric alongside the rule:

```json
{
  "metric": "ops:entries_created",
  "metricLabel": "Content: entries created",
  "metricUnit": "entries",
  "metricWindow": 900,
  "metricAvailable": true,
  "alertContext": {
    "metric": "ops:entries_created",
    "metricLabel": "Content: entries created",
    "observedValue": 240,
    "unit": "entries",
    "window": 900,
    "operator": ">=",
    "threshold": 100
  }
}
```

Slack and Teams messages show the metric's label.
