# Release Notes for Control Tower

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
