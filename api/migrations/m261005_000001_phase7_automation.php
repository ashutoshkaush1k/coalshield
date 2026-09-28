<?php

use yii\db\Migration;

require_once __DIR__ . '/m260928_000004_create_alert.php';

/**
 * Phase 7: automation and the ai-service.
 *
 *   anomaly_flag          what the detectors found (ai-service, or the PHP fallback): one row per
 *                         detector, mine and subject (a day, a sensor run, a category, a contractor);
 *                         kept active while the detector still finds it, cleared when it no longer does
 *   job_run               every scheduled job's run: when, how long, what it did, which engine
 *   mine_risk_snapshot    once a day per mine: the compliance score and the Governance Risk Index
 *   mine_risk_prediction  the predictive model's latest output per mine (US-trained, transferred)
 *
 * Alert codes ANOMALY_DETECTED (a new flag) and PRODUCTION_ENTRY_PENDING (yesterday's shifts not
 * submitted).
 */
class m261005_000001_phase7_automation extends Migration
{
    private const ALERT_CODES_BEFORE = ['CONTRACT_WORKER_CAP_EXCEEDED', 'OBLIGATION_DUE_SOON', 'OBLIGATION_OVERDUE'];

    public function safeUp()
    {
        $this->createTable('{{%anomaly_flag}}', [
            'id' => $this->primaryKey(),
            'detector' => $this->string(40)->notNull(),
            'mine_id' => $this->integer()->notNull(),
            'subject' => $this->string(120)->notNull(),
            'window_from' => $this->timestamp()->null(),
            'window_to' => $this->timestamp()->null(),
            'score' => $this->double()->notNull(),
            'reasons' => 'jsonb NOT NULL',
            'entities' => "jsonb NOT NULL DEFAULT '{}'",
            'engine' => $this->string(16)->notNull(),
            'status' => $this->string(12)->notNull()->defaultValue('active'),
            'first_detected_at' => $this->timestamp()->notNull(),
            'last_seen_at' => $this->timestamp()->notNull(),
            'cleared_at' => $this->timestamp()->null(),
        ]);
        $this->execute("ALTER TABLE {{%anomaly_flag}}
            ADD CONSTRAINT anomaly_flag_engine_check CHECK (engine IN ('ai-service', 'php')),
            ADD CONSTRAINT anomaly_flag_status_check CHECK (status IN ('active', 'cleared'))");
        $this->addForeignKey('fk_anomaly_flag_mine', '{{%anomaly_flag}}', 'mine_id', '{{%mine}}', 'id', 'CASCADE', 'CASCADE');
        $this->createIndex('anomaly_flag_unique', '{{%anomaly_flag}}', ['detector', 'mine_id', 'subject'], true);
        $this->createIndex('anomaly_flag_mine_status', '{{%anomaly_flag}}', ['mine_id', 'status']);

        $this->createTable('{{%job_run}}', [
            'id' => $this->primaryKey(),
            'job' => $this->string(40)->notNull(),
            'started_at' => $this->timestamp()->notNull(),
            'finished_at' => $this->timestamp()->null(),
            'status' => $this->string(12)->notNull(),
            'summary' => "jsonb NOT NULL DEFAULT '{}'",
            'error' => $this->text()->null(),
        ]);
        $this->execute("ALTER TABLE {{%job_run}} ADD CONSTRAINT job_run_status_check CHECK (status IN ('running', 'ok', 'failed', 'skipped'))");
        $this->createIndex('job_run_job_started', '{{%job_run}}', ['job', 'started_at']);

        $this->createTable('{{%mine_risk_snapshot}}', [
            'mine_id' => $this->integer()->notNull(),
            'day' => $this->date()->notNull(),
            'score' => $this->integer()->notNull(),
            'risk_level' => $this->string(12)->notNull(),
            'gri' => $this->integer()->notNull(),
            'gri_band' => $this->string(12)->notNull(),
            'components' => 'jsonb NOT NULL',
            'created_at' => $this->timestamp()->notNull(),
        ]);
        $this->addPrimaryKey('mine_risk_snapshot_pk', '{{%mine_risk_snapshot}}', ['mine_id', 'day']);
        $this->addForeignKey('fk_mine_risk_snapshot_mine', '{{%mine_risk_snapshot}}', 'mine_id', '{{%mine}}', 'id', 'CASCADE', 'CASCADE');

        $this->createTable('{{%mine_risk_prediction}}', [
            'mine_id' => $this->integer()->notNull(),
            'predicted_at' => $this->timestamp()->notNull(),
            'probability' => $this->double()->notNull(),
            'band' => $this->string(12)->notNull(),
            'factors' => 'jsonb NOT NULL',
            'features' => 'jsonb NOT NULL',
            'model_version' => $this->string(40)->notNull(),
            'engine' => $this->string(16)->notNull(),
        ]);
        $this->addPrimaryKey('mine_risk_prediction_pk', '{{%mine_risk_prediction}}', ['mine_id']);
        $this->addForeignKey('fk_mine_risk_prediction_mine', '{{%mine_risk_prediction}}', 'mine_id', '{{%mine}}', 'id', 'CASCADE', 'CASCADE');

        $this->setCodes([...m260928_000004_create_alert::CODES, ...self::ALERT_CODES_BEFORE, 'ANOMALY_DETECTED', 'PRODUCTION_ENTRY_PENDING']);
    }

    public function safeDown()
    {
        $this->execute("DELETE FROM {{%alert}} WHERE code IN ('ANOMALY_DETECTED', 'PRODUCTION_ENTRY_PENDING')");
        $this->setCodes([...m260928_000004_create_alert::CODES, ...self::ALERT_CODES_BEFORE]);
        $this->dropTable('{{%mine_risk_prediction}}');
        $this->dropTable('{{%mine_risk_snapshot}}');
        $this->dropTable('{{%job_run}}');
        $this->dropTable('{{%anomaly_flag}}');
    }

    private function setCodes(array $codes): void
    {
        $list = implode(', ', array_map(fn($c) => "'$c'", $codes));
        $this->execute('ALTER TABLE {{%alert}} DROP CONSTRAINT alert_code_check');
        $this->execute("ALTER TABLE {{%alert}} ADD CONSTRAINT alert_code_check CHECK (code IN ($list))");
    }
}
