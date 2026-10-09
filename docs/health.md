# Health endpoint and status command

Control Tower can answer an outside uptime monitor (Oh Dear, Better Stack, Uptime Kuma, Pingdom, a
Nagios or Zabbix check) with one verdict: is any alert firing, and are the alert checks still
running? There are two ways to ask, and both return the same report.

| | `/control-tower/health` | `php craft control-tower/status` |
|---|---|---|
| For | HTTP monitors | cron, CI, deploy scripts, command-based monitors |
| Access | token, off by default | shell access to the server |
| Healthy | HTTP 200 | exit 0 |
| Failing | HTTP 503 | exit 1 (warning/info) or 2 (critical) |
| Stalled checks, unlicensed | HTTP 503 | exit 3 |

The exit codes follow the Nagios plugin convention: 0 OK, 1 WARNING, 2 CRITICAL, 3 UNKNOWN.

## What counts as failing

- **A firing alert** at or above the *fail-on* severity. The default, `info`, means any firing
  alert. Pass `failOn=critical` (HTTP) or `--fail-on=critical` (console) to page only on critical
  rules; alerts below it are still listed, marked `"countsTowardStatus": false`.
- **Stalled checks.** Alerts can only fire when `RunAlertChecksJob` runs. If the last sweep is
  older than the **Stalled Check Threshold** setting (30 minutes by default; 0 turns this off),
  the status is `stale`. A queue that has stopped would otherwise look exactly like a healthy site.
  The time of the last sweep lives in Craft's data cache, so straight after clearing caches the
  report says it hasn't been recorded yet and doesn't fail on it.
- **No license.** An unlicensed install collects and checks nothing, so it reports `unlicensed`
  rather than a reassuring `ok`.

## Turning on the HTTP endpoint

The endpoint is off until a token is set. Generate a long random value and put it in `.env`:

```bash
openssl rand -hex 32
```

```dotenv
CONTROL_TOWER_HEALTH_TOKEN="9f2c…"
```

Then, under **Control Tower → Settings → Health Endpoint**, set **Health Token** to
`$CONTROL_TOWER_HEALTH_TOKEN`. Or in `config/control-tower.php`:

```php
return [
    'healthToken' => '$CONTROL_TOWER_HEALTH_TOKEN',
];
```

The value is resolved with `App::parseEnv()`. The endpoint stays closed (a plain 404) when:

- the setting is empty,
- it names an environment variable that isn't defined, or
- the resolved token is shorter than 32 characters.

A literal token typed into the setting works, but it is then stored in project config and visible
to anyone who can read your repository, so prefer the environment variable.

### Sending the token

Monitors send the token in one of three ways, in this order of preference:

```
Authorization: Bearer <token>
X-Control-Tower-Token: <token>
https://example.com/control-tower/health?key=<token>
```

Use the query string only for a monitor that can't set headers: URLs end up in access logs. (It
is `key`, not `token`, because Craft 5.9+ rejects requests whose `token` parameter it doesn't
recognise.)

The token is compared exactly and in constant time. A missing or wrong token gets a `401` whose
body is `{"error": "A valid token is required."}` and nothing else.

### Monitor setup

- **Oh Dear / Better Stack / Uptime Kuma**: add an HTTP check on `https://your-site/control-tower/health`,
  add the `Authorization: Bearer …` header, and alert on any non-2xx status. Optionally add a
  keyword check for `"status":"ok"`.
- Add `?failOn=critical` to the URL to page only on critical rules.
- The response is `Cache-Control: no-store` and the requests are not counted as visitors.

## The report

```json
{
    "status": "failing",
    "failOn": "info",
    "highestSeverity": "critical",
    "checkedAt": "2026-10-09T12:51:21+00:00",
    "lastAlertCheckAt": "2026-10-09T12:50:00+00:00",
    "checksStale": false,
    "alerts": [
        {
            "rule": "Disk usage critical",
            "severity": "critical",
            "metric": "disk_percent",
            "observed": 94,
            "operator": ">=",
            "threshold": 90,
            "unit": "%",
            "since": "2026-10-09T09:12:00+00:00",
            "countsTowardStatus": true
        }
    ],
    "gauges": {
        "queue_failed": { "label": "Queue: failed jobs", "unit": "jobs", "value": 0 },
        "queue_pending": { "label": "Queue: pending jobs", "unit": "jobs", "value": 3 },
        "cpu_percent": { "label": "Server: CPU usage", "unit": "%", "value": 30.3 },
        "memory_percent": { "label": "Server: memory usage", "unit": "%", "value": 15.6 },
        "disk_percent": { "label": "Server: disk usage", "unit": "%", "value": 94 },
        "db_response_ms": { "label": "Server: database response time", "unit": "ms", "value": 0.2 }
    }
}
```

`status` is one of `ok`, `failing`, `stale` or `unlicensed`. A gauge whose value can't be read on
this server is `null`. When unlicensed, `alerts` and `gauges` are empty.

The report never includes alert messages, hostnames, file paths, Craft or PHP versions, the
environment name, user names or IP addresses: monitoring services keep response bodies, and
the token is shared with them.

## The status command

```bash
php craft control-tower/status                    # human-readable, exit code as above
php craft control-tower/status --fail-on=critical # only critical alerts fail it
php craft control-tower/status --json             # the endpoint's report as JSON
php craft control-tower/status --refresh          # evaluate every rule now, then report
```

`--refresh` runs the same sweep as `RunAlertChecksJob`, so alerts it opens or resolves send their
notifications as usual. The command needs no token.
