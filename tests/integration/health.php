<?php
/**
 * Control Tower health endpoint and status command checks.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-controltower/tests/integration/health.php
 *
 * Token handling (exact, constant-time, env-resolved, refused when unset or short), the report's
 * verdict (fail-on severity, stalled checks) and what it must never contain, then the real thing:
 * `/control-tower/health` over HTTP — closed until a token is set, 401 with nothing in it for any
 * wrong token, 200/503 for the right one — and `php craft control-tower/status` with its exit codes.
 *
 * Self-cleaning: its alerts are typed `CT check health …`; alerts already active in the harness, and
 * the enabled rules that would re-open them, are set aside for the run and restored. The token is written to project config in a separate PHP
 * process and the original settings are restored the same way (see plugin-testing-harness notes).
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\controltower\console\controllers\StatusController;
use justinholtweb\controltower\models\Settings;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\AlertRecord;
use justinholtweb\controltower\records\AlertRuleRecord;
use justinholtweb\controltower\records\VisitorRecord;
use justinholtweb\controltower\services\HealthService;

const PREFIX = 'CT check health';

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

function addAlert(string $severity): void
{
    $record = new AlertRecord();
    $record->type = PREFIX . " $severity";
    $record->severity = $severity;
    $record->message = 'Internal detail that must not reach the report';
    $record->context = json_encode(['metric' => 'queue_failed', 'operator' => '>=', 'threshold' => 1, 'observedValue' => 4, 'unit' => 'jobs']);
    $record->isActive = true;
    $record->createdAt = craft\helpers\Db::prepareDateForDb(new DateTime());
    $record->save(false);
}

function clearAlerts(): void
{
    AlertRecord::deleteAll(['like', 'type', PREFIX . '%', false]);
}

/**
 * Writes the plugin's settings in a fresh PHP process: once the web process has read project config,
 * this process's copy is stale and its own writes can be silently skipped.
 */
function writeSettings(array $settings): string
{
    $script = tempnam(sys_get_temp_dir(), 'cthealth') . '.php';
    file_put_contents($script, '<?php
require "' . getcwd() . '/bootstrap.php";
$app = require CRAFT_VENDOR_PATH . "/craftcms/cms/bootstrap/console.php";
$config = Craft::$app->getProjectConfig();
$config->set("plugins.control-tower.settings", json_decode(base64_decode($argv[1]), true), "Control Tower health checks");
$config->saveModifiedConfigData();
$config->writeYamlFiles(true);
echo "ok";
');
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(base64_encode(json_encode($settings))) . ' 2>&1');
    @unlink($script);

    return trim($out);
}

$plugin = Plugin::getInstance();
$health = $plugin->health;
$settings = $plugin->getSettings();
$originalToken = $settings->healthToken;
$originalMaxAge = $settings->healthMaxCheckAge;
$originalLastCheck = $health->getLastCheckTime();
$storedSettings = Craft::$app->getProjectConfig()->get('plugins.control-tower.settings') ?? [];

clearAlerts();

// Alerts already firing in the shared harness would decide every verdict below, and its enabled
// rules would re-open them (and queue notifications) whenever a sweep runs. Set both aside.
$parkedIds = AlertRecord::find()->select('id')->where(['isActive' => true])->column();
if ($parkedIds) {
    AlertRecord::updateAll(['isActive' => false], ['id' => $parkedIds]);
}
$parkedRuleIds = AlertRuleRecord::find()->select('id')->where(['isEnabled' => true])->column();
if ($parkedRuleIds) {
    AlertRuleRecord::updateAll(['isEnabled' => false], ['id' => $parkedRuleIds]);
}

$goodToken = bin2hex(random_bytes(24)); // 48 characters
$exitCode = 0;

try {
    // -------------------------------------------------------------------------------------------
    section('Token');

    check('with no token the endpoint is off and nothing verifies', function() use ($health, $settings) {
        $settings->healthToken = null;

        return !$health->getIsEnabled() && !$health->verifyToken('') && !$health->verifyToken('anything') && !$health->verifyToken(null);
    });

    check('an undefined environment variable keeps it off — the literal reference is never a token', function() use ($health, $settings) {
        $settings->healthToken = '$CT_HEALTH_CHECK_UNDEFINED_VARIABLE_NAME';
        $plain = !$health->getIsEnabled() && !$health->verifyToken('$CT_HEALTH_CHECK_UNDEFINED_VARIABLE_NAME');
        $settings->healthToken = '${CT_HEALTH_CHECK_UNDEFINED_VARIABLE_NAME}-padding-padding';

        return $plain && !$health->getIsEnabled() && !$health->verifyToken('${CT_HEALTH_CHECK_UNDEFINED_VARIABLE_NAME}-padding-padding');
    });

    check('a token shorter than 32 characters keeps it off, and is refused when saved', function() use ($health, $settings) {
        $settings->healthToken = str_repeat('a', 31);
        $model = new Settings(['healthToken' => 'short-token']);

        return !$health->getIsEnabled() && !$health->verifyToken(str_repeat('a', 31))
            && !$model->validate(['healthToken']) && $model->hasErrors('healthToken');
    });

    check('an environment variable reference is resolved through App::parseEnv', function() use ($health, $settings, $goodToken) {
        putenv("CT_HEALTH_CHECK_TOKEN=$goodToken");
        $_SERVER['CT_HEALTH_CHECK_TOKEN'] = $goodToken;
        $settings->healthToken = '$CT_HEALTH_CHECK_TOKEN';
        $model = new Settings(['healthToken' => '$CT_HEALTH_CHECK_TOKEN']);

        return $health->getIsEnabled() && $health->getToken() === $goodToken && $health->verifyToken($goodToken)
            && !$health->verifyToken('$CT_HEALTH_CHECK_TOKEN') && $model->validate(['healthToken']);
    });

    check('only the exact token verifies — not a prefix, a longer string, other case, padding or an array', function() use ($health, $settings, $goodToken) {
        $settings->healthToken = $goodToken;
        $wrong = [substr($goodToken, 0, -1), $goodToken . 'x', strtoupper($goodToken), " $goodToken", "$goodToken ", '', [$goodToken], 0, true];
        foreach ($wrong as $candidate) {
            if ($health->verifyToken($candidate)) {
                return 'accepted ' . var_export($candidate, true);
            }
        }

        return $health->verifyToken($goodToken);
    });

    // -------------------------------------------------------------------------------------------
    section('Report');

    $settings->healthToken = $goodToken;
    $settings->healthMaxCheckAge = 30;
    $health->markChecked();

    check('with nothing firing the report is ok and carries the gauges', function() use ($health) {
        $report = $health->getReport();

        return $report['status'] === HealthService::STATUS_OK && $report['alerts'] === [] && $report['highestSeverity'] === null
            && array_keys($report['gauges']) !== [] && array_diff(array_keys($report['gauges']), HealthService::GAUGES) === []
            && StatusController::exitCodeFor($report) === 0 ?: json_encode($report);
    });

    addAlert('warning');

    check('a firing warning fails the report (exit 1)', function() use ($health) {
        $report = $health->getReport();
        $alert = $report['alerts'][0] ?? [];

        return $report['status'] === HealthService::STATUS_FAILING && $report['highestSeverity'] === 'warning'
            && $alert['rule'] === PREFIX . ' warning' && $alert['observed'] === 4.0 && $alert['metric'] === 'queue_failed'
            && $alert['countsTowardStatus'] === true && StatusController::exitCodeFor($report) === 1 ?: json_encode($report);
    });

    check('…but not with failOn=critical, where it is listed as not counting', function() use ($health) {
        $report = $health->getReport('critical');

        return $report['status'] === HealthService::STATUS_OK && count($report['alerts']) === 1
            && $report['alerts'][0]['countsTowardStatus'] === false && $report['failOn'] === 'critical' ?: json_encode($report);
    });

    check('an unknown failOn falls back to info (any alert)', function() use ($health) {
        $report = $health->getReport('everything');

        return $report['failOn'] === 'info' && $report['status'] === HealthService::STATUS_FAILING;
    });

    addAlert('critical');

    check('a firing critical alert fails it at any failOn (exit 2)', function() use ($health) {
        $report = $health->getReport('critical');

        return $report['status'] === HealthService::STATUS_FAILING && $report['highestSeverity'] === 'critical'
            && StatusController::exitCodeFor($report) === 2 ?: json_encode($report);
    });

    check('the report carries no messages, hostnames, versions, paths or settings', function() use ($health, $goodToken) {
        $json = json_encode($health->getReport());
        $host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST);
        foreach (['Internal detail', $host, Craft::$app->getVersion(), PHP_VERSION, Craft::getAlias('@root'), 'devMode', 'environment', 'phpVersion', $goodToken, '@'] as $needle) {
            if ($needle && str_contains($json, (string)$needle)) {
                return "contains $needle";
            }
        }

        return true;
    });

    clearAlerts();

    check('checks that stopped running make it stale (exit 3)', function() use ($health) {
        $health->markChecked(time() - 3600);
        $report = $health->getReport();

        return $report['status'] === HealthService::STATUS_STALE && $report['checksStale'] === true
            && StatusController::exitCodeFor($report) === 3 ?: json_encode($report);
    });

    check('…unless the stalled-check threshold is 0', function() use ($health, $settings) {
        $settings->healthMaxCheckAge = 0;
        $report = $health->getReport();
        $settings->healthMaxCheckAge = 30;

        return $report['status'] === HealthService::STATUS_OK && $report['checksStale'] === false;
    });

    check('an alert sweep records when it ran', function() use ($health, $plugin) {
        $plugin->alerts->runChecks();
        $last = $health->getLastCheckTime();

        return $last !== null && time() - $last < 5 && $health->getReport()['checksStale'] === false;
    });

    // -------------------------------------------------------------------------------------------
    section('HTTP endpoint');

    $host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
    $base = getenv('CT_SITE_BASE') ?: 'http://' . $host;
    $httpGet = function(string $query = '', array $headers = []) use ($base, $host): array {
        $ch = curl_init($base . '/control-tower/health' . $query);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'controltower-health-checks',
            CURLOPT_RESOLVE => ["$host:443:127.0.0.1", "$host:80:127.0.0.1"],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$responseHeaders) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        $body = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$code, $body, $responseHeaders];
    };

    check('with no token configured the endpoint is a bare 404', function() use ($httpGet, $goodToken) {
        [$code, $body] = $httpGet('', ["Authorization: Bearer $goodToken"]);

        return $code === 404 && !str_contains($body, 'alerts') && !str_contains($body, 'gauges') ?: "HTTP $code " . substr($body, 0, 200);
    });

    $written = writeSettings(array_merge($storedSettings, ['healthToken' => $goodToken]));
    check('the token is saved to the plugin settings', fn() => $written === 'ok' ?: $written);

    $refusals = [
        'no token' => ['', []],
        'a wrong bearer token' => ['', ['Authorization: Bearer ' . str_repeat('0', 48)]],
        'a prefix of the token' => ['', ['Authorization: Bearer ' . substr($goodToken, 0, 32)]],
        'the token with a suffix' => ['', ['Authorization: Bearer ' . $goodToken . 'a']],
        'the token in another case' => ['', ['Authorization: Bearer ' . strtoupper($goodToken)]],
        'a wrong X-Control-Tower-Token' => ['', ['X-Control-Tower-Token: nope']],
        'an array key parameter' => ['?key[]=' . $goodToken, []],
        'a Basic credential holding the token' => ['', ['Authorization: Basic ' . base64_encode("x:$goodToken")]],
    ];
    foreach ($refusals as $label => [$query, $headers]) {
        check("refuses $label with a 401 that says nothing", function() use ($httpGet, $query, $headers) {
            [$code, $body] = $httpGet($query, $headers);
            $json = json_decode($body, true);

            return $code === 401 && is_array($json) && array_keys($json) === ['error'] ?: "HTTP $code " . substr($body, 0, 200);
        });
    }

    $okReport = function(int $code, string $body): bool|string {
        $json = json_decode($body, true);

        return $code === 200 && ($json['status'] ?? null) === 'ok' && isset($json['gauges']) ?: "HTTP $code " . substr($body, 0, 300);
    };

    check('a bearer token gets the report (200, no-store)', function() use ($httpGet, $goodToken, $okReport) {
        [$code, $body, $headers] = $httpGet('', ["Authorization: Bearer $goodToken"]);

        return $okReport($code, $body) === true && str_contains($headers['cache-control'] ?? '', 'no-store') ?: $okReport($code, $body);
    });

    check('so does the X-Control-Tower-Token header', function() use ($httpGet, $goodToken, $okReport) {
        return $okReport(...array_slice($httpGet('', ["X-Control-Tower-Token: $goodToken"]), 0, 2));
    });

    check('so does ?key=', function() use ($httpGet, $goodToken, $okReport) {
        return $okReport(...array_slice($httpGet('?key=' . $goodToken), 0, 2));
    });

    addAlert('warning');

    check('a firing alert answers 503 with the alert listed', function() use ($httpGet, $goodToken) {
        [$code, $body] = $httpGet('', ["Authorization: Bearer $goodToken"]);
        $json = json_decode($body, true);

        return $code === 503 && ($json['status'] ?? null) === 'failing' && ($json['alerts'][0]['rule'] ?? null) === PREFIX . ' warning'
            && !str_contains($body, 'Internal detail') ?: "HTTP $code " . substr($body, 0, 300);
    });

    check('…and 200 with ?failOn=critical when only a warning fires', function() use ($httpGet, $goodToken) {
        [$code, $body] = $httpGet('?failOn=critical', ["Authorization: Bearer $goodToken"]);

        return $code === 200 && json_decode($body, true)['failOn'] === 'critical' ?: "HTTP $code " . substr($body, 0, 300);
    });

    check('monitor polls are not recorded as visitors', function() {
        return !VisitorRecord::find()->where(['url' => Plugin::HEALTH_PATH])->exists();
    });

    // -------------------------------------------------------------------------------------------
    section('Console');

    $craft = fn(string $args): array => [exec('php craft control-tower/status ' . $args . ' 2>&1', $out, $status) !== false ? implode("\n", $out) : '', $status];

    check('status exits 1 while a warning fires, and names it', function() use ($craft) {
        [$out, $status] = $craft('');

        return $status === 1 && str_contains($out, 'WARNING') && str_contains($out, PREFIX . ' warning') ?: "exit $status: $out";
    });

    check('--fail-on=critical exits 0 with the warning marked as below it', function() use ($craft) {
        [$out, $status] = $craft('--fail-on=critical');

        return $status === 0 && str_contains($out, 'below --fail-on') ?: "exit $status: $out";
    });

    addAlert('critical');

    check('--json prints the endpoint\'s report and exits 2 on a critical alert', function() use ($craft) {
        [$out, $status] = $craft('--json');
        $json = json_decode($out, true);

        return $status === 2 && ($json['highestSeverity'] ?? null) === 'critical' && isset($json['gauges']) ?: "exit $status: " . substr($out, 0, 300);
    });

    check('an unknown --fail-on exits 3 (unknown)', function() use ($craft) {
        [, $status] = $craft('--fail-on=everything');

        return $status === 3 ?: "exit $status";
    });

    clearAlerts();

    check('--refresh runs the alert checks first and exits 0 when nothing fires', function() use ($craft) {
        [$out, $status] = $craft('--refresh');

        return $status === 0 && str_contains($out, 'OK') ?: "exit $status: $out";
    });
} finally {
    clearAlerts();
    if ($parkedIds) {
        AlertRecord::updateAll(['isActive' => true], ['id' => $parkedIds]);
    }
    if ($parkedRuleIds) {
        AlertRuleRecord::updateAll(['isEnabled' => true], ['id' => $parkedRuleIds]);
    }

    $restored = writeSettings($storedSettings);
    if ($restored !== 'ok') {
        echo "\n  ! settings restore failed: $restored\n";
        $failed++;
    }

    $settings->healthToken = $originalToken;
    $settings->healthMaxCheckAge = $originalMaxAge;
    if ($originalLastCheck !== null) {
        $health->markChecked($originalLastCheck);
    }
    putenv('CT_HEALTH_CHECK_TOKEN');
}

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
