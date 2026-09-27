<?php

use yii\db\Migration;

/**
 * Production reporting (brief Phase 4); columns exactly as data/schema/daily_production.yaml,
 * production_edit_log.yaml and production_detail_request.yaml.
 *
 * daily_production.status: draft (free to edit) -> submitted (by the mine head) -> locked (the
 * reporting period closed, ProductionService::LOCK_AFTER_DAYS). Submitted and locked entries are
 * both closed to direct editing: a change needs a reason and writes production_edit_log.
 */
class m260930_000001_create_production_tables extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%daily_production}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'date' => $this->date()->notNull(),
            'shift' => $this->char(1)->notNull(),
            'coal_target_t' => $this->decimal(12, 1)->notNull(),
            'coal_actual_t' => $this->decimal(12, 1)->notNull(),
            'ob_target_m3' => $this->decimal(12, 1)->notNull(),
            'ob_actual_m3' => $this->decimal(12, 1)->notNull(),
            'dispatch_t' => $this->decimal(12, 1)->notNull(),
            'closing_stock_t' => $this->decimal(14, 1)->notNull(),
            'breakdown_hours' => $this->decimal(4, 1)->notNull(),
            'manpower_present' => $this->integer()->notNull(),
            'remarks' => $this->text()->null(),
            'status' => $this->string(16)->notNull()->defaultValue('draft'),
            'submitted_by' => $this->integer()->null(),
            'submitted_at' => 'timestamptz NULL',
        ]);
        $this->execute("ALTER TABLE {{%daily_production}}
            ADD CONSTRAINT daily_production_shift_check CHECK (shift IN ('A', 'B', 'C')),
            ADD CONSTRAINT daily_production_status_check CHECK (status IN ('draft', 'submitted', 'locked')),
            ADD CONSTRAINT daily_production_submitted_check CHECK ((status = 'draft') = (submitted_at IS NULL)),
            ADD CONSTRAINT daily_production_amounts_check CHECK (coal_target_t >= 0 AND coal_actual_t >= 0 AND ob_target_m3 >= 0
                AND ob_actual_m3 >= 0 AND dispatch_t >= 0 AND closing_stock_t >= 0 AND manpower_present >= 0),
            ADD CONSTRAINT daily_production_breakdown_check CHECK (breakdown_hours >= 0 AND breakdown_hours <= 8)");
        $this->addForeignKey('fk_daily_production_mine', '{{%daily_production}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_daily_production_submitted_by', '{{%daily_production}}', 'submitted_by', '{{%user}}', 'id', 'SET NULL', 'CASCADE');
        $this->createIndex('uq_daily_production_mine_date_shift', '{{%daily_production}}', ['mine_id', 'date', 'shift'], true);
        $this->createIndex('idx_daily_production_date', '{{%daily_production}}', ['date', 'mine_id']);
        $this->createIndex('idx_daily_production_status', '{{%daily_production}}', ['status', 'date']);

        $this->createTable('{{%production_edit_log}}', [
            'id' => $this->primaryKey(),
            'production_id' => $this->integer()->notNull(),
            'field' => $this->string(32)->notNull(),
            'old_value' => $this->text()->notNull(),
            'new_value' => $this->text()->notNull(),
            'reason' => $this->text()->notNull(),
            'edited_by' => $this->integer()->notNull(),
            'edited_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%production_edit_log}}
            ADD CONSTRAINT production_edit_log_reason_check CHECK (length(btrim(reason)) > 0)");
        $this->addForeignKey('fk_production_edit_log_production', '{{%production_edit_log}}', 'production_id', '{{%daily_production}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_production_edit_log_user', '{{%production_edit_log}}', 'edited_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_production_edit_log_production', '{{%production_edit_log}}', ['production_id', 'edited_at']);

        $this->createTable('{{%production_detail_request}}', [
            'id' => $this->primaryKey(),
            'mine_id' => $this->integer()->notNull(),
            'requested_by' => $this->integer()->notNull(),
            'date_from' => $this->date()->notNull(),
            'date_to' => $this->date()->notNull(),
            'reason' => $this->text()->notNull(),
            'due_at' => 'timestamptz NOT NULL',
            'status' => $this->string(16)->notNull()->defaultValue('pending'),
            'response_note' => $this->text()->null(),
            'response_file_id' => $this->integer()->null(),
            'responded_by' => $this->integer()->null(),
            'responded_at' => 'timestamptz NULL',
            'created_at' => 'timestamptz NOT NULL DEFAULT now()',
        ]);
        $this->execute("ALTER TABLE {{%production_detail_request}}
            ADD CONSTRAINT production_detail_request_status_check CHECK (status IN ('pending', 'submitted', 'overdue', 'escalated', 'closed')),
            ADD CONSTRAINT production_detail_request_dates_check CHECK (date_to >= date_from),
            ADD CONSTRAINT production_detail_request_response_check
                CHECK ((status IN ('submitted', 'closed')) = (responded_at IS NOT NULL AND responded_by IS NOT NULL))");
        $this->addForeignKey('fk_detail_request_mine', '{{%production_detail_request}}', 'mine_id', '{{%mine}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_detail_request_requested_by', '{{%production_detail_request}}', 'requested_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_detail_request_responded_by', '{{%production_detail_request}}', 'responded_by', '{{%user}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk_detail_request_file', '{{%production_detail_request}}', 'response_file_id', '{{%file}}', 'id', 'RESTRICT', 'CASCADE');
        $this->createIndex('idx_detail_request_mine_range', '{{%production_detail_request}}', ['mine_id', 'status', 'date_from', 'date_to']);
        $this->createIndex('idx_detail_request_due', '{{%production_detail_request}}', ['status', 'due_at']);
    }

    public function safeDown()
    {
        $this->dropTable('{{%production_detail_request}}');
        $this->dropTable('{{%production_edit_log}}');
        $this->dropTable('{{%daily_production}}');
    }
}
