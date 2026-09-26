<?php

use yii\db\Migration;

/**
 * Accidents and dangerous occurrences; data/schema/incident.yaml (HANDOFF C23).
 * obligation_code cites data/reference/obligations.csv: RPT-03 fatal, RPT-04 injuries,
 * RPT-05 dangerous occurrence.
 */
class m260928_000006_create_incident extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%incident}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'occurred_at' => 'timestamptz NOT NULL',
            'reported_at' => 'timestamptz NOT NULL',
            'type' => $this->string(32)->notNull(),
            'severity' => $this->string(24)->notNull(),
            'persons_affected' => $this->integer()->notNull()->defaultValue(0),
            'description_code' => $this->string(64)->notNull(),
            'related_violation_id' => $this->integer()->null(),
            'reported_within_48h' => $this->boolean()->notNull(),
            'obligation_code' => $this->string(8)->notNull(),
        ]);
        $this->execute("ALTER TABLE {{%incident}}
            ADD CONSTRAINT incident_type_check CHECK (type IN ('ground_movement', 'transportation_winding',
                'transportation_other', 'machinery_other', 'explosives', 'electricity', 'gas_dust_fire',
                'fall_other_than_ground', 'other_causes')),
            ADD CONSTRAINT incident_severity_check CHECK (severity IN ('fatal', 'serious', 'minor', 'dangerous_occurrence')),
            ADD CONSTRAINT incident_obligation_check CHECK (obligation_code IN ('RPT-03', 'RPT-04', 'RPT-05')),
            ADD CONSTRAINT incident_persons_check CHECK (persons_affected >= 0),
            ADD CONSTRAINT incident_reported_after_check CHECK (reported_at >= occurred_at),
            ADD CONSTRAINT incident_description_code_check CHECK (description_code ~ '^[A-Z0-9_]+$')");
        $this->addForeignKey('fk_incident_mine', '{{%incident}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_incident_violation', '{{%incident}}', 'related_violation_id', '{{%violation}}', 'id', 'SET NULL', 'CASCADE');
        $this->createIndex('idx_incident_mine_occurred', '{{%incident}}', ['mine_id', 'occurred_at']);
        $this->createIndex('idx_incident_mine_severity', '{{%incident}}', ['mine_id', 'severity']);
        $this->createIndex('idx_incident_violation', '{{%incident}}', 'related_violation_id');
    }

    public function safeDown()
    {
        $this->dropTable('{{%incident}}');
    }
}
