<?php

namespace justinholtweb\controltower\migrations;

use craft\db\Migration;

/**
 * Drops the visitor IP hashes written before 5.2.0.
 *
 * Those were plain SHA-256 of the IP, which can be reversed by hashing all 2^32 IPv4 addresses —
 * personal data in all but name. New rows are keyed HMACs; the old ones are simply cleared rather
 * than kept for their retention window. Nothing reads `ipHash`, so no report changes.
 */
class m260929_000000_clear_reversible_ip_hashes extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%controltower_visitors}}')) {
            $this->update('{{%controltower_visitors}}', ['ipHash' => null], ['not', ['ipHash' => null]], [], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        // The cleared hashes are gone on purpose.
        return true;
    }
}
