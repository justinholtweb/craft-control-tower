# Release Notes for Control Tower

## 5.0.1 - 2026-05-14

### Fixed
- Plugin store install failing with “Table `controltower_editor_sessions` doesn't exist” because activity tracking ran before the install migration. Tracking now waits until the plugin is fully installed.

## 5.0.0 - 2026-05-02

### Added
- Initial release of Control Tower for Craft CMS 5.
- Live operational monitoring dashboard with site activity, editor tracking, content health, queue watch, and system metrics.
