<?php

namespace justinholtweb\controltower\migrations;

use craft\db\Migration;
use craft\helpers\Db;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createVisitorsTable();
        $this->_createEditorSessionsTable();
        $this->_createEditorActivityTable();
        $this->_createContentEventsTable();
        $this->_createMetricSamplesTable();
        $this->_createAlertsTable();
        $this->_createAlertRulesTable();
        $this->_createWebhooksTable();
        $this->_addRuleIdToAlerts();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%controltower_alerts}}');
        $this->dropTableIfExists('{{%controltower_webhooks}}');
        $this->dropTableIfExists('{{%controltower_alert_rules}}');
        $this->dropTableIfExists('{{%controltower_metric_samples}}');
        $this->dropTableIfExists('{{%controltower_content_events}}');
        $this->dropTableIfExists('{{%controltower_editor_activity}}');
        $this->dropTableIfExists('{{%controltower_editor_sessions}}');
        $this->dropTableIfExists('{{%controltower_visitors}}');

        return true;
    }

    private function _tableExists(string $table): bool
    {
        return $this->db->getSchema()->getTableSchema($table, true) !== null;
    }

    private function _createVisitorsTable(): void
    {
        if ($this->_tableExists('{{%controltower_visitors}}')) {
            return;
        }

        $this->createTable('{{%controltower_visitors}}', [
            'id' => $this->primaryKey(),
            'sessionHash' => $this->string(64)->notNull(),
            'ipHash' => $this->string(64)->null(),
            'userAgentHash' => $this->string(64)->null(),
            'url' => $this->string(2048)->notNull(),
            'referrer' => $this->string(2048)->null(),
            'method' => $this->string(10)->notNull()->defaultValue('GET'),
            'isGuest' => $this->boolean()->notNull()->defaultValue(true),
            'isBot' => $this->boolean()->notNull()->defaultValue(false),
            'responseCode' => $this->smallInteger()->null(),
            'firstSeenAt' => $this->dateTime()->notNull(),
            'lastSeenAt' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%controltower_visitors}}', ['sessionHash']);
        $this->createIndex(null, '{{%controltower_visitors}}', ['lastSeenAt']);
        $this->createIndex(null, '{{%controltower_visitors}}', ['isBot']);
        $this->createIndex(null, '{{%controltower_visitors}}', ['url(191)']);
    }

    private function _createEditorSessionsTable(): void
    {
        if ($this->_tableExists('{{%controltower_editor_sessions}}')) {
            return;
        }

        $this->createTable('{{%controltower_editor_sessions}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'startedAt' => $this->dateTime()->notNull(),
            'lastSeenAt' => $this->dateTime()->notNull(),
            'currentRoute' => $this->string(500)->null(),
            'currentUrl' => $this->string(2048)->null(),
            'sectionHandle' => $this->string(255)->null(),
            'elementType' => $this->string(255)->null(),
            'elementId' => $this->integer()->null(),
            'draftId' => $this->integer()->null(),
            'action' => $this->string(50)->notNull()->defaultValue('browsing'),
        ]);

        $this->createIndex(null, '{{%controltower_editor_sessions}}', ['userId']);
        $this->createIndex(null, '{{%controltower_editor_sessions}}', ['lastSeenAt']);
        $this->createIndex(null, '{{%controltower_editor_sessions}}', ['elementId']);

        $this->addForeignKey(
            null,
            '{{%controltower_editor_sessions}}',
            'userId',
            '{{%users}}',
            'id',
            'CASCADE',
        );
    }

    private function _createEditorActivityTable(): void
    {
        if ($this->_tableExists('{{%controltower_editor_activity}}')) {
            return;
        }

        $this->createTable('{{%controltower_editor_activity}}', [
            'id' => $this->primaryKey(),
            'userId' => $this->integer()->notNull(),
            'route' => $this->string(500)->notNull(),
            'url' => $this->string(2048)->null(),
            'sectionHandle' => $this->string(255)->null(),
            'elementType' => $this->string(255)->null(),
            'elementId' => $this->integer()->null(),
            'draftId' => $this->integer()->null(),
            'action' => $this->string(50)->notNull()->defaultValue('viewing'),
            'recordedAt' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%controltower_editor_activity}}', ['userId']);
        $this->createIndex(null, '{{%controltower_editor_activity}}', ['recordedAt']);
        $this->createIndex(null, '{{%controltower_editor_activity}}', ['elementId']);

        $this->addForeignKey(
            null,
            '{{%controltower_editor_activity}}',
            'userId',
            '{{%users}}',
            'id',
            'CASCADE',
        );
    }

    private function _createContentEventsTable(): void
    {
        if ($this->_tableExists('{{%controltower_content_events}}')) {
            return;
        }

        $this->createTable('{{%controltower_content_events}}', [
            'id' => $this->primaryKey(),
            'elementType' => $this->string(50)->notNull(),
            'elementId' => $this->integer()->notNull(),
            'action' => $this->string(50)->notNull(),
            'sectionHandle' => $this->string(255)->null(),
            'userId' => $this->integer()->null(),
            'recordedAt' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%controltower_content_events}}', ['elementType']);
        $this->createIndex(null, '{{%controltower_content_events}}', ['recordedAt']);
        $this->createIndex(null, '{{%controltower_content_events}}', ['action']);
        $this->createIndex(null, '{{%controltower_content_events}}', ['sectionHandle']);
    }

    private function _createMetricSamplesTable(): void
    {
        if ($this->_tableExists('{{%controltower_metric_samples}}')) {
            return;
        }

        $this->createTable('{{%controltower_metric_samples}}', [
            'id' => $this->primaryKey(),
            'cpuPercent' => $this->float()->null(),
            'memoryPercent' => $this->float()->null(),
            'diskPercent' => $this->float()->null(),
            'load1' => $this->float()->null(),
            'phpMemoryUsage' => $this->bigInteger()->null(),
            'dbPingMs' => $this->float()->null(),
            'queueBacklog' => $this->integer()->null(),
            'errorRate' => $this->float()->null(),
            'sampledAt' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%controltower_metric_samples}}', ['sampledAt']);
    }

    private function _createAlertsTable(): void
    {
        if ($this->_tableExists('{{%controltower_alerts}}')) {
            return;
        }

        $this->createTable('{{%controltower_alerts}}', [
            'id' => $this->primaryKey(),
            'type' => $this->string(100)->notNull(),
            'severity' => $this->string(20)->notNull()->defaultValue('warning'),
            'message' => $this->text()->notNull(),
            'context' => $this->text()->null(),
            'isActive' => $this->boolean()->notNull()->defaultValue(true),
            'resolvedAt' => $this->dateTime()->null(),
            'createdAt' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%controltower_alerts}}', ['type']);
        $this->createIndex(null, '{{%controltower_alerts}}', ['severity']);
        $this->createIndex(null, '{{%controltower_alerts}}', ['isActive']);
        $this->createIndex(null, '{{%controltower_alerts}}', ['createdAt']);
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

        $this->_seedDefaultRules();
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
