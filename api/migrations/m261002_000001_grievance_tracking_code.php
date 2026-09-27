<?php

use yii\db\Migration;

/**
 * Grievance tracking codes (Phase 5B fix): tracking needs the ticket number AND a random code given
 * once at submission. Only an HMAC of the code is stored (GrievanceService::trackingHash), never
 * the code. Rows loaded before this migration have no code until the next `yii seed` (the demo
 * data carries codes for every seeded grievance).
 */
class m261002_000001_grievance_tracking_code extends Migration
{
    public function safeUp()
    {
        $this->addColumn('{{%grievance}}', 'tracking_code_hash', $this->char(64)->null()->after('ticket_no'));
        $this->execute("ALTER TABLE {{%grievance}} ADD CONSTRAINT grievance_tracking_hash_check
            CHECK (tracking_code_hash IS NULL OR tracking_code_hash ~ '^[0-9a-f]{64}$')");
    }

    public function safeDown()
    {
        $this->dropColumn('{{%grievance}}', 'tracking_code_hash');
    }
}
