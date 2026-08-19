# Release Notes for Control Tower

## Unreleased

### Added
- **Craftnet license validation.** Control Tower now checks its license key status through Craft's built-in Craftnet integration and gates itself when the install isn't licensed. Enforcement is strict: only `valid` and `trial` unlock the plugin.
- **License screen** at Control Tower → License, showing the current status, Craft's license issues, and a key field (admins only). This page is never gated — it's how a locked install gets unlocked.
- `disableLicenseEnforcement` config setting for CI and unrecognised staging domains. Set it in `config/control-tower.php`; it is intentionally not exposed in the CP.
- **Refresh status** button on the license screen, and an automatic re-check when a key is saved, so a valid key unlocks the install immediately instead of waiting for Craft's next scheduled update check.

### Notes on expired licenses
- An expired license does not lock the plugin. Licenses are perpetual for versions released before they expire, so an install that lapses its renewal and stays on its current version keeps reporting `valid` and keeps working. Only `astray` — installed version newer than the license covers — locks the plugin, and Craftnet determines that itself.
- From `astray`, the license screen presents both remedies as equally valid: renew to cover the newer version, or downgrade to the last covered version and continue without renewing.

### Changed
- A locked install collapses its CP nav to the License item, redirects all Control Tower pages there, returns `402` from the JSON API, and renders a locked notice in the dashboard widget.
- A locked install stops collecting visitor, editor, content, and metrics data, and stops running alert checks and notifications. Retention cleanup still runs, and no existing data is deleted.
- Installs on domains Craft considers testable (local/dev) are never gated. The verdict is mirrored into the cache so console and queue requests agree with web requests.

## 5.1.3 - 2026-08-19

### Fixed
- **Plugin settings are saved again.** Every field in the settings screen was named
  `settings[…]`, but both screens that render those fields already apply the `settings`
  namespace themselves — so the values posted as `settings[settings][…]` and Craft's
  `plugins/save-plugin-settings` action ignored them. The screen reported "Plugin settings
  saved" and discarded every edit. Fields are now emitted bare, and Control Tower's own
  Settings tab wraps them in `{% namespace 'settings' %}` to match the Settings → Plugins
  screen, which Craft namespaces for it.

### Note on 5.1.2
- The `5.1.2` tag was originally published against the 5.1.1 commit, so the fixes listed
  under 5.1.2 below never actually reached anyone. The tag has been corrected, and 5.1.3
  contains that work regardless — if you are coming from 5.1.1 or earlier, upgrading to
  5.1.3 picks up both releases.

## 5.1.2 - 2026-07-19

### Fixed
- Alert `context` and rule `webhookIds` are no longer stored as the literal `false` when `json_encode()` fails; they now fall back to `null`.
- Server metric collection no longer passes a `false` file-read result into `preg_match()`/`substr_count()` when `/proc/meminfo` or `/proc/cpuinfo` can't be read, so CPU/memory sampling degrades gracefully instead of misreporting.
- Content events now cast the current user id to an integer before storing, avoiding a type mismatch on the `userId` column.

### Added
- PHPStan static analysis is wired up for the plugin (`craftcms/phpstan` dev dependency, `phpstan.neon` at level 5, and a `composer phpstan` script). The `src/` tree passes cleanly.

## 5.1.1 - 2026-05-17

### Fixed
- Republish 5.1.0 under a corrected tag so Packagist accepts it. The 5.1.0 tag was placed on a commit whose `composer.json` still read `5.0.2`, causing Packagist to skip it.

## 5.1.0 - 2026-05-14

### Added
- **User-configurable alert rules.** New Alerts → Rules section lets users define alert conditions in the CMS without editing config. Each rule has a metric, operator, threshold, severity, and enable toggle.
- **Webhook notifications** for Slack, Microsoft Teams (Power Automate / Adaptive Cards), and generic JSON receivers like Zapier. Defined once globally and referenced per-rule.
- **Per-rule email recipients.** “Notify admins” toggle plus a freeform list of additional email addresses.
- **Send test** button on the webhook edit page — fires a sample payload and reports delivery status inline.
- **Flap throttle** — `minNotifyInterval` (minutes) per rule suppresses repeat notifications when an alert flaps. Alert still appears in the CP regardless.
- **Notify on resolve** option per rule.
- **User permissions** — `controltower:viewDashboard`, `controltower:manageAlerts`, and `controltower:manageSettings`. CP navigation respects them so non-admin users can be granted scoped access.
- **Metric registry** — extensible list of metrics that rules can target: queue failed/pending, CPU/memory/disk %, editor collisions, active editor count, stale content count.
- Alerts now record which rule fired them via a new `alertRuleId` foreign key; the alerts UI shows the rule name instead of the internal type slug.
- Email template at `templates/_cp/_emails/alert.twig`, overrideable by site builders.
- `SendAlertNotificationJob` queue job — notifications dispatch asynchronously so SMTP / webhook latency doesn't block the request that triggers the alert.

### Changed
- The three hardcoded checks (queue failure, editor collisions, server resources) are now data — seeded as default rules on install. Existing installs are migrated automatically by the install of the new schema. The corresponding settings (`queueFailureAlertThreshold`, `enableCollisionDetection`) are now ignored; configure the seeded rules instead.
- `Plugin::PERMISSION_VIEW` replaces the implicit `accessPlugin-control-tower` requirement in controllers.

### Migration
- New tables: `controltower_alert_rules`, `controltower_webhooks`.
- New column: `alertRuleId` on `controltower_alerts` (FK to alert rules, SET NULL on delete).
- Schema version bumped to 1.1.0.

## 5.0.2 - 2026-05-14

### Fixed
- Install migration now skips table creation if the table already exists, so installing on a load-balanced environment with a shared database no longer fails on the second node.

## 5.0.1 - 2026-05-14

### Fixed
- Plugin store install failing with “Table `controltower_editor_sessions` doesn't exist” because activity tracking ran before the install migration. Tracking now waits until the plugin is fully installed.
- Correct documentation URL in composer.json to point to https://craft-controltower.com/docs instead of the placeholder URL. (Thanks @brandonkelly!)    

## 5.0.0 - 2026-05-02

### Added
- Initial release of Control Tower for Craft CMS 5.
- Live operational monitoring dashboard with site activity, editor tracking, content health, queue watch, and system metrics.
