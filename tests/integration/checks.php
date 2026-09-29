<?php
/**
 * Control Tower integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-controltower/tests/integration/checks.php
 *
 * Covers the webhook SSRF guard (address ranges, scheme and credential refusals, save-time
 * validation, redirects not followed, the private-hosts escape hatch), the keyed visitor hashes,
 * and a smoke pass over every service the dashboard reads — so a Craft upgrade that breaks a
 * query shows up here rather than as a blank widget.
 *
 * Self-cleaning: every rule and webhook it creates is named `CT check …` and removed at the end
 * (and at the start, if a dead run left any). Settings changes are made in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\controltower\helpers\Ip;
use justinholtweb\controltower\models\AlertRule;
use justinholtweb\controltower\models\Webhook;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\AlertRuleRecord;
use justinholtweb\controltower\records\WebhookRecord;
use justinholtweb\controltower\services\VisitorTrackingService;

const PREFIX = 'CT check';

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

function sweep(): void
{
    WebhookRecord::deleteAll(['like', 'name', PREFIX . '%', false]);
    AlertRuleRecord::deleteAll(['like', 'name', PREFIX . '%', false]);
}

$plugin = Plugin::getInstance();
$settings = $plugin->getSettings();
$originalAllowPrivate = $settings->allowPrivateWebhookHosts;
$settings->allowPrivateWebhookHosts = false;

sweep();

// -----------------------------------------------------------------------------------------------
section('Address ranges');

check('public addresses pass', function() {
    return Ip::isPublic('1.1.1.1') && Ip::isPublic('8.8.8.8') && Ip::isPublic('2606:4700:4700::1111');
});

check('private, loopback, link-local and metadata addresses are refused', function() {
    foreach (['127.0.0.1', '10.1.2.3', '172.16.0.5', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1'] as $ip) {
        if (Ip::isPublic($ip)) {
            return "$ip passed";
        }
    }

    return true;
});

check('IPv4 wearing an IPv6 hat is unwrapped and refused', function() {
    return !Ip::isPublic('::ffff:127.0.0.1') && !Ip::isPublic('::ffff:169.254.169.254') && !Ip::isPublic('::127.0.0.1');
});

check('garbage is refused, not waved through', function() {
    return !Ip::isPublic('') && !Ip::isPublic('not-an-ip') && !Ip::isPublic('999.1.1.1');
});

check('a host resolving to loopback is refused', function() {
    return Ip::resolvePublic('localhost') === [] && Ip::resolvePublic('127.0.0.1') === [];
});

// -----------------------------------------------------------------------------------------------
section('Webhook targets');

$notifier = $plugin->alertNotifier;

check('metadata service URL is refused with a reason', function() use ($notifier) {
    $t = $notifier->webhookTarget('http://169.254.169.254/latest/meta-data/');

    return is_string($t) && str_contains($t, 'public') ?: json_encode($t);
});

check('loopback with a port is refused', function() use ($notifier) {
    return is_string($notifier->webhookTarget('http://127.0.0.1:6379/'));
});

check('non-http schemes are refused', function() use ($notifier) {
    foreach (['file:///etc/passwd', 'gopher://example.com/', 'ftp://example.com/', 'dict://127.0.0.1:11211/', '//example.com/'] as $url) {
        if (!is_string($notifier->webhookTarget($url))) {
            return "$url accepted";
        }
    }

    return true;
});

check('credentials in the URL are refused', function() use ($notifier) {
    return is_string($notifier->webhookTarget('https://user:pass@example.com/hook'));
});

check('a public https host is accepted and pinned to its addresses', function() use ($notifier) {
    $t = $notifier->webhookTarget('https://hooks.slack.com/services/T000/B000/XXXX');

    if (is_string($t)) {
        return "refused: $t (no DNS in this container?)";
    }

    return $t['host'] === 'hooks.slack.com' && $t['port'] === 443 && $t['addresses'] !== []
        && array_filter($t['addresses'], fn($ip) => !Ip::isPublic($ip)) === [] ?: json_encode($t);
});

check('an explicit port is kept for the pin', function() use ($notifier) {
    $t = $notifier->webhookTarget('http://1.1.1.1:8080/hook');

    return is_array($t) && $t['port'] === 8080 && $t['addresses'] === ['1.1.1.1'] ?: json_encode($t);
});

// -----------------------------------------------------------------------------------------------
section('Sending');

check('testWebhook to loopback never connects, returns no body from the target', function() use ($notifier) {
    $w = new Webhook(['name' => PREFIX . ' loopback', 'type' => Webhook::TYPE_GENERIC, 'url' => 'http://127.0.0.1/']);
    $r = $notifier->testWebhook($w);

    return $r['ok'] === false && $r['status'] === null && str_contains($r['body'], 'public') ?: json_encode($r);
});

check('testWebhook to the metadata service is refused', function() use ($notifier) {
    $w = new Webhook(['name' => PREFIX . ' metadata', 'type' => Webhook::TYPE_SLACK, 'url' => 'http://169.254.169.254/']);
    $r = $notifier->testWebhook($w);

    return $r['status'] === null && !$r['ok'];
});

check('with allowPrivateWebhookHosts, a private host is reached — and a redirect is NOT followed', function() use ($notifier, $settings) {
    $settings->allowPrivateWebhookHosts = true;

    try {
        // The harness answers /admin with a 302 to the login page.
        $w = new Webhook(['name' => PREFIX . ' private', 'type' => Webhook::TYPE_GENERIC, 'url' => 'http://127.0.0.1/admin']);
        $r = $notifier->testWebhook($w);
    } finally {
        $settings->allowPrivateWebhookHosts = false;
    }

    return $r['status'] !== null && $r['status'] >= 300 && $r['status'] < 400 ?: json_encode(['status' => $r['status'], 'ok' => $r['ok']]);
});

// -----------------------------------------------------------------------------------------------
section('Saving webhooks');

check('a webhook pointed at a private address will not save', function() use ($plugin) {
    $w = new Webhook(['name' => PREFIX . ' private save', 'type' => Webhook::TYPE_GENERIC, 'url' => 'http://10.0.0.5/hook']);

    return !$plugin->webhooks->save($w) && $w->hasErrors('url') ?: json_encode($w->getErrors());
});

check('a public webhook saves, toggles and deletes', function() use ($plugin) {
    $w = new Webhook(['name' => PREFIX . ' public', 'type' => Webhook::TYPE_SLACK, 'url' => 'https://hooks.slack.com/services/T000/B000/XXXX']);

    if (!$plugin->webhooks->save($w)) {
        return json_encode($w->getErrors());
    }

    $toggled = $plugin->webhooks->toggle((int)$w->id);
    $ok = $toggled !== null && $toggled->isEnabled === false && $plugin->webhooks->get((int)$w->id)?->url === $w->url;
    $plugin->webhooks->delete((int)$w->id);

    return $ok && $plugin->webhooks->get((int)$w->id) === null;
});

check('the masked URL hides the secret path', function() {
    $w = new Webhook(['url' => 'https://hooks.slack.com/services/T000/B000/SECRETSECRET']);

    return !str_contains($w->maskedUrl(), 'SECRETSE');
});

// -----------------------------------------------------------------------------------------------
section('Visitor hashes');

$service = $plugin->visitorTracking;
$keyed = new ReflectionMethod(VisitorTrackingService::class, '_keyedHash');
$keyed->setAccessible(true);

check('the IP hash is not a plain SHA-256 of the IP', function() use ($keyed, $service) {
    $h = $keyed->invoke($service, '203.0.113.7');

    return $h !== hash('sha256', '203.0.113.7') && strlen($h) === 64;
});

check('it is keyed on the security key and the day', function() use ($keyed, $service) {
    $key = Craft::$app->getConfig()->getGeneral()->securityKey . '|control-tower|' . date('Y-m-d');

    return $keyed->invoke($service, '203.0.113.7') === hash_hmac('sha256', '203.0.113.7', $key);
});

check('it is stable within a day, so sessions still group', function() use ($keyed, $service) {
    return $keyed->invoke($service, '198.51.100.1|UA') === $keyed->invoke($service, '198.51.100.1|UA');
});

check('no stored IP hash is a plain SHA-256 of a common address', function() {
    $plain = array_map(fn($ip) => hash('sha256', $ip), ['127.0.0.1', '::1', '172.18.0.1', '192.168.65.1']);

    return !(new craft\db\Query())->from('{{%controltower_visitors}}')->where(['ipHash' => $plain])->exists();
});

// -----------------------------------------------------------------------------------------------
section('Alert rules');

check('a rule saves, toggles and deletes', function() use ($plugin) {
    $rule = new AlertRule();
    $rule->name = PREFIX . ' cpu';
    $rule->metric = array_key_first($plugin->metricRegistry->options()) ?? 'cpu_percent';
    $rule->operator = '>=';
    $rule->threshold = 99;
    $rule->severity = 'warning';

    if (!$plugin->alerts->saveRule($rule)) {
        return json_encode($rule->getErrors());
    }

    $plugin->alerts->toggleRule((int)$rule->id);
    $disabled = $plugin->alerts->getRule((int)$rule->id)?->isEnabled === false;
    $plugin->alerts->deleteRule((int)$rule->id);

    return $disabled && $plugin->alerts->getRule((int)$rule->id) === null;
});

check('every registered metric evaluates without throwing', function() use ($plugin) {
    foreach (array_keys($plugin->metricRegistry->all()) as $key) {
        $plugin->metricRegistry->evaluate($key);
    }

    return count($plugin->metricRegistry->all()) > 0;
});

// -----------------------------------------------------------------------------------------------
section('Dashboard data');

$reads = [
    'visitor count' => fn() => $plugin->visitorTracking->getActiveVisitorCount(),
    'top URLs' => fn() => $plugin->visitorTracking->getTopUrls(),
    'traffic breakdown' => fn() => $plugin->visitorTracking->getTrafficBreakdown(),
    'active editors' => fn() => $plugin->editorTracking->getActiveEditors(),
    'editor collisions' => fn() => $plugin->editorTracking->getCollisions(),
    'editor timeline' => fn() => $plugin->editorTracking->getEditorTimeline(),
    'content summary' => fn() => $plugin->contentHealth->getContentSummary(),
    'content pipeline' => fn() => $plugin->contentHealth->getContentPipeline(),
    'stale entries' => fn() => $plugin->contentHealth->getStaleEntries(),
    'asset volumes' => fn() => $plugin->contentHealth->getAssetVolumeSummary(),
    'queue summary' => fn() => $plugin->queueMonitor->getSummary(),
    'queue health' => fn() => $plugin->queueMonitor->getQueueHealth(),
    'server health' => fn() => $plugin->metricsCollector->getServerHealth(),
    'metric timeline' => fn() => $plugin->metricsCollector->getMetricTimeline('cpu_percent'),
    'active alerts' => fn() => $plugin->alerts->getActiveAlerts(),
    'alert history' => fn() => $plugin->alerts->getAlertHistory(),
];

foreach ($reads as $label => $read) {
    check("$label reads without throwing", function() use ($read) {
        $read();

        return true;
    });
}

// -----------------------------------------------------------------------------------------------
sweep();
$settings->allowPrivateWebhookHosts = $originalAllowPrivate;

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
