<?php
/**
 * Control Tower control panel checks, over real HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-controltower/tests/integration/http.php
 *
 * Every dashboard screen renders, the webhook test action demands a signed-in user and a CSRF
 * token, refuses internal URLs through the real controller, and never echoes a response body.
 * Registered metrics reach the rule editor, System Pulse and the widget API; a rule whose metric
 * is no longer registered lists and opens as unavailable instead of failing.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

$base = getenv('CT_CP_BASE') ?: null;
$user = getenv('CT_CP_USER') ?: 'admin';
$pass = getenv('CT_CP_PASS') ?: 'claudepassword';
$host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
// The site's own host, resolved to this container.
$base ??= 'http://' . $host;
$jar = tempnam(sys_get_temp_dir(), 'ct');

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;
    try {
        $r = $test();
        if ($r === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }
        $failed++;
        echo "  ✗ $label\n    " . (is_string($r) ? $r : var_export($r, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . $e->getMessage() . "\n";
    }
}

function http(string $method, string $path, array $post = [], array $headers = []): array
{
    global $base, $jar, $host;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => $headers,
        // Craft's `requireUserAgentAndIpForSession` refuses a login with no User-Agent, and PHP's
        // curl sends none — the login answers “Invalid username or password.”
        CURLOPT_USERAGENT => 'controltower-http-checks',
        CURLOPT_RESOLVE => ["$host:443:127.0.0.1", "$host:80:127.0.0.1"],

        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ]);
    global $lastLocation;
    $lastLocation = null;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($ch, $line) {
        global $lastLocation;
        if (stripos($line, 'Location:') === 0) {
            $lastLocation = trim(substr($line, 9));
        }
        return strlen($line);
    });
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, $body];
}

function csrf(string $html): ?string
{
    return preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $html, $m)
        || preg_match('/"csrfTokenValue":"([^"]+)"/', $html, $m) ? stripcslashes($m[1]) : null;
}

$cp = '/' . Craft::$app->getConfig()->getGeneral()->cpTrigger;

echo "\nAnonymous\n";

check('the dashboard sends an anonymous visitor to login', function() use ($cp) {
    [$code] = http('GET', "$cp/control-tower");
    global $lastLocation;
    return $code === 302 && str_contains((string)$lastLocation, 'login') ?: "HTTP $code";
});

check('webhook test refuses an anonymous POST', function() use ($cp) {
    [, $html] = http('GET', "$cp/login");
    [$code] = http('POST', "$cp/actions/control-tower/webhooks/test", ['CRAFT_CSRF_TOKEN' => csrf($html), 'url' => 'http://127.0.0.1/'], ['Accept: application/json']);
    return in_array($code, [400, 401, 403], true) ?: "HTTP $code";
});

echo "\nSigned in\n";
check('the harness admin can sign in', function() use ($cp, $user, $pass) {
    [, $html] = http('GET', "$cp/login");
    [$code, $body] = http('POST', "$cp/actions/users/login", [
        'CRAFT_CSRF_TOKEN' => csrf($html), 'loginName' => $user, 'password' => $pass,
    ], ['Accept: application/json']);
    if ($code === 200 && str_contains($body, '"returnUrl"')) {
        return true;
    }
    // The shared harness's password login is unreliable (sibling plugins hook the login). Craft's
    // own impersonation link signs in without one.
    exec('php craft users/impersonate ' . escapeshellarg($user) . ' 2>&1', $out);
    if (!preg_match('#https?://\S+#', implode("\n", $out), $m)) {
        return 'password login failed and no impersonation URL: ' . implode(' ', $out);
    }
    $url = parse_url($m[0]);
    http('GET', $url['path'] . '?' . ($url['query'] ?? ''));
    [$code] = http('GET', "$cp/dashboard");
    return $code === 200 ?: "impersonation did not sign in (dashboard HTTP $code)";
});



$pageHtml = '';

foreach (['control-tower', 'control-tower/visitors', 'control-tower/editors', 'control-tower/content', 'control-tower/queue',
    'control-tower/system', 'control-tower/alerts', 'control-tower/rules', 'control-tower/webhooks', 'control-tower/webhooks/new', ] as $path) {
    check("CP $path renders", function() use ($cp, $path, &$pageHtml) {
        [$code, $html] = http('GET', "$cp/$path");
        if ($path === 'control-tower/webhooks/new') {
            $pageHtml = $html;
        }
        return $code === 200 ?: "HTTP $code";
    });
}

check('webhook test without a CSRF token is refused', function() use ($cp) {
    [$code] = http('POST', "$cp/actions/control-tower/webhooks/test", ['url' => 'http://127.0.0.1/'], ['Accept: application/json']);
    return $code === 400 ?: "HTTP $code";
});

foreach ([
    'the metadata service' => 'http://169.254.169.254/latest/meta-data/',
    'loopback' => 'http://127.0.0.1:3306/',
    'a file URL' => 'file:///etc/passwd',
] as $label => $url) {
    check("webhook test refuses $label, with no body in the response", function() use ($cp, $url, &$pageHtml) {
        [$code, $body] = http('POST', "$cp/actions/control-tower/webhooks/test", [
            'CRAFT_CSRF_TOKEN' => csrf($pageHtml), 'name' => 'CT check', 'type' => 'generic', 'url' => $url,
        ], ['Accept: application/json']);
        $json = json_decode($body, true);
        return $code === 200 && ($json['success'] ?? true) === false && array_key_exists('status', $json) && $json['status'] === null
            && !array_key_exists('body', $json) && !str_contains($body, 'root:') ?: "HTTP $code $body";
    });
}

echo "\nMetric registry\n";

check('the rule editor offers the new built-in metrics', function() use ($cp) {
    [$code, $html] = http('GET', "$cp/control-tower/rules/new");
    foreach (['db_response_ms', 'failed_logins', 'critical_updates', 'queue_failed'] as $handle) {
        if (!str_contains($html, 'value="' . $handle . '"')) {
            return "HTTP $code, no $handle option";
        }
    }
    return $code === 200;
});

check('System Pulse lists every registered metric', function() use ($cp) {
    [$code, $html] = http('GET', "$cp/control-tower/system");
    return $code === 200 && str_contains($html, 'Alert Metrics') && str_contains($html, '<code>failed_logins</code>')
        && str_contains($html, '<code>cpu_percent</code>') ?: "HTTP $code";
});

check('the widget API evaluates only registered metric handles', function() use ($cp) {
    [$code, $body] = http('GET', "$cp/actions/control-tower/api/widget?metrics=" . rawurlencode('cpu_percent,../../etc/passwd,nope,queue_failed'), [], ['Accept: application/json']);
    $json = json_decode($body, true);
    return $code === 200 && array_keys($json['metrics'] ?? []) === ['queue_failed', 'cpu_percent']
        && array_key_exists('formatted', $json['metrics']['cpu_percent']) ?: "HTTP $code " . substr($body, 0, 300);
});

check('the system API includes every metric', function() use ($cp) {
    [$code, $body] = http('GET', "$cp/actions/control-tower/api/system", [], ['Accept: application/json']);
    $json = json_decode($body, true);
    return $code === 200 && isset($json['metrics']['critical_updates'], $json['metrics']['db_response_ms']) ?: "HTTP $code";
});

// A rule left behind by a removed plugin/module: written straight to the table, as validation
// would (rightly) refuse it.
$gone = new justinholtweb\controltower\records\AlertRuleRecord();
$gone->name = 'CT check gone metric';
$gone->metric = 'ctcheck:gone';
$gone->operator = '>=';
$gone->threshold = 1;
$gone->severity = 'warning';
$gone->isEnabled = true;
$gone->save(false);
$goneId = (int)$gone->id;

check('a rule on an unregistered metric is listed as unavailable', function() use ($cp) {
    [$code, $html] = http('GET', "$cp/control-tower/rules");
    return $code === 200 && str_contains($html, 'ctcheck:gone') && str_contains($html, 'Unavailable') ?: "HTTP $code";
});

$editHtml = '';
check('…and opens with a warning, keeping its stored handle selected', function() use ($cp, $goneId, &$editHtml) {
    [$code, $editHtml] = http('GET', "$cp/control-tower/rules/$goneId");
    return $code === 200 && str_contains($editHtml, 'ctcheck:gone (unavailable)') && str_contains($editHtml, 'no longer registered') ?: "HTTP $code";
});

check('…and saving it unchanged is refused with a metric error, not a 500', function() use ($cp, $goneId, &$editHtml) {
    [$code, $html] = http('POST', "$cp/control-tower/rules/$goneId", [
        'CRAFT_CSRF_TOKEN' => csrf($editHtml), 'action' => 'control-tower/rules/save', 'id' => $goneId,
        'name' => 'CT check gone metric', 'metric' => 'ctcheck:gone', 'operator' => '>=', 'threshold' => 1, 'severity' => 'warning',
    ]);
    return $code === 200 && str_contains($html, 'is not an available metric') ?: "HTTP $code";
});

check('saving a rule without a CSRF token is refused', function() use ($cp, $goneId) {
    [$code] = http('POST', "$cp/actions/control-tower/rules/save", ['id' => $goneId, 'metric' => 'queue_failed', 'name' => 'x', 'operator' => '>=', 'severity' => 'info']);
    return $code === 400 ?: "HTTP $code";
});

justinholtweb\controltower\records\AlertRuleRecord::deleteAll(['id' => $goneId]);

echo "\n$passed passed, $failed failed\n";
@unlink($jar);
exit($failed === 0 ? 0 : 1);
