<?php

namespace justinholtweb\controltower\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property float|null $cpuPercent
 * @property float|null $memoryPercent
 * @property float|null $diskPercent
 * @property float|null $load1
 * @property int|null $phpMemoryUsage
 * @property float|null $dbPingMs
 * @property int|null $queueBacklog
 * @property float|null $errorRate
 * @property string $sampledAt
 */
class MetricSampleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%controltower_metric_samples}}';
    }
}
