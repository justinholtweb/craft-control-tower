<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use yii\base\Component;

class QueueMonitorService extends Component
{
    public function getSummary(): array
    {
        $db = Craft::$app->getDb();
        $tableName = Craft::$app->getQueue()->tableName ?? '{{%queue}}';

        $total = (int) (new \craft\db\Query())
            ->from($tableName)
            ->count();

        $waiting = (int) (new \craft\db\Query())
            ->from($tableName)
            ->where(['fail' => false])
            ->andWhere(['timePushed' => null])
            ->orWhere(['>=', 'timePushed', time()])
            ->count();

        $reserved = (int) (new \craft\db\Query())
            ->from($tableName)
            ->where(['not', ['attempt' => null]])
            ->andWhere(['fail' => false])
            ->count();

        $failed = (int) (new \craft\db\Query())
            ->from($tableName)
            ->where(['fail' => true])
            ->count();

        $done = (int) (new \craft\db\Query())
            ->from($tableName)
            ->where(['fail' => false])
            ->andWhere(['not', ['dateReserved' => null]])
            ->count();

        return [
            'total' => $total,
            'waiting' => max(0, $total - $reserved - $failed),
            'reserved' => $reserved,
            'failed' => $failed,
            'healthy' => $failed === 0 && $total < 100,
        ];
    }

    public function getFailedJobs(int $limit = 20): array
    {
        $tableName = Craft::$app->getQueue()->tableName ?? '{{%queue}}';

        return (new \craft\db\Query())
            ->select(['id', 'job', 'description', 'error', 'dateReserved', 'dateFailed', 'attempt'])
            ->from($tableName)
            ->where(['fail' => true])
            ->orderBy(['dateFailed' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getRecentJobs(int $limit = 30): array
    {
        $tableName = Craft::$app->getQueue()->tableName ?? '{{%queue}}';

        return (new \craft\db\Query())
            ->select(['id', 'description', 'timePushed', 'dateReserved', 'dateFailed', 'attempt', 'fail', 'progress', 'progressLabel'])
            ->from($tableName)
            ->orderBy(['timePushed' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getCommonFailures(int $limit = 10): array
    {
        $tableName = Craft::$app->getQueue()->tableName ?? '{{%queue}}';

        return (new \craft\db\Query())
            ->select(['description', 'COUNT(*) as failCount', 'MAX(error) as lastError'])
            ->from($tableName)
            ->where(['fail' => true])
            ->groupBy(['description'])
            ->orderBy(['failCount' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    public function getQueueHealth(): string
    {
        $summary = $this->getSummary();

        if ($summary['failed'] > 0) {
            return 'critical';
        }
        if ($summary['waiting'] > 50) {
            return 'warning';
        }
        return 'healthy';
    }
}
