<?php

use yii\db\Migration;

/**
 * The nullable contractor_id columns that exist since Phase 2 (violation, corrective_action,
 * observation) now reference the contractor table (brief Phase 3: "link violations to contractors").
 */
class m260929_000003_contractor_foreign_keys extends Migration
{
    private const TABLES = ['violation', 'corrective_action', 'observation'];

    public function safeUp()
    {
        foreach (self::TABLES as $table) {
            // A database seeded before Phase 3 has contractor ids but no contractor rows yet. Those
            // links are cleared (and reported) so the key can be added; `yii seed` restores them.
            $dangling = $this->db->createCommand("UPDATE {{%$table}} t SET contractor_id = NULL
                WHERE contractor_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {{%contractor}} c WHERE c.id = t.contractor_id)")->execute();
            if ($dangling) {
                echo "    > $table: cleared $dangling contractor link(s) to contractors not loaded yet - re-run `yii seed` to restore them\n";
            }
            $this->addForeignKey("fk_{$table}_contractor", "{{%$table}}", 'contractor_id', '{{%contractor}}', 'id', 'SET NULL', 'CASCADE');
        }
    }

    public function safeDown()
    {
        foreach (self::TABLES as $table) {
            $this->dropForeignKey("fk_{$table}_contractor", "{{%$table}}");
        }
    }
}
