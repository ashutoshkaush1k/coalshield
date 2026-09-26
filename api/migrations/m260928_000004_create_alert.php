<?php

use yii\db\Migration;

/**
 * Alerts carry {code, params} only - no display text (brief rule 7); data/schema/alert.yaml.
 * INSPECTION_DIRECTIVE is added to the data's codes for government-raised directives (the
 * prototype's "Flag for inspection"), which the historical data does not contain.
 */
class m260928_000004_create_alert extends Migration
{
    public const CODES = [
        'SENSOR_THRESHOLD_BREACHED', 'VIOLATION_RECORDED', 'CORRECTIVE_ACTION_OVERDUE',
        'CONTRACTOR_LICENCE_EXPIRING', 'WORKER_VT_EXPIRED', 'WORKER_MEDICAL_EXPIRED',
        'CONTRACTOR_DOC_MISSING', 'GRIEVANCE_SLA_BREACHED', 'DETAIL_REQUEST_OVERDUE',
        'DANGEROUS_OCCURRENCE_REPORTED', 'INSPECTION_DIRECTIVE', 'AI_SERVICE_UNAVAILABLE',
    ];

    public function safeUp()
    {
        $this->createTable('{{%alert}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(48)->notNull(),
            'params' => "jsonb NOT NULL DEFAULT '{}'::jsonb",
            'severity' => $this->string(8)->notNull(),
            'mine_id' => $this->integer()->notNull(),
            'entity_type' => $this->string(48)->notNull(),
            'entity_id' => $this->bigInteger()->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('open'),
            'ack_by' => $this->integer()->null(),
            'escalation_level' => $this->smallInteger()->notNull()->defaultValue(0),
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $codes = implode(', ', array_map(fn($c) => "'$c'", self::CODES));
        $this->execute("ALTER TABLE {{%alert}}
            ADD CONSTRAINT alert_code_check CHECK (code IN ($codes)),
            ADD CONSTRAINT alert_severity_check CHECK (severity IN ('low', 'medium', 'high')),
            ADD CONSTRAINT alert_status_check CHECK (status IN ('open', 'acknowledged', 'resolved')),
            ADD CONSTRAINT alert_params_object_check CHECK (jsonb_typeof(params) = 'object'),
            ADD CONSTRAINT alert_escalation_check CHECK (escalation_level >= 0)");
        $this->addForeignKey('fk_alert_mine', '{{%alert}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_alert_ack_by', '{{%alert}}', 'ack_by', '{{%user}}', 'id', 'SET NULL', 'CASCADE');
        $this->createIndex('idx_alert_mine_status', '{{%alert}}', ['mine_id', 'status']);
        $this->createIndex('idx_alert_mine_created', '{{%alert}}', ['mine_id', 'created_at']);
        $this->createIndex('idx_alert_created', '{{%alert}}', 'created_at');
        $this->createIndex('idx_alert_entity', '{{%alert}}', ['entity_type', 'entity_id']);
        $this->createIndex('idx_alert_ack_by', '{{%alert}}', 'ack_by');
        $this->createIndex('idx_alert_code', '{{%alert}}', 'code');
    }

    public function safeDown()
    {
        $this->dropTable('{{%alert}}');
    }
}
