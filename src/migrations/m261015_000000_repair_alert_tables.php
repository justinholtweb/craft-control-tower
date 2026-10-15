<?php

namespace justinholtweb\controltower\migrations;

use craft\db\Migration;

/**
 * Creates the alert rules and webhooks tables on sites that never got them.
 *
 * Before 5.4.1 they existed only in `m260514_000000_alerts_v1`, which Craft marks as applied
 * without running it on a fresh install, so every site installed on 5.x was missing both tables,
 * the `alertRuleId` column and the default rules. The install migration now creates all of them,
 * and each step skips what is already there, so re-running it here is safe on any site.
 */
class m261015_000000_repair_alert_tables extends Migration
{
    public function safeUp(): bool
    {
        return (new Install(['db' => $this->db]))->safeUp();
    }

    public function safeDown(): bool
    {
        // The tables belong to alerts_v1 and the install migration; nothing to undo here.
        return true;
    }
}
