<?php

use yii\db\Migration;

require_once __DIR__ . '/m260928_000004_create_alert.php';

/**
 * Statutory obligation register (Phase 5B); columns exactly as data/schema/obligation.yaml,
 * obligation_applicability.yaml, obligation_task.yaml and obligation_submission.yaml.
 * Alert codes OBLIGATION_DUE_SOON (reminder) and OBLIGATION_OVERDUE (overdue, escalation levels 1-2).
 */
class m261003_000001_create_obligation_tables extends Migration
{
    private const TASK_STATUSES = "'open', 'submitted', 'accepted', 'rejected', 'overdue', 'escalated', 'waived'";

    public function safeUp()
    {
        $this->createTable('{{%obligation}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(16)->notNull()->unique(),
            'domain' => $this->string(16)->notNull(),
            'title' => $this->text()->notNull(),
            'instrument' => $this->text()->null(),
            'clause' => $this->string(96)->null(),
            'applies_to' => $this->string(16)->notNull(),
            'frequency' => $this->string(64)->notNull(),
            'due_rule' => $this->text()->null(),
            'responsible_role' => $this->text()->notNull(),
            'evidence_type' => $this->text()->notNull(),
            'citation_page' => $this->integer()->null(),
            'citation_file' => $this->text()->null(),
            'citation_quote' => $this->text()->null(),
            'verified' => $this->boolean()->notNull(),
            'note' => $this->text()->null(),
            'schedule' => $this->string(16)->notNull(),
            'generates_tasks' => $this->boolean()->notNull(),
            'due_basis' => $this->string(8)->notNull(),
        ]);
        $this->execute("ALTER TABLE {{%obligation}}
            ADD CONSTRAINT obligation_domain_check CHECK (domain IN ('reporting', 'safety', 'health', 'labour', 'environment')),
            ADD CONSTRAINT obligation_applies_check CHECK (applies_to IN ('mine', 'contractor', 'worker')),
            ADD CONSTRAINT obligation_schedule_check CHECK (schedule IN ('weekly', 'fortnightly', 'monthly', 'quarterly', 'half_yearly', 'annual', 'none')),
            ADD CONSTRAINT obligation_due_basis_check CHECK (due_basis IN ('law', 'product', 'none')),
            ADD CONSTRAINT obligation_generates_check CHECK (NOT generates_tasks OR (verified AND applies_to = 'mine' AND schedule <> 'none'))");

        $this->createTable('{{%obligation_applicability}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'obligation_id' => $this->integer()->notNull(),
            'basis' => $this->string(24)->notNull(),
        ]);
        $this->execute("ALTER TABLE {{%obligation_applicability}}
            ADD CONSTRAINT obligation_applicability_basis_check CHECK (basis IN ('all_mines', 'underground_or_mixed', 'workforce_500'))");
        $this->addForeignKey('fk_obligation_applicability_mine', '{{%obligation_applicability}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_obligation_applicability_obligation', '{{%obligation_applicability}}', 'obligation_id', '{{%obligation}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('uq_obligation_applicability', '{{%obligation_applicability}}', ['mine_id', 'obligation_id'], true);

        $this->createTable('{{%obligation_task}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'obligation_id' => $this->integer()->notNull(),
            'period' => $this->string(12)->notNull(),
            'period_start' => $this->date()->notNull(),
            'period_end' => $this->date()->notNull(),
            'due_at' => 'timestamptz NOT NULL',
            'due_basis' => $this->string(8)->notNull(),
            'status' => $this->string(16)->notNull()->defaultValue('open'),
            'escalation_level' => $this->smallInteger()->notNull()->defaultValue(0),
            'accepted_at' => 'timestamptz NULL',
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%obligation_task}}
            ADD CONSTRAINT obligation_task_status_check CHECK (status IN (" . self::TASK_STATUSES . ")),
            ADD CONSTRAINT obligation_task_due_basis_check CHECK (due_basis IN ('law', 'product')),
            ADD CONSTRAINT obligation_task_period_check CHECK (period_end >= period_start),
            ADD CONSTRAINT obligation_task_escalation_check CHECK (escalation_level BETWEEN 0 AND 2),
            ADD CONSTRAINT obligation_task_accepted_check CHECK ((status = 'accepted') = (accepted_at IS NOT NULL))");
        $this->addForeignKey('fk_obligation_task_mine', '{{%obligation_task}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_obligation_task_obligation', '{{%obligation_task}}', 'obligation_id', '{{%obligation}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('uq_obligation_task_period', '{{%obligation_task}}', ['mine_id', 'obligation_id', 'period'], true);
        $this->createIndex('idx_obligation_task_mine_status', '{{%obligation_task}}', ['mine_id', 'status', 'due_at']);
        $this->createIndex('idx_obligation_task_status_due', '{{%obligation_task}}', ['status', 'due_at']);
        $this->createIndex('idx_obligation_task_due', '{{%obligation_task}}', 'due_at');

        $this->createTable('{{%obligation_submission}}', [
            'id' => $this->primaryKey(),
            'task_id' => $this->integer()->notNull(),
            'file_id' => $this->integer()->notNull(),
            'note' => $this->text()->null(),
            'submitted_by' => $this->integer()->notNull(),
            'submitted_at' => 'timestamptz NOT NULL DEFAULT now()',
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'reviewed_by' => $this->integer()->null(),
            'reviewed_at' => 'timestamptz NULL',
            'review_note' => $this->text()->null(),
        ]);
        $this->execute("ALTER TABLE {{%obligation_submission}}
            ADD CONSTRAINT obligation_submission_status_check CHECK (status IN ('pending', 'accepted', 'rejected')),
            ADD CONSTRAINT obligation_submission_review_check CHECK ((status = 'pending') = (reviewed_at IS NULL AND reviewed_by IS NULL)),
            ADD CONSTRAINT obligation_submission_reason_check CHECK (status <> 'rejected' OR length(btrim(coalesce(review_note, ''))) > 0)");
        $this->addForeignKey('fk_obligation_submission_task', '{{%obligation_submission}}', 'task_id', '{{%obligation_task}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_obligation_submission_file', '{{%obligation_submission}}', 'file_id', '{{%file}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_obligation_submission_by', '{{%obligation_submission}}', 'submitted_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_obligation_submission_reviewer', '{{%obligation_submission}}', 'reviewed_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_obligation_submission_task', '{{%obligation_submission}}', ['task_id', 'submitted_at']);
        $this->createIndex('idx_obligation_submission_pending', '{{%obligation_submission}}', ['status', 'submitted_at']);

        $this->setCodes([...m260928_000004_create_alert::CODES, 'CONTRACT_WORKER_CAP_EXCEEDED', 'OBLIGATION_DUE_SOON', 'OBLIGATION_OVERDUE']);
    }

    public function safeDown()
    {
        $this->execute("DELETE FROM {{%alert}} WHERE code IN ('OBLIGATION_DUE_SOON', 'OBLIGATION_OVERDUE')");
        $this->setCodes([...m260928_000004_create_alert::CODES, 'CONTRACT_WORKER_CAP_EXCEEDED']);
        $this->dropTable('{{%obligation_submission}}');
        $this->dropTable('{{%obligation_task}}');
        $this->dropTable('{{%obligation_applicability}}');
        $this->dropTable('{{%obligation}}');
    }

    private function setCodes(array $codes): void
    {
        $list = implode(', ', array_map(fn($c) => "'$c'", $codes));
        $this->execute('ALTER TABLE {{%alert}} DROP CONSTRAINT alert_code_check');
        $this->execute("ALTER TABLE {{%alert}} ADD CONSTRAINT alert_code_check CHECK (code IN ($list))");
    }
}
