<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use justinholtweb\controltower\records\MetricSampleRecord;
use yii\base\Component;

class MetricsCollectorService extends Component
{
    public function collectSample(): void
    {
        $record = new MetricSampleRecord();
        $record->cpuPercent = $this->_getCpuUsage();
        $record->memoryPercent = $this->_getMemoryUsage();
        $record->diskPercent = $this->_getDiskUsage();
        $record->load1 = $this->_getLoadAverage();
        $record->phpMemoryUsage = memory_get_usage(true);
        $record->dbPingMs = $this->_getDbPingMs();
        $record->queueBacklog = $this->_getQueueBacklog();
        $record->sampledAt = Db::prepareDateForDb(new \DateTime());
        $record->save(false);
    }

    public function getLatestSample(): ?array
    {
        $record = MetricSampleRecord::find()
            ->orderBy(['sampledAt' => SORT_DESC])
            ->one();

        if (!$record) {
            return null;
        }

        return $record->getAttributes();
    }

    public function getCurrentSnapshot(): array
    {
        return [
            'cpuPercent' => $this->_getCpuUsage(),
            'memoryPercent' => $this->_getMemoryUsage(),
            'diskPercent' => $this->_getDiskUsage(),
            'load1' => $this->_getLoadAverage(),
            'phpMemoryUsage' => memory_get_usage(true),
            'phpMemoryLimit' => $this->_getPhpMemoryLimit(),
            'phpVersion' => PHP_VERSION,
            'dbPingMs' => $this->_getDbPingMs(),
            'queueBacklog' => $this->_getQueueBacklog(),
            'uptime' => $this->_getUptime(),
            'craftVersion' => Craft::$app->getVersion(),
            'environment' => Craft::$app->env,
            'devMode' => Craft::$app->getConfig()->getGeneral()->devMode,
        ];
    }

    public function getMetricTimeline(string $metric, string $period = '1h'): array
    {
        $since = match ($period) {
            '15m' => new \DateTime('-15 minutes'),
            '1h' => new \DateTime('-1 hour'),
            '24h' => new \DateTime('-24 hours'),
            '7d' => new \DateTime('-7 days'),
            default => new \DateTime('-1 hour'),
        };

        $sinceDb = Db::prepareDateForDb($since);

        $validMetrics = [
            'cpuPercent', 'memoryPercent', 'diskPercent', 'load1',
            'phpMemoryUsage', 'dbPingMs', 'queueBacklog', 'errorRate',
        ];

        if (!in_array($metric, $validMetrics, true)) {
            return [];
        }

        return (new \craft\db\Query())
            ->select([$metric, 'sampledAt'])
            ->from('{{%controltower_metric_samples}}')
            ->where(['>=', 'sampledAt', $sinceDb])
            ->andWhere(['not', [$metric => null]])
            ->orderBy(['sampledAt' => SORT_ASC])
            ->all();
    }

    public function getServerHealth(): string
    {
        $cpu = $this->_getCpuUsage();
        $memory = $this->_getMemoryUsage();
        $disk = $this->_getDiskUsage();

        if (($cpu !== null && $cpu > 90) || ($memory !== null && $memory > 95) || ($disk !== null && $disk > 95)) {
            return 'critical';
        }
        if (($cpu !== null && $cpu > 70) || ($memory !== null && $memory > 80) || ($disk !== null && $disk > 85)) {
            return 'warning';
        }
        return 'healthy';
    }

    public function cleanup(int $retentionDays = 30): int
    {
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$retentionDays} days"));

        return Craft::$app->getDb()->createCommand()
            ->delete('{{%controltower_metric_samples}}', ['<', 'sampledAt', $cutoff])
            ->execute();
    }

    private function _getCpuUsage(): ?float
    {
        $load = sys_getloadavg();
        if ($load === false) {
            return null;
        }

        $cores = $this->_getCpuCoreCount();
        return round(($load[0] / max($cores, 1)) * 100, 1);
    }

    private function _getMemoryUsage(): ?float
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $total) &&
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $available)) {
                $totalKb = (int) $total[1];
                $availableKb = (int) $available[1];
                return round((1 - $availableKb / $totalKb) * 100, 1);
            }
        }

        // Fallback: PHP memory usage as percentage of limit
        $used = memory_get_usage(true);
        $limit = $this->_getPhpMemoryLimitBytes();
        if ($limit > 0) {
            return round(($used / $limit) * 100, 1);
        }

        return null;
    }

    private function _getDiskUsage(): ?float
    {
        $total = @disk_total_space(Craft::getAlias('@storage'));
        $free = @disk_free_space(Craft::getAlias('@storage'));

        if ($total && $free) {
            return round((1 - $free / $total) * 100, 1);
        }

        return null;
    }

    private function _getLoadAverage(): ?float
    {
        $load = sys_getloadavg();
        return $load !== false ? round($load[0], 2) : null;
    }

    private function _getDbPingMs(): ?float
    {
        try {
            $start = microtime(true);
            Craft::$app->getDb()->createCommand('SELECT 1')->execute();
            $elapsed = (microtime(true) - $start) * 1000;
            return round($elapsed, 2);
        } catch (\Throwable) {
            return null;
        }
    }

    private function _getQueueBacklog(): int
    {
        try {
            $tableName = Craft::$app->getQueue()->tableName ?? '{{%queue}}';
            return (int) (new \craft\db\Query())
                ->from($tableName)
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function _getPhpMemoryLimit(): string
    {
        return ini_get('memory_limit') ?: 'Unknown';
    }

    private function _getPhpMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1') {
            return 0;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function _getUptime(): ?string
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/uptime')) {
            $uptime = (float) file_get_contents('/proc/uptime');
            return $this->_formatUptime((int) $uptime);
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $bootTime = (int) trim(@shell_exec('sysctl -n kern.boottime 2>/dev/null | awk \'{print $4}\' | tr -d ,') ?: '0');
            if ($bootTime > 0) {
                return $this->_formatUptime(time() - $bootTime);
            }
        }

        return null;
    }

    private function _formatUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        $parts[] = "{$minutes}m";

        return implode(' ', $parts);
    }

    private function _getCpuCoreCount(): int
    {
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            return max(1, substr_count($cpuinfo, 'processor'));
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $cores = @trim((string) shell_exec('sysctl -n hw.ncpu 2>/dev/null'));
            if ($cores) {
                return max(1, (int) $cores);
            }
        }

        return 1;
    }
}
