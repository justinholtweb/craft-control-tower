<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\AlertRecord;
use yii\base\Component;

class AlertService extends Component
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const TYPE_QUEUE_FAILURE = 'queue_failure';
    public const TYPE_EDITOR_COLLISION = 'editor_collision';
    public const TYPE_HIGH_ERROR_RATE = 'high_error_rate';
    public const TYPE_HIGH_404_RATE = 'high_404_rate';
    public const TYPE_STALE_CONTENT = 'stale_content';
    public const TYPE_SERVER_RESOURCE = 'server_resource';
    public const TYPE_PENDING_UPDATES = 'pending_updates';
    public const TYPE_FAILED_CRON = 'failed_cron';

    public function createAlert(string $type, string $severity, string $message, ?array $context = null): void
    {
        // Avoid duplicate active alerts of the same type
        $existing = AlertRecord::find()
            ->where(['type' => $type, 'isActive' => true])
            ->one();

        if ($existing) {
            $existing->message = $message;
            $existing->severity = $severity;
            $existing->context = $context ? json_encode($context) : null;
            $existing->save(false);
            return;
        }

        $record = new AlertRecord();
        $record->type = $type;
        $record->severity = $severity;
        $record->message = $message;
        $record->context = $context ? json_encode($context) : null;
        $record->isActive = true;
        $record->createdAt = Db::prepareDateForDb(new \DateTime());
        $record->save(false);
    }

    public function resolveAlert(string $type): void
    {
        $alert = AlertRecord::find()
            ->where(['type' => $type, 'isActive' => true])
            ->one();

        if ($alert) {
            $alert->isActive = false;
            $alert->resolvedAt = Db::prepareDateForDb(new \DateTime());
            $alert->save(false);
        }
    }

    public function getActiveAlerts(): array
    {
        return array_map(fn($record) => [
            'id' => $record->id,
            'type' => $record->type,
            'severity' => $record->severity,
            'message' => $record->message,
            'context' => $record->context ? json_decode($record->context, true) : null,
            'createdAt' => $record->createdAt,
        ], AlertRecord::find()
            ->where(['isActive' => true])
            ->orderBy(['severity' => SORT_ASC, 'createdAt' => SORT_DESC])
            ->all());
    }

    public function getActiveAlertCount(): int
    {
        return (int) AlertRecord::find()
            ->where(['isActive' => true])
            ->count();
    }

    public function getAlertHistory(int $limit = 50): array
    {
        return array_map(fn($record) => [
            'id' => $record->id,
            'type' => $record->type,
            'severity' => $record->severity,
            'message' => $record->message,
            'isActive' => (bool) $record->isActive,
            'createdAt' => $record->createdAt,
            'resolvedAt' => $record->resolvedAt,
        ], AlertRecord::find()
            ->orderBy(['createdAt' => SORT_DESC])
            ->limit($limit)
            ->all());
    }

    /**
     * Run all alert checks. Call this periodically (e.g., from a queue job or cron).
     */
    public function runChecks(): void
    {
        $this->_checkQueueFailures();
        $this->_checkEditorCollisions();
        $this->_checkServerResources();
    }

    public function cleanup(int $retentionDays = 90): int
    {
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$retentionDays} days"));

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%controltower_alerts}}', [
                'and',
                ['isActive' => false],
                ['<', 'createdAt', $cutoff],
            ])
            ->execute();
    }

    private function _checkQueueFailures(): void
    {
        $plugin = Plugin::getInstance();
        $queueSummary = $plugin->queueMonitor->getSummary();
        $threshold = $plugin->getSettings()->queueFailureAlertThreshold;

        if ($queueSummary['failed'] >= $threshold) {
            $this->createAlert(
                self::TYPE_QUEUE_FAILURE,
                self::SEVERITY_CRITICAL,
                "Queue has {$queueSummary['failed']} failed job(s).",
                ['failedCount' => $queueSummary['failed']],
            );
        } else {
            $this->resolveAlert(self::TYPE_QUEUE_FAILURE);
        }
    }

    private function _checkEditorCollisions(): void
    {
        $plugin = Plugin::getInstance();
        if (!$plugin->getSettings()->enableCollisionDetection) {
            return;
        }

        $collisions = $plugin->editorTracking->getCollisions();

        if (!empty($collisions)) {
            $messages = [];
            foreach ($collisions as $collision) {
                $messages[] = "Element #{$collision['elementId']} has {$collision['editorCount']} editors";
            }
            $this->createAlert(
                self::TYPE_EDITOR_COLLISION,
                self::SEVERITY_WARNING,
                implode('; ', $messages),
                ['collisions' => $collisions],
            );
        } else {
            $this->resolveAlert(self::TYPE_EDITOR_COLLISION);
        }
    }

    private function _checkServerResources(): void
    {
        $plugin = Plugin::getInstance();
        $health = $plugin->metricsCollector->getServerHealth();

        if ($health === 'critical') {
            $snapshot = $plugin->metricsCollector->getCurrentSnapshot();
            $this->createAlert(
                self::TYPE_SERVER_RESOURCE,
                self::SEVERITY_CRITICAL,
                'Server resources critically high — CPU: ' . ($snapshot['cpuPercent'] ?? '?') . '%, Memory: ' . ($snapshot['memoryPercent'] ?? '?') . '%, Disk: ' . ($snapshot['diskPercent'] ?? '?') . '%',
                $snapshot,
            );
        } elseif ($health === 'warning') {
            $snapshot = $plugin->metricsCollector->getCurrentSnapshot();
            $this->createAlert(
                self::TYPE_SERVER_RESOURCE,
                self::SEVERITY_WARNING,
                'Server resources elevated — CPU: ' . ($snapshot['cpuPercent'] ?? '?') . '%, Memory: ' . ($snapshot['memoryPercent'] ?? '?') . '%, Disk: ' . ($snapshot['diskPercent'] ?? '?') . '%',
                $snapshot,
            );
        } else {
            $this->resolveAlert(self::TYPE_SERVER_RESOURCE);
        }
    }
}
