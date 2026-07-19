<?php

namespace justinholtweb\controltower\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\Db;

class m260514_000000_alerts_v1 extends Migration
{
    public function safeUp(): bool
    {
        $this->_createAlertRulesTable();
        $this->_createWebhooksTable();
        $this->_addRuleIdToAlerts();
        $this->_seedDefaultRules();

        return true;
    }

    public function safeDown(): bool
    {
        $alertsSchema = $this->db->getSchema()->getTableSchema('{{%controltower_alerts}}', true);
        if ($alertsSchema !== null) {
            // Best-effort: drop FK before dropping column.
            $fks = $alertsSchema->foreignKeys;
            foreach ($fks as $name => $fk) {
                if (isset($fk['alertRuleId'])) {
                    $this->dropForeignKey($name, '{{%controltower_alerts}}');
                }
            }
            $this->dropColumn('{{%controltower_alerts}}', 'alertRuleId');
        }

        $this->dropTableIfExists('{{%controltower_webhooks}}');
        $this->dropTableIfExists('{{%controltower_alert_rules}}');

        return true;
    }

    private function _tableExists(string $table): bool
    {
        return $this->db->getSchema()->getTableSchema($table, true) !== null;
    }

    private function _createAlertRulesTable(): void
    {
        if ($this->_tableExists('{{%controltower_alert_rules}}')) {
            return;
        }

        $this->createTable('{{%controltower_alert_rules}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'description' => $this->text()->null(),
            'metric' => $this->string(50)->notNull(),
            'operator' => $this->string(5)->notNull()->defaultValue('>='),
            'threshold' => $this->float()->notNull()->defaultValue(0),
            'severity' => $this->string(20)->notNull()->defaultValue('warning'),
            'isEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'notifyAdmins' => $this->boolean()->notNull()->defaultValue(false),
            'notifyEmails' => $this->text()->null(),
            'webhookIds' => $this->text()->null(),
            'notifyOnResolve' => $this->boolean()->notNull()->defaultValue(false),
            'minNotifyInterval' => $this->integer()->notNull()->defaultValue(0),
            'lastNotifiedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%controltower_alert_rules}}', ['metric']);
        $this->createIndex(null, '{{%controltower_alert_rules}}', ['isEnabled']);
    }

    private function _createWebhooksTable(): void
    {
        if ($this->_tableExists('{{%controltower_webhooks}}')) {
            return;
        }

        $this->createTable('{{%controltower_webhooks}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'type' => $this->string(20)->notNull()->defaultValue('generic'),
            'url' => $this->string(2048)->notNull(),
            'isEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%controltower_webhooks}}', ['isEnabled']);
    }

    private function _addRuleIdToAlerts(): void
    {
        $alertsTable = $this->db->getSchema()->getTableSchema('{{%controltower_alerts}}', true);
        if ($alertsTable === null || isset($alertsTable->columns['alertRuleId'])) {
            return;
        }

        $this->addColumn('{{%controltower_alerts}}', 'alertRuleId', $this->integer()->null()->after('id'));
        $this->createIndex(null, '{{%controltower_alerts}}', ['alertRuleId']);
        $this->addForeignKey(
            null,
            '{{%controltower_alerts}}',
            'alertRuleId',
            '{{%controltower_alert_rules}}',
            'id',
            'SET NULL',
        );
    }

    private function _seedDefaultRules(): void
    {
        $now = Db::prepareDateForDb(new \DateTime());

        $existing = (new \craft\db\Query())
            ->from('{{%controltower_alert_rules}}')
            ->count();

        if ($existing > 0) {
            return;
        }

        $rows = [
            [
                'name' => 'Queue has failed jobs',
                'description' => 'Fires when failed queue jobs reach the threshold.',
                'metric' => 'queue_failed',
                'operator' => '>=',
                'threshold' => 3,
                'severity' => 'critical',
                'isEnabled' => true,
            ],
            [
                'name' => 'Multiple editors on same entry',
                'description' => 'Two or more editors currently editing the same element.',
                'metric' => 'editor_collisions',
                'operator' => '>=',
                'threshold' => 1,
                'severity' => 'warning',
                'isEnabled' => true,
            ],
            [
                'name' => 'CPU usage critical',
                'description' => 'Sustained CPU at or above 90%.',
                'metric' => 'cpu_percent',
                'operator' => '>=',
                'threshold' => 90,
                'severity' => 'critical',
                'isEnabled' => true,
            ],
            [
                'name' => 'Memory usage critical',
                'description' => 'Sustained memory at or above 90%.',
                'metric' => 'memory_percent',
                'operator' => '>=',
                'threshold' => 90,
                'severity' => 'critical',
                'isEnabled' => true,
            ],
            [
                'name' => 'Disk usage critical',
                'description' => 'Disk at or above 90% full.',
                'metric' => 'disk_percent',
                'operator' => '>=',
                'threshold' => 90,
                'severity' => 'critical',
                'isEnabled' => true,
            ],
        ];

        foreach ($rows as $row) {
            $this->insert('{{%controltower_alert_rules}}', array_merge($row, [
                'notifyAdmins' => false,
                'notifyOnResolve' => false,
                'minNotifyInterval' => 0,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ]));
        }
    }
}
