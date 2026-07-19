<?php

namespace justinholtweb\controltower\services;

use Craft;
use craft\helpers\Db;
use justinholtweb\controltower\jobs\SendAlertNotificationJob;
use justinholtweb\controltower\models\AlertRule;
use justinholtweb\controltower\Plugin;
use justinholtweb\controltower\records\AlertRecord;
use justinholtweb\controltower\records\AlertRuleRecord;
use yii\base\Component;

class AlertService extends Component
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    // ----- Rules CRUD -----

    /**
     * @return AlertRule[]
     */
    public function getRules(?bool $enabledOnly = null): array
    {
        $query = AlertRuleRecord::find();
        if ($enabledOnly === true) {
            $query->where(['isEnabled' => true]);
        }
        /** @var AlertRuleRecord[] $records */
        $records = $query->orderBy(['severity' => SORT_ASC, 'name' => SORT_ASC])->all();

        return array_map(fn($r) => $this->_recordToRule($r), $records);
    }

    public function getRule(int $id): ?AlertRule
    {
        $record = AlertRuleRecord::findOne(['id' => $id]);
        return $record ? $this->_recordToRule($record) : null;
    }

    public function saveRule(AlertRule $rule): bool
    {
        if (!$rule->validate()) {
            return false;
        }

        $record = $rule->id ? AlertRuleRecord::findOne(['id' => $rule->id]) : null;
        if (!$record) {
            $record = new AlertRuleRecord();
        }

        $record->name = $rule->name;
        $record->description = $rule->description;
        $record->metric = $rule->metric;
        $record->operator = $rule->operator;
        $record->threshold = $rule->threshold;
        $record->severity = $rule->severity;
        $record->isEnabled = $rule->isEnabled;
        $record->notifyAdmins = $rule->notifyAdmins;
        $record->notifyEmails = $rule->notifyEmails;
        $record->webhookIds = !empty($rule->webhookIds) ? (json_encode(array_values(array_map('intval', $rule->webhookIds))) ?: null) : null;
        $record->notifyOnResolve = $rule->notifyOnResolve;
        $record->minNotifyInterval = $rule->minNotifyInterval;

        if (!$record->save()) {
            foreach ($record->getErrors() as $attr => $errs) {
                foreach ($errs as $err) {
                    $rule->addError($attr, $err);
                }
            }
            return false;
        }

        $rule->id = $record->id;
        return true;
    }

    public function deleteRule(int $id): bool
    {
        $record = AlertRuleRecord::findOne(['id' => $id]);
        if (!$record) {
            return false;
        }
        return (bool) $record->delete();
    }

    public function toggleRule(int $id): ?AlertRule
    {
        $record = AlertRuleRecord::findOne(['id' => $id]);
        if (!$record) {
            return null;
        }
        $record->isEnabled = !$record->isEnabled;
        $record->save(false, ['isEnabled', 'dateUpdated']);
        return $this->_recordToRule($record);
    }

    // ----- Alert records (existing read API, with light tweaks) -----

    public function createAlert(
        string $type,
        string $severity,
        string $message,
        ?array $context = null,
        ?int $ruleId = null,
    ): void {
        // Dedupe key: rule when provided, otherwise type (back-compat).
        $existing = $this->_findActiveAlert($ruleId, $type);

        $wasInactive = $existing === null;

        if ($existing) {
            $existing->message = $message;
            $existing->severity = $severity;
            $existing->context = $context ? (json_encode($context) ?: null) : null;
            $existing->save(false);
            return;
        }

        $record = new AlertRecord();
        $record->alertRuleId = $ruleId;
        $record->type = $type;
        $record->severity = $severity;
        $record->message = $message;
        $record->context = $context ? (json_encode($context) ?: null) : null;
        $record->isActive = true;
        $record->createdAt = Db::prepareDateForDb(new \DateTime());
        $record->save(false);

        if ($wasInactive && $ruleId !== null) {
            $this->_pushNotification((int) $record->id, 'firing');
        }
    }

    public function resolveAlert(string $type, ?int $ruleId = null): void
    {
        $alert = $this->_findActiveAlert($ruleId, $type);
        if (!$alert) {
            return;
        }

        $alert->isActive = false;
        $alert->resolvedAt = Db::prepareDateForDb(new \DateTime());
        $alert->save(false);

        if ($ruleId !== null) {
            $rule = $this->getRule($ruleId);
            if ($rule && $rule->notifyOnResolve) {
                $this->_pushNotification((int) $alert->id, 'resolved');
            }
        }
    }

    public function getActiveAlerts(): array
    {
        /** @var AlertRecord[] $records */
        $records = AlertRecord::find()
            ->where(['isActive' => true])
            ->orderBy(['severity' => SORT_ASC, 'createdAt' => SORT_DESC])
            ->all();

        return array_map(fn($r) => $this->_alertToArray($r), $records);
    }

    public function getActiveAlertCount(): int
    {
        return (int) AlertRecord::find()->where(['isActive' => true])->count();
    }

    public function getAlertHistory(int $limit = 50): array
    {
        /** @var AlertRecord[] $records */
        $records = AlertRecord::find()
            ->orderBy(['createdAt' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn($r) => $this->_alertToArray($r, includeResolved: true), $records);
    }

    // ----- Checks -----

    /**
     * Evaluate every enabled rule. Called from RunAlertChecksJob.
     */
    public function runChecks(): void
    {
        $registry = Plugin::getInstance()->metricRegistry;

        foreach ($this->getRules(enabledOnly: true) as $rule) {
            if (!$registry->has($rule->metric)) {
                Craft::warning("Control Tower rule {$rule->id} references unknown metric “{$rule->metric}” — skipping.", __METHOD__);
                continue;
            }

            $value = $registry->evaluate($rule->metric);
            if ($value === null) {
                continue;
            }

            $type = "rule_{$rule->id}";

            if ($rule->evaluate($value)) {
                $unit = $registry->get($rule->metric)['unit'] ?? '';
                $valueStr = $this->_formatValue($value, $unit);
                $thresholdStr = $this->_formatValue((float) $rule->threshold, $unit);
                $message = "{$rule->name} — observed {$valueStr} (threshold {$rule->operator} {$thresholdStr}).";

                $this->createAlert(
                    type: $type,
                    severity: $rule->severity,
                    message: $message,
                    context: [
                        'metric' => $rule->metric,
                        'operator' => $rule->operator,
                        'threshold' => $rule->threshold,
                        'observedValue' => $value,
                        'unit' => $unit,
                    ],
                    ruleId: $rule->id,
                );
            } else {
                $this->resolveAlert(type: $type, ruleId: $rule->id);
            }
        }
    }

    public function cleanup(int $retentionDays = 90): int
    {
        $cutoff = Db::prepareDateForDb(new \DateTime("-{$retentionDays} days"));

        return (int) Craft::$app->getDb()->createCommand()
            ->delete('{{%controltower_alerts}}', [
                'and',
                ['isActive' => false],
                ['<', 'createdAt', $cutoff],
            ])
            ->execute();
    }

    // ----- Internals -----

    private function _findActiveAlert(?int $ruleId, string $type): ?AlertRecord
    {
        $q = AlertRecord::find()->where(['isActive' => true]);
        if ($ruleId !== null) {
            $q->andWhere(['alertRuleId' => $ruleId]);
        } else {
            $q->andWhere(['type' => $type]);
        }

        /** @var AlertRecord|null $record */
        $record = $q->one();

        return $record;
    }

    private function _pushNotification(int $alertId, string $event): void
    {
        try {
            Craft::$app->getQueue()->push(new SendAlertNotificationJob([
                'alertId' => $alertId,
                'event' => $event,
            ]));
        } catch (\Throwable $e) {
            Craft::error('Control Tower: failed to enqueue notification job: ' . $e->getMessage(), __METHOD__);
        }
    }

    private function _alertToArray(AlertRecord $record, bool $includeResolved = false): array
    {
        $ruleName = null;
        if ($record->alertRuleId) {
            $rule = AlertRuleRecord::findOne(['id' => $record->alertRuleId]);
            $ruleName = $rule?->name;
        }

        $row = [
            'id' => $record->id,
            'alertRuleId' => $record->alertRuleId,
            'type' => $record->type,
            'ruleName' => $ruleName,
            'displayLabel' => $ruleName ?? $record->type,
            'severity' => $record->severity,
            'message' => $record->message,
            'context' => $record->context ? json_decode($record->context, true) : null,
            'createdAt' => $record->createdAt,
        ];
        if ($includeResolved) {
            $row['isActive'] = (bool) $record->isActive;
            $row['resolvedAt'] = $record->resolvedAt;
        }
        return $row;
    }

    private function _recordToRule(AlertRuleRecord $record): AlertRule
    {
        $rule = new AlertRule();
        $rule->id = (int) $record->id;
        $rule->name = (string) $record->name;
        $rule->description = $record->description;
        $rule->metric = (string) $record->metric;
        $rule->operator = (string) $record->operator;
        $rule->threshold = (float) $record->threshold;
        $rule->severity = (string) $record->severity;
        $rule->isEnabled = (bool) $record->isEnabled;
        $rule->notifyAdmins = (bool) $record->notifyAdmins;
        $rule->notifyEmails = $record->notifyEmails;
        $decoded = $record->webhookIds ? json_decode((string) $record->webhookIds, true) : [];
        $rule->webhookIds = is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
        $rule->notifyOnResolve = (bool) $record->notifyOnResolve;
        $rule->minNotifyInterval = (int) $record->minNotifyInterval;
        $rule->lastNotifiedAt = $record->lastNotifiedAt;
        return $rule;
    }

    private function _formatValue(float $value, string $unit): string
    {
        $rounded = abs($value - round($value)) < 0.01 ? (string) (int) round($value) : (string) round($value, 2);
        return $unit === '%' ? "{$rounded}%" : trim("{$rounded} {$unit}");
    }
}
