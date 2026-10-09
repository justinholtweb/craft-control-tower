<?php

namespace justinholtweb\controltower\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\services\AlertService;
use justinholtweb\controltower\services\HealthService;

/**
 * `php craft control-tower/status` — the same report as the health endpoint, for cron, CI and
 * monitors that run a command (Nagios, Icinga, Zabbix, a deploy script).
 *
 * Exit codes follow the Nagios plugin convention: 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN.
 */
class StatusController extends Controller
{
    public const EXIT_OK = 0;
    public const EXIT_WARNING = 1;
    public const EXIT_CRITICAL = 2;
    public const EXIT_UNKNOWN = 3;

    public $defaultAction = 'index';

    /**
     * @var string Lowest alert severity that makes the command fail: info (any firing alert), warning or critical.
     */
    public string $failOn = AlertService::SEVERITY_INFO;

    /**
     * @var bool Print the report as JSON — the same shape `/control-tower/health` returns.
     */
    public bool $json = false;

    /**
     * @var bool Evaluate every enabled alert rule now, before reporting, instead of reading the last sweep.
     */
    public bool $refresh = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'failOn', 'json', 'refresh'];
    }

    public function optionAliases(): array
    {
        return [...parent::optionAliases(), 'f' => 'failOn', 'r' => 'refresh'];
    }

    /**
     * Reports firing alerts and key gauges, and exits non-zero when any alert at or above
     * --fail-on is firing (or the alert checks have stopped running).
     */
    public function actionIndex(): int
    {
        if (!in_array($this->failOn, HealthService::SEVERITIES, true)) {
            $this->stderr('--fail-on must be one of: ' . implode(', ', HealthService::SEVERITIES) . PHP_EOL, Console::FG_RED);

            return self::EXIT_UNKNOWN;
        }

        $plugin = Plugin::getInstance();

        if ($this->refresh && $plugin->license->getIsValid()) {
            $plugin->alerts->runChecks();
        }

        $report = $plugin->health->getReport($this->failOn);
        $code = self::exitCodeFor($report);

        if ($this->json) {
            $this->stdout(Json::encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

            return $code;
        }

        $this->_printReport($report, $code);

        return $code;
    }

    /**
     * @param array<string, mixed> $report
     */
    public static function exitCodeFor(array $report): int
    {
        return match ($report['status']) {
            HealthService::STATUS_OK => self::EXIT_OK,
            HealthService::STATUS_FAILING => self::_failingCode($report),
            default => self::EXIT_UNKNOWN,
        };
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function _failingCode(array $report): int
    {
        foreach ($report['alerts'] as $alert) {
            if ($alert['countsTowardStatus'] && $alert['severity'] === AlertService::SEVERITY_CRITICAL) {
                return self::EXIT_CRITICAL;
            }
        }

        return self::EXIT_WARNING;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function _printReport(array $report, int $code): void
    {
        $label = [self::EXIT_OK => 'OK', self::EXIT_WARNING => 'WARNING', self::EXIT_CRITICAL => 'CRITICAL'][$code] ?? 'UNKNOWN';
        $color = [self::EXIT_OK => Console::FG_GREEN, self::EXIT_WARNING => Console::FG_YELLOW, self::EXIT_CRITICAL => Console::FG_RED][$code] ?? Console::FG_GREY;

        $summary = match ($report['status']) {
            HealthService::STATUS_UNLICENSED => 'Control Tower is not licensed, so nothing is being checked.',
            HealthService::STATUS_STALE => 'Alert checks last ran at ' . $report['lastAlertCheckAt'] . ' — is the queue running RunAlertChecksJob?',
            default => count($report['alerts']) . ' alert(s) firing.',
        };
        $this->stdout("Control Tower {$label}: {$summary}" . PHP_EOL, $color);

        foreach ($report['alerts'] as $alert) {
            $line = sprintf('  [%s] %s', $alert['severity'], $alert['rule']);
            if ($alert['observed'] !== null) {
                $line .= sprintf(' — observed %s%s (threshold %s %s)', $this->_number($alert['observed']), $alert['unit'] ? ' ' . $alert['unit'] : '', $alert['operator'], $this->_number($alert['threshold']));
            }
            if (!$alert['countsTowardStatus']) {
                $line .= ' (below --fail-on)';
            }
            $this->stdout($line . PHP_EOL);
        }

        if ($report['gauges'] !== []) {
            $this->stdout(PHP_EOL);
            foreach ($report['gauges'] as $gauge) {
                $value = $gauge['value'] === null ? '—' : $this->_number($gauge['value']) . ($gauge['unit'] === '%' ? '%' : ' ' . $gauge['unit']);
                $this->stdout(sprintf('  %-32s %s', $gauge['label'], $value) . PHP_EOL);
            }
        }

        if ($report['status'] !== HealthService::STATUS_UNLICENSED) {
            $this->stdout(PHP_EOL . '  Alert checks last ran: ' . ($report['lastAlertCheckAt'] ?? 'not recorded yet') . PHP_EOL);
        }
    }

    private function _number(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        return abs($value - round($value)) < 0.01 ? (string) (int) round($value) : (string) round($value, 2);
    }
}
