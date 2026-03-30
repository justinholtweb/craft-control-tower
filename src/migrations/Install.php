<?php

namespace justinholtweb\controltower\migrations;

use craft\db\Migration;

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

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%controltower_alerts}}');
        $this->dropTableIfExists('{{%controltower_metric_samples}}');
        $this->dropTableIfExists('{{%controltower_content_events}}');
        $this->dropTableIfExists('{{%controltower_editor_activity}}');
        $this->dropTableIfExists('{{%controltower_editor_sessions}}');
        $this->dropTableIfExists('{{%controltower_visitors}}');

        return true;
    }

    private function _createVisitorsTable(): void
    {
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
}
