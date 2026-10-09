<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use justinholtweb\controltower\Plugin;
use yii\base\Component;

/**
 * One health verdict for uptime monitors, read by both `/control-tower/health` and
 * `php craft control-tower/status`, so the two can never disagree about whether the site is well.
 *
 * The report deliberately carries only what a monitor needs to decide and a person needs to act:
 * the firing alerts, a handful of gauges and when the alert checks last ran. No hostnames, paths,
 * versions, user names or IPs — the endpoint answers anyone holding the token, and monitoring
 * services keep response bodies.
 */
class HealthService extends Component
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAILING = 'failing';
    public const STATUS_STALE = 'stale';
    public const STATUS_UNLICENSED = 'unlicensed';

    /** Lowest first: an alert counts against the verdict when its severity is at or above `failOn`. */
    public const SEVERITIES = [
        AlertService::SEVERITY_INFO,
        AlertService::SEVERITY_WARNING,
        AlertService::SEVERITY_CRITICAL,
    ];

    /** Metric handles reported as gauges. Cheap to read, and none of them names anything. */
    public const GAUGES = ['queue_failed', 'queue_pending', 'cpu_percent', 'memory_percent', 'disk_percent', 'db_response_ms'];

    /** A token shorter than this is treated as not configured, so a weak one can't switch the endpoint on. */
    public const MIN_TOKEN_LENGTH = 32;

    private const LAST_CHECK_CACHE_KEY = 'controltower:lastAlertCheck';

    /**
     * The configured token, resolved through `App::parseEnv()`, or null when the endpoint should
     * stay closed: nothing set, an environment variable that isn't defined, or too short to trust.
     */
    public function getToken(): ?string
    {
        $raw = Plugin::getInstance()->getSettings()->healthToken;
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $token = App::parseEnv($raw);

        // An unset `$VAR` resolves to null; a `${VAR}` inside a longer string is left as written —
        // and the literal reference is in the docs, so it must never work as a token.
        if (!is_string($token) || str_contains($token, '$') || strlen($token) < self::MIN_TOKEN_LENGTH) {
            return null;
        }

        return $token;
    }

    public function getIsEnabled(): bool
    {
        return $this->getToken() !== null;
    }

    /**
     * Exact, constant-time comparison against the configured token. Anything that isn't a
     * non-empty string — a missing header, `key[]=…` — is refused before comparing.
     */
    public function verifyToken(mixed $provided): bool
    {
        $token = $this->getToken();

        if ($token === null || !is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($token, $provided);
    }

    /**
     * Called at the end of every alert sweep. Lives in the data cache: if that's cleared the next
     * report says "never checked" (not failing) until the next sweep, which is minutes away.
     */
    public function markChecked(?int $time = null): void
    {
        Craft::$app->getCache()->set(self::LAST_CHECK_CACHE_KEY, $time ?? time(), 0);
    }

    public function getLastCheckTime(): ?int
    {
        $value = Craft::$app->getCache()->get(self::LAST_CHECK_CACHE_KEY);

        return is_int($value) ? $value : null;
    }

    public static function normalizeSeverity(mixed $severity): string
    {
        return is_string($severity) && in_array($severity, self::SEVERITIES, true) ? $severity : AlertService::SEVERITY_INFO;
    }

    /**
     * @param string $failOn Lowest severity that makes the report fail (info = any firing alert)
     * @return array{status: string, failOn: string, highestSeverity: ?string, checkedAt: string, lastAlertCheckAt: ?string, checksStale: bool, alerts: array<int, array<string, mixed>>, gauges: array<string, array{label: string, unit: string, value: ?float}>}
     */
    public function getReport(string $failOn = AlertService::SEVERITY_INFO): array
    {
        $failOn = self::normalizeSeverity($failOn);
        $plugin = Plugin::getInstance();
        $now = time();

        $lastCheck = $this->getLastCheckTime();
        $maxAge = $plugin->getSettings()->healthMaxCheckAge;
        $stale = $maxAge > 0 && $lastCheck !== null && ($now - $lastCheck) > $maxAge * 60;

        $report = [
            'status' => self::STATUS_OK,
            'failOn' => $failOn,
            'highestSeverity' => null,
            'checkedAt' => gmdate(DATE_ATOM, $now),
            'lastAlertCheckAt' => $lastCheck !== null ? gmdate(DATE_ATOM, $lastCheck) : null,
            'checksStale' => $stale,
            'alerts' => [],
            'gauges' => [],
        ];

        // Unlicensed, nothing is collected or checked: any figures would be stale, so report none.
        if (!$plugin->license->getIsValid()) {
            $report['status'] = self::STATUS_UNLICENSED;

            return $report;
        }

        $threshold = array_search($failOn, self::SEVERITIES, true);
        $highest = -1;
        $failing = false;

        foreach ($plugin->alerts->getActiveAlerts() as $alert) {
            $severity = self::normalizeSeverity($alert['severity'] ?? null);
            $rank = (int) array_search($severity, self::SEVERITIES, true);
            $highest = max($highest, $rank);
            $failing = $failing || $rank >= $threshold;

            $context = is_array($alert['context'] ?? null) ? $alert['context'] : [];
            $report['alerts'][] = [
                'rule' => $alert['displayLabel'],
                'severity' => $severity,
                'metric' => $context['metric'] ?? null,
                'observed' => isset($context['observedValue']) ? (float) $context['observedValue'] : null,
                'operator' => $context['operator'] ?? null,
                'threshold' => isset($context['threshold']) ? (float) $context['threshold'] : null,
                'unit' => $context['unit'] ?? null,
                'since' => $this->_atom($alert['createdAt'] ?? null),
                'countsTowardStatus' => $rank >= $threshold,
            ];
        }

        $report['highestSeverity'] = $highest >= 0 ? self::SEVERITIES[$highest] : null;

        foreach ($plugin->metricRegistry->snapshot(self::GAUGES) as $handle => $row) {
            $report['gauges'][$handle] = [
                'label' => $row['label'],
                'unit' => $row['unit'],
                'value' => $row['value'],
            ];
        }

        $report['status'] = match (true) {
            $failing => self::STATUS_FAILING,
            $stale => self::STATUS_STALE,
            default => self::STATUS_OK,
        };

        return $report;
    }

    private function _atom(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        // Stored as UTC by Db::prepareDateForDb(); toDateTime() reads a bare string as UTC.
        $date = DateTimeHelper::toDateTime($value);

        return $date ? $date->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM) : null;
    }
}
