<?php

use yii\db\Migration;

/**
 * violation and observation (data/schema/violation.yaml, observation.yaml).
 *
 * The two reference each other (observation.violation_id <-> violation.observation_id), so both
 * foreign keys are DEFERRABLE: `yii seed` loads them in one transaction with SET CONSTRAINTS ALL
 * DEFERRED (HANDOFF load order 16-17). Categories are the 11 of violation_categories.yaml.
 * contractor_id and grievance_id get their foreign keys when those tables exist (Phases 3 and 5).
 */
class m260928_000003_create_violation_and_observation extends Migration
{
    private const CATEGORIES = "'roof_strata', 'ventilation_gas', 'electrical', 'transport_haulage', 'explosives', "
        . "'ppe', 'fire', 'environment', 'welfare', 'documentation', 'machinery'";

    public function safeUp()
    {
        $this->createTable('{{%violation}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'violation_type' => $this->string(64)->notNull(),
            'category' => $this->string(32)->notNull(),
            'confidence' => $this->decimal(4, 3)->null(),
            'source' => $this->string(16)->notNull(),
            'frame_ref' => $this->string(255)->null(),
            'inspection_id' => $this->integer()->null(),
            'observation_id' => $this->integer()->null(),
            'contractor_id' => $this->integer()->null(),
            'detected_at' => 'timestamptz NOT NULL',
            'resolved' => $this->boolean()->notNull()->defaultValue(false),
            'resolved_at' => 'timestamptz NULL',
        ]);
        $this->execute("ALTER TABLE {{%violation}}
            ADD CONSTRAINT violation_category_check CHECK (category IN (" . self::CATEGORIES . ")),
            ADD CONSTRAINT violation_source_check CHECK (source IN ('vision', 'inspection', 'grievance')),
            ADD CONSTRAINT violation_confidence_check CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 1)),
            ADD CONSTRAINT violation_resolved_check CHECK (resolved = (resolved_at IS NOT NULL))");

        $this->createTable('{{%observation}}', [
            'id' => $this->primaryKey(),
            'inspection_id' => $this->integer()->null(),
            'grievance_id' => $this->integer()->null(),
            'mine_id' => $this->integer()->notNull(),
            'category' => $this->string(32)->notNull(),
            'severity' => $this->string(8)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('open'),
            'violation_id' => $this->integer()->null(),
            'contractor_id' => $this->integer()->null(),
            'observed_at' => 'timestamptz NOT NULL',
        ]);
        $this->execute("ALTER TABLE {{%observation}}
            ADD CONSTRAINT observation_category_check CHECK (category IN (" . self::CATEGORIES . ")),
            ADD CONSTRAINT observation_severity_check CHECK (severity IN ('low', 'medium', 'high')),
            ADD CONSTRAINT observation_status_check CHECK (status IN ('open', 'promoted', 'dismissed')),
            ADD CONSTRAINT observation_promoted_check CHECK ((status = 'promoted') = (violation_id IS NOT NULL))");

        $this->addForeignKey('fk_violation_mine', '{{%violation}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_violation_inspection', '{{%violation}}', 'inspection_id', '{{%inspection}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_observation_mine', '{{%observation}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_observation_inspection', '{{%observation}}', 'inspection_id', '{{%inspection}}', 'id', 'RESTRICT', 'CASCADE');
        $this->execute('ALTER TABLE {{%violation}} ADD CONSTRAINT fk_violation_observation FOREIGN KEY (observation_id)
            REFERENCES {{%observation}} (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE');
        $this->execute('ALTER TABLE {{%observation}} ADD CONSTRAINT fk_observation_violation FOREIGN KEY (violation_id)
            REFERENCES {{%violation}} (id) ON DELETE RESTRICT DEFERRABLE INITIALLY IMMEDIATE');

        $this->createIndex('idx_violation_mine_resolved', '{{%violation}}', ['mine_id', 'resolved']);
        $this->createIndex('idx_violation_mine_detected', '{{%violation}}', ['mine_id', 'detected_at']);
        $this->createIndex('idx_violation_inspection', '{{%violation}}', 'inspection_id');
        $this->createIndex('idx_violation_observation', '{{%violation}}', 'observation_id');
        $this->createIndex('idx_violation_contractor', '{{%violation}}', 'contractor_id');
        $this->createIndex('idx_observation_mine_status', '{{%observation}}', ['mine_id', 'status']);
        $this->createIndex('idx_observation_inspection', '{{%observation}}', 'inspection_id');
        $this->createIndex('idx_observation_violation', '{{%observation}}', 'violation_id');
        $this->createIndex('idx_observation_grievance', '{{%observation}}', 'grievance_id');
        $this->createIndex('idx_observation_contractor', '{{%observation}}', 'contractor_id');
    }

    public function safeDown()
    {
        $this->dropForeignKey('fk_observation_violation', '{{%observation}}');
        $this->dropTable('{{%violation}}');
        $this->dropTable('{{%observation}}');
    }
}
