# Control Tower for Craft CMS

Live operational monitoring dashboard for Craft CMS 5. Know what's happening on your site right now and what needs attention.

## Features

- **Live Traffic** — Active visitors, requests per minute, top URLs, bot vs human breakdown
- **Editor Tracking** — Who's logged in, what they're editing, collision warnings when two editors work on the same entry
- **Content Health** — Entries by section, stale content detection, scheduled/expired entries, drafts awaiting attention, asset volume summaries
- **Queue Watch** — Waiting/running/failed jobs, common failure patterns, queue health status
- **System Pulse** — CPU, memory, disk, load average, DB response time, PHP info, uptime
- **Alerts** — Automatic warnings for queue failures, editor collisions, server resource spikes
- **Dashboard Widget** — Configurable at-a-glance summary card with auto-refresh
- **Full CP Section** — Seven-tab deep dive (Overview, Live Traffic, Editors, Content Health, Queue Watch, System Pulse, Alerts)

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Installation

### With Composer

```bash
# If developing locally, add as a path repository first:
composer config repositories.control-tower path /path/to/craft-controltower

composer require justinholtweb/craft-controltower
php craft plugin/install control-tower
```

### Manual

1. Copy the plugin to your project
2. Add the path repository to your `composer.json`:
   ```json
   "repositories": [
       { "type": "path", "url": "./plugins/control-tower" }
   ]
   ```
3. Run `composer require justinholtweb/craft-controltower`
4. Install via the CLI (`php craft plugin/install control-tower`) or through the CP under Settings > Plugins

## Configuration

After installation, visit **Control Tower > Settings** in the control panel to configure:

### Tracking

| Setting | Default | Description |
|---------|---------|-------------|
| Track Visitors | On | Enable front-end visitor tracking via request logging |
| Track Editors | On | Track CP user activity and element editing |
| Track Server Metrics | On | Periodically sample CPU, memory, disk, and DB metrics |
| Collision Detection | On | Alert when multiple editors work on the same content |

### Refresh & Timeouts

| Setting | Default | Description |
|---------|---------|-------------|
| Refresh Interval | 30s | How often the dashboard auto-refreshes |
| Visitor Timeout | 2 min | Minutes before a visitor is considered inactive |
| Editor Timeout | 5 min | Minutes before an editor session is considered inactive |

### Data Retention

| Setting | Default | Description |
|---------|---------|-------------|
| Visitor Data | 30 days | |
| Editor Data | 90 days | |
| Content Events | 90 days | |
| Metric Samples | 30 days | |
| Alert History | 90 days | |

### Alert Thresholds

| Setting | Default | Description |
|---------|---------|-------------|
| Queue Failure Threshold | 3 | Failed jobs before triggering an alert |
| 5xx Error Rate | 10/min | Errors per minute before alert |
| 404 Spike | 50/min | 404s per minute before alert |
| Stale Content | 90 days | Days without update before flagging |

## Scheduled Jobs

Control Tower includes three queue jobs that should be run on a schedule via cron:

```bash
# Collect server metrics (every 1-2 minutes)
php craft queue/push justinholtweb\\controltower\\jobs\\CollectMetricsJob

# Run alert checks (every 5 minutes)
php craft queue/push justinholtweb\\controltower\\jobs\\RunAlertChecksJob

# Data retention cleanup (daily)
php craft queue/push justinholtweb\\controltower\\jobs\\CleanupJob
```

Or push them programmatically:

```php
use justinholtweb\controltower\jobs\CollectMetricsJob;
use justinholtweb\controltower\jobs\RunAlertChecksJob;
use justinholtweb\controltower\jobs\CleanupJob;

Craft::$app->getQueue()->push(new CollectMetricsJob());
Craft::$app->getQueue()->push(new RunAlertChecksJob());
Craft::$app->getQueue()->push(new CleanupJob());
```

## Dashboard Widget

Add the **Control Tower** widget to any user's dashboard. The widget is configurable:

- Toggle visibility for each panel (visitors, editors, queue, server, alerts, content, top URLs)
- Set a custom refresh interval
- Resize to any column span

The widget links to the full CP section for deeper investigation.

## Architecture

```
Plugin.php              → Event wiring, CP nav, settings
controllers/
  DashboardController   → 8 CP page actions
  ApiController         → 8 JSON endpoints for live polling
services/
  VisitorTrackingService   → Session-hash tracking, bot detection
  EditorTrackingService    → CP route parsing, collision detection
  ContentHealthService     → Stale/scheduled/expired content, pipeline
  QueueMonitorService      → Queue health, failed jobs
  MetricsCollectorService  → CPU/memory/disk/DB, cross-platform
  AlertService             → Alert lifecycle, automated checks
records/                → ActiveRecord models (6 tables)
migrations/Install.php → Database schema
jobs/                   → CleanupJob, CollectMetricsJob, RunAlertChecksJob
widgets/                → Dashboard widget
assets/                 → CSS + JS with auto-refresh polling
templates/              → CP section (7 tabs) + widget
```

## Privacy

- Visitor IP addresses are stored as SHA-256 hashes, never in plain text
- User agents are hashed for bot detection grouping
- Session identity uses a daily-rotating hash of IP + user agent
- All tracking data is automatically purged based on retention settings
- Visitor tracking can be fully disabled in settings

## Roadmap

**v1.5** — Trend charts (15m / 1h / 24h / 7d), per-section content health, 404 and error rate trends, configurable alert thresholds

**v2** — Slack/email alert notifications, deployment awareness (git SHA, last deploy, environment), cache metrics, database slow query panel, multi-site comparisons

## License

See [LICENSE.md](LICENSE.md).
