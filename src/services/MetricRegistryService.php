<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use DateTimeImmutable;
use justinholtweb\controltower\events\RegisterMetricsEvent;
use justinholtweb\controltower\metrics\CallbackMetric;
use justinholtweb\controltower\metrics\MetricInterface;
use justinholtweb\controltower\metrics\MetricPeriod;
use justinholtweb\controltower\models\AlertRule;
use justinholtweb\controltower\Plugin;
use yii\base\Component;

/**
 * Every metric an alert rule, dashboard or widget can read — the built-ins plus whatever other
 * plugins and modules register through `EVENT_REGISTER_METRICS`. There is one path: the built-ins
 * are `CallbackMetric`s handed to the same event as everyone else's.
 */
class MetricRegistryService extends Component
{
    /**
     * @event RegisterMetricsEvent Lets plugins and modules add (or remove) alert metrics.
     *
     * ```php
     * Event::on(
     *     MetricRegistryService::class,
     *     MetricRegistryService::EVENT_REGISTER_METRICS,
     *     function(RegisterMetricsEvent $event) {
     *         $event->metrics[] = new CallbackMetric([
     *             'handle' => 'shop:failed_payments',
     *             'label' => 'Shop: failed payments',
     *             'unit' => 'payments',
     *             'window' => 3600,
     *             'evaluator' => fn(MetricPeriod $period) => MyShop::countFailedPayments($period->start),
     *         ]);
     *     }
     * );
     * ```
     */
    public const EVENT_REGISTER_METRICS = 'registerMetrics';

    /** Handles are stored in `controltower_alert_rules.metric`, a 50-character column. */
    private const HANDLE_PATTERN = '/^[A-Za-z][A-Za-z0-9_.:\-]{0,49}$/';

    /** Comparisons that make sense for a continuous gauge, where `=` almost never matches. */
    private const GAUGE_OPERATORS = ['>', '>=', '<', '<='];

    /** @var array<string, MetricInterface>|null */
    private ?array $_metrics = null;

    /**
     * @return array<string, MetricInterface> handle => metric, in registration order
     */
    public function all(): array
    {
        if ($this->_metrics !== null) {
            return $this->_metrics;
        }

        $event = new RegisterMetricsEvent(['metrics' => $this->_builtInMetrics()]);
        if ($this->hasEventHandlers(self::EVENT_REGISTER_METRICS)) {
            $this->trigger(self::EVENT_REGISTER_METRICS, $event);
        }

        $metrics = [];
        foreach ($event->metrics as $definition) {
            $metric = $this->_resolve($definition);
            if ($metric === null) {
                continue;
            }

            $handle = $metric->getHandle();
            if (isset($metrics[$handle])) {
                Craft::warning("Control Tower metric “{$handle}” is registered twice — keeping the first.", __METHOD__);
                continue;
            }

            $metrics[$handle] = $metric;
        }

        return $this->_metrics = $metrics;
    }

    /**
     * Forgets the resolved list so the next read fires `EVENT_REGISTER_METRICS` again. Only needed
     * when handlers are attached after something has already read the registry.
     */
    public function reset(): void
    {
        $this->_metrics = null;
    }

    public function has(string $handle): bool
    {
        return isset($this->all()[$handle]);
    }

    public function get(string $handle): ?MetricInterface
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * The metric's current value, or `null` when it is unknown, can't be measured right now, or
     * threw. A failing metric is logged and never stops the caller — one bad third-party evaluator
     * must not take the rest of an alert sweep down with it.
     */
    public function evaluate(string $handle, ?DateTimeImmutable $now = null): ?float
    {
        $metric = $this->get($handle);
        if ($metric === null) {
            return null;
        }

        try {
            $value = $metric->getValue(MetricPeriod::forWindow($metric->getWindow(), $now));
        } catch (\Throwable $e) {
            Craft::warning("Control Tower metric “{$handle}” failed to evaluate: " . $e->getMessage(), __METHOD__);
            return null;
        }

        if ($value !== null && !is_finite($value)) {
            Craft::warning("Control Tower metric “{$handle}” returned a non-finite value.", __METHOD__);
            return null;
        }

        return $value;
    }

    public function allowsOperator(string $handle, string $operator): bool
    {
        $metric = $this->get($handle);

        return $metric !== null && in_array($operator, $metric->getOperators(), true);
    }

    /**
     * Returns handle => label for select inputs.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        return array_map(fn(MetricInterface $m) => $m->getLabel(), $this->all());
    }

    /**
     * Evaluates metrics for display — the System Pulse panel, the widget and the JSON API.
     * Unknown handles are dropped, so a caller can pass through anything it was handed.
     *
     * @param string[]|null $handles Limit to these handles (null = every metric)
     * @return array<string, array{handle: string, label: string, unit: string, description: ?string, window: ?int, windowLabel: string, value: ?float, formatted: string}>
     */
    public function snapshot(?array $handles = null): array
    {
        $metrics = $this->all();
        if ($handles !== null) {
            $metrics = array_intersect_key($metrics, array_flip(array_map('strval', $handles)));
        }

        $rows = [];
        foreach ($metrics as $handle => $metric) {
            $value = $this->evaluate($handle);
            $rows[$handle] = [
                'handle' => $handle,
                'label' => $metric->getLabel(),
                'unit' => $metric->getUnit(),
                'description' => $metric->getDescription(),
                'window' => $metric->getWindow(),
                'windowLabel' => $this->describeWindow($metric->getWindow()),
                'value' => $value,
                'formatted' => $value === null ? '—' : $this->formatValue($value, $metric->getUnit()),
            ];
        }

        return $rows;
    }

    public function formatValue(float $value, string $unit): string
    {
        $rounded = abs($value - round($value)) < 0.01 ? (string) (int) round($value) : (string) round($value, 2);

        return $unit === '%' ? "{$rounded}%" : trim("{$rounded} {$unit}");
    }

    /**
     * "last hour", "last 15 minutes" — empty for point-in-time gauges.
     */
    public function describeWindow(?int $seconds): string
    {
        if (!$seconds) {
            return '';
        }

        return match (true) {
            $seconds % 86400 === 0 => $seconds === 86400 ? 'last 24 hours' : 'last ' . ($seconds / 86400) . ' days',
            $seconds % 3600 === 0 => $seconds === 3600 ? 'last hour' : 'last ' . ($seconds / 3600) . ' hours',
            $seconds % 60 === 0 => $seconds === 60 ? 'last minute' : 'last ' . ($seconds / 60) . ' minutes',
            default => "last {$seconds} seconds",
        };
    }

    // ----- Internals -----

    private function _resolve(mixed $definition): ?MetricInterface
    {
        try {
            if (is_array($definition) && !isset($definition['class'])) {
                $definition['class'] = CallbackMetric::class;
            }

            $metric = $definition instanceof MetricInterface ? $definition : Craft::createObject($definition);
        } catch (\Throwable $e) {
            Craft::warning('Control Tower skipped a metric that could not be created: ' . $e->getMessage(), __METHOD__);
            return null;
        }

        if (!$metric instanceof MetricInterface) {
            Craft::warning('Control Tower skipped a registered metric that does not implement ' . MetricInterface::class . '.', __METHOD__);
            return null;
        }

        $handle = $metric->getHandle();
        $problem = match (true) {
            !preg_match(self::HANDLE_PATTERN, $handle) => 'its handle must start with a letter and use at most 50 letters, digits, “_”, “-”, “.” or “:”',
            trim($metric->getLabel()) === '' => 'it has no label',
            array_diff($metric->getOperators(), AlertRule::OPERATORS) !== [] || $metric->getOperators() === [] => 'its operators must be a non-empty subset of ' . implode(' ', AlertRule::OPERATORS),
            $metric->getWindow() !== null && $metric->getWindow() <= 0 => 'its window must be a positive number of seconds, or null',
            default => null,
        };

        if ($problem !== null) {
            Craft::warning("Control Tower skipped metric “{$handle}”: {$problem}.", __METHOD__);
            return null;
        }

        return $metric;
    }

    /**
     * @return MetricInterface[]
     */
    private function _builtInMetrics(): array
    {
        $plugin = Plugin::getInstance();

        return [
            new CallbackMetric([
                'handle' => 'queue_failed',
                'label' => 'Queue: failed jobs',
                'unit' => 'jobs',
                'description' => 'Number of jobs in the failed state right now.',
                'evaluator' => fn() => $plugin->queueMonitor->getSummary()['failed'] ?? 0,
            ]),
            new CallbackMetric([
                'handle' => 'queue_pending',
                'label' => 'Queue: pending jobs',
                'unit' => 'jobs',
                'description' => 'Number of jobs waiting to run.',
                'evaluator' => fn() => $plugin->queueMonitor->getSummary()['waiting'] ?? 0,
            ]),
            new CallbackMetric([
                'handle' => 'cpu_percent',
                'label' => 'Server: CPU usage',
                'unit' => '%',
                'description' => 'Current CPU utilization (0–100), from the 1-minute load average.',
                'operators' => self::GAUGE_OPERATORS,
                'evaluator' => fn() => $plugin->metricsCollector->getCpuPercent(),
            ]),
            new CallbackMetric([
                'handle' => 'memory_percent',
                'label' => 'Server: memory usage',
                'unit' => '%',
                'description' => 'Current memory utilization (0–100).',
                'operators' => self::GAUGE_OPERATORS,
                'evaluator' => fn() => $plugin->metricsCollector->getMemoryPercent(),
            ]),
            new CallbackMetric([
                'handle' => 'disk_percent',
                'label' => 'Server: disk usage',
                'unit' => '%',
                'description' => 'Current disk utilization (0–100) of the volume holding storage/.',
                'operators' => self::GAUGE_OPERATORS,
                'evaluator' => fn() => $plugin->metricsCollector->getDiskPercent(),
            ]),
            new CallbackMetric([
                'handle' => 'db_response_ms',
                'label' => 'Server: database response time',
                'unit' => 'ms',
                'description' => 'Round trip of a trivial query to the database.',
                'operators' => self::GAUGE_OPERATORS,
                'evaluator' => fn() => $plugin->metricsCollector->getDbPingMs(),
            ]),
            new CallbackMetric([
                'handle' => 'editor_collisions',
                'label' => 'Editors: collisions',
                'unit' => 'collisions',
                'description' => 'Distinct elements currently being edited by more than one user.',
                'evaluator' => fn() => count($plugin->editorTracking->getCollisions()),
            ]),
            new CallbackMetric([
                'handle' => 'active_editors',
                'label' => 'Editors: active sessions',
                'unit' => 'editors',
                'description' => 'Number of editors active within the editor timeout window.',
                'evaluator' => fn() => $plugin->editorTracking->getActiveEditorCount(),
            ]),
            new CallbackMetric([
                'handle' => 'stale_content_count',
                'label' => 'Content: stale entries',
                'unit' => 'entries',
                'description' => 'Live entries not updated within the staleContentDays setting.',
                'evaluator' => fn() => count($plugin->contentHealth->getStaleEntries()),
            ]),
            new CallbackMetric([
                'handle' => 'failed_logins',
                'label' => 'Security: accounts with failed logins',
                'unit' => 'accounts',
                'description' => 'User accounts with at least one failed login in the last hour. Attempts against usernames that don’t exist aren’t recorded by Craft, so they aren’t counted.',
                'window' => 3600,
                'evaluator' => fn(MetricPeriod $period) => (new Query())
                    ->from(Table::USERS)
                    ->where(['>=', 'lastInvalidLoginDate', Db::prepareDateForDb($period->start)])
                    ->count(),
            ]),
            new CallbackMetric([
                'handle' => 'critical_updates',
                'label' => 'Updates: critical updates available',
                'unit' => 'packages',
                'description' => 'Craft and plugins with a critical release waiting, from the update info Craft has cached. Reads nothing while Craft hasn’t checked for updates recently — it never calls out itself.',
                'evaluator' => fn() => $this->_countCriticalUpdates(),
            ]),
        ];
    }

    private function _countCriticalUpdates(): ?int
    {
        $updates = Craft::$app->getUpdates();

        // getUpdates() would call craftcms.com when nothing is cached. Never do that from an
        // alert sweep — "unknown" is the honest answer.
        if (!$updates->getIsUpdateInfoCached()) {
            return null;
        }

        $info = $updates->getUpdates();
        $count = $info->cms->getHasCritical() ? 1 : 0;
        foreach ($info->plugins as $update) {
            if ($update->getHasCritical()) {
                $count++;
            }
        }

        return $count;
    }
}
