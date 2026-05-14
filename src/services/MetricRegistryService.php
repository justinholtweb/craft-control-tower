<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use justinholtweb\controltower\Plugin;
use yii\base\Component;

class MetricRegistryService extends Component
{
    /**
     * Each metric: ['label', 'unit', 'description', 'evaluator' => callable(): float].
     *
     * Evaluators must return a numeric value comparable against a rule threshold.
     */
    public function all(): array
    {
        $plugin = Plugin::getInstance();

        return [
            'queue_failed' => [
                'label' => 'Queue: failed jobs',
                'unit' => 'jobs',
                'description' => 'Number of jobs in the failed state right now.',
                'evaluator' => fn() => (float) ($plugin->queueMonitor->getSummary()['failed'] ?? 0),
            ],
            'queue_pending' => [
                'label' => 'Queue: pending jobs',
                'unit' => 'jobs',
                'description' => 'Number of jobs waiting to run.',
                'evaluator' => fn() => (float) ($plugin->queueMonitor->getSummary()['waiting'] ?? 0),
            ],
            'cpu_percent' => [
                'label' => 'Server: CPU usage',
                'unit' => '%',
                'description' => 'Current CPU utilization (0–100).',
                'evaluator' => fn() => (float) ($plugin->metricsCollector->getCurrentSnapshot()['cpuPercent'] ?? 0),
            ],
            'memory_percent' => [
                'label' => 'Server: memory usage',
                'unit' => '%',
                'description' => 'Current memory utilization (0–100).',
                'evaluator' => fn() => (float) ($plugin->metricsCollector->getCurrentSnapshot()['memoryPercent'] ?? 0),
            ],
            'disk_percent' => [
                'label' => 'Server: disk usage',
                'unit' => '%',
                'description' => 'Current disk utilization (0–100).',
                'evaluator' => fn() => (float) ($plugin->metricsCollector->getCurrentSnapshot()['diskPercent'] ?? 0),
            ],
            'editor_collisions' => [
                'label' => 'Editors: collisions',
                'unit' => 'collisions',
                'description' => 'Distinct elements currently being edited by more than one user.',
                'evaluator' => fn() => (float) count($plugin->editorTracking->getCollisions()),
            ],
            'active_editors' => [
                'label' => 'Editors: active sessions',
                'unit' => 'editors',
                'description' => 'Number of editors active within the editor timeout window.',
                'evaluator' => fn() => (float) $plugin->editorTracking->getActiveEditorCount(),
            ],
            'stale_content_count' => [
                'label' => 'Content: stale entries',
                'unit' => 'entries',
                'description' => 'Live entries not updated within the staleContentDays setting.',
                'evaluator' => fn() => (float) count($plugin->contentHealth->getStaleEntries()),
            ],
        ];
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function evaluate(string $key): ?float
    {
        $metric = $this->get($key);
        if ($metric === null) {
            return null;
        }

        try {
            return (float) ($metric['evaluator'])();
        } catch (\Throwable $e) {
            Craft::warning("Control Tower metric “{$key}” failed to evaluate: " . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Returns key => label for select inputs.
     */
    public function options(): array
    {
        $opts = [];
        foreach ($this->all() as $key => $meta) {
            $opts[$key] = $meta['label'];
        }
        return $opts;
    }
}
