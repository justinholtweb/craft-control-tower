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
 * the metric registry (a custom metric registered through EVENT_REGISTER_METRICS and evaluated
 * by a real alert rule, malformed registrations, rules whose metric has gone away), and a smoke
 * pass over every service the dashboard reads — so a Craft upgrade that breaks a
 * query shows up here rather than as a blank widget.
 *
 * Self-cleaning: every rule and webhook it creates is named `CT check …` and removed at the end
 * (and at the start, if a dead run left any). Settings changes are made in memory only.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use justinholtweb\controltower\events\RegisterMetricsEvent;
use justinholtweb\controltower\helpers\Ip;
use justinholtweb\controltower\metrics\CallbackMetric;
use justinholtweb\controltower\metrics\Metric;
use justinholtweb\controltower\metrics\MetricInterface;
use justinholtweb\controltower\metrics\MetricPeriod;
use justinholtweb\controltower\models\AlertRule;
use justinholtweb\controltower\models\Webhook;
use justinholtweb\controltower\notifications\GenericFormatter;
use justinholtweb\controltower\notifications\NotificationContext;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\AlertRecord;
use justinholtweb\controltower\records\AlertRuleRecord;
use justinholtweb\controltower\records\WebhookRecord;
use justinholtweb\controltower\services\MetricRegistryService;
use justinholtweb\controltower\services\VisitorTrackingService;
use yii\base\Event;

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
    $ruleIds = AlertRuleRecord::find()->select('id')->where(['like', 'name', PREFIX . '%', false])->column();
    if ($ruleIds) {
        AlertRecord::deleteAll(['alertRuleId' => $ruleIds]);
    }
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
section('Metric registry');

/** A class-string registration, to prove the registry instantiates it. */
class CtCheckClassMetric extends Metric
{
    public string $handle = 'ctcheck:class';
    public string $label = 'CT check: class metric';
    public string $unit = 'things';

    public function getValue(MetricPeriod $period): ?float
    {
        return 3.0;
    }
}

$registry = $plugin->metricRegistry;
$builtIns = ['queue_failed', 'queue_pending', 'cpu_percent', 'memory_percent', 'disk_percent', 'db_response_ms',
    'editor_collisions', 'active_editors', 'stale_content_count', 'failed_logins', 'critical_updates', ];

check('the eleven built-ins are registered, all on the one contract', function() use ($registry, $builtIns) {
    $registry->reset();
    $all = $registry->all();
    $missing = array_diff($builtIns, array_keys($all));
    $foreign = array_filter($all, fn($m) => !$m instanceof MetricInterface);

    return $missing === [] && $foreign === [] ?: 'missing: ' . implode(', ', $missing);
});

// What the custom metric reports, and what the windowed one was handed.
$customValue = 7.0;
$seenPeriod = null;

$handler = function(RegisterMetricsEvent $event) use (&$customValue, &$seenPeriod) {
    // An instance…
    $event->metrics[] = new CallbackMetric([
        'handle' => 'ctcheck:custom',
        'label' => 'CT check: custom metric',
        'unit' => 'widgets',
        'description' => 'Registered in-process by the integration checks.',
        'operators' => ['>', '>='],
        'evaluator' => function() use (&$customValue) {
            return $customValue;
        },
    ]);
    // …a config array becomes a CallbackMetric…
    $event->metrics[] = [
        'handle' => 'ctcheck:windowed',
        'label' => 'CT check: windowed',
        'window' => 900,
        'evaluator' => function(MetricPeriod $period) use (&$seenPeriod) {
            $seenPeriod = $period;

            return 1;
        },
    ];
    // …a class name is instantiated.
    $event->metrics[] = CtCheckClassMetric::class;
    $event->metrics[] = ['handle' => 'ctcheck:throws', 'label' => 'CT check: throws', 'evaluator' => fn() => throw new RuntimeException('boom')];
    $event->metrics[] = ['handle' => 'ctcheck:nan', 'label' => 'CT check: NaN', 'evaluator' => fn() => NAN];

    // Malformed registrations are skipped, never fatal.
    $event->metrics[] = ['handle' => '9 bad handle', 'label' => 'Bad handle', 'evaluator' => fn() => 1];
    $event->metrics[] = ['handle' => 'ctcheck:nolabel', 'label' => '', 'evaluator' => fn() => 1];
    $event->metrics[] = ['handle' => 'ctcheck:badops', 'label' => 'Bad ops', 'operators' => ['~='], 'evaluator' => fn() => 1];
    $event->metrics[] = ['handle' => 'ctcheck:badwindow', 'label' => 'Bad window', 'window' => -5, 'evaluator' => fn() => 1];
    $event->metrics[] = ['handle' => str_repeat('x', 51), 'label' => 'Too long', 'evaluator' => fn() => 1];
    $event->metrics[] = stdClass::class;
    $event->metrics[] = 'No\\Such\\ClassAnywhere';
    $event->metrics[] = 42;
    // A duplicate of a built-in loses to the built-in.
    $event->metrics[] = ['handle' => 'cpu_percent', 'label' => 'Hijacked CPU', 'evaluator' => fn() => 100];
};

Event::on(MetricRegistryService::class, MetricRegistryService::EVENT_REGISTER_METRICS, $handler);
$registry->reset();

check('metrics registered through EVENT_REGISTER_METRICS appear alongside the built-ins', function() use ($registry, $builtIns) {
    $options = $registry->options();
    foreach (['ctcheck:custom', 'ctcheck:windowed', 'ctcheck:class'] as $handle) {
        if (!isset($options[$handle])) {
            return "$handle missing";
        }
    }

    return array_diff($builtIns, array_keys($options)) === [] && $options['ctcheck:custom'] === 'CT check: custom metric';
});

check('a config array becomes a CallbackMetric, a class name is instantiated', function() use ($registry) {
    return $registry->get('ctcheck:windowed') instanceof CallbackMetric
        && $registry->get('ctcheck:class') instanceof CtCheckClassMetric
        && $registry->evaluate('ctcheck:class') === 3.0;
});

check('malformed registrations are skipped without throwing', function() use ($registry) {
    $all = $registry->all();
    foreach (['9 bad handle', 'ctcheck:nolabel', 'ctcheck:badops', 'ctcheck:badwindow', str_repeat('x', 51)] as $handle) {
        if (isset($all[$handle])) {
            return "$handle was registered";
        }
    }

    return true;
});

check('a duplicate handle cannot replace a built-in', function() use ($registry) {
    return $registry->get('cpu_percent')?->getLabel() === 'Server: CPU usage';
});

check('a metric that throws or returns NaN evaluates to null, not an exception', function() use ($registry) {
    return $registry->evaluate('ctcheck:throws') === null && $registry->evaluate('ctcheck:nan') === null;
});

check('a windowed metric is handed a period of its window', function() use ($registry, &$seenPeriod) {
    $now = new DateTimeImmutable('2026-10-08 12:00:00');
    $registry->evaluate('ctcheck:windowed', $now);

    return $seenPeriod instanceof MetricPeriod && $seenPeriod->getSeconds() === 900 && $seenPeriod->end == $now;
});

check('a point-in-time metric gets an instant period', function() {
    return MetricPeriod::forWindow(null)->isInstant() && MetricPeriod::forWindow(3600)->getSeconds() === 3600;
});

check('snapshot evaluates only registered handles', function() use ($registry, &$customValue) {
    $customValue = 7.0;
    $rows = $registry->snapshot(['ctcheck:custom', 'nope', '../../etc/passwd']);

    return array_keys($rows) === ['ctcheck:custom'] && $rows['ctcheck:custom']['value'] === 7.0
        && $rows['ctcheck:custom']['formatted'] === '7 widgets' ?: json_encode($rows);
});

check('a rule only saves with an operator its metric allows', function() use ($plugin) {
    $rule = new AlertRule(['name' => PREFIX . ' bad op', 'metric' => 'ctcheck:custom', 'operator' => '<', 'threshold' => 1]);
    $cpu = new AlertRule(['name' => PREFIX . ' cpu eq', 'metric' => 'cpu_percent', 'operator' => '=', 'threshold' => 50]);

    return !$plugin->alerts->saveRule($rule) && $rule->hasErrors('operator')
        && !$plugin->alerts->saveRule($cpu) && $cpu->hasErrors('operator') ?: json_encode([$rule->getErrors(), $cpu->getErrors()]);
});

check('an alert rule on the custom metric fires, carries it into the webhook payload, and resolves', function() use ($plugin, &$customValue) {
    $rule = new AlertRule([
        'name' => PREFIX . ' custom',
        'metric' => 'ctcheck:custom',
        'operator' => '>=',
        'threshold' => 5,
        'severity' => 'warning',
    ]);
    if (!$plugin->alerts->saveRule($rule)) {
        return json_encode($rule->getErrors());
    }

    $customValue = 7.0;
    $observed = $plugin->alerts->checkRule($rule);
    /** @var AlertRecord|null $alert */
    $alert = AlertRecord::find()->where(['alertRuleId' => $rule->id, 'isActive' => true])->one();
    if ($observed !== 7.0 || !$alert) {
        return 'did not fire: ' . var_export($observed, true);
    }

    $context = json_decode((string)$alert->context, true);
    if (($context['metricLabel'] ?? null) !== 'CT check: custom metric' || !str_contains((string)$alert->message, '7 widgets')) {
        return 'context/message: ' . $alert->message . ' ' . $alert->context;
    }

    $payload = GenericFormatter::format(new NotificationContext('firing', $alert, $rule));
    if ($payload['metric'] !== 'ctcheck:custom' || $payload['metricLabel'] !== 'CT check: custom metric'
        || $payload['metricUnit'] !== 'widgets' || $payload['metricAvailable'] !== true
        || ($payload['alertContext']['observedValue'] ?? null) != 7) {
        return 'payload: ' . json_encode($payload);
    }

    $customValue = 1.0;
    $plugin->alerts->checkRule($rule);

    return !AlertRecord::find()->where(['alertRuleId' => $rule->id, 'isActive' => true])->exists();
});

check('runChecks evaluates registered metrics too (the job path)', function() use ($plugin, &$customValue) {
    $rule = new AlertRule(['name' => PREFIX . ' custom job', 'metric' => 'ctcheck:custom', 'operator' => '>', 'threshold' => 100, 'severity' => 'info']);
    if (!$plugin->alerts->saveRule($rule)) {
        return json_encode($rule->getErrors());
    }

    $customValue = 101.0;
    $plugin->alerts->runChecks();
    $fired = AlertRecord::find()->where(['alertRuleId' => $rule->id, 'isActive' => true])->exists();
    $customValue = 1.0;
    $plugin->alerts->runChecks();

    return $fired && !AlertRecord::find()->where(['alertRuleId' => $rule->id, 'isActive' => true])->exists();
});

// Unregister, as if the module providing the metric were removed.
Event::off(MetricRegistryService::class, MetricRegistryService::EVENT_REGISTER_METRICS, $handler);
$registry->reset();

check('a rule whose metric is gone loads, is flagged unavailable, and is skipped', function() use ($plugin) {
    $rule = array_values(array_filter($plugin->alerts->getRules(), fn($r) => $r->name === PREFIX . ' custom'))[0] ?? null;
    if (!$rule) {
        return 'rule not listed';
    }

    return $rule->getIsMetricAvailable() === false && $plugin->alerts->checkRule($rule) === null;
});

check('…runChecks carries on past it', function() use ($plugin) {
    $plugin->alerts->runChecks();

    return true;
});

check('…it won\'t save until it points at an available metric', function() use ($plugin) {
    $rule = array_values(array_filter($plugin->alerts->getRules(), fn($r) => $r->name === PREFIX . ' custom'))[0];
    $rejected = !$plugin->alerts->saveRule($rule) && $rule->hasErrors('metric');
    $rule->clearErrors();
    $rule->metric = 'queue_failed';

    return $rejected && $plugin->alerts->saveRule($rule) ?: json_encode($rule->getErrors());
});

check('…and a notification for it falls back to the handle', function() use ($plugin) {
    $rule = new AlertRule(['name' => PREFIX . ' gone', 'metric' => 'ctcheck:custom']);
    $ctx = new NotificationContext('firing', new AlertRecord(), $rule);
    $payload = GenericFormatter::format($ctx);

    return $ctx->metric === null && $ctx->metricLabel() === 'ctcheck:custom' && $payload['metricAvailable'] === false;
});

check('failed_logins counts accounts with a failed login inside its hour', function() use ($registry) {
    $now = new DateTimeImmutable();
    $expected = (int)(new craft\db\Query())->from(craft\db\Table::USERS)
        ->where(['>=', 'lastInvalidLoginDate', craft\helpers\Db::prepareDateForDb($now->modify('-3600 seconds'))])
        ->count();

    return $registry->get('failed_logins')?->getWindow() === 3600 && $registry->evaluate('failed_logins', $now) === (float)$expected;
});

check('critical_updates reads Craft\'s cached update info and never calls out', function() use ($registry) {
    $updates = Craft::$app->getUpdates();
    $prop = new ReflectionProperty($updates, '_updates');
    $prop->setAccessible(true);
    $original = $prop->getValue($updates);

    try {
        // In memory only — the shared cache is never written.
        $prop->setValue($updates, new craft\models\Updates([
            'cms' => ['releases' => [['version' => '99.0.0', 'critical' => true]]],
            'plugins' => [
                'one' => ['releases' => [['version' => '9.0.0', 'critical' => true]]],
                'two' => ['releases' => [['version' => '9.0.0', 'critical' => false]]],
            ],
        ]));
        $two = $registry->evaluate('critical_updates');

        $prop->setValue($updates, new craft\models\Updates([]));
        $none = $registry->evaluate('critical_updates');
    } finally {
        $prop->setValue($updates, $original);
    }

    return $two === 2.0 && $none === 0.0 ?: json_encode([$two, $none]);
});

check('the documented example module registers both of its metrics, and they evaluate', function() use ($registry) {
    require_once dirname(__DIR__, 2) . '/docs/examples/opsmetrics/PendingUsersMetric.php';
    require_once dirname(__DIR__, 2) . '/docs/examples/opsmetrics/Module.php';
    new modules\opsmetrics\Module('ct-check-ops-metrics', Craft::$app);
    $registry->reset();

    $entries = $registry->get('ops:entries_created');
    $pending = $registry->get('ops:pending_users');
    if (!$entries || !$pending || $entries->getWindow() !== 900) {
        return 'not registered';
    }

    // Over a long span the element query's date bounds must agree with a raw count.
    $end = new DateTimeImmutable();
    $start = $end->modify('-3650 days');
    $raw = (int)(new craft\db\Query())
        ->from(['e' => craft\db\Table::ELEMENTS])
        ->innerJoin(['es' => craft\db\Table::ELEMENTS_SITES], '[[es.elementId]] = [[e.id]]')
        ->where([
            'e.type' => craft\elements\Entry::class,
            'e.draftId' => null,
            'e.revisionId' => null,
            'e.dateDeleted' => null,
            'es.siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        ])
        ->andWhere(['>=', 'e.dateCreated', craft\helpers\Db::prepareDateForDb($start)])
        ->andWhere(['<', 'e.dateCreated', craft\helpers\Db::prepareDateForDb($end)])
        ->count();
    $viaMetric = $entries->getValue(new MetricPeriod($start, $end));
    $pendingUsers = (float)craft\elements\User::find()->status(craft\elements\User::STATUS_PENDING)->count();

    return $viaMetric === (float)$raw && $registry->evaluate('ops:pending_users') === $pendingUsers
        && $registry->evaluate('ops:entries_created') !== null ?: json_encode([$viaMetric, $raw]);
});

check('the widget offers every metric and renders the chosen ones as cards, skipping unknown handles', function() {
    $view = Craft::$app->getView();
    $mode = $view->getTemplateMode();
    $view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);

    try {
        $widget = new justinholtweb\controltower\widgets\ControlTowerWidget(['metrics' => ['failed_logins', 'ctcheck:gone', 'ops:pending_users']]);
        $settings = (string)$widget->getSettingsHtml();
        $body = (string)$widget->getBodyHtml();
        $empty = new justinholtweb\controltower\widgets\ControlTowerWidget(['metrics' => '']);
    } finally {
        $view->setTemplateMode($mode);
    }

    return str_contains($settings, 'value="critical_updates"') && str_contains($settings, 'value="ops:pending_users"')
        && str_contains($body, 'data-ct-metric="failed_logins"') && str_contains($body, 'data-ct-metric="ops:pending_users"')
        && !str_contains($body, 'ctcheck:gone') && $empty->getMetricHandles() === [] ?: substr($body, 0, 300);
});

check('every registered metric evaluates to a number or null', function() use ($registry) {
    foreach ($registry->snapshot() as $handle => $row) {
        if ($row['value'] !== null && !is_float($row['value'])) {
            return $handle;
        }
    }

    return true;
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
